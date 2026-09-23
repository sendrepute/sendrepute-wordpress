<?php
/**
 * wp_mail pre-send classification integration.
 *
 * @package SendRepute
 */

defined( 'ABSPATH' ) || exit;

final class SendRepute_Mail {
	const LOCK_PREFIX = 'sendrepute_mail_';
	const TTL         = 86400;

	/**
	 * One-request signatures for WordPress account/recovery notifications.
	 *
	 * @var array
	 */
	private static $protected_core_mail = array();

	/**
	 * Process-local recursion guard.
	 *
	 * @var bool
	 */
	private static $active = false;

	/**
	 * Register after ordinary pre_wp_mail filters, while still respecting them.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'pre_wp_mail', array( __CLASS__, 'pre_send' ), PHP_INT_MAX, 2 );
		foreach ( array(
			'retrieve_password_notification_email' => 4,
			'password_change_email'                 => 3,
			'email_change_email'                    => 3,
			'wp_new_user_notification_email'        => 3,
			'wp_new_user_notification_email_admin'  => 3,
			'recovery_mode_email'                   => 2,
		) as $hook => $accepted_args ) {
			add_filter( $hook, array( __CLASS__, 'protect_core_mail' ), PHP_INT_MAX, $accepted_args );
		}
	}

	/**
	 * Mark the final core-generated account/recovery message as non-blocking.
	 *
	 * The complete mail arguments, rather than a subject pattern, identify the
	 * next wp_mail call. The filter's value is returned byte-for-byte unchanged.
	 *
	 * @param array $email Core email arguments.
	 * @return array
	 */
	public static function protect_core_mail( $email ) {
		if ( is_array( $email ) ) {
			self::$protected_core_mail[ self::mail_signature( $email ) ] = true;
		}
		return $email;
	}

