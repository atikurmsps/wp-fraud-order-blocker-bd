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

// Clean up any options or transients if needed
// Currently, this plugin doesn't store any data, so nothing to clean up

