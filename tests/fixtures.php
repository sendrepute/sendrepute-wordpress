<?php
/**
 * Offline fixture tests for the WordPress integration.
 *
 * Run with: php integrations/wordpress/tests/fixtures.php
 *
 * These tests deliberately provide their own small WordPress surface and never
 * open a socket. They load the production client and mail hook directly.
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );
define( 'SENDREPUTE_API_BASE', 'https://offline.invalid/api' );

$GLOBALS['wp_options']        = array();
$GLOBALS['wp_filters']        = array();
$GLOBALS['remote_calls']      = array();
$GLOBALS['remote_handler']    = null;
$GLOBALS['add_option_hook']   = null;
$GLOBALS['tests_run']         = 0;

class Fixture_WPDB {
	public $options = 'wp_options';
	public $queries = 0;

	public function prepare( $query, $option_name, $option_value ) {
		return array( $query, $option_name, $option_value );
	}

	public function query( $prepared ) {
		$this->queries++;
		$option_name  = $prepared[1];
		$option_value = $prepared[2];
		if ( array_key_exists( $option_name, $GLOBALS['wp_options'] ) && maybe_serialize( $GLOBALS['wp_options'][ $option_name ] ) === $option_value ) {
			unset( $GLOBALS['wp_options'][ $option_name ] );
			return 1;
		}
		return 0;
	}
}

$GLOBALS['wpdb'] = new Fixture_WPDB();

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}

	public function add_data( $data ) {
		$this->data = $data;
	}
}

function __( $text ) {
	return $text;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, is_array( $args ) ? $args : array() );
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wp_options'] ) ? $GLOBALS['wp_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$changed                         = ! array_key_exists( $name, $GLOBALS['wp_options'] ) || $GLOBALS['wp_options'][ $name ] !== $value;
	$GLOBALS['wp_options'][ $name ] = $value;
	return $changed;
}

function delete_option( $name ) {
	if ( ! array_key_exists( $name, $GLOBALS['wp_options'] ) ) {
		return false;
	}
	unset( $GLOBALS['wp_options'][ $name ] );
	return true;
}

function add_option( $name, $value, $deprecated = '', $autoload = 'yes' ) {
	if ( is_callable( $GLOBALS['add_option_hook'] ) ) {
		$result = call_user_func( $GLOBALS['add_option_hook'], $name, $value );
		if ( null !== $result ) {
			return $result;
		}
	}
	if ( array_key_exists( $name, $GLOBALS['wp_options'] ) ) {
		return false;
	}
	$GLOBALS['wp_options'][ $name ] = $value;
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['wp_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
	return true;
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

function wp_salt( $scheme = 'auth' ) {
	return 'offline-test-salt-' . $scheme;
}

function maybe_serialize( $value ) {
	return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
}

function wp_cache_delete( $key, $group = '' ) {
	return true;
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

function wp_http_validate_url( $url ) {
	return false !== filter_var( $url, FILTER_VALIDATE_URL );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wp_remote_request( $url, $args ) {
	$GLOBALS['remote_calls'][] = array( 'url' => $url, 'args' => $args );
	if ( ! is_callable( $GLOBALS['remote_handler'] ) ) {
		throw new RuntimeException( 'An offline HTTP response was not installed.' );
	}
	return call_user_func( $GLOBALS['remote_handler'], $url, $args );
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function get_bloginfo( $field ) {
	return 'Fixture Site';
}

require_once __DIR__ . '/../sendrepute/includes/class-sendrepute-client.php';
require_once __DIR__ . '/../sendrepute/includes/class-sendrepute-mail.php';

function fixture_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function fixture_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

function fixture_test( $name, $callback ) {
	$GLOBALS['tests_run']++;
	fixture_reset();
	try {
		call_user_func( $callback );
		echo "ok - {$name}\n";
	} catch ( Throwable $error ) {
		fwrite( STDERR, "not ok - {$name}: " . $error->getMessage() . "\n" );
		exit( 1 );
	}
}

function fixture_reset() {
	$GLOBALS['wp_options']      = array();
	$GLOBALS['remote_calls']    = array();
	$GLOBALS['remote_handler']  = null;
	$GLOBALS['add_option_hook'] = null;
	$GLOBALS['wpdb']->queries   = 0;
}

function fixture_settings( $overrides = array() ) {
	$GLOBALS['wp_options'][ SendRepute_Client::SETTINGS_OPTION ] = array_merge(
		array(
			'enabled'        => true,
			'paid_consent'   => true,
			'failure_policy' => 'open',
			'risk_policy'    => 'advisory',
			'threshold'      => 0.8,
			'model'          => 'thor',
		),
		$overrides
	);
	$token_result = SendRepute_Client::store_token( 'offline-fixture-bearer' );
	fixture_assert( true === $token_result, 'Could not install the offline encrypted bearer fixture.' );
}

function fixture_atts() {
	return array(
		'to'          => array( 'one@example.test', 'two@example.test' ),
		'subject'     => 'A real subject',
		'message'     => "Message body\nwith another line.",
		'headers'     => array( 'From: "Fixture Sender" <sender@example.test>', 'X-Fixture: keep-me' ),
		'attachments' => array( '/private/tmp/report.pdf' ),
	);
}

function fixture_response( $probability = 0.1 ) {
	return array(
		'requestId' => 'fixture-request-1234',
		'model'     => 'thor',
		'result'    => array(
			'label'           => $probability >= 0.8 ? 'spam' : 'inbox',
			'spamProbability' => $probability,
			'confidence'      => 'high',
			'reasons'         => array(),
			'flaggedTerms'    => array(),
			'analyzedFields'  => array( 'sender', 'subject', 'body' ),
			'modelVersion'    => 'fixture-v1',
			'analyzedAt'      => '2025-01-01T00:00:00.000Z',
		),
		'billing'   => array(
			'chargedMillicents' => 1000,
			'replayed'           => false,
		),
	);
}

function fixture_http_json( $value, $status = 200 ) {
	return array(
		'response' => array( 'code' => $status ),
		'body'     => json_encode( $value ),
	);
}

function fixture_success_handler( $probability = 0.1 ) {
	return function () use ( $probability ) {
		return fixture_http_json( fixture_response( $probability ) );
	};
}

/**
 * A deliberately small OpenAPI 3.1 schema validator covering the constructs
 * used by the classification request and response. This keeps the fixtures
 * coupled to the checked-in public contract rather than a copied field list.
 */
