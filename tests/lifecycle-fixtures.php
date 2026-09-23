<?php
/**
 * Offline lifecycle fixture tests for the production plugin entry points.
 *
 * Run with: php integrations/wordpress/tests/lifecycle-fixtures.php
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['fixture_actions']      = array();
$GLOBALS['fixture_filters']      = array();
$GLOBALS['fixture_activation']   = null;
$GLOBALS['fixture_deactivation'] = null;
$GLOBALS['fixture_scheduled']    = array();
$GLOBALS['fixture_schedule_calls'] = array();
$GLOBALS['fixture_cleared']      = array();
$GLOBALS['fixture_unscheduled']  = array();
$GLOBALS['fixture_multisite']    = false;
$GLOBALS['fixture_blog_id']      = 1;
$GLOBALS['fixture_sites']        = array( 1 );
$GLOBALS['fixture_options']      = array( 1 => array() );
$GLOBALS['fixture_switches']     = array();
$GLOBALS['fixture_restores']     = 0;

class Lifecycle_WPDB {
	public $options = 'wp_options';

	public function prepare( $query, ...$args ) {
		return array( $query, $args );
	}

	public function esc_like( $value ) {
		return addcslashes( $value, '_%\\' );
	}

	public function get_col( $prepared ) {
		$pattern = $prepared[1][0];
		$prefix  = str_replace( array( '\\_', '\\%', '%' ), array( '_', '%', '' ), $pattern );
		return array_values(
			array_filter(
				array_keys( lifecycle_options() ),
				function ( $name ) use ( $prefix ) {
					return 0 === strpos( $name, $prefix );
				}
			)
		);
	}
}

$GLOBALS['wpdb'] = new Lifecycle_WPDB();

function &lifecycle_options() {
	$blog_id = $GLOBALS['fixture_blog_id'];
	if ( ! isset( $GLOBALS['fixture_options'][ $blog_id ] ) ) {
		$GLOBALS['fixture_options'][ $blog_id ] = array();
	}
	return $GLOBALS['fixture_options'][ $blog_id ];
}

function add_action( $hook, $callback ) {
	$GLOBALS['fixture_actions'][ $hook ][] = $callback;
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fixture_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
	return true;
}

function register_activation_hook( $file, $callback ) {
	$GLOBALS['fixture_activation'] = $callback;
}

function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['fixture_deactivation'] = $callback;
}

function wp_next_scheduled( $hook ) {
	$key = $GLOBALS['fixture_blog_id'] . ':' . $hook;
	return isset( $GLOBALS['fixture_scheduled'][ $key ] ) ? $GLOBALS['fixture_scheduled'][ $key ] : false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	$key = $GLOBALS['fixture_blog_id'] . ':' . $hook;
	$GLOBALS['fixture_scheduled'][ $key ] = $timestamp;
	$GLOBALS['fixture_schedule_calls'][] = array( $GLOBALS['fixture_blog_id'], $recurrence, $hook );
	return true;
}

function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['fixture_cleared'][] = array( $GLOBALS['fixture_blog_id'], $hook );
	unset( $GLOBALS['fixture_scheduled'][ $GLOBALS['fixture_blog_id'] . ':' . $hook ] );
	return 1;
}

function wp_unschedule_hook( $hook ) {
	$GLOBALS['fixture_unscheduled'][] = array( $GLOBALS['fixture_blog_id'], $hook );
	return 1;
}

function get_option( $name, $default = false ) {
	$options = lifecycle_options();
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$options          =& lifecycle_options();
	$options[ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	$options =& lifecycle_options();
	unset( $options[ $name ] );
	return true;
}

function is_multisite() {
	return $GLOBALS['fixture_multisite'];
}

function get_sites( $args ) {
	return array_slice( $GLOBALS['fixture_sites'], $args['offset'], $args['number'] );
}

function switch_to_blog( $site_id ) {
	$GLOBALS['fixture_switches'][] = $site_id;
	$GLOBALS['fixture_blog_id']    = $site_id;
	return true;
}

function restore_current_blog() {
	$GLOBALS['fixture_restores']++;
	$GLOBALS['fixture_blog_id'] = 1;
	return true;
}

function lifecycle_state() {
	return array(
		'actions'      => array_keys( $GLOBALS['fixture_actions'] ),
		'filters'      => $GLOBALS['fixture_filters'],
		'scheduled'    => $GLOBALS['fixture_scheduled'],
		'schedule_calls' => $GLOBALS['fixture_schedule_calls'],
		'cleared'      => $GLOBALS['fixture_cleared'],
		'unscheduled'  => $GLOBALS['fixture_unscheduled'],
		'options'      => $GLOBALS['fixture_options'],
		'switches'     => $GLOBALS['fixture_switches'],
		'restores'     => $GLOBALS['fixture_restores'],
	);
}

function lifecycle_emit_state() {
	echo '@@STATE@@' . json_encode( lifecycle_state() );
}

function lifecycle_run_child( $mode ) {
	if ( 'bootstrap' === $mode ) {
		require __DIR__ . '/../sendrepute/sendrepute.php';
		call_user_func( $GLOBALS['fixture_activation'] );
		$first = $GLOBALS['fixture_scheduled']['1:sendrepute_cleanup'];
		call_user_func( $GLOBALS['fixture_activation'] );
		if ( $first !== $GLOBALS['fixture_scheduled']['1:sendrepute_cleanup'] ) {
			throw new RuntimeException( 'Activation replaced an existing cleanup event.' );
		}
		call_user_func( $GLOBALS['fixture_deactivation'] );
		lifecycle_emit_state();
		return;
	}
	if ( 'bootstrap-multisite' === $mode ) {
		$GLOBALS['fixture_multisite'] = true;
		$GLOBALS['fixture_sites']     = range( 1, 101 );
		require __DIR__ . '/../sendrepute/sendrepute.php';
		call_user_func( $GLOBALS['fixture_activation'], true );
		call_user_func( $GLOBALS['fixture_deactivation'], true );
		lifecycle_emit_state();
		return;
	}

	define( 'WP_UNINSTALL_PLUGIN', true );
	if ( 'retain' === $mode ) {
		$GLOBALS['fixture_options'][1] = array(
			'sendrepute_settings' => array( 'enabled' => true, 'paid_consent' => true, 'retain_data' => true, 'model' => 'thor' ),
			'sendrepute_token'    => array( 'ciphertext' => 'secret' ),
			'sendrepute_mail_a'   => array( 'status' => 'complete' ),
			'unrelated'           => 'keep',
		);
	} elseif ( 'delete' === $mode ) {
		$GLOBALS['fixture_options'][1] = array(
			'sendrepute_settings' => array( 'retain_data' => false ),
			'sendrepute_token'    => array( 'ciphertext' => 'secret' ),
			'sendrepute_mail_a'   => array( 'status' => 'complete' ),
			'sendrepute_manual_b' => array( 'status' => 'pending' ),
			'unrelated'           => 'keep',
		);
	} elseif ( 'multisite' === $mode ) {
		$GLOBALS['fixture_multisite'] = true;
		$GLOBALS['fixture_sites']     = range( 1, 101 );
		foreach ( $GLOBALS['fixture_sites'] as $site_id ) {
			$GLOBALS['fixture_options'][ $site_id ] = array(
				'sendrepute_settings' => array( 'retain_data' => 1 === $site_id % 2, 'enabled' => true, 'paid_consent' => true ),
				'sendrepute_token'    => 'site-secret',
				'sendrepute_mail_x'   => 'opaque',
				'unrelated'           => $site_id,
			);
		}
	}
	require __DIR__ . '/../sendrepute/uninstall.php';
	lifecycle_emit_state();
}

if ( isset( $argv[1] ) && 0 === strpos( $argv[1], 'child-' ) ) {
	lifecycle_run_child( substr( $argv[1], 6 ) );
	exit;
}

$GLOBALS['lifecycle_tests'] = 0;

function lifecycle_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function lifecycle_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

function lifecycle_child( $mode ) {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( 'child-' . $mode );
	exec( $command, $lines, $status );
	$output = implode( "\n", $lines );
	lifecycle_same( 0, $status, "Lifecycle child {$mode} failed." );
	$marker = strrpos( $output, '@@STATE@@' );
	lifecycle_assert( false !== $marker, "Lifecycle child {$mode} returned no state." );
	$state = json_decode( substr( $output, $marker + 9 ), true );
	lifecycle_assert( is_array( $state ), "Lifecycle child {$mode} returned malformed state." );
	return $state;
}

function lifecycle_test( $name, $callback ) {
	$GLOBALS['lifecycle_tests']++;
	try {
		call_user_func( $callback );
		echo "ok - {$name}\n";
	} catch ( Throwable $error ) {
		fwrite( STDERR, "not ok - {$name}: " . $error->getMessage() . "\n" );
		exit( 1 );
	}
}

lifecycle_test(
	'production bootstrap registers hooks and activation and deactivation own the cleanup event',
	function () {
		$state = lifecycle_child( 'bootstrap' );
		lifecycle_assert( isset( $state['filters']['pre_wp_mail'] ), 'The production mail filter was not registered.' );
		lifecycle_same( PHP_INT_MAX, $state['filters']['pre_wp_mail'][0][1], 'The production mail filter priority changed.' );
		foreach ( array( 'admin_menu', 'sendrepute_cleanup', 'sendrepute_cleanup_manual_lock' ) as $hook ) {
			lifecycle_assert( in_array( $hook, $state['actions'], true ), "Missing production action {$hook}." );
		}
		lifecycle_same( array( array( 1, 'sendrepute_cleanup' ) ), $state['cleared'], 'Deactivation did not clear exactly the recurring cleanup hook.' );
		lifecycle_assert( ! isset( $state['scheduled']['1:sendrepute_cleanup'] ), 'The cleanup event remained scheduled after deactivation.' );
	}
);

lifecycle_test(
	'network activation and deactivation manage cleanup events on every existing site',
	function () {
		$state = lifecycle_child( 'bootstrap-multisite' );
		lifecycle_same( range( 1, 101 ), array_column( $state['schedule_calls'], 0 ), 'Network activation skipped or repeated a site.' );
		lifecycle_same( 202, $state['restores'], 'Network lifecycle did not restore every switched blog context.' );
		lifecycle_same( array(), $state['scheduled'], 'Network deactivation left cleanup events scheduled.' );
		lifecycle_same( 202, count( $state['switches'] ), 'Network lifecycle did not visit each site during activation and deactivation.' );
	}
);

lifecycle_test(
	'uninstall retention removes credentials and disables paid behavior without erasing retained data',
	function () {
		$state   = lifecycle_child( 'retain' );
		$options = $state['options'][1];
		lifecycle_assert( ! isset( $options['sendrepute_token'] ), 'Retained uninstall left a credential behind.' );
		lifecycle_same( false, $options['sendrepute_settings']['enabled'], 'Retained settings stayed enabled.' );
		lifecycle_same( false, $options['sendrepute_settings']['paid_consent'], 'Retained settings kept paid consent.' );
		lifecycle_assert( isset( $options['sendrepute_mail_a'] ), 'Retained opaque mail state was erased.' );
		lifecycle_same( 'keep', $options['unrelated'], 'Unrelated data changed.' );
	}
);

lifecycle_test(
	'uninstall deletion removes every plugin option and leaves unrelated options',
	function () {
		$state   = lifecycle_child( 'delete' );
		$options = $state['options'][1];
		foreach ( array_keys( $options ) as $name ) {
			lifecycle_assert( 0 !== strpos( $name, 'sendrepute_' ), "Plugin option {$name} survived deletion." );
		}
		lifecycle_same( array( 'unrelated' => 'keep' ), $options, 'Delete uninstall touched unrelated data.' );
		lifecycle_same( array( array( 1, 'sendrepute_cleanup_manual_lock' ) ), $state['unscheduled'], 'Manual lock events were not unscheduled.' );
	}
);

lifecycle_test(
	'multisite uninstall visits every site in batches and applies each site retention choice',
	function () {
		$state = lifecycle_child( 'multisite' );
		lifecycle_same( range( 1, 101 ), $state['switches'], 'Multisite uninstall skipped or repeated a site.' );
		lifecycle_same( 101, $state['restores'], 'Multisite blog context was not restored for every site.' );
		foreach ( range( 1, 101 ) as $site_id ) {
			$options = $state['options'][ $site_id ];
			lifecycle_assert( ! isset( $options['sendrepute_token'] ), "Site {$site_id} retained its credential." );
			lifecycle_same( $site_id, $options['unrelated'], "Site {$site_id} lost unrelated data." );
			if ( 1 === $site_id % 2 ) {
				lifecycle_same( false, $options['sendrepute_settings']['enabled'], "Retaining site {$site_id} stayed enabled." );
				lifecycle_assert( isset( $options['sendrepute_mail_x'] ), "Retaining site {$site_id} lost opaque state." );
			} else {
				lifecycle_assert( ! isset( $options['sendrepute_settings'], $options['sendrepute_mail_x'] ), "Deleting site {$site_id} retained plugin data." );
			}
		}
	}
);

echo '1..' . $GLOBALS['lifecycle_tests'] . "\n";