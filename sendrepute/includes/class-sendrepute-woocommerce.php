<?php
/**
 * Optional WooCommerce mail context adapter.
 *
 * @package SendRepute
 */

defined( 'ABSPATH' ) || exit;

final class SendRepute_WooCommerce {
	/**
	 * Context stack for nested WC_Email::send() calls.
	 *
	 * @var array
	 */
	private static $contexts = array();

	/**
	 * Callback-parameter signatures captured before third-party mutation.
	 *
	 * @var array
	 */
	private static $pending_signatures = array();

	/**
	 * Register against WooCommerce's documented mail callback filter. The
	 * original callback is always retained, including SMTP-plugin callbacks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'woocommerce_mail_callback', array( __CLASS__, 'wrap_callback' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_mail_callback_params', array( __CLASS__, 'capture_callback_params' ), PHP_INT_MIN, 2 );
	}

	/**
	 * Capture WooCommerce's original callback parameters before later filters.
	 *
	 * @param array  $params Callback parameters.
	 * @param object $email  WC_Email instance.
	 * @return array Unchanged parameters.
	 */
	public static function capture_callback_params( $params, $email ) {
		if ( is_array( $params ) && is_object( $email ) ) {
			$key = spl_object_hash( $email );
			if ( ! isset( self::$pending_signatures[ $key ] ) ) {
				self::$pending_signatures[ $key ] = array();
			}
			self::$pending_signatures[ $key ][] = self::signature_from_args( $params );
		}
		return $params;
	}

	/**
	 * Built-in WooCommerce email IDs exposed by the settings page.
	 *
	 * Unknown/extension IDs remain unselected and therefore bypass analysis.
	 *
	 * @return array
	 */
	public static function email_types() {
		return array(
			'new_order'                  => __( 'New order (store admin)', 'sendrepute' ),
			'cancelled_order'            => __( 'Cancelled order (store admin)', 'sendrepute' ),
			'failed_order'               => __( 'Failed order (store admin)', 'sendrepute' ),
			'customer_on_hold_order'     => __( 'Order on hold (customer; protected)', 'sendrepute' ),
			'customer_processing_order'  => __( 'Processing order (customer; protected)', 'sendrepute' ),
			'customer_completed_order'   => __( 'Completed order (customer; protected)', 'sendrepute' ),
			'customer_refunded_order'    => __( 'Refunded order (customer; protected)', 'sendrepute' ),
			'customer_invoice'           => __( 'Invoice/order details (customer; protected)', 'sendrepute' ),
			'customer_note'              => __( 'Customer note (customer; protected)', 'sendrepute' ),
			'customer_new_account'       => __( 'New account/access link (customer; protected)', 'sendrepute' ),
			'customer_reset_password'    => __( 'Password reset (customer; protected)', 'sendrepute' ),
			'customer_failed_order'      => __( 'Failed order (customer; protected)', 'sendrepute' ),
			'customer_cancelled_order'   => __( 'Cancelled order (customer; protected)', 'sendrepute' ),
			'low_stock'                  => __( 'Low stock (store admin)', 'sendrepute' ),
			'no_stock'                   => __( 'Out of stock (store admin)', 'sendrepute' ),
			'backorder'                  => __( 'Backorder (store admin)', 'sendrepute' ),
		);
	}

	/**
	 * Customer authentication, payment and order-status mail must never be
	 * blocked by this adapter. It may still be analyzed in advisory mode when
	 * the administrator explicitly selected it and consented to the paid call.
	 *
	 * @param string $email_id WooCommerce email ID.
	 * @return bool
	 */
	public static function is_protected( $email_id ) {
		return 0 === strpos( $email_id, 'customer_' );
	}

	/**
	 * Wrap the callback selected by WooCommerce without replacing transport.
	 *
	 * @param callable $callback Existing WooCommerce mail callback.
	 * @param object   $email    WC_Email instance.
	 * @return callable
	 */
	public static function wrap_callback( $callback, $email ) {
		if ( ! is_callable( $callback ) || ! is_object( $email ) ) {
			return $callback;
		}

		return function () use ( $callback, $email ) {
			$args = func_get_args();
			$id   = isset( $email->id ) && is_string( $email->id ) ? sanitize_key( $email->id ) : '';
			$key  = spl_object_hash( $email );
			if ( ! empty( self::$pending_signatures[ $key ] ) ) {
				$signature = array_shift( self::$pending_signatures[ $key ] );
				if ( empty( self::$pending_signatures[ $key ] ) ) {
					unset( self::$pending_signatures[ $key ] );
				}
			} else {
				$signature = self::signature_from_args( $args );
			}
			if ( method_exists( $email, 'get_content_type' ) ) {
				$type = strtolower( trim( (string) $email->get_content_type() ) );
			} else {
				$type = method_exists( $email, 'get_email_type' ) ? strtolower( trim( (string) $email->get_email_type() ) ) : '';
			}

			self::$contexts[] = array(
				'id'        => $id,
				'type'      => $type,
				'signature' => $signature,
			);
			try {
				return call_user_func_array( $callback, $args );
			} finally {
				array_pop( self::$contexts );
			}
		};
	}