function fixture_validate_schema( $value, $schema, $document, $path = '$' ) {
	if ( isset( $schema['$ref'] ) ) {
		$parts = explode( '/', substr( $schema['$ref'], 2 ) );
		$schema = $document;
		foreach ( $parts as $part ) {
			fixture_assert( isset( $schema[ $part ] ), "{$path}: unresolved OpenAPI reference" );
			$schema = $schema[ $part ];
		}
	}
	if ( isset( $schema['enum'] ) ) {
		fixture_assert( in_array( $value, $schema['enum'], true ), "{$path}: value is outside the OpenAPI enum" );
	}
	$type = isset( $schema['type'] ) ? $schema['type'] : null;
	if ( 'object' === $type ) {
		fixture_assert( is_array( $value ), "{$path}: expected object" );
		foreach ( isset( $schema['required'] ) ? $schema['required'] : array() as $required ) {
			fixture_assert( array_key_exists( $required, $value ), "{$path}: missing required property {$required}" );
		}
		if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
			foreach ( array_keys( $value ) as $property ) {
				fixture_assert( isset( $schema['properties'][ $property ] ), "{$path}: unexpected property {$property}" );
			}
		}
		foreach ( $value as $property => $child ) {
			if ( isset( $schema['properties'][ $property ] ) ) {
				fixture_validate_schema( $child, $schema['properties'][ $property ], $document, "{$path}.{$property}" );
			}
		}
	} elseif ( 'array' === $type ) {
		fixture_assert( is_array( $value ), "{$path}: expected array" );
		foreach ( $value as $index => $child ) {
			fixture_validate_schema( $child, $schema['items'], $document, "{$path}[{$index}]" );
		}
	} elseif ( 'string' === $type ) {
		fixture_assert( is_string( $value ), "{$path}: expected string" );
		fixture_assert( ! isset( $schema['minLength'] ) || strlen( $value ) >= $schema['minLength'], "{$path}: string is too short" );
		fixture_assert( ! isset( $schema['maxLength'] ) || strlen( $value ) <= $schema['maxLength'], "{$path}: string is too long" );
	} elseif ( 'number' === $type || 'integer' === $type ) {
		fixture_assert( is_int( $value ) || is_float( $value ), "{$path}: expected number" );
		fixture_assert( 'integer' !== $type || is_int( $value ), "{$path}: expected integer" );
		fixture_assert( ! isset( $schema['minimum'] ) || $value >= $schema['minimum'], "{$path}: number is below minimum" );
		fixture_assert( ! isset( $schema['maximum'] ) || $value <= $schema['maximum'], "{$path}: number is above maximum" );
	} elseif ( 'boolean' === $type ) {
		fixture_assert( is_bool( $value ), "{$path}: expected boolean" );
	}
}

