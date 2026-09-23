<?php
/**
 * Server-side client for the SendRepute customer API.
 *
 * @package SendRepute
 */

defined( 'ABSPATH' ) || exit;

final class SendRepute_Client {
	const SETTINGS_OPTION = 'sendrepute_settings';
	const TOKEN_OPTION    = 'sendrepute_token';
	const MAX_RESPONSE_BYTES = 1048576;

	/**
	 * Return the plugin settings without ever mixing in the API token.
	 *
	 * @return array
	 */
	public static function settings() {
		$defaults = array(
			'enabled'        => false,
			'paid_consent'   => false,
			'failure_policy' => 'open',
			'risk_policy'    => 'advisory',
			'threshold'      => 0.8,
			'model'          => '',
			'retain_data'    => false,
			'woocommerce_enabled' => false,
			'woocommerce_types'   => array(),
		);
		$stored = get_option( self::SETTINGS_OPTION, array() );
		$stored = is_array( $stored ) ? array_intersect_key( $stored, $defaults ) : array();

		return wp_parse_args( $stored, $defaults );
	}

	/**
	 * Encrypt and persist a token. An empty value removes the stored token.
	 *
	 * @param string $token Customer API bearer token.
	 * @return true|WP_Error
	 */
	public static function store_token( $token ) {
		if ( ! is_string( $token ) ) {
			return new WP_Error( 'sendrepute_invalid_token', __( 'The API token must be a string.', 'sendrepute' ) );
		}

		$token = trim( $token );
		if ( '' === $token ) {
			delete_option( self::TOKEN_OPTION );
			return true;
		}
		if ( strlen( $token ) > 4096 ) {
			return new WP_Error( 'sendrepute_invalid_token', __( 'The API token is too long.', 'sendrepute' ) );
		}
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_get_cipher_methods' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			return new WP_Error( 'sendrepute_crypto_unavailable', __( 'Authenticated token encryption is unavailable on this server.', 'sendrepute' ) );
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( Exception $exception ) {
			return new WP_Error( 'sendrepute_crypto_unavailable', __( 'Secure random data is unavailable on this server.', 'sendrepute' ) );
		}

		$tag        = '';
		$ciphertext = openssl_encrypt(
			$token,
			'aes-256-gcm',
			self::encryption_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			self::TOKEN_OPTION,
			16
		);
		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return new WP_Error( 'sendrepute_encryption_failed', __( 'The API token could not be encrypted.', 'sendrepute' ) );
		}

		$payload = array(
			'v'          => 1,
			'ciphertext' => base64_encode( $ciphertext ),
			'iv'         => base64_encode( $iv ),
			'tag'        => base64_encode( $tag ),
		);

		if ( ! update_option( self::TOKEN_OPTION, $payload, false ) && get_option( self::TOKEN_OPTION ) !== $payload ) {
			return new WP_Error( 'sendrepute_token_storage_failed', __( 'The encrypted API token could not be stored.', 'sendrepute' ) );
		}