	/**
	 * Decide whether the current wp_mail call belongs to a selected Woo email.
	 *
	 * @param array $atts     wp_mail attributes.
	 * @param array $settings SendRepute settings.
	 * @return array|null Null for non-Woo mail, otherwise adapter policy.
	 */
	public static function context_policy( $atts, $settings ) {
		if ( empty( self::$contexts ) ) {
			$email_id = self::stock_notification_id();
			if ( '' === $email_id ) {
				return null;
			}
			$context = array(
				'id'   => $email_id,
				'type' => 'plain',
			);
		} else {
			$context = end( self::$contexts );
			if ( ! hash_equals( $context['signature'], self::signature_from_atts( $atts ) ) ) {
				// WooCommerce filters may legitimately mutate callback arguments
				// after our context is created. While a Woo callback is active,
				// never fall back to ordinary paid classification: a mismatch is
				// conservatively treated as ineligible Woo mail. This also keeps
				// nested unrelated wp_mail calls from inheriting general policy.
				return array(
					'eligible'    => false,
					'protected'   => self::is_protected( $context['id'] ),
					'unsupported' => false,
				);
			}
		}

		$selected = isset( $settings['woocommerce_types'] ) && is_array( $settings['woocommerce_types'] )
			? $settings['woocommerce_types']
			: array();
		$enabled  = ! empty( $settings['woocommerce_enabled'] );
		$eligible = $enabled && in_array( $context['id'], $selected, true ) && isset( self::email_types()[ $context['id'] ] );

		return array(
			'eligible'    => $eligible,
			'protected'   => self::is_protected( $context['id'] ),
			'unsupported' => $eligible && ! in_array( $context['type'], array( 'plain', 'html', 'text/plain', 'text/html' ), true ),
		);
	}

	/**
	 * Stock notifications are sent directly with wp_mail() by WC_Emails rather
	 * than WC_Email::send(). WordPress keeps the originating action on its
	 * current-filter stack for the duration of that synchronous call.
	 *
	 * @return string
	 */
	private static function stock_notification_id() {
		if ( ! function_exists( 'doing_action' ) ) {
			return '';
		}
		$actions = array(
			'woocommerce_low_stock_notification'             => 'low_stock',
			'woocommerce_no_stock_notification'              => 'no_stock',
			'woocommerce_product_on_backorder_notification'  => 'backorder',
		);
		foreach ( $actions as $action => $email_id ) {
			if ( doing_action( $action ) ) {
				return $email_id;
			}
		}
		return '';
	}

	/**
	 * @param array $args Callback arguments.
	 * @return string
	 */
	private static function signature_from_args( $args ) {
		$atts = array(
			'to'          => isset( $args[0] ) ? $args[0] : null,
			'subject'     => isset( $args[1] ) ? $args[1] : null,
			'message'     => isset( $args[2] ) ? $args[2] : null,
			'headers'     => isset( $args[3] ) ? $args[3] : null,
			'attachments' => isset( $args[4] ) ? $args[4] : null,
		);
		return self::signature_from_atts( $atts );
	}

	/**
	 * @param array $atts wp_mail attributes.
	 * @return string
	 */
	private static function signature_from_atts( $atts ) {
		$json = wp_json_encode(
			array(
				isset( $atts['to'] ) ? $atts['to'] : null,
				isset( $atts['subject'] ) ? $atts['subject'] : null,
				isset( $atts['message'] ) ? $atts['message'] : null,
				isset( $atts['headers'] ) ? $atts['headers'] : null,
				isset( $atts['attachments'] ) ? $atts['attachments'] : null,
			)
		);
		return hash( 'sha256', false === $json ? serialize( $atts ) : $json );
	}
}