fixture_test(
	'registers last and preserves prior pre_wp_mail decisions',
	function () {
		SendRepute_Mail::register();
		$filter = end( $GLOBALS['wp_filters']['pre_wp_mail'] );
		fixture_same( PHP_INT_MAX, $filter[1], 'The hook must run after ordinary filters.' );
		fixture_settings();
		fixture_same( false, SendRepute_Mail::pre_send( false, fixture_atts() ), 'A prior false result was not preserved.' );
		fixture_same( true, SendRepute_Mail::pre_send( true, fixture_atts() ), 'A prior true result was not preserved.' );
		fixture_same( 0, count( $GLOBALS['remote_calls'] ), 'Prior decisions must not classify.' );
	}
);

fixture_test(
	'null continuation leaves recipients headers and attachments unchanged',
	function () {
		fixture_settings();
		$GLOBALS['remote_handler'] = fixture_success_handler();
		$atts                      = fixture_atts();
		$before                    = $atts;
		$result                    = SendRepute_Mail::pre_send( null, $atts );
		fixture_same( null, $result, 'Advisory classification must continue into the normal SMTP path.' );
		fixture_same( $before, $atts, 'The original wp_mail attributes changed.' );
		fixture_same( 1, count( $GLOBALS['remote_calls'] ), 'Expected exactly one offline request.' );
		$request = json_decode( $GLOBALS['remote_calls'][0]['args']['body'], true );
		fixture_same(
			array( 'sender', 'subject', 'body', 'model' ),
			array_keys( $request ),
			'Recipient, header, or attachment data leaked into classification.'
		);
		fixture_same( 'Fixture Sender', $request['sender'], 'The From display name was not extracted.' );
		fixture_assert( ! isset( $GLOBALS['remote_calls'][0]['args']['headers']['Idempotency-Key'] ), 'The client added an unsupported idempotency header.' );
	}
);

