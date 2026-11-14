<?php
/**
 * Writes the two webhook deliveries the conformance suite reads, signed by a key pair made for the purpose and kept nowhere
 *
 * Usage: php tests/Support/write-test-deliveries.php
 *
 * Makes a key pair and its certificate (TestDeliveries), signs a PAYMENT.AUTHORIZATION.CREATED
 * event, which the gateway reads as a result, and a CHECKOUT.ORDER.APPROVED event, which it
 * ignores, exactly as PayPal signs its deliveries, for TestDeliveries::WEBHOOK_ID, and writes them
 * with the certificate to tests/fixtures/paypal/webhooks. The private key is never written: when
 * this script ends, nothing can sign another delivery the fixtures' certificate verifies. Run again,
 * it writes another pair's deliveries and certificate, and the tapes that hold the deliveries are
 * recorded again with them. Two deliveries PayPal signed take their place the same way: each file
 * holds the webhook id it was signed for, its mode, when it was sent, its headers and its raw body,
 * and cert.pem the certificate its `paypal-cert-url` names.
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\GatewayForPayPal\Tests\Support\TestDeliveries;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// The unit tests' bootstrap: SEOCart's development autoloader and this plugin's src/ and tests/.
require dirname( __DIR__ ) . '/bootstrap.php';

$seocart_gateway_for_paypal_keys = new TestDeliveries();
$seocart_gateway_for_paypal_at   = $seocart_gateway_for_paypal_keys->validFrom->modify( '+5 seconds' );
$seocart_gateway_for_paypal_dir  = dirname( __DIR__ ) . '/fixtures/paypal/webhooks';

/**
 * Returns an event id in PayPal's shape: `WH-`, then two runs of 17 capitals and digits.
 *
 * @since 0.2.0
 *
 * @return string The id.
 */
function seocart_gateway_for_paypal_event_id(): string {
	$run = static fn(): string => implode( '', array_map( static fn(): string => '0123456789ABCDEFGHJKLMNPRSTUVWXY'[ random_int( 0, 31 ) ], range( 1, 17 ) ) );

	return 'WH-' . $run() . '-' . $run();
}

$seocart_gateway_for_paypal_events = array(
	'authorization-created' => TestDeliveries::event( 'PAYMENT.AUTHORIZATION.CREATED', TestDeliveries::authorization(), seocart_gateway_for_paypal_event_id(), $seocart_gateway_for_paypal_at ),
	'order-approved'        => TestDeliveries::event( 'CHECKOUT.ORDER.APPROVED', TestDeliveries::approvedOrder(), seocart_gateway_for_paypal_event_id(), $seocart_gateway_for_paypal_at ),
);

foreach ( $seocart_gateway_for_paypal_events as $seocart_gateway_for_paypal_name => $seocart_gateway_for_paypal_body ) {
	$seocart_gateway_for_paypal_delivery = array(
		'signed_by'  => 'A key pair made for the test fixtures; its private key was never written.',
		'webhook_id' => TestDeliveries::WEBHOOK_ID,
		'mode'       => 'test',
		'signed_at'  => $seocart_gateway_for_paypal_at->format( DATE_ATOM ),
		'headers'    => $seocart_gateway_for_paypal_keys->headers( $seocart_gateway_for_paypal_body, $seocart_gateway_for_paypal_at ),
		'body'       => $seocart_gateway_for_paypal_body,
	);

	file_put_contents( $seocart_gateway_for_paypal_dir . '/' . $seocart_gateway_for_paypal_name . '.json', json_encode( $seocart_gateway_for_paypal_delivery, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
}

file_put_contents( $seocart_gateway_for_paypal_dir . '/cert.pem', $seocart_gateway_for_paypal_keys->certificate );

printf( "Wrote %d deliveries and their certificate, valid from %s, to %s.\n", count( $seocart_gateway_for_paypal_events ), $seocart_gateway_for_paypal_keys->validFrom->format( DATE_ATOM ), $seocart_gateway_for_paypal_dir );