	/**
	 * Analyze an email without mutating its recipients, headers, body or files.
	 *
	 * A null return preserves the original wp_mail call exactly. False is used
	 * only when the configured failure or risk policy requires blocking.
	 *
	 * @param null|bool $return Previous short-circuit result.
	 * @param array     $atts   Original wp_mail attributes.
	 * @return null|bool
	 */
	public static function pre_send( $return, $atts ) {
		if ( null !== $return ) {
			return $return;
		}

		$settings = SendRepute_Client::settings();
		if ( ! self::enabled( $settings, 'enabled' ) || ! self::enabled( $settings, 'paid_consent' ) ) {
			return null;
		}
		$core_protected = self::consume_core_protection( $atts );
		$woocommerce = SendRepute_WooCommerce::context_policy( $atts, $settings );
		if ( is_array( $woocommerce ) && empty( $woocommerce['eligible'] ) ) {
			return null;
		}
		$protected = $core_protected || ( is_array( $woocommerce ) && ! empty( $woocommerce['protected'] ) );
		if ( is_array( $woocommerce ) && ! empty( $woocommerce['unsupported'] ) ) {
			// WooCommerce creates its multipart AltBody later in phpmailer_init.
			// At pre_wp_mail there is no safe way to submit both displayed
			// alternatives in one classification. Never approve from HTML alone
			// or incur a partial paid analysis.
			return self::failure_result( $settings, $protected );
		}
		if ( self::$active ) {
			return null;
		}

		$failure_closed = isset( $settings['failure_policy'] ) && 'closed' === $settings['failure_policy'];
		$token_identity = SendRepute_Client::token_identity();
		if ( is_wp_error( $token_identity ) ) {
			// Credential failures must never reach a decision cached for a
			// different account or a previously valid token.
			return self::failure_result( $settings, $protected );
		}
		if ( ! is_array( $atts ) || ! isset( $atts['subject'], $atts['message'] ) || ! is_string( $atts['subject'] ) || ! is_string( $atts['message'] ) ) {
			return self::failure_result( $settings, $protected );
		}

		$sender  = self::sender_name( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$subject = $atts['subject'];
		$body    = $atts['message'];
		if ( '' === trim( $sender ) || '' === trim( $subject ) || '' === $body || strlen( $sender ) > 320 || strlen( $subject ) > 998 || strlen( $body ) > 524288 ) {
			return self::failure_result( $settings, $protected );
		}

		$request = array(
			'sender'  => $sender,
			'subject' => $subject,
			'body'    => $body,
		);
		$models = array( 'thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo' );
		if ( isset( $settings['model'] ) && in_array( $settings['model'], $models, true ) ) {
			$request['model'] = $settings['model'];
		}

		$fingerprint = self::fingerprint( $request, $token_identity );
		$option_name = self::LOCK_PREFIX . $fingerprint;
		$now         = time();
		$state       = get_option( $option_name, null );
		if ( is_array( $state ) && isset( $state['expires'] ) && (int) $state['expires'] > $now ) {
			if ( isset( $state['status'] ) && 'complete' === $state['status'] && isset( $state['advisory'] ) && is_array( $state['advisory'] ) ) {
				return self::apply_policy( $state['advisory'], $settings, $protected );
			}
			// An in-flight or unknown paid outcome is suppressed for 24 hours.
			return $protected ? null : ( $failure_closed ? false : null );
		}
		if ( is_array( $state ) && isset( $state['expires'] ) && (int) $state['expires'] <= $now ) {
			// Delete only the exact expired value observed above. A normal
			// delete_option() check/delete sequence could erase a fresh lock
			// installed by another process between those two operations.
			self::delete_expired_lock( $option_name, $state );
		}

		$lock = array(
			'status'  => 'pending',
			'created' => $now,
			'expires' => $now + self::TTL,
		);
		if ( ! add_option( $option_name, $lock, '', 'no' ) ) {
			// Another request won the atomic lock. Never issue a concurrent paid call.
			$state = get_option( $option_name, null );
			if ( is_array( $state ) && isset( $state['status'], $state['advisory'] ) && 'complete' === $state['status'] && is_array( $state['advisory'] ) ) {
				return self::apply_policy( $state['advisory'], $settings, $protected );
			}
			return $protected ? null : ( $failure_closed ? false : null );
		}

		self::$active = true;
		try {
			$response = SendRepute_Client::request( 'POST', '/v1/classify', $request );
		} finally {
			self::$active = false;
		}
		if ( is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			if ( $status >= 400 && $status < 500 && 409 !== $status ) {
				// These responses definitively rejected the request before paid
				// work. Permit another attempt after the administrator fixes it.
				delete_option( $option_name );
			}
			// Retain the opaque lock for transport, 409, and 5xx outcomes: they
			// may have happened after charging. The deterministic API key plus
			// suppression prevents accidental duplicate paid attempts.
			return $protected ? null : ( $failure_closed ? false : null );
		}

		$advisory = self::safe_advisory( $response );
		if ( is_wp_error( $advisory ) ) {
			return $protected ? null : ( $failure_closed ? false : null );
		}

		// Persist only non-content policy metadata. Never cache message text,
		// headers, recipients, attachments, reasons, or flagged terms.
		update_option(
			$option_name,
			array(
				'status'   => 'complete',
				'created'  => $now,
				'expires'  => $now + self::TTL,
				'advisory' => $advisory,
			),
			false
		);

		return self::apply_policy( $advisory, $settings, $protected );
	}

	/**
	 * Unsupported-input and preflight failures follow the configured failure
	 * policy, except that protected WooCommerce customer mail always continues.
	 *
	 * @param array $settings  Plugin settings.
	 * @param bool  $protected Protected WooCommerce customer mail.
	 * @return null|false
	 */
	private static function failure_result( $settings, $protected ) {
		if ( $protected ) {
			return null;
		}
		return isset( $settings['failure_policy'] ) && 'closed' === $settings['failure_policy'] ? false : null;
	}

	/**
	 * Atomically remove the exact expired lock that was read by this request.
	 *
	 * @param string $option_name Lock option name.
	 * @param array  $state       Expired option value previously observed.
	 * @return bool Whether that exact row was removed.
	 */
	private static function delete_expired_lock( $option_name, $state ) {
		global $wpdb;

		if ( ! isset( $wpdb, $wpdb->options ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			// Production WordPress always supplies wpdb. Refuse a non-atomic
			// fallback because preserving a newer paid-operation lock is safer.
			return false;
		}
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option_name,
				maybe_serialize( $state )
			)
		);
		if ( 1 === (int) $deleted ) {
			wp_cache_delete( $option_name, 'options' );
			return true;
		}
		return false;
	}

