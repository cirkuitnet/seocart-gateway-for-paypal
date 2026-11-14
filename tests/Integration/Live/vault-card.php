<?php
/**
 * Vaults one sandbox test card, once, for the conformance suite to pay with
 *
 * Usage: php tests/Integration/Live/vault-card.php <settings file>
 *
 * The suite cannot type a card: a card reaches PayPal only through PayPal's own card fields in a
 * browser. So, once per sandbox account, this creates an order to authorize whose card PayPal is
 * asked to vault when the payment succeeds, approves it with a published sandbox test card in
 * PayPal's card fields (tests/browser/approve-card.mjs, given the card on its standard input),
 * authorizes it, prints the id PayPal vaulted the card under, and voids the authorization, so
 * nothing stays held. The id goes into PayPalSuiteDeclarations::VAULTED_CARD. The settings file is
 * the test mode's, readable by its owner alone; only the sandbox is called. Prints statuses and ids
 * only: never the client id, the secret, a token or the card.
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\GatewayForPayPal\Tests\Integration\Live\PayPalTestApi;
use SEOCart\GatewayForPayPal\Tests\Support\Live\LiveRun;
use SEOCart\GatewayForPayPal\Tests\Support\Live\MessageGuard;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// The unit tests' bootstrap: SEOCart's development autoloader and this plugin's src/ and tests/.
require dirname( __DIR__, 2 ) . '/bootstrap.php';
require_once __DIR__ . '/PayPalTestApi.php';

if ( ! isset( $argv[1] ) || ! is_readable( $argv[1] ) || 0 !== ( fileperms( $argv[1] ) & 0077 ) ) {
	fwrite( STDERR, "usage: php vault-card.php <settings file, readable by its owner alone>\n" );
	exit( 2 );
}

/**
 * The card the order is approved with: a Visa PayPal publishes for testing card payments and vaulting in the sandbox.
 *
 * @var array{number: string, expiry: string, cvv: string, name: string}
 */
const SEOCART_GATEWAY_FOR_PAYPAL_TEST_CARD = array(
	'number' => '4012888888881881',
	'expiry' => '12/30',
	'cvv'    => '123',
	'name'   => 'Vaulted Test Card',
);

$seocart_gateway_for_paypal_api  = PayPalTestApi::fromSettingsFile( $argv[1] );
$seocart_gateway_for_paypal_uuid = LiveRun::uuid();

$seocart_gateway_for_paypal_order = $seocart_gateway_for_paypal_api->post(
	'v2/checkout/orders',
	array(
		'intent'         => 'AUTHORIZE',
		'purchase_units' => array(
			array(
				'amount'      => array(
					'currency_code' => 'USD',
					'value'         => '1.00',
				),
				'custom_id'   => $seocart_gateway_for_paypal_uuid,
				'description' => 'Vaulting a sandbox test card',
			),
		),
		'payment_source' => array(
			'card' => array(
				'attributes' => array(
					'vault'        => array( 'store_in_vault' => 'ON_SUCCESS' ),
					'verification' => array( 'method' => 'SCA_WHEN_REQUIRED' ),
				),
			),
		),
	),
	$seocart_gateway_for_paypal_uuid
);
$seocart_gateway_for_paypal_id    = (string) ( $seocart_gateway_for_paypal_order['id'] ?? '' );

echo MessageGuard::say( "vault-card: order %1\$s created, %2\$s.\n", MessageGuard::shown( $seocart_gateway_for_paypal_id ), (string) ( $seocart_gateway_for_paypal_order['status'] ?? 'no status' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a command-line script's message, built by MessageGuard.

$seocart_gateway_for_paypal_word = LiveRun::approveCard( $seocart_gateway_for_paypal_api->clientId(), $seocart_gateway_for_paypal_id, 'USD', SEOCART_GATEWAY_FOR_PAYPAL_TEST_CARD, 'complete' );

echo MessageGuard::say( "vault-card: the card fields answered %s.\n", $seocart_gateway_for_paypal_word ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.

if ( 'approved' !== $seocart_gateway_for_paypal_word ) {
	echo "vault-card: nothing was authorized, so nothing is held; the order lapses at PayPal.\n";
	exit( 1 );
}

$seocart_gateway_for_paypal_authorized = $seocart_gateway_for_paypal_api->post( 'v2/checkout/orders/' . $seocart_gateway_for_paypal_id . '/authorize', array(), $seocart_gateway_for_paypal_uuid . ':authorize' );
$seocart_gateway_for_paypal_auth       = $seocart_gateway_for_paypal_authorized['purchase_units'][0]['payments']['authorizations'][0] ?? array();
$seocart_gateway_for_paypal_vault      = $seocart_gateway_for_paypal_authorized['payment_source']['card']['attributes']['vault'] ?? array();

echo MessageGuard::say( "vault-card: authorization %1\$s %2\$s; vaulted card %3\$s, %4\$s.\n", MessageGuard::shown( (string) ( $seocart_gateway_for_paypal_auth['id'] ?? 'none' ) ), (string) ( $seocart_gateway_for_paypal_auth['status'] ?? 'no status' ), MessageGuard::shown( (string) ( $seocart_gateway_for_paypal_vault['id'] ?? 'none' ) ), (string) ( $seocart_gateway_for_paypal_vault['status'] ?? 'no status' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.

if ( is_string( $seocart_gateway_for_paypal_auth['id'] ?? null ) && 'CREATED' === ( $seocart_gateway_for_paypal_auth['status'] ?? null ) ) {
	$seocart_gateway_for_paypal_api->post( 'v2/payments/authorizations/' . $seocart_gateway_for_paypal_auth['id'] . '/void', array(), $seocart_gateway_for_paypal_uuid . ':void' );

	echo MessageGuard::say( "vault-card: authorization %s voided.\n", $seocart_gateway_for_paypal_auth['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.
}

exit( is_string( $seocart_gateway_for_paypal_vault['id'] ?? null ) && 'VAULTED' === ( $seocart_gateway_for_paypal_vault['status'] ?? null ) ? 0 : 1 );
