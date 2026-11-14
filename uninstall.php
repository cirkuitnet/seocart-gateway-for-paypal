<?php
/**
 * Runs when SEOCart Gateway for PayPal is deleted from the Plugins screen
 *
 * The extension keeps one thing on the site of its own: the public certificates PayPal signs
 * webhook deliveries with, each in a transient named `seocart_paypal_cert_` and a digest of its
 * address, for a day. This file removes them. The settings the merchant saved are SEOCart's
 * gateway settings, kept by SEOCart as its store data is, and the payments the gateway took are
 * SEOCart's records: neither is removed here.
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( '_transient_seocart_paypal_cert_', '_transient_timeout_seocart_paypal_cert_' ) as $seocart_gateway_for_paypal_prefix ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transients are found by their prefix only in the options table, once, on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $seocart_gateway_for_paypal_prefix ) . '%' ) );
}
