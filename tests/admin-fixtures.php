<?php
/**
 * Offline fixture tests for the SendRepute administrator UI.
 *
 * Run with: php integrations/wordpress/tests/admin-fixtures.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['fixture_capability'] = true;
$GLOBALS['fixture_nonce']      = true;
$GLOBALS['fixture_options']    = array();
$GLOBALS['fixture_actions']    = array();
$GLOBALS['fixture_calls']      = array();
$GLOBALS['fixture_scheduled']  = array();
$GLOBALS['fixture_cache']      = array();
$GLOBALS['fixture_token']      = 'never-render-this-secret';
$GLOBALS['fixture_settings']   = array(
	'enabled'        => false,
	'paid_consent'   => false,
	'failure_policy' => 'open',
	'risk_policy'    => 'advisory',
	'threshold'      => 0.8,
	'model'          => '',
	'retain_data'    => false,
);
$GLOBALS['fixture_state'] = array(
	'account' => array( 'id' => 'account-fixture', 'username' => 'fixture', 'scopes' => array( 'account:read', 'catalog:read', 'vip:read', 'rewrite', 'ai:generate' ) ),
	'pricing' => array(
		'classificationBaseMillicents'       => 1000,
		'additionalTermMillicents'           => 100,
		'maximumClassificationMillicents'    => 10000,
		'aiMinimumPerUniqueTermMillicents'   => 1000,
	),
	'vip'     => array(
		'active'                            => true,
		'aiTemplatePriceMillicents'         => 149000,
		'regularAiTemplatePriceMillicents'  => 99500,
	),
);

class Fixture_Stop extends RuntimeException {}
class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
class Fixture_WPDB {
	public $options = 'wp_options';
	public $replace_before_delete = null;
	public function prepare( $query, $name, $value ) { return array( $query, $name, $value ); }
	public function query( $prepared ) {
		$name = $prepared[1];
		if ( is_array( $this->replace_before_delete ) ) {
			$GLOBALS['fixture_options'][ $name ] = $this->replace_before_delete;
			$this->replace_before_delete = null;
		}
		if ( array_key_exists( $name, $GLOBALS['fixture_options'] ) && maybe_serialize( $GLOBALS['fixture_options'][ $name ] ) === $prepared[2] ) {
			unset( $GLOBALS['fixture_options'][ $name ] );
			return 1;
		}
		return 0;
	}
}
$GLOBALS['wpdb'] = new Fixture_WPDB();

final class SendRepute_Client {
	public static function settings() { return $GLOBALS['fixture_settings']; }
	public static function token() { return $GLOBALS['fixture_token']; }
	public static function store_token( $token ) {
		$GLOBALS['fixture_options']['stored_token_input'] = $token;
		return true;
	}
	public static function connection() {
		$GLOBALS['fixture_calls'][] = array( 'method' => 'GET', 'path' => '/v1/account+/v1/pricing' );
		return array(
			'connected' => true,
			'account'   => $GLOBALS['fixture_state']['account'],
			'pricing'   => $GLOBALS['fixture_state']['pricing'],
		);
	}
	public static function request( $method, $path, $body = null ) {
		$GLOBALS['fixture_calls'][] = array( 'method' => $method, 'path' => $path, 'body' => $body );
		if ( 'GET' === $method && '/v1/vip' === $path ) {
			return $GLOBALS['fixture_state']['vip'];
		}
		return array( 'document' => array( 'fixture' => true ), 'requestId' => 'fixture-request', 'replayed' => false );
	}
}

function __( $text ) { return $text; }
function esc_html__( $text ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $text ) { return esc_attr( $text ); }
function esc_textarea( $text ) { return esc_html( $text ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can() { return $GLOBALS['fixture_capability']; }
function wp_die( $message ) { throw new Fixture_Stop( (string) $message ); }
function check_admin_referer() {
	if ( ! $GLOBALS['fixture_nonce'] ) {
		throw new Fixture_Stop( 'nonce failure' );
	}
	return 1;
}
function add_action( $hook, $callback ) { $GLOBALS['fixture_actions'][ $hook ] = $callback; }
function add_options_page() {}
function admin_url( $path = '' ) { return 'https://wordpress.invalid/wp-admin/' . $path; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $action ) . '">'; }
function checked( $checked ) { if ( $checked ) echo 'checked="checked"'; }
function selected( $value, $expected ) { if ( $value === $expected ) echo 'selected="selected"'; }
function submit_button( $text, $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button name="' . esc_attr( $name ) . '">' . esc_html( $text ) . '</button>'; }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_http_validate_url( $url ) { return false !== filter_var( $url, FILTER_VALIDATE_URL ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_salt( $scheme = 'auth' ) { return 'admin-fixture-' . $scheme; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( $number, $decimals, '.', ',' ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['fixture_options'] ) ? $GLOBALS['fixture_options'][ $name ] : $default; }
function update_option( $name, $value ) { $GLOBALS['fixture_options'][ $name ] = $value; return true; }
function add_option( $name, $value ) {
	if ( array_key_exists( $name, $GLOBALS['fixture_options'] ) ) return false;
	$GLOBALS['fixture_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) { unset( $GLOBALS['fixture_options'][ $name ] ); return true; }
function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; }
function wp_cache_delete( $name, $group = '' ) { $GLOBALS['fixture_cache'][] = array( $name, $group ); }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_single_event( $time, $hook, $args = array() ) { $GLOBALS['fixture_scheduled'][] = array( $time, $hook, $args ); return true; }
function wp_safe_redirect( $url ) { throw new Fixture_Stop( 'redirect:' . $url ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

require_once __DIR__ . '/../sendrepute/includes/class-sendrepute-admin.php';

function admin_price_digest( $state ) {
	$method = new ReflectionMethod( 'SendRepute_Admin', 'price_digest' );
	$method->setAccessible( true );
	return $method->invoke( null, $state );
}

function admin_post_for_mode( $mode ) {
	$state  = $GLOBALS['fixture_state'];
	$digest = admin_price_digest( $state );
	$_POST  = array( 'paid_confirmation' => '1', 'price_state' => $digest );
	if ( 'stale' === $mode ) {
		$_POST += array( 'template_content' => 'A launch announcement', 'template_category' => 'business', 'template_subtype' => 'announcement' );
		$GLOBALS['fixture_state']['vip']['active'] = false;
		SendRepute_Admin::manual_template();
	}
	if ( 'stale-price' === $mode ) {
		$_POST += array( 'template_content' => 'A launch announcement', 'template_category' => 'business', 'template_subtype' => 'announcement' );
		$GLOBALS['fixture_state']['vip']['regularAiTemplatePriceMillicents']++;
		SendRepute_Admin::manual_template();
	}
	if ( 'duplicate' === $mode ) {
		$body = array(
			'content'                 => 'A launch announcement',
			'category'                => 'custom',
			'subtype'                 => 'custom',
			'expectedPriceMillicents' => $state['vip']['regularAiTemplatePriceMillicents'],
		);
		$token_identity = hash_hmac( 'sha256', SendRepute_Client::token(), wp_salt( 'auth' ) );
		$hash = hash_hmac( 'sha256', $token_identity . "\n/v1/email-builder/ai-template\n" . $digest . "\n" . wp_json_encode( $body ), wp_salt( 'nonce' ) );
		$GLOBALS['fixture_options']['sendrepute_manual_' . $hash] = array( 'status' => 'pending', 'created' => time(), 'expires' => time() + DAY_IN_SECONDS );
		$_POST += array( 'template_content' => $body['content'], 'template_category' => $body['category'], 'template_subtype' => $body['subtype'] );
		SendRepute_Admin::manual_template();
	}
	if ( 'token-scope' === $mode ) {
		$body = array(
			'content'                 => 'A launch announcement',
			'category'                => 'custom',
			'subtype'                 => 'custom',
			'expectedPriceMillicents' => $state['vip']['regularAiTemplatePriceMillicents'],
		);
		$old_identity = hash_hmac( 'sha256', SendRepute_Client::token(), wp_salt( 'auth' ) );
		$hash = hash_hmac( 'sha256', $old_identity . "\n/v1/email-builder/ai-template\n" . $digest . "\n" . wp_json_encode( $body ), wp_salt( 'nonce' ) );
		$GLOBALS['fixture_options']['sendrepute_manual_' . $hash] = array( 'status' => 'complete', 'created' => time(), 'expires' => time() + DAY_IN_SECONDS );
		$GLOBALS['fixture_token'] = 'a-different-api-key';
		$_POST += array( 'template_content' => $body['content'], 'template_category' => $body['category'], 'template_subtype' => $body['subtype'] );
		SendRepute_Admin::manual_template();
	}
	if ( 'rewrite' === $mode ) {
		$_POST += array( 'parent_request_id' => 'fixture_parent_123456789', 'rewrite_mode' => 'all', 'terms' => "offer\nurgent" );
		SendRepute_Admin::manual_rewrite();
	}
	if ( 'template' === $mode ) {
		$_POST += array( 'template_content' => 'A launch announcement', 'template_category' => 'business', 'template_subtype' => 'announcement' );
		SendRepute_Admin::manual_template();
	}
	if ( 'vip' === $mode ) {
		$_POST += array( 'vip_prompt' => 'Create a restrained product launch.', 'image_urls' => "https://assets.example.test/hero.png" );
		SendRepute_Admin::manual_vip_template();
	}
}

if ( isset( $argv[1] ) && 0 === strpos( $argv[1], 'child-' ) ) {
	$mode = substr( $argv[1], 6 );
	register_shutdown_function(
		function () {
			echo "\n@@STATE@@" . json_encode(
				array(
					'calls'     => $GLOBALS['fixture_calls'],
					'options'   => $GLOBALS['fixture_options'],
					'scheduled' => $GLOBALS['fixture_scheduled'],
				)
			);
		}
	);
	admin_post_for_mode( $mode );
	exit;
}

$GLOBALS['admin_tests'] = 0;
function admin_assert( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
}
function admin_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
}
function admin_test( $name, $callback ) {
	$GLOBALS['admin_tests']++;
	try {
		$callback();
		echo "ok - {$name}\n";
	} catch ( Throwable $error ) {
		fwrite( STDERR, "not ok - {$name}: " . $error->getMessage() . "\n" );
		exit( 1 );
	}
}
function admin_child( $mode ) {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( 'child-' . $mode );
	exec( $command, $lines, $status );
	$output = implode( "\n", $lines );
	admin_same( 0, $status, "Child {$mode} failed." );
	$marker = strrpos( $output, '@@STATE@@' );
	admin_assert( false !== $marker, "Child {$mode} did not report state." );
	$state = json_decode( substr( $output, $marker + 9 ), true );
	admin_assert( is_array( $state ), "Child {$mode} returned malformed state." );
	return array( 'output' => substr( $output, 0, $marker ), 'state' => $state );
}
function admin_posts( $state ) {
	return array_values( array_filter( $state['calls'], function ( $call ) { return 'POST' === $call['method']; } ) );
}
function admin_validate_schema( $value, $schema, $document, $path = '$' ) {
	if ( isset( $schema['$ref'] ) ) {
		$reference = $schema['$ref'];
		$schema = $document;
		foreach ( explode( '/', substr( $reference, 2 ) ) as $part ) {
			admin_assert( isset( $schema[ $part ] ), "{$path}: unresolved OpenAPI reference" );
			$schema = $schema[ $part ];
		}
	}
	if ( isset( $schema['enum'] ) ) admin_assert( in_array( $value, $schema['enum'], true ), "{$path}: invalid enum" );
	$type = isset( $schema['type'] ) ? $schema['type'] : null;
	if ( 'object' === $type ) {
		admin_assert( is_array( $value ), "{$path}: expected object" );
		foreach ( isset( $schema['required'] ) ? $schema['required'] : array() as $key ) admin_assert( array_key_exists( $key, $value ), "{$path}: missing {$key}" );
		if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
			foreach ( array_keys( $value ) as $key ) admin_assert( isset( $schema['properties'][ $key ] ), "{$path}: unexpected {$key}" );
		}
		foreach ( $value as $key => $child ) if ( isset( $schema['properties'][ $key ] ) ) admin_validate_schema( $child, $schema['properties'][ $key ], $document, "{$path}.{$key}" );
	} elseif ( 'array' === $type ) {
		admin_assert( is_array( $value ), "{$path}: expected array" );
		foreach ( $value as $index => $child ) admin_validate_schema( $child, $schema['items'], $document, "{$path}[{$index}]" );
	} elseif ( 'string' === $type ) {
		admin_assert( is_string( $value ), "{$path}: expected string" );
		if ( isset( $schema['pattern'] ) ) admin_assert( 1 === preg_match( '~' . str_replace( '~', '\\~', $schema['pattern'] ) . '~', $value ), "{$path}: pattern mismatch" );
	} elseif ( 'number' === $type || 'integer' === $type ) {
		admin_assert( is_int( $value ) || is_float( $value ), "{$path}: expected number" );
	}
}

admin_test(
	'registers only administrator hooks and rejects missing capability or nonce',
	function () {
		SendRepute_Admin::register();
		admin_assert( isset( $GLOBALS['fixture_actions']['admin_post_sendrepute_save_settings'] ), 'Settings handler was not registered.' );
		$GLOBALS['fixture_capability'] = false;
		try { SendRepute_Admin::save_settings(); admin_assert( false, 'Unauthorized save continued.' ); } catch ( Fixture_Stop $error ) { admin_assert( false !== strpos( $error->getMessage(), 'not allowed' ), 'Wrong authorization error.' ); }
		$GLOBALS['fixture_capability'] = true;
		$GLOBALS['fixture_nonce'] = false;
		try { SendRepute_Admin::save_settings(); admin_assert( false, 'Invalid nonce continued.' ); } catch ( Fixture_Stop $error ) { admin_same( 'nonce failure', $error->getMessage(), 'Wrong nonce error.' ); }
	}
);

admin_test(
	'token password field always renders blank and never renders the token',
	function () {
		ob_start();
		SendRepute_Admin::render_page();
		$html = ob_get_clean();
		admin_assert( false !== strpos( $html, 'name="api_token" value=""' ), 'Token input was not blank.' );
		admin_assert( false === strpos( $html, 'never-render-this-secret' ), 'Plaintext token leaked into HTML.' );
	}
);

admin_test(
	'manual tools are disabled when authoritative regular pricing is unavailable',
	function () {
		$saved = $GLOBALS['fixture_state']['vip']['regularAiTemplatePriceMillicents'];
		unset( $GLOBALS['fixture_state']['vip']['regularAiTemplatePriceMillicents'] );
		ob_start();
		SendRepute_Admin::render_page();
		$html = ob_get_clean();
		$GLOBALS['fixture_state']['vip']['regularAiTemplatePriceMillicents'] = $saved;
		admin_assert( false !== strpos( $html, 'Paid actions are unavailable' ), 'Missing authoritative price did not disable paid tools.' );
		admin_assert( false === strpos( $html, 'action" value="sendrepute_manual_template' ), 'A paid form rendered without authoritative pricing.' );
	}
);

admin_test(
	'VIP status or effective regular price changes abort before a paid request',
	function () {
		foreach ( array( 'stale', 'stale-price' ) as $mode ) {
			$child = admin_child( $mode );
			admin_same( 0, count( admin_posts( $child['state'] ) ), "{$mode} confirmation sent a paid request." );
			admin_assert( false !== strpos( $child['output'], 'price or membership changed' ), "{$mode} explanation was not rendered." );
		}
	}
);

admin_test(
	'identical manual paid submissions are suppressed',
	function () {
		$child = admin_child( 'duplicate' );
		admin_same( 0, count( admin_posts( $child['state'] ) ), 'Duplicate paid request was submitted.' );
		admin_assert( false !== strpos( $child['output'], 'already submitted recently' ), 'Duplicate explanation was not rendered.' );
	}
);

admin_test(
	'manual duplicate locks are scoped to the API token identity',
	function () {
		$child = admin_child( 'token-scope' );
		admin_same( 1, count( admin_posts( $child['state'] ) ), 'A lock created for another API token suppressed the paid request.' );
	}
);

admin_test(
	'expired cleanup compare-deletes only the exact observed lock',
	function () {
		$name = 'sendrepute_manual_' . str_repeat( 'a', 64 );
		$old  = array( 'status' => 'pending', 'created' => time() - 100, 'expires' => time() - 1 );
		$new  = array( 'status' => 'pending', 'created' => time(), 'expires' => time() + DAY_IN_SECONDS );
		$GLOBALS['fixture_options'][ $name ] = $old;
		$GLOBALS['wpdb']->replace_before_delete = $new;
		SendRepute_Admin::cleanup_manual_lock( $name );
		admin_same( $new, $GLOBALS['fixture_options'][ $name ], 'Cleanup erased a replacement lock.' );
		$GLOBALS['wpdb']->replace_before_delete = null;
		$GLOBALS['fixture_options'][ $name ] = $old;
		SendRepute_Admin::cleanup_manual_lock( $name );
		admin_assert( ! isset( $GLOBALS['fixture_options'][ $name ] ), 'Exact expired lock was not deleted.' );
		admin_same( array( $name, 'options' ), end( $GLOBALS['fixture_cache'] ), 'Option cache was not invalidated.' );
	}
);

admin_test(
'manual bodies use the public routes and explicit template price',
	function () {
		$cases = array(
			'rewrite'  => '/v1/rewrite',
			'template' => '/v1/email-builder/ai-template',
			'vip'      => '/v1/vip/email-template',
		);
		foreach ( $cases as $mode => $path ) {
			$child = admin_child( $mode );
			$posts = admin_posts( $child['state'] );
			admin_same( 1, count( $posts ), "{$mode} did not issue exactly one paid request." );
			admin_same( $path, $posts[0]['path'], "{$mode} used the wrong endpoint." );
			if ( 'template' === $mode ) {
				admin_same(
					$GLOBALS['fixture_state']['vip']['regularAiTemplatePriceMillicents'],
					$posts[0]['body']['expectedPriceMillicents'],
					'Regular template request omitted the authoritative expected price.'
				);
				admin_same( 'custom', $posts[0]['body']['category'], 'Regular template did not force custom category.' );
				admin_same( 'custom', $posts[0]['body']['subtype'], 'Regular template did not force custom subtype.' );
admin_assert( is_string( $posts[0]['body']['content'] ) && '' !== $posts[0]['body']['content'], 'Template source content is required.' );
			}
		}
	}
);

echo '1..' . $GLOBALS['admin_tests'] . "\n";