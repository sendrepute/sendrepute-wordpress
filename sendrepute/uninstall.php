<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function sendrepute_uninstall_site() {
	global $wpdb;
	wp_clear_scheduled_hook( 'sendrepute_cleanup' );
	wp_unschedule_hook( 'sendrepute_cleanup_manual_lock' );
	$settings = get_option( 'sendrepute_settings', array() );
	// Never retain a stored credential on uninstall, even when settings are kept.
	delete_option( 'sendrepute_token' );
	if ( ! empty( $settings['retain_data'] ) ) {
		$settings['enabled'] = false;
		$settings['paid_consent'] = false;
		update_option( 'sendrepute_settings', $settings, false );
		return;
	}
	$names = $wpdb->get_col( $wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'sendrepute_' ) . '%'
	) );
	foreach ( $names as $name ) {
		delete_option( $name );
	}
}
if ( is_multisite() ) {
	$offset = 0;
	do {
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset ) );
		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			sendrepute_uninstall_site();
			restore_current_blog();
		}
		$offset += 100;
	} while ( count( $sites ) === 100 );
} else {
	sendrepute_uninstall_site();
}