	/**
	 * @param array  $settings Settings array.
	 * @param string $key      Setting key.
	 * @return bool
	 */
	private static function enabled( $settings, $key ) {
		if ( ! isset( $settings[ $key ] ) ) {
			return false;
		}
		return true === $settings[ $key ] || 1 === $settings[ $key ] || '1' === $settings[ $key ] || 'yes' === $settings[ $key ] || 'on' === $settings[ $key ];
	}

	/**
	 * Consume only an exact, core-produced mail signature.
	 *
	 * @param array $atts wp_mail attributes.
	 * @return bool
	 */
	private static function consume_core_protection( $atts ) {
		$signature = self::mail_signature( $atts );
		if ( ! isset( self::$protected_core_mail[ $signature ] ) ) {
			return false;
		}
		unset( self::$protected_core_mail[ $signature ] );
		return true;
	}

	/**
	 * @param array $mail Mail arguments.
	 * @return string
	 */
	private static function mail_signature( $mail ) {
		$value = array(
			isset( $mail['to'] ) ? $mail['to'] : null,
			isset( $mail['subject'] ) ? $mail['subject'] : null,
			isset( $mail['message'] ) ? $mail['message'] : null,
			isset( $mail['headers'] ) ? $mail['headers'] : null,
			isset( $mail['attachments'] ) ? $mail['attachments'] : array(),
		);
		$json = wp_json_encode( $value );
		return hash( 'sha256', false === $json ? serialize( $value ) : $json );
	}

	/**
	 * Extract only the display name from a From header.
	 *
	 * @param string|array $headers wp_mail headers.
	 * @return string
	 */
	private static function sender_name( $headers ) {
		$lines = is_array( $headers ) ? $headers : preg_split( '/\r\n|\r|\n/', (string) $headers );
		foreach ( $lines as $line ) {
			if ( is_string( $line ) && preg_match( '/^\s*From\s*:\s*(.+)$/i', $line, $matches ) ) {
				$value = trim( $matches[1] );
				if ( preg_match( '/^(.*?)\s*<[^>]+>\s*$/', $value, $parts ) && '' !== trim( $parts[1], " \t\n\r\0\x0B\"'" ) ) {
					return trim( $parts[1], " \t\n\r\0\x0B\"'" );
				}
			}
		}

		$name = get_bloginfo( 'name' );
		return is_string( $name ) && '' !== trim( $name ) ? trim( $name ) : 'WordPress';
	}

	/**
	 * Produce an opaque installation-bound fingerprint; message content is not
	 * recoverable from option names.
	 *
	 * @param array  $request        Classification request.
	 * @param string $token_identity Non-reversible active credential identity.
	 * @return string
	 */
	private static function fingerprint( $request, $token_identity ) {
		$json = wp_json_encode( $request );
		$body = false === $json ? serialize( $request ) : $json;
		return hash_hmac( 'sha256', $token_identity . "\n" . $body, wp_salt( 'nonce' ) );
	}

