<?php
/**
 * Plugin Name: SendRepute
 * Description: Optional paid pre-send email analysis and explicit manual AI tools. Does not send email or guarantee delivery.
 * Version: 0.1.0
 * Requires at least: 5.7
 * Requires PHP: 7.4
 * Author: SendRepute
 * License: GPL-2.0-or-later
 * Text Domain: sendrepute
 */
defined( 'ABSPATH' ) || exit;

// Fixed canonical HTTPS destination: credentials must never follow redirects.
define( 'SENDREPUTE_API_BASE', 'https://www.sendrepute.com/api' );
require_once __DIR__ . '/includes/class-sendrepute-client.php';
require_once __DIR__ . '/includes/class-sendrepute-mail.php';
require_once __DIR__ . '/includes/class-sendrepute-admin.php';
SendRepute_Mail::register();
SendRepute_Admin::register();

// Only opaque, expired analysis metadata is eligible for periodic cleanup.
add_action( 'sendrepute_cleanup', 'sendrepute_cleanup' );
function sendrepute_cleanup() {
	global $wpdb;
	$names = $wpdb->get_col( $wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500",
		$wpdb->esc_like( 'sendrepute_mail_' ) . '%'
	) );
	foreach ( $names as $name ) {
		$state = get_option( $name );
		if ( is_array( $state ) && isset( $state['expires'] ) && $state['expires'] <= time() ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$name, maybe_serialize( $state )
			) );
			wp_cache_delete( $name, 'options' );
		}
	}
}
function sendrepute_schedule_cleanup() {
	if ( ! wp_next_scheduled( 'sendrepute_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'sendrepute_cleanup' );
	}
}
function sendrepute_clear_cleanup() {
	wp_clear_scheduled_hook( 'sendrepute_cleanup' );
	// Keep paid-operation locks when disabled/re-enabled to avoid duplicate charges.
}
function sendrepute_each_site( $callback ) {
	$offset = 0;
	do {
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset ) );
		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			try {
				call_user_func( $callback );
			} finally {
				restore_current_blog();
			}
		}
		$offset += 100;
	} while ( count( $sites ) === 100 );
}
function sendrepute_activate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		sendrepute_each_site( 'sendrepute_schedule_cleanup' );
		return;
	}
	sendrepute_schedule_cleanup();
}
function sendrepute_deactivate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		sendrepute_each_site( 'sendrepute_clear_cleanup' );
		return;
	}
	sendrepute_clear_cleanup();
}
register_activation_hook( __FILE__, 'sendrepute_activate' );
register_deactivation_hook( __FILE__, 'sendrepute_deactivate' );