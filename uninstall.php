<?php
/**
 * Uninstall script for Fraud Order Blocker BD
 *
 * @package Fraud_Order_Blocker_BD
 */

// If uninstall not called from WordPress, then exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Clean up plugin options
delete_option( 'fob_bd_enabled' );
delete_option( 'fob_bd_blocked_shipping_methods' );
delete_option( 'fob_bd_blocked_payment_gateways' );
delete_option( 'fob_bd_error_message' );

// Clean up cached API responses (transients)
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_fob_bd_fraud_' ) . '%'
	)
);
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_timeout_fob_bd_fraud_' ) . '%'
	)
);

