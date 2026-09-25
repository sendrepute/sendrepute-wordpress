<?php
/**
 * Administrator settings and deliberately manual AI tools.
 *
 * @package SendRepute
 */

defined( 'ABSPATH' ) || exit;

final class SendRepute_Admin {
	const PAGE_SLUG = 'sendrepute';
	const LOCK_TTL  = DAY_IN_SECONDS;

	/**
	 * Register administrator-only UI and form handlers.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'sendrepute_cleanup_manual_lock', array( __CLASS__, 'cleanup_manual_lock' ) );
		add_action( 'admin_post_sendrepute_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_sendrepute_check_connection', array( __CLASS__, 'check_connection' ) );
		add_action( 'admin_post_sendrepute_manual_rewrite', array( __CLASS__, 'manual_rewrite' ) );
		add_action( 'admin_post_sendrepute_manual_template', array( __CLASS__, 'manual_template' ) );
		add_action( 'admin_post_sendrepute_manual_vip_template', array( __CLASS__, 'manual_vip_template' ) );
	}

	/**
	 * @return void
	 */
	public static function admin_menu() {
		add_options_page(
			__( 'SendRepute', 'sendrepute' ),
			__( 'SendRepute', 'sendrepute' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render settings and the manual paid tools.
	 *
	 * @param array|null $result Optional one-request-only AI result.
	 * @param string     $notice Optional status notice.
	 * @param bool       $error  Whether the notice is an error.
	 * @return void
	 */
	public static function render_page( $result = null, $notice = '', $error = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'You are not allowed to manage SendRepute.', 'sendrepute' ), '', array( 'response' => 403 ) );
		}

		$settings = SendRepute_Client::settings();
		$token    = SendRepute_Client::token();
		$classification_state = self::classification_price_state();
		$pricing  = self::price_state( $classification_state );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'SendRepute', 'sendrepute' ); ?></h1>
<?php $last = get_option( 'sendrepute_last_advisory', array() ); ?>
<?php if ( isset( $last['label'], $last['probability'], $last['checked_at'] ) && $last['checked_at'] > time() - DAY_IN_SECONDS ) : ?>
<p><?php echo esc_html( sprintf( 'Latest analysis advisory: %s, spam probability %.1f%%. This is not a delivery result.', $last['label'], $last['probability'] * 100 ) ); ?></p>
<?php endif; ?>
			<?php if ( '' !== $notice ) : ?>
				<div class="notice <?php echo $error ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['sendrepute_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php self::render_redirect_notice( sanitize_key( wp_unslash( $_GET['sendrepute_notice'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php endif; ?>

			<p><?php echo esc_html__( 'SendRepute sends the sender name, subject, and message body to the SendRepute API only when the enabled features require it. The API may retain operational and billing metadata; message handling is governed by your SendRepute account and privacy terms.', 'sendrepute' ); ?></p>
			<div class="notice notice-warning inline"><p><strong><?php echo esc_html__( 'Release prerequisite:', 'sendrepute' ); ?></strong>
				<?php echo esc_html__( 'Deploy and verify API migration 0081 before enabling this integration. Older API storage does not provide the required customer-service receipt and retry guarantees.', 'sendrepute' ); ?>
			</p></div>