fixture_test(
	'enabled and paid consent both gate content sharing',
	function () {
		$GLOBALS['remote_handler'] = fixture_success_handler();
		fixture_settings( array( 'enabled' => false ) );
		fixture_same( null, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Disabled mail should continue.' );
		fixture_settings( array( 'paid_consent' => false ) );
		fixture_same( null, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Unconsented mail should continue.' );
		fixture_same( 0, count( $GLOBALS['remote_calls'] ), 'Gated mail disclosed content.' );
	}
);

fixture_test(
	'risk threshold blocks at and above the configured boundary',
	function () {
		fixture_settings( array( 'risk_policy' => 'block', 'threshold' => 0.8 ) );
		$GLOBALS['remote_handler'] = fixture_success_handler( 0.8 );
		fixture_same( false, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Probability equal to threshold must block.' );
		fixture_reset();
		fixture_settings( array( 'risk_policy' => 'block', 'threshold' => 0.8 ) );
		$GLOBALS['remote_handler'] = fixture_success_handler( 0.799 );
		fixture_same( null, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Probability below threshold must continue.' );
	}
);

fixture_test(
	'transport errors obey open and closed failure policies',
	function () {
		fixture_settings( array( 'failure_policy' => 'open' ) );
		$GLOBALS['remote_handler'] = function () {
			return new WP_Error( 'http_request_failed', 'Deliberate offline failure.' );
		};
		fixture_same( null, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Open policy blocked on transport error.' );
		fixture_reset();
		fixture_settings( array( 'failure_policy' => 'closed' ) );
		$GLOBALS['remote_handler'] = function () {
			return new WP_Error( 'http_request_failed', 'Deliberate offline failure.' );
		};
		fixture_same( false, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Closed policy continued on transport error.' );
	}
);

fixture_test(
	'malformed successful responses obey failure policy',
	function () {
		fixture_settings( array( 'failure_policy' => 'open' ) );
		$GLOBALS['remote_handler'] = function () {
			return fixture_http_json( array( 'result' => array( 'label' => 'perhaps' ) ) );
		};
		fixture_same( null, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Open policy blocked malformed JSON data.' );
		fixture_reset();
		fixture_settings( array( 'failure_policy' => 'closed' ) );
		$GLOBALS['remote_handler'] = function () {
			return array( 'response' => array( 'code' => 200 ), 'body' => '{not-json' );
		};
		fixture_same( false, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Closed policy continued malformed JSON.' );
	}
);

fixture_test(
	'recursive mail triggered by HTTP hooks does not recurse into classification',
	function () {
		fixture_settings();
		$inner_result              = 'not-called';
		$GLOBALS['remote_handler'] = function () use ( &$inner_result ) {
			$inner_result = SendRepute_Mail::pre_send( null, fixture_atts() );
			return fixture_http_json( fixture_response() );
		};
		fixture_same( null, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Outer advisory call did not continue.' );
		fixture_same( null, $inner_result, 'Recursive call did not safely continue.' );
		fixture_same( 1, count( $GLOBALS['remote_calls'] ), 'Recursion issued another paid request.' );
	}
);

fixture_test(
	'completed sequential duplicates reuse advisory without another request',
	function () {
		fixture_settings( array( 'risk_policy' => 'block', 'threshold' => 0.8 ) );
		$GLOBALS['remote_handler'] = fixture_success_handler( 0.9 );
		fixture_same( false, SendRepute_Mail::pre_send( null, fixture_atts() ), 'First risky mail should block.' );
		fixture_same( false, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Cached risky mail should block.' );
		fixture_same( 1, count( $GLOBALS['remote_calls'] ), 'Sequential duplicate was classified twice.' );
		$serialized = serialize( $GLOBALS['wp_options'] );
		fixture_assert( false === strpos( $serialized, 'Message body' ), 'A retry lock retained message content.' );
		fixture_assert( false === strpos( $serialized, 'example.test' ), 'A retry lock retained addresses.' );
	}
);

fixture_test(
	'credential rotation scopes duplicate advisories to the active account',
	function () {
		fixture_settings( array( 'risk_policy' => 'block', 'threshold' => 0.8 ) );
		$GLOBALS['remote_handler'] = fixture_success_handler( 0.9 );
		fixture_same( false, SendRepute_Mail::pre_send( null, fixture_atts() ), 'First credential should receive its risky advisory.' );
		fixture_same( false, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Same credential should replay its advisory.' );
		fixture_same( 1, count( $GLOBALS['remote_calls'] ), 'Same credential did not reuse its completed lock.' );

		fixture_same( true, SendRepute_Client::store_token( 'rotated-account-token' ), 'Could not rotate the encrypted credential.' );
		$GLOBALS['remote_handler'] = fixture_success_handler( 0.1 );
		fixture_same( null, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Rotated credential reused the previous account advisory.' );
		fixture_same( 2, count( $GLOBALS['remote_calls'] ), 'Credential rotation did not issue a fresh classification.' );

		foreach ( array_keys( $GLOBALS['wp_options'] ) as $option_name ) {
			fixture_assert( false === strpos( $option_name, 'offline-fixture-bearer' ), 'A lock name exposed the original token.' );
			fixture_assert( false === strpos( $option_name, 'rotated-account-token' ), 'A lock name exposed the rotated token.' );
		}
	}
);

fixture_test(
	'missing and invalid credentials obey failure policy before cache lookup',
	function () {
		fixture_settings( array( 'failure_policy' => 'open', 'risk_policy' => 'block', 'threshold' => 0.8 ) );
		$GLOBALS['remote_handler'] = fixture_success_handler( 0.9 );
		fixture_same( false, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Fixture did not create the prior risky advisory.' );
		delete_option( SendRepute_Client::TOKEN_OPTION );
		fixture_same( null, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Open policy reused an advisory after its credential disappeared.' );
		fixture_same( 1, count( $GLOBALS['remote_calls'] ), 'Missing credential reached the HTTP client.' );

		$GLOBALS['wp_options'][ SendRepute_Client::SETTINGS_OPTION ]['failure_policy'] = 'closed';
		$GLOBALS['wp_options'][ SendRepute_Client::TOKEN_OPTION ] = array(
			'v'          => 1,
			'ciphertext' => 'invalid',
			'iv'         => 'invalid',
			'tag'        => 'invalid',
		);
		fixture_same( false, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Closed policy did not block an invalid credential.' );
		fixture_same( 1, count( $GLOBALS['remote_calls'] ), 'Invalid credential reached the HTTP client.' );
	}
);

fixture_test(
	'atomic pending lock suppresses a concurrent duplicate',
	function () {
		fixture_settings( array( 'failure_policy' => 'closed' ) );
		$GLOBALS['add_option_hook'] = function ( $name, $value ) {
			if ( 0 === strpos( $name, SendRepute_Mail::LOCK_PREFIX ) ) {
				$GLOBALS['wp_options'][ $name ] = $value;
				return false;
			}
			return null;
		};
		$GLOBALS['remote_handler'] = fixture_success_handler();
		fixture_same( false, SendRepute_Mail::pre_send( null, fixture_atts() ), 'Closed policy must block behind an in-flight lock.' );
		fixture_same( 0, count( $GLOBALS['remote_calls'] ), 'Lock loser issued a duplicate paid request.' );
	}
);

fixture_test(
	'expired locks are removed with compare-and-delete while missing locks are untouched',
	function () {
		fixture_settings();
		$GLOBALS['remote_handler'] = fixture_success_handler();
		SendRepute_Mail::pre_send( null, fixture_atts() );
		fixture_same( 0, $GLOBALS['wpdb']->queries, 'A missing option incorrectly entered the expired-lock delete path.' );

		$lock_names = array_values(
			array_filter(
				array_keys( $GLOBALS['wp_options'] ),
				function ( $name ) {
					return 0 === strpos( $name, SendRepute_Mail::LOCK_PREFIX );
				}
			)
		);
		fixture_same( 1, count( $lock_names ), 'Could not locate the opaque fixture lock.' );
		$GLOBALS['wp_options'][ $lock_names[0] ] = array(
			'status'  => 'pending',
			'created' => time() - SendRepute_Mail::TTL - 10,
			'expires' => time() - 10,
		);
		SendRepute_Mail::pre_send( null, fixture_atts() );
		fixture_same( 1, $GLOBALS['wpdb']->queries, 'Expired lock was not removed through SQL compare-and-delete.' );
		fixture_same( 2, count( $GLOBALS['remote_calls'] ), 'An expired lock did not permit a fresh classification.' );
	}
);

fixture_test(
	'encrypted token round-trips and authenticated tampering fails',
	function () {
		fixture_assert( function_exists( 'openssl_encrypt' ), 'The PHP OpenSSL extension is required by the plugin.' );
		$result = SendRepute_Client::store_token( 'fixture-secret-token' );
		fixture_same( true, $result, 'Token encryption failed.' );
		$stored = $GLOBALS['wp_options'][ SendRepute_Client::TOKEN_OPTION ];
		fixture_assert( is_array( $stored ), 'Token was not stored as an encrypted envelope.' );
		fixture_assert( false === strpos( serialize( $stored ), 'fixture-secret-token' ), 'Plaintext token was persisted.' );
		fixture_same( 'fixture-secret-token', SendRepute_Client::token(), 'Encrypted token did not round-trip.' );

		$ciphertext       = base64_decode( $stored['ciphertext'], true );
		$ciphertext[0]    = chr( ord( $ciphertext[0] ) ^ 1 );
		$stored['ciphertext'] = base64_encode( $ciphertext );
		$GLOBALS['wp_options'][ SendRepute_Client::TOKEN_OPTION ] = $stored;
		$tampered = SendRepute_Client::token();
		fixture_assert( is_wp_error( $tampered ), 'Tampered authenticated ciphertext was accepted.' );
		fixture_same( 'sendrepute_token_decryption_failed', $tampered->get_error_code(), 'Tamper error code changed.' );
	}
);

echo '1..' . $GLOBALS['tests_run'] . "\n";