	/**
	 * Reduce an API response to fields needed for local policy decisions.
	 *
	 * @param array $response API response.
	 * @return array|WP_Error
	 */
	private static function safe_advisory( $response ) {
		$models = array( 'thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo' );
		if ( ! is_array( $response ) ||
			! self::allowed_keys( $response, array( 'requestId', 'model', 'result', 'billing' ) ) ||
			! isset( $response['requestId'], $response['model'], $response['result'], $response['billing'] ) ||
			! is_string( $response['requestId'] ) ||
			'' === $response['requestId'] ||
			strlen( $response['requestId'] ) > 128 ||
			! in_array( $response['model'], $models, true ) ||
			! is_array( $response['result'] ) ||
			! is_array( $response['billing'] )
		) {
			return new WP_Error( 'sendrepute_invalid_classification', __( 'The classification response is incomplete.', 'sendrepute' ) );
		}
		$result = $response['result'];
		$billing = $response['billing'];
		if ( ! array_key_exists( 'chargedMillicents', $billing ) ||
			! self::allowed_keys( $billing, array( 'chargedMillicents', 'replayed' ) ) ||
			! array_key_exists( 'replayed', $billing ) ||
			! self::non_negative_integer( $billing['chargedMillicents'] ) ||
			! is_bool( $billing['replayed'] ) ||
			! isset( $result['label'], $result['spamProbability'], $result['confidence'], $result['reasons'], $result['flaggedTerms'], $result['analyzedFields'], $result['modelVersion'], $result['analyzedAt'] ) ||
			! self::allowed_keys( $result, array( 'label', 'spamProbability', 'flaggedTermCount', 'confidence', 'reasons', 'flaggedTerms', 'analyzedFields', 'modelVersion', 'analyzedAt', 'contentAudit' ) ) ||
			! in_array( $result['label'], array( 'inbox', 'spam' ), true ) ||
			! self::finite_number( $result['spamProbability'] ) ||
			(float) $result['spamProbability'] < 0 ||
			(float) $result['spamProbability'] > 1 ||
			! in_array( $result['confidence'], array( 'low', 'medium', 'high' ), true ) ||
			! self::valid_reasons( $result['reasons'] ) ||
			! self::string_array( $result['flaggedTerms'] ) ||
			! self::string_array( $result['analyzedFields'] ) ||
			! is_string( $result['modelVersion'] ) ||
			'' === $result['modelVersion'] ||
			! is_string( $result['analyzedAt'] ) ||
			'' === $result['analyzedAt'] ||
			( isset( $result['flaggedTermCount'] ) && ! self::non_negative_integer( $result['flaggedTermCount'] ) ) ||
			( isset( $result['contentAudit'] ) && ! self::valid_content_audit( $result['contentAudit'] ) )
		) {
			return new WP_Error( 'sendrepute_invalid_classification', __( 'The classification response is invalid.', 'sendrepute' ) );
		}

		return array(
			'label'            => $result['label'],
			'spam_probability' => (float) $result['spamProbability'],
			'confidence'       => $result['confidence'],
		);
	}