		return true;
	}

	/**
	 * Obtain the plaintext token for server-side requests only.
	 *
	 * The wp-config.php override always wins. Callers must never render or log
	 * this return value.
	 *
	 * @return string|WP_Error
	 */
	public static function token() {
		if ( defined( 'SENDREPUTE_API_TOKEN' ) ) {
			if ( ! is_string( SENDREPUTE_API_TOKEN ) || '' === trim( SENDREPUTE_API_TOKEN ) ) {
				return new WP_Error( 'sendrepute_invalid_token_override', __( 'SENDREPUTE_API_TOKEN is empty or invalid.', 'sendrepute' ) );
			}
			return trim( SENDREPUTE_API_TOKEN );
		}

		$payload = get_option( self::TOKEN_OPTION, null );
		if ( ! is_array( $payload ) || 1 !== (int) ( isset( $payload['v'] ) ? $payload['v'] : 0 ) ) {
			return new WP_Error( 'sendrepute_token_missing', __( 'A SendRepute API token has not been configured.', 'sendrepute' ) );
		}
		foreach ( array( 'ciphertext', 'iv', 'tag' ) as $field ) {
			if ( ! isset( $payload[ $field ] ) || ! is_string( $payload[ $field ] ) ) {
				return new WP_Error( 'sendrepute_token_invalid', __( 'The stored API token is invalid.', 'sendrepute' ) );
			}
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return new WP_Error( 'sendrepute_crypto_unavailable', __( 'Authenticated token encryption is unavailable on this server.', 'sendrepute' ) );
		}

		$ciphertext = base64_decode( $payload['ciphertext'], true );
		$iv         = base64_decode( $payload['iv'], true );
		$tag        = base64_decode( $payload['tag'], true );
		if ( false === $ciphertext || false === $iv || false === $tag || 12 !== strlen( $iv ) || 16 !== strlen( $tag ) ) {
			return new WP_Error( 'sendrepute_token_invalid', __( 'The stored API token is invalid.', 'sendrepute' ) );
		}

		$token = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			self::encryption_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			self::TOKEN_OPTION
		);
		if ( false === $token || '' === $token ) {
			return new WP_Error( 'sendrepute_token_decryption_failed', __( 'The stored API token could not be decrypted.', 'sendrepute' ) );
		}

		return $token;
	}

	/**
	 * Return an installation-bound, non-reversible identity for the active
	 * credential. This can safely scope opaque local cache keys without storing
	 * or exposing the bearer token itself.
	 *
	 * @return string|WP_Error
	 */
	public static function token_identity() {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		return hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
	}

	/**
	 * Make one authenticated customer API request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Absolute API path, beginning with /v1/.
	 * @param array|null $body   Optional JSON request body.
	 * @return array|WP_Error
	 */
	public static function request( $method, $path, $body = null ) {
		$method = strtoupper( (string) $method );
		if ( ! in_array( $method, array( 'GET', 'POST', 'DELETE' ), true ) || ! is_string( $path ) || ! preg_match( '#^/v1/[A-Za-z0-9/_-]*$#', $path ) ) {
			return new WP_Error( 'sendrepute_invalid_request', __( 'The SendRepute API request is invalid.', 'sendrepute' ) );
		}
		if ( ! defined( 'SENDREPUTE_API_BASE' ) || ! is_string( SENDREPUTE_API_BASE ) || '' === trim( SENDREPUTE_API_BASE ) ) {
			return new WP_Error( 'sendrepute_api_base_missing', __( 'The SendRepute API base URL is not configured.', 'sendrepute' ) );
		}

		$base = untrailingslashit( trim( SENDREPUTE_API_BASE ) );
		if ( ! wp_http_validate_url( $base ) || 0 !== strpos( strtolower( $base ), 'https://' ) ) {
			return new WP_Error( 'sendrepute_api_base_invalid', __( 'The SendRepute API base URL must be a valid HTTPS URL.', 'sendrepute' ) );
		}

		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$headers = array(
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $token,
		);
		$args    = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => 20,
			'redirection' => 0,
			'sslverify'   => true,
			// Ask WordPress to retain one byte past our accepted maximum so a
			// truncated/oversized response is distinguishable from an exact fit.
			'limit_response_size' => self::MAX_RESPONSE_BYTES + 1,
		);
		if ( null !== $body ) {
			if ( ! is_array( $body ) ) {
				return new WP_Error( 'sendrepute_invalid_request', __( 'The SendRepute API request body must be an array.', 'sendrepute' ) );
			}
			$json = wp_json_encode( $body );
			if ( false === $json ) {
				return new WP_Error( 'sendrepute_json_failed', __( 'The SendRepute API request could not be encoded.', 'sendrepute' ) );
			}
			$args['body']                    = $json;
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request( $base . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sendrepute_transport_error', __( 'The SendRepute API could not be reached.', 'sendrepute' ), array( 'cause' => $response->get_error_code() ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$content_length = wp_remote_retrieve_header( $response, 'content-length' );
		if ( strlen( $raw ) > self::MAX_RESPONSE_BYTES ||
			( is_scalar( $content_length ) && ctype_digit( trim( (string) $content_length ) ) && (int) $content_length > self::MAX_RESPONSE_BYTES )
		) {
			return new WP_Error( 'sendrepute_response_too_large', __( 'The SendRepute API response exceeded the safe size limit.', 'sendrepute' ), array( 'status' => $status ) );
		}
		$decoded = json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 ) {
			$message = __( 'The SendRepute API rejected the request.', 'sendrepute' );
			$code    = 'sendrepute_api_error';
			if ( is_array( $decoded ) && isset( $decoded['error']['code'], $decoded['error']['message'] ) ) {
				$code    = 'sendrepute_api_' . sanitize_key( $decoded['error']['code'] );
				$message = sanitize_text_field( $decoded['error']['message'] );
			}
			return new WP_Error( $code, $message, array( 'status' => $status ) );
		}
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'sendrepute_invalid_response', __( 'The SendRepute API returned an invalid response.', 'sendrepute' ), array( 'status' => $status ) );
		}

		return $decoded;
	}

	/**
	 * Verify non-paid account and catalog scopes and return current pricing.
	 *
	 * The classify scope is reported as required but is intentionally not probed,
	 * because its endpoint is paid.
	 *
	 * @return array|WP_Error
	 */
	public static function connection() {
		$account = self::request( 'GET', '/v1/account' );
		if ( is_wp_error( $account ) ) {
			$data                   = $account->get_error_data();
			$data                   = is_array( $data ) ? $data : array();
			$data['required_scope'] = 'account:read';
			$account->add_data( $data );
			return $account;
		}
		$pricing = self::request( 'GET', '/v1/pricing' );
		if ( is_wp_error( $pricing ) ) {
			$data                   = $pricing->get_error_data();
			$data                   = is_array( $data ) ? $data : array();
			$data['required_scope'] = 'catalog:read';
			$pricing->add_data( $data );
			return $pricing;
		}

		$required_prices = array(
			'classificationBaseMillicents',
			'additionalTermMillicents',
			'maximumClassificationMillicents',
		);
		foreach ( $required_prices as $field ) {
			if ( ! isset( $pricing[ $field ] ) || ! is_numeric( $pricing[ $field ] ) || (float) $pricing[ $field ] < 0 ) {
				return new WP_Error( 'sendrepute_invalid_pricing', __( 'The SendRepute API returned invalid classification pricing.', 'sendrepute' ) );
			}
		}

		return array(
			'connected'       => true,
			'account'         => $account,
			'pricing'         => $pricing,
			'verified_scopes' => array( 'account:read', 'catalog:read' ),
			'required_scopes' => array( 'account:read', 'catalog:read', 'classify' ),
		);
	}

	/**
	 * Derive an installation-bound encryption key from WordPress salts.
	 *
	 * @return string Binary key.
	 */
	private static function encryption_key() {
		return hash_hmac( 'sha256', self::TOKEN_OPTION, wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
	}

}