			<h2><?php echo esc_html__( 'Connection and policy', 'sendrepute' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sendrepute_save_settings">
				<?php wp_nonce_field( 'sendrepute_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'API token', 'sendrepute' ); ?></th>
						<td>
							<input type="password" class="regular-text" name="api_token" value="" autocomplete="new-password" spellcheck="false">
							<p class="description">
								<?php
								echo defined( 'SENDREPUTE_API_TOKEN' )
									? esc_html__( 'SENDREPUTE_API_TOKEN is set in wp-config.php and overrides encrypted database storage. This field is always empty and cannot replace that override.', 'sendrepute' )
									: esc_html__( 'Always shown empty. Enter a token only to replace the encrypted stored token; leave empty to keep it.', 'sendrepute' );
								?>
							</p>
							<?php if ( ! defined( 'SENDREPUTE_API_TOKEN' ) ) : ?>
								<label><input type="checkbox" name="remove_token" value="1"> <?php echo esc_html__( 'Remove the stored token', 'sendrepute' ); ?></label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Automatic classification', 'sendrepute' ); ?></th>
						<td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( self::truthy( $settings, 'enabled' ) ); ?>> <?php echo esc_html__( 'Enable pre-send classification', 'sendrepute' ); ?></label><br>
							<?php if ( is_wp_error( $classification_state ) ) : ?>
								<p class="description"><?php echo esc_html__( 'Paid classification consent is unavailable until the current authenticated tariff can be verified.', 'sendrepute' ); ?></p>
							<?php else : ?>
								<?php $classification_pricing = self::classification_pricing( $classification_state ); ?>
								<input type="hidden" name="classification_price_state" value="<?php echo esc_attr( self::classification_price_digest( $classification_state ) ); ?>">
								<p class="description"><?php echo esc_html( sprintf( __( 'Current effective tariff: base %1$s, %2$d included unique terms, %3$s per additional term, service maximum %4$s.', 'sendrepute' ), self::money( $classification_pricing['classificationBaseMillicents'] ), $classification_pricing['includedUniqueTerms'], self::money( $classification_pricing['additionalTermMillicents'] ), self::money( $classification_pricing['maximumClassificationMillicents'] ) ) ); ?></p>
								<label><?php echo esc_html__( 'Maximum charge authorized per new classification (millicents)', 'sendrepute' ); ?> <input type="number" min="0" max="<?php echo esc_attr( $classification_pricing['maximumClassificationMillicents'] ); ?>" step="1" name="classification_max_charge_millicents" value="<?php echo esc_attr( self::classification_maximum_value( $settings, $classification_pricing ) ); ?>"></label><br>
								<label><input type="checkbox" name="paid_consent" value="1" <?php checked( self::truthy( $settings, 'paid_consent' ) ); ?>> <?php echo esc_html__( 'I consent to sending email content and authorize the exact four-field tariff shown above, up to my per-request maximum.', 'sendrepute' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php echo esc_html__( 'Tariff changes are never accepted automatically. A PRICE_CHANGED refusal blocks that delivery even in fail-open mode; review and save fresh consent. API-key cumulative spending caps remain separate. This consent applies only to classification.', 'sendrepute' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'WooCommerce email analysis', 'sendrepute' ); ?></th>
						<td>
							<label><input type="checkbox" name="woocommerce_enabled" value="1" <?php checked( self::truthy( $settings, 'woocommerce_enabled' ) ); ?>> <?php echo esc_html__( 'Analyze only the selected WooCommerce email types', 'sendrepute' ); ?></label>
							<p class="description"><?php echo esc_html__( 'Off by default. When off, WooCommerce mail bypasses SendRepute even if general classification is enabled. Extension-defined email types are not selected automatically.', 'sendrepute' ); ?></p>
							<fieldset>
								<legend class="screen-reader-text"><?php echo esc_html__( 'WooCommerce email types', 'sendrepute' ); ?></legend>
								<?php
								$woocommerce_selected = isset( $settings['woocommerce_types'] ) && is_array( $settings['woocommerce_types'] ) ? $settings['woocommerce_types'] : array();
								foreach ( SendRepute_WooCommerce::email_types() as $email_id => $label ) :
									?>
									<label style="display:block"><input type="checkbox" name="woocommerce_types[]" value="<?php echo esc_attr( $email_id ); ?>" <?php checked( in_array( $email_id, $woocommerce_selected, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</fieldset>
<p class="description"><strong><?php echo esc_html__( 'Safety boundary:', 'sendrepute' ); ?></strong> <?php echo esc_html__( 'Customer authentication, payment, invoice, note and order-status email types marked protected are never blocked by risk, ordinary API failure, or unsupported-content policy. A billing-consent PRICE_CHANGED refusal blocks any selected delivery rather than accepting a new tariff. Unselected mail is never analyzed. WooCommerce multipart mail is not partially analyzed: it is allowed in advisory/fail-open mode and rejected only for explicitly selected, non-protected mail under fail-closed mode.', 'sendrepute' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sendrepute-failure"><?php echo esc_html__( 'API failure policy', 'sendrepute' ); ?></label></th>
						<td><select id="sendrepute-failure" name="failure_policy">
							<option value="open" <?php selected( isset( $settings['failure_policy'] ) ? $settings['failure_policy'] : '', 'open' ); ?>><?php echo esc_html__( 'Open — allow mail when analysis fails', 'sendrepute' ); ?></option>
							<option value="closed" <?php selected( isset( $settings['failure_policy'] ) ? $settings['failure_policy'] : '', 'closed' ); ?>><?php echo esc_html__( 'Closed — block mail when analysis fails', 'sendrepute' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="sendrepute-risk"><?php echo esc_html__( 'Risk policy', 'sendrepute' ); ?></label></th>
						<td><select id="sendrepute-risk" name="risk_policy">
							<option value="advisory" <?php selected( isset( $settings['risk_policy'] ) ? $settings['risk_policy'] : '', 'advisory' ); ?>><?php echo esc_html__( 'Advisory only', 'sendrepute' ); ?></option>
							<option value="block" <?php selected( isset( $settings['risk_policy'] ) ? $settings['risk_policy'] : '', 'block' ); ?>><?php echo esc_html__( 'Block at or above threshold', 'sendrepute' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="sendrepute-threshold"><?php echo esc_html__( 'Spam probability threshold', 'sendrepute' ); ?></label></th>
						<td><input id="sendrepute-threshold" name="threshold" type="number" min="0" max="1" step="0.01" value="<?php echo esc_attr( isset( $settings['threshold'] ) ? $settings['threshold'] : '0.8' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sendrepute-model"><?php echo esc_html__( 'Classifier model', 'sendrepute' ); ?></label></th>
						<td><select id="sendrepute-model" name="model">
							<option value=""><?php echo esc_html__( 'Account default', 'sendrepute' ); ?></option>
							<?php foreach ( array( 'thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo' ) as $model ) : ?>
								<option value="<?php echo esc_attr( $model ); ?>" <?php selected( isset( $settings['model'] ) ? $settings['model'] : '', $model ); ?>><?php echo esc_html( ucfirst( $model ) ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Local data retention', 'sendrepute' ); ?></th>
<td><label><input type="checkbox" name="retain_data" value="1" <?php checked( self::truthy( $settings, 'retain_data' ) ); ?>> <?php echo esc_html__( 'Keep settings and opaque retry metadata after uninstall. Stored credentials are always removed and analysis is disabled. Otherwise all plugin data is deleted.', 'sendrepute' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Manual AI inputs and results are never saved by this page. Retry locks contain only opaque hashes and status.', 'sendrepute' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'sendrepute' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sendrepute_check_connection">
				<?php wp_nonce_field( 'sendrepute_check_connection' ); ?>
				<?php submit_button( __( 'Check connection and non-paid scopes', 'sendrepute' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php self::render_connection( $token, $classification_state ); ?>
			<hr>
			<h2><?php echo esc_html__( 'Manual AI actions', 'sendrepute' ); ?></h2>
			<p><?php echo esc_html__( 'Nothing below runs automatically, changes WordPress content, or sends email. Each submission is a paid API request and requires explicit permission and consent. The API server is the final authority for key scopes, VIP status, price, balance, and replay behavior.', 'sendrepute' ); ?></p>
			<?php self::render_ai_forms( $pricing ); ?>
			<?php self::render_result( $result ); ?>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	public static function save_settings() {
		self::authorize( 'sendrepute_save_settings' );

		$failure_policy = isset( $_POST['failure_policy'] ) && 'closed' === sanitize_key( wp_unslash( $_POST['failure_policy'] ) ) ? 'closed' : 'open';
		$risk_policy    = isset( $_POST['risk_policy'] ) && 'block' === sanitize_key( wp_unslash( $_POST['risk_policy'] ) ) ? 'block' : 'advisory';
		$threshold      = isset( $_POST['threshold'] ) ? (float) wp_unslash( $_POST['threshold'] ) : 0.8;
		$model          = isset( $_POST['model'] ) ? sanitize_key( wp_unslash( $_POST['model'] ) ) : '';
		if ( ! in_array( $model, array( '', 'thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo' ), true ) ) {
			$model = '';
		}
		$consent        = false;
		$consent_prices = array();
		$consent_maximum = -1;
		$consent_token_identity = '';
		$credential_change = ! defined( 'SENDREPUTE_API_TOKEN' ) &&
			( isset( $_POST['remove_token'] ) || ( isset( $_POST['api_token'] ) && '' !== trim( wp_unslash( $_POST['api_token'] ) ) ) );
		if ( ! $credential_change && isset( $_POST['paid_consent'], $_POST['classification_price_state'], $_POST['classification_max_charge_millicents'] ) ) {
			$current = self::classification_price_state();
			$maximum = wp_unslash( $_POST['classification_max_charge_millicents'] );
			$token_identity = SendRepute_Client::token_identity();
			if ( ! is_wp_error( $current ) &&
				! is_wp_error( $token_identity ) &&
				is_string( $maximum ) &&
				ctype_digit( $maximum ) &&
				(int) $maximum <= self::classification_pricing( $current )['maximumClassificationMillicents'] &&
				hash_equals( self::classification_price_digest( $current ), sanitize_text_field( wp_unslash( $_POST['classification_price_state'] ) ) )
			) {
				$consent         = true;
				$consent_prices  = self::classification_pricing( $current );
				$consent_maximum = (int) $maximum;
				$consent_token_identity = $token_identity;
			}
		}
		$woocommerce_types = isset( $_POST['woocommerce_types'] ) && is_array( $_POST['woocommerce_types'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['woocommerce_types'] ) )
			: array();
		$woocommerce_types = array_values( array_intersect( array_keys( SendRepute_WooCommerce::email_types() ), array_unique( $woocommerce_types ) ) );
		$stored  = array(
			'enabled'        => isset( $_POST['enabled'] ),
			'paid_consent'   => $consent,
			'classification_pricing' => $consent_prices,
			'classification_max_charge_millicents' => $consent_maximum,
			'classification_consent_token_identity' => $consent_token_identity,
			'failure_policy' => $failure_policy,
			'risk_policy'    => $risk_policy,
			'threshold'      => max( 0, min( 1, $threshold ) ),
			'model'          => $model,
			'retain_data'    => isset( $_POST['retain_data'] ),
			'woocommerce_enabled' => isset( $_POST['woocommerce_enabled'] ),
			'woocommerce_types'   => $woocommerce_types,
		);
		update_option( 'sendrepute_settings', $stored, false );

		$token_result = true;
		if ( ! defined( 'SENDREPUTE_API_TOKEN' ) && isset( $_POST['remove_token'] ) ) {
			$token_result = SendRepute_Client::store_token( '' );
		} elseif ( ! defined( 'SENDREPUTE_API_TOKEN' ) && isset( $_POST['api_token'] ) && '' !== trim( wp_unslash( $_POST['api_token'] ) ) ) {
			$token_result = SendRepute_Client::store_token( wp_unslash( $_POST['api_token'] ) );
		}
		self::redirect_notice( is_wp_error( $token_result ) ? 'token_error' : 'saved' );
	}

	/**
	 * @return void
	 */
	public static function check_connection() {
		self::authorize( 'sendrepute_check_connection' );
		$result = SendRepute_Client::connection();
		self::redirect_notice( is_wp_error( $result ) ? 'connection_error' : 'connected' );
	}

	/**
	 * @return void
	 */
	public static function manual_rewrite() {
		self::authorize( 'sendrepute_manual_rewrite' );
		self::require_paid_confirmation();
		$state = self::require_fresh_price();

		$parent = isset( $_POST['parent_request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['parent_request_id'] ) ) : '';
		$mode   = isset( $_POST['rewrite_mode'] ) && 'all' === sanitize_key( wp_unslash( $_POST['rewrite_mode'] ) ) ? 'all' : 'single';
		$terms  = isset( $_POST['terms'] ) ? preg_split( '/\r\n|\r|\n/', wp_unslash( $_POST['terms'] ) ) : array();
		$terms  = array_values( array_unique( array_filter( array_map( 'trim', $terms ) ) ) );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $parent ) || empty( $terms ) || count( $terms ) > 1000 ) {
			self::fail_page( __( 'Enter a valid parent classification request ID and 1–1000 terms, one per line.', 'sendrepute' ) );
		}
		foreach ( $terms as $term ) {
			if ( strlen( $term ) > 500 ) {
				self::fail_page( __( 'Each rewrite term must be 500 characters or fewer.', 'sendrepute' ) );
			}
		}
		self::perform_paid( '/v1/rewrite', array( 'parentRequestId' => $parent, 'mode' => $mode, 'terms' => $terms ), $state );
	}

	/**
	 * @return void
	 */
	public static function manual_template() {
		self::authorize( 'sendrepute_manual_template' );
		self::require_paid_confirmation();
		$state    = self::require_fresh_price();
		$content  = isset( $_POST['template_content'] ) ? trim( wp_unslash( $_POST['template_content'] ) ) : '';
		if ( '' === $content ) {
			self::fail_page( __( 'Template instructions cannot be empty.', 'sendrepute' ) );
		}
		self::perform_paid(
			'/v1/email-builder/ai-template',
			array(
				'content'                 => $content,
				'category'                => 'custom',
				'subtype'                 => 'custom',
				'expectedPriceMillicents' => (int) $state['vip']['regularAiTemplatePriceMillicents'],
			),
			$state
		);
	}

	/**
	 * @return void
	 */
	public static function manual_vip_template() {
		self::authorize( 'sendrepute_manual_vip_template' );
		self::require_paid_confirmation();
		$state  = self::require_fresh_price();
		$prompt = isset( $_POST['vip_prompt'] ) ? trim( wp_unslash( $_POST['vip_prompt'] ) ) : '';
		if ( '' === $prompt || strlen( $prompt ) > 4000 || empty( $state['vip']['active'] ) ) {
			self::fail_page( __( 'An active VIP membership and a prompt of at most 4000 characters are required.', 'sendrepute' ) );
		}
		$body = array(
			'prompt'                  => $prompt,
			'expectedPriceMillicents' => (int) $state['vip']['aiTemplatePriceMillicents'],
		);
		$urls = isset( $_POST['image_urls'] ) ? preg_split( '/\r\n|\r|\n/', wp_unslash( $_POST['image_urls'] ) ) : array();
		$urls = array_values( array_filter( array_map( 'trim', $urls ) ) );
		if ( count( $urls ) > 8 ) {
			self::fail_page( __( 'At most eight HTTPS image URLs are supported.', 'sendrepute' ) );
		}
		foreach ( $urls as $url ) {
			if ( strlen( $url ) > 2048 || 0 !== strpos( strtolower( $url ), 'https://' ) || ! wp_http_validate_url( $url ) ) {
				self::fail_page( __( 'Every image URL must be a valid HTTPS URL.', 'sendrepute' ) );
			}
		}
		if ( $urls ) {
			$body['imageUrls'] = $urls;
		}
		self::perform_paid( '/v1/vip/email-template', $body, $state );
	}

	/**
	 * Render current account/scope information.
	 *
	 * @param string|WP_Error $token Token state.
	 * @param array|WP_Error  $pricing Classification account/pricing state.
	 * @return void
	 */
	private static function render_connection( $token, $pricing ) {
		echo '<h3>' . esc_html__( 'Connection status', 'sendrepute' ) . '</h3>';
		if ( is_wp_error( $token ) ) {
			echo '<p><strong>' . esc_html__( 'Not configured:', 'sendrepute' ) . '</strong> ' . esc_html( $token->get_error_message() ) . '</p>';
			return;
		}
		if ( is_wp_error( $pricing ) ) {
			echo '<p><strong>' . esc_html__( 'Not verified:', 'sendrepute' ) . '</strong> ' . esc_html( $pricing->get_error_message() ) . '</p>';
			echo '<p>' . esc_html__( 'A 403 response identifies a missing server-side scope. Do not rely on locally claimed permissions.', 'sendrepute' ) . '</p>';
			return;
		}
		$username = isset( $pricing['account']['username'] ) ? $pricing['account']['username'] : '';
		echo '<p>' . esc_html( sprintf( __( 'Connected as %s. Verified by non-paid calls: account:read, catalog:read.', 'sendrepute' ), $username ) ) . '</p>';
$reported_scopes = array();
if ( isset( $pricing['account']['scopes'] ) && is_array( $pricing['account']['scopes'] ) ) {
$reported_scopes = $pricing['account']['scopes'];
} elseif ( isset( $pricing['account']['key']['scopes'] ) && is_array( $pricing['account']['key']['scopes'] ) ) {
$reported_scopes = $pricing['account']['key']['scopes'];
} elseif ( isset( $pricing['account']['apiKey']['scopes'] ) && is_array( $pricing['account']['apiKey']['scopes'] ) ) {
$reported_scopes = $pricing['account']['apiKey']['scopes'];
}
$reported_scopes = array_values( array_filter( array_map( 'sanitize_text_field', $reported_scopes ) ) );
if ( $reported_scopes ) {
echo '<p>' . esc_html( sprintf( __( 'API-reported key scopes: %s.', 'sendrepute' ), implode( ', ', $reported_scopes ) ) ) . '</p>';
}
		echo '<p>' . esc_html__( 'Paid actions additionally require classify/rewrite or ai:generate as shown below. Those scopes are not probed with a paid call; server errors are authoritative.', 'sendrepute' ) . '</p>';
	}

	/**
	 * @param array|WP_Error $state Pricing state.
	 * @return void
	 */
	private static function render_ai_forms( $state ) {
		if ( is_wp_error( $state ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Paid actions are unavailable until account, catalog, and VIP pricing can be verified: ', 'sendrepute' ) . esc_html( $state->get_error_message() ) . '</p></div>';
			return;
		}
		$vip       = ! empty( $state['vip']['active'] );
		$discount  = $vip ? 0.5 : 1;
		$regular   = (int) $state['vip']['regularAiTemplatePriceMillicents'];
		$minimum   = isset( $state['pricing']['aiMinimumPerUniqueTermMillicents'] ) ? (int) $state['pricing']['aiMinimumPerUniqueTermMillicents'] : 1000;
		$minimum   = (int) ( $minimum * $discount );
		$digest    = self::price_digest( $state );
		$vip_price = isset( $state['vip']['aiTemplatePriceMillicents'] ) ? (int) $state['vip']['aiTemplatePriceMillicents'] : 0;
		?>
		<h3><?php echo esc_html__( 'Rewrite a classified message', 'sendrepute' ); ?></h3>
<p><strong><?php echo esc_html( sprintf( __( 'Current effective minimum: %1$s per unique term%2$s. The final charge is variable and may exceed this minimum; no fixed-price AI rewrite quote is available.', 'sendrepute' ), self::money( $minimum ), $vip ? __( ', including the active VIP 50% usage discount', 'sendrepute' ) : '' ) ); ?></strong></p>
		<p><?php echo esc_html__( 'Permission required: rewrite scope. Input is tied to an existing classification receipt. /rewrite/quote is a deprecated manual-edit quote and is not used as an AI quote.', 'sendrepute' ); ?></p>
		<?php self::open_paid_form( 'sendrepute_manual_rewrite', $digest ); ?>
			<p><label><?php echo esc_html__( 'Parent classification request ID', 'sendrepute' ); ?><br><input class="regular-text" required name="parent_request_id" maxlength="128"></label></p>
			<p><label><?php echo esc_html__( 'Mode', 'sendrepute' ); ?> <select name="rewrite_mode"><option value="single"><?php echo esc_html__( 'Selected terms', 'sendrepute' ); ?></option><option value="all"><?php echo esc_html__( 'All supplied terms', 'sendrepute' ); ?></option></select></label></p>
			<p><label><?php echo esc_html__( 'Terms, one per line', 'sendrepute' ); ?><br><textarea name="terms" rows="5" class="large-text" required></textarea></label></p>
<?php self::paid_confirmation( __( 'I have permission to send this classified content to SendRepute and authorize a variable AI rewrite charge which may exceed the displayed minimum, subject to my API key spending cap.', 'sendrepute' ) ); ?>
		</form>

		<h3><?php echo esc_html__( 'Generate a regular email template', 'sendrepute' ); ?></h3>
		<p><strong><?php echo esc_html( sprintf( __( 'Current effective account price: %s for this generation.', 'sendrepute' ), self::money( $regular ) ) ); ?></strong></p>
		<p><?php echo esc_html__( 'Permission required: ai:generate. The result is returned for copying only; this plugin does not open, save, publish, or send it.', 'sendrepute' ); ?></p>
		<?php self::open_paid_form( 'sendrepute_manual_template', $digest ); ?>
			<p><label><?php echo esc_html__( 'Instructions/content', 'sendrepute' ); ?><br><textarea name="template_content" rows="6" class="large-text" required></textarea></label></p>
			<p class="description"><?php echo esc_html__( 'This WordPress tool uses the supported custom/custom mode. Instructions are required; preset modes are intentionally not exposed because presets accept no content.', 'sendrepute' ); ?></p>
			<?php self::paid_confirmation( __( 'I have permission to send these instructions/content to SendRepute and authorize the exact template-generation price shown above.', 'sendrepute' ) ); ?>
		</form>

		<h3><?php echo esc_html__( 'Generate a native VIP email template', 'sendrepute' ); ?></h3>
		<p><strong><?php echo esc_html( sprintf( __( 'Current VIP account price: %s for this generation.', 'sendrepute' ), self::money( $vip_price ) ) ); ?></strong></p>
		<p><?php echo esc_html__( 'Permissions required: ai:generate plus an active VIP membership. The submitted expected price is checked by the server.', 'sendrepute' ); ?></p>
		<?php if ( ! $vip ) : ?><p><em><?php echo esc_html__( 'Unavailable: this account does not have an active VIP membership.', 'sendrepute' ); ?></em></p><?php else : ?>
			<?php self::open_paid_form( 'sendrepute_manual_vip_template', $digest ); ?>
				<p><label><?php echo esc_html__( 'Prompt', 'sendrepute' ); ?><br><textarea name="vip_prompt" rows="6" maxlength="4000" class="large-text" required></textarea></label></p>
				<p><label><?php echo esc_html__( 'Optional HTTPS image URLs, one per line (maximum 8)', 'sendrepute' ); ?><br><textarea name="image_urls" rows="4" class="large-text"></textarea></label></p>
				<?php self::paid_confirmation( __( 'I have permission to send this prompt and these image URLs to SendRepute and authorize the exact VIP generation price shown above.', 'sendrepute' ) ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param string $action Action name.
	 * @param string $digest Price-state digest.
	 * @return void
	 */
	private static function open_paid_form( $action, $digest ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="price_state" value="' . esc_attr( $digest ) . '">';
		wp_nonce_field( $action );
	}

	/**
	 * @param string $label Confirmation label.
	 * @return void
	 */
	private static function paid_confirmation( $label ) {
		echo '<p><label><input type="checkbox" required name="paid_confirmation" value="1"> <strong>' . esc_html( $label ) . '</strong></label></p>';
		submit_button( __( 'Execute once and show result', 'sendrepute' ), 'primary', 'submit', false );
	}

	/**
	 * @param array|null $result Result held in request memory only.
	 * @return void
	 */
	private static function render_result( $result ) {
		if ( ! is_array( $result ) ) {
			return;
		}
		$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		echo '<hr><h2>' . esc_html__( 'Result — copy only', 'sendrepute' ) . '</h2>';
		echo '<p>' . esc_html__( 'This result has not been persisted in WordPress. Leaving or refreshing this page removes it.', 'sendrepute' ) . '</p>';
		echo '<textarea class="large-text code" rows="24" readonly>' . esc_textarea( false === $json ? '' : $json ) . '</textarea>';
	}

	/**
	 * Fetch account and classification pricing without requiring VIP access.
	 *
	 * @return array|WP_Error
	 */
	private static function classification_price_state() {
		$connection = SendRepute_Client::connection();
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}
		return array(
			'account' => $connection['account'],
			'pricing' => $connection['pricing'],
		);
	}

	/**
	 * Fetch VIP-only data needed by the manual AI controls.
	 *
	 * @param array|WP_Error|null $classification_state Optional verified account/pricing state.
	 * @return array|WP_Error
	 */
	private static function price_state( $classification_state = null ) {
		if ( null === $classification_state ) {
			$classification_state = self::classification_price_state();
		}
		if ( is_wp_error( $classification_state ) ) {
			return $classification_state;
		}
		$vip = SendRepute_Client::request( 'GET', '/v1/vip' );
		if ( is_wp_error( $vip ) ) {
			return $vip;
		}
		foreach ( array( 'active', 'aiTemplatePriceMillicents', 'regularAiTemplatePriceMillicents' ) as $field ) {
			if ( ! array_key_exists( $field, $vip ) ) {
				return new WP_Error( 'sendrepute_invalid_vip_pricing', __( 'The API returned incomplete VIP pricing.', 'sendrepute' ) );
			}
		}
		if ( ! is_bool( $vip['active'] ) ||
			! is_int( $vip['aiTemplatePriceMillicents'] ) ||
			$vip['aiTemplatePriceMillicents'] < 0 ||
			! is_int( $vip['regularAiTemplatePriceMillicents'] ) ||
			$vip['regularAiTemplatePriceMillicents'] < 0
		) {
			return new WP_Error( 'sendrepute_invalid_vip_pricing', __( 'The API returned invalid effective AI pricing.', 'sendrepute' ) );
		}
		return array(
			'account' => $classification_state['account'],
			'pricing' => $classification_state['pricing'],
			'vip'     => $vip,
		);
	}

	/**
	 * @param array $state Price state.
	 * @return string
	 */
	private static function price_digest( $state ) {
		$snapshot = array(
			'account_id'        => isset( $state['account']['id'] ) ? (string) $state['account']['id'] : '',
			'vip_active'        => ! empty( $state['vip']['active'] ),
			'vip_ai_price'      => isset( $state['vip']['aiTemplatePriceMillicents'] ) ? (int) $state['vip']['aiTemplatePriceMillicents'] : -1,
			'regular_ai_price'  => isset( $state['vip']['regularAiTemplatePriceMillicents'] ) ? (int) $state['vip']['regularAiTemplatePriceMillicents'] : -1,
			'ai_term_minimum'   => isset( $state['pricing']['aiMinimumPerUniqueTermMillicents'] ) ? (int) $state['pricing']['aiMinimumPerUniqueTermMillicents'] : -1,
		);
		return hash_hmac( 'sha256', wp_json_encode( $snapshot ), wp_salt( 'nonce' ) );
	}

	/**
	 * Return the exact effective classification schedule in public API order.
	 *
	 * @param array $state Verified price state.
	 * @return array
	 */
	private static function classification_pricing( $state ) {
		return array(
			'classificationBaseMillicents'    => (int) $state['pricing']['classificationBaseMillicents'],
			'includedUniqueTerms'             => (int) $state['pricing']['includedUniqueTerms'],
			'additionalTermMillicents'        => (int) $state['pricing']['additionalTermMillicents'],
			'maximumClassificationMillicents' => (int) $state['pricing']['maximumClassificationMillicents'],
		);
	}

	/**
	 * Bind saved classification consent to the account and all four rates.
	 *
	 * @param array $state Verified price state.
	 * @return string
	 */
	private static function classification_price_digest( $state ) {
		$snapshot = array(
			'account_id' => isset( $state['account']['id'] ) ? (string) $state['account']['id'] : '',
			'pricing'    => self::classification_pricing( $state ),
		);
		return hash_hmac( 'sha256', wp_json_encode( $snapshot ), wp_salt( 'nonce' ) );
	}

	/**
	 * Retain an existing deliberate ceiling only for an identical schedule.
	 *
	 * @param array $settings Current settings.
	 * @param array $pricing  Current effective pricing.
	 * @return int
	 */
	private static function classification_maximum_value( $settings, $pricing ) {
		if ( isset( $settings['classification_pricing'], $settings['classification_max_charge_millicents'] ) &&
			$settings['classification_pricing'] === $pricing &&
			is_int( $settings['classification_max_charge_millicents'] ) &&
			$settings['classification_max_charge_millicents'] >= 0 &&
			$settings['classification_max_charge_millicents'] <= $pricing['maximumClassificationMillicents']
		) {
			return $settings['classification_max_charge_millicents'];
		}
		return $pricing['maximumClassificationMillicents'];
	}

	/**
	 * @return array Fresh state.
	 */
	private static function require_fresh_price() {
		$submitted = isset( $_POST['price_state'] ) ? sanitize_text_field( wp_unslash( $_POST['price_state'] ) ) : '';
		$current   = self::price_state();
		if ( is_wp_error( $current ) ) {
			self::fail_page( $current->get_error_message() );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $submitted ) || ! hash_equals( self::price_digest( $current ), $submitted ) ) {
			self::fail_page( __( 'The effective account price or membership changed. No paid request was sent. Review the refreshed price and confirm again.', 'sendrepute' ) );
		}
		return $current;
	}

	/**
	 * Execute one paid request with a local pending/completed suppression lock.
	 *
	 * @param string $path  API path.
	 * @param array  $body  Request body.
	 * @param array  $state Verified price state.
	 * @return never
	 */
	private static function perform_paid( $path, $body, $state ) {
		$token = SendRepute_Client::token();
		if ( is_wp_error( $token ) ) {
			self::fail_page( $token->get_error_message() );
		}
		// Scope duplicate suppression to the credential too. Only this
		// installation-bound HMAC is retained; the token is never stored.
		$token_identity = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
		$fingerprint    = hash_hmac( 'sha256', $token_identity . "\n" . $path . "\n" . self::price_digest( $state ) . "\n" . wp_json_encode( $body ), wp_salt( 'nonce' ) );
		$lock_name   = 'sendrepute_manual_' . $fingerprint;
		$existing    = get_option( $lock_name, null );
		if ( is_array( $existing ) && isset( $existing['expires'] ) && (int) $existing['expires'] <= time() ) {
// Remove only the exact expired value observed above. A newer paid-action
// lock may have replaced it while this request was reading the option.
if ( self::delete_expired_lock( $lock_name, $existing ) ) {
$existing = null;
}
		}
		$lock = array(
			'status'  => 'pending',
			'created' => time(),
			'expires' => time() + self::LOCK_TTL,
		);
		if ( null !== $existing || ! add_option( $lock_name, $lock, '', 'no' ) ) {
			self::fail_page( __( 'This identical paid action was already submitted recently. It was not submitted again. Change the input only if you intend a new paid operation.', 'sendrepute' ) );
		}
		// The lock is intentionally retained after errors: the remote operation may
		// have completed despite a timeout. Server receipt replay is a second guard.
		if ( ! wp_next_scheduled( 'sendrepute_cleanup_manual_lock', array( $lock_name ) ) ) {
			wp_schedule_single_event( time() + self::LOCK_TTL, 'sendrepute_cleanup_manual_lock', array( $lock_name ) );
		}
		$response = SendRepute_Client::request( 'POST', $path, $body );
		if ( is_wp_error( $response ) ) {
			self::fail_page( $response->get_error_message() . ' ' . __( 'The retry lock remains in place to prevent a possible duplicate charge; server scope and pricing decisions are final.', 'sendrepute' ) );
		}
		$lock['status'] = 'complete';
		update_option( $lock_name, $lock, false );
		self::render_page( $response, __( 'The manual action completed. Copy the result below; it will not be saved.', 'sendrepute' ), false );
		exit;
	}

	/**
	 * Remove an expired opaque manual-action lock.
	 *
	 * @param string $option_name Lock option name supplied by WP-Cron.
	 * @return void
	 */
	public static function cleanup_manual_lock( $option_name ) {
		if ( ! is_string( $option_name ) || ! preg_match( '/^sendrepute_manual_[a-f0-9]{64}$/', $option_name ) ) {
			return;
		}
		$lock = get_option( $option_name, null );
		if ( is_array( $lock ) && isset( $lock['expires'] ) && (int) $lock['expires'] <= time() ) {
self::delete_expired_lock( $option_name, $lock );
		}
	}

/**
 * Atomically remove the exact expired lock observed by this request.
 *
 * @param string $option_name Lock option name.
 * @param array  $lock        Expired option value previously observed.
 * @return bool Whether that exact row was removed.
 */
private static function delete_expired_lock( $option_name, $lock ) {
global $wpdb;

if ( ! isset( $wpdb, $wpdb->options ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
// Refusing a non-atomic fallback is safer than deleting a replacement lock.
return false;
}
$deleted = $wpdb->query(
$wpdb->prepare(
"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
$option_name,
maybe_serialize( $lock )
)
);
if ( 1 === (int) $deleted ) {
wp_cache_delete( $option_name, 'options' );
return true;
}
return false;
}

	/**
	 * @return void
	 */
	private static function require_paid_confirmation() {
		if ( ! isset( $_POST['paid_confirmation'] ) || '1' !== (string) wp_unslash( $_POST['paid_confirmation'] ) ) {
			self::fail_page( __( 'Explicit permission, data-sharing consent, and charge authorization are required. No paid request was sent.', 'sendrepute' ) );
		}
	}

	/**
	 * @param string $nonce_action Nonce action.
	 * @return void
	 */
	private static function authorize( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage SendRepute.', 'sendrepute' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * @param array  $settings Settings.
	 * @param string $key Key.
	 * @return bool
	 */
	private static function truthy( $settings, $key ) {
		return isset( $settings[ $key ] ) && in_array( $settings[ $key ], array( true, 1, '1', 'yes', 'on' ), true );
	}

	/**
	 * Convert integer millicents to a dollar amount without floating point.
	 *
	 * @param int $millicents Amount.
	 * @return string
	 */
	private static function money( $millicents ) {
		$millicents = max( 0, (int) $millicents );
		return '$' . number_format_i18n( $millicents / 100000, 3 );
	}

	/**
	 * @param string $message Safe error message.
	 * @return never
	 */
	private static function fail_page( $message ) {
		self::render_page( null, $message, true );
		exit;
	}

	/**
	 * @param string $code Notice code.
	 * @return never
	 */
	private static function redirect_notice( $code ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'sendrepute_notice' => sanitize_key( $code ) ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * @param string $code Notice code.
	 * @return void
	 */
	private static function render_redirect_notice( $code ) {
		$messages = array(
			'saved'            => array( __( 'SendRepute settings were saved.', 'sendrepute' ), false ),
			'connected'        => array( __( 'Connection succeeded. The account:read and catalog:read scopes were verified without a paid request.', 'sendrepute' ), false ),
			'token_error'      => array( __( 'Settings were saved, but the API token could not be stored securely.', 'sendrepute' ), true ),
			'connection_error' => array( __( 'Connection failed. Check the token, API availability, and server-reported scopes.', 'sendrepute' ), true ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		echo '<div class="notice ' . ( $messages[ $code ][1] ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $messages[ $code ][0] ) . '</p></div>';
	}
}