	/**
	 * @param mixed $value Candidate number.
	 * @return bool
	 */
	private static function finite_number( $value ) {
		return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value );
	}

	/**
	 * @param array $value   Object-shaped array.
	 * @param array $allowed Public-schema property names.
	 * @return bool
	 */
	private static function allowed_keys( $value, $allowed ) {
		return array() === array_diff( array_keys( $value ), $allowed );
	}

	/**
	 * @param mixed $value Candidate integer-valued number.
	 * @return bool
	 */
	private static function non_negative_integer( $value ) {
		return self::finite_number( $value ) && (float) $value >= 0 && floor( (float) $value ) === (float) $value;
	}

	/**
	 * @param mixed $value Candidate string list.
	 * @return bool
	 */
	private static function string_array( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param mixed $reasons Classification reasons.
	 * @return bool
	 */
	private static function valid_reasons( $reasons ) {
		if ( ! is_array( $reasons ) ) {
			return false;
		}
		foreach ( $reasons as $reason ) {
			if ( ! is_array( $reason ) ||
				! isset( $reason['signal'], $reason['detail'], $reason['weight'] ) ||
				! self::allowed_keys( $reason, array( 'signal', 'detail', 'weight' ) ) ||
				! is_string( $reason['signal'] ) ||
				! is_string( $reason['detail'] ) ||
				! self::finite_number( $reason['weight'] )
			) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Validate the optional typed content-audit object from the public schema.
	 *
	 * @param mixed $audit Content audit.
	 * @return bool
	 */
	private static function valid_content_audit( $audit ) {
		$required = array( 'score', 'grade', 'summary', 'counts', 'totalIssues', 'criticalCount', 'warningCount', 'suggestionCount', 'issues', 'goodPractices', 'inputTruncated' );
		if ( ! is_array( $audit ) || count( array_intersect( $required, array_keys( $audit ) ) ) !== count( $required ) ||
			! self::allowed_keys( $audit, array( 'score', 'grade', 'summary', 'counts', 'totalIssues', 'criticalCount', 'warningCount', 'suggestionCount', 'issues', 'goodPractices', 'homoglyphTerms', 'inputTruncated' ) ) ||
			! self::non_negative_integer( $audit['score'] ) || $audit['score'] > 100 ||
			! in_array( $audit['grade'], array( 'A', 'B', 'C', 'D', 'F' ), true ) ||
			! in_array( $audit['summary'], array( 'fix_critical', 'fix_warnings', 'review_suggestions', 'looks_good' ), true ) ||
			! is_array( $audit['counts'] ) || ! is_array( $audit['issues'] ) || count( $audit['issues'] ) > 50 ||
			! is_array( $audit['goodPractices'] ) || count( $audit['goodPractices'] ) > 20 ||
			! is_bool( $audit['inputTruncated'] )
		) {
			return false;
		}
		if ( ! self::allowed_keys( $audit['counts'], array( 'words', 'links', 'images', 'triggerPhrases' ) ) ) {
			return false;
		}
		foreach ( array( 'words', 'links', 'images', 'triggerPhrases' ) as $field ) {
			if ( ! isset( $audit['counts'][ $field ] ) || ! self::non_negative_integer( $audit['counts'][ $field ] ) ) {
				return false;
			}
		}
		foreach ( array( 'totalIssues', 'criticalCount', 'warningCount', 'suggestionCount' ) as $field ) {
			if ( ! self::non_negative_integer( $audit[ $field ] ) ) {
				return false;
			}
		}
		foreach ( $audit['issues'] as $issue ) {
			if ( ! is_array( $issue ) || ! isset( $issue['code'], $issue['category'], $issue['severity'], $issue['deduction'], $issue['evidence'] ) ||
				! self::allowed_keys( $issue, array( 'code', 'category', 'severity', 'deduction', 'evidence' ) ) ||
				! is_string( $issue['code'] ) || ! in_array( $issue['category'], array( 'subject', 'content', 'links', 'structure', 'compliance' ), true ) ||
				! in_array( $issue['severity'], array( 'critical', 'warning', 'suggestion' ), true ) ||
				! self::non_negative_integer( $issue['deduction'] ) || $issue['deduction'] > 100 ||
				! is_string( $issue['evidence'] ) || strlen( $issue['evidence'] ) > 200
			) {
				return false;
			}
		}
		foreach ( $audit['goodPractices'] as $practice ) {
			if ( ! is_array( $practice ) || ! isset( $practice['code'], $practice['category'] ) ||
				! self::allowed_keys( $practice, array( 'code', 'category' ) ) ||
				! is_string( $practice['code'] ) || ! in_array( $practice['category'], array( 'subject', 'content', 'links', 'structure', 'compliance' ), true )
			) {
				return false;
			}
		}
		if ( isset( $audit['homoglyphTerms'] ) ) {
			if ( ! self::string_array( $audit['homoglyphTerms'] ) || count( $audit['homoglyphTerms'] ) > 20 ) {
				return false;
			}
			foreach ( $audit['homoglyphTerms'] as $term ) {
				if ( strlen( $term ) > 120 ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @param array $advisory Safe classification metadata.
	 * @param array $settings Plugin settings.
	 * @param bool  $protected Protected WooCommerce customer mail.
	 * @return null|false
	 */
	private static function apply_policy( $advisory, $settings, $protected = false ) {
update_option( 'sendrepute_last_advisory', array(
	'label' => $advisory['label'],
	'probability' => $advisory['spam_probability'],
	'checked_at' => time(),
), false );
		if ( $protected || ! isset( $settings['risk_policy'] ) || 'block' !== $settings['risk_policy'] ) {
			return null;
		}
		$threshold = isset( $settings['threshold'] ) && is_numeric( $settings['threshold'] ) ? (float) $settings['threshold'] : 0.8;
		$threshold = max( 0.0, min( 1.0, $threshold ) );

		return $advisory['spam_probability'] >= $threshold ? false : null;
	}
}