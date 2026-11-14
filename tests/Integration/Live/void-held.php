<?php
/**
 * Voids what earlier runs against PayPal's sandbox left authorized on a day, when no run list of theirs is left
 *
 * Usage: php tests/Integration/Live/void-held.php <settings file> [<order id> ...]
 *
 * Gathers the authorizations of the orders named, and of the transactions PayPal's transaction
 * search lists for today (UTC) with a store uuid, reads each as PayPal holds it, and voids those
 * still holding money that were created today for a store uuid (CleanupSelection::heldOn()); then
 * reads them again. Nothing else is touched: nothing captured, nothing of another day, nothing
 * without a store uuid; nothing is deleted, and no webhook is created or removed. The transaction
 * search may lag by hours or be refused to the app; the script says so and goes on with the orders
 * named. The settings file is the test mode's, readable by its owner alone; only the sandbox is
 * called. Prints counts, statuses and ids only: never the client id, the secret or a token.
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\GatewayForPayPal\Tests\Integration\Live\PayPalTestApi;
use SEOCart\GatewayForPayPal\Tests\Support\Live\CleanupSelection;
use SEOCart\GatewayForPayPal\Tests\Support\Live\MessageGuard;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// The unit tests' bootstrap: SEOCart's development autoloader and this plugin's src/ and tests/.
require dirname( __DIR__, 2 ) . '/bootstrap.php';
require_once __DIR__ . '/PayPalTestApi.php';

if ( ! isset( $argv[1] ) || ! is_readable( $argv[1] ) || 0 !== ( fileperms( $argv[1] ) & 0077 ) ) {
	fwrite( STDERR, "usage: php void-held.php <settings file, readable by its owner alone> [<order id> ...]\n" );
	exit( 2 );
}

/**
 * The most authorizations read, so a long search cannot turn into a flood of reads.
 *
 * @var int
 */
const SEOCART_GATEWAY_FOR_PAYPAL_MOST_READS = 200;

$seocart_gateway_for_paypal_api      = PayPalTestApi::fromSettingsFile( $argv[1] );
$seocart_gateway_for_paypal_day      = gmdate( 'Y-m-d' );
$seocart_gateway_for_paypal_orders   = array_values( array_filter( array_slice( $argv, 2 ), static fn( string $id ): bool => 1 === preg_match( '/^[A-Z0-9]{17}$/D', $id ) ) );
$seocart_gateway_for_paypal_found    = array();
$seocart_gateway_for_paypal_failures = array();

foreach ( $seocart_gateway_for_paypal_orders as $seocart_gateway_for_paypal_order ) {
	try {
		$seocart_gateway_for_paypal_read = $seocart_gateway_for_paypal_api->get( 'v2/checkout/orders/' . $seocart_gateway_for_paypal_order );
		$seocart_gateway_for_paypal_unit = is_array( $seocart_gateway_for_paypal_read['purchase_units'][0] ?? null ) ? $seocart_gateway_for_paypal_read['purchase_units'][0] : array();

		foreach ( is_array( $seocart_gateway_for_paypal_unit['payments']['authorizations'] ?? null ) ? $seocart_gateway_for_paypal_unit['payments']['authorizations'] : array() as $seocart_gateway_for_paypal_authorization ) {
			if ( is_array( $seocart_gateway_for_paypal_authorization ) && is_string( $seocart_gateway_for_paypal_authorization['id'] ?? null ) ) {
				$seocart_gateway_for_paypal_found[ $seocart_gateway_for_paypal_authorization['id'] ] = $seocart_gateway_for_paypal_authorization;
			}
		}
	} catch ( \RuntimeException $seocart_gateway_for_paypal_failed ) {
		$seocart_gateway_for_paypal_failures[] = $seocart_gateway_for_paypal_failed->getMessage();
	}
}

$seocart_gateway_for_paypal_searched = array();
$seocart_gateway_for_paypal_page     = 0;
$seocart_gateway_for_paypal_pages    = 1;

try {
	while ( $seocart_gateway_for_paypal_page < $seocart_gateway_for_paypal_pages ) {
		++$seocart_gateway_for_paypal_page;

		$seocart_gateway_for_paypal_answer   = $seocart_gateway_for_paypal_api->get(
			'v1/reporting/transactions',
			array(
				'start_date' => $seocart_gateway_for_paypal_day . 'T00:00:00Z',
				'end_date'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'fields'     => 'transaction_info',
				'page_size'  => 500,
				'page'       => $seocart_gateway_for_paypal_page,
			)
		);
		$seocart_gateway_for_paypal_pages    = min( 10, is_int( $seocart_gateway_for_paypal_answer['total_pages'] ?? null ) ? $seocart_gateway_for_paypal_answer['total_pages'] : 1 );
		$seocart_gateway_for_paypal_searched = array_merge( $seocart_gateway_for_paypal_searched, CleanupSelection::searched( $seocart_gateway_for_paypal_answer ) );
	}

	$seocart_gateway_for_paypal_search = sprintf( '%d transactions for a store uuid', count( $seocart_gateway_for_paypal_searched ) );
} catch ( \RuntimeException $seocart_gateway_for_paypal_refused ) {
	$seocart_gateway_for_paypal_search = 'not answered: ' . $seocart_gateway_for_paypal_refused->getMessage();
}

$seocart_gateway_for_paypal_reads   = 0;
$seocart_gateway_for_paypal_unknown = 0;

foreach ( array_unique( $seocart_gateway_for_paypal_searched ) as $seocart_gateway_for_paypal_id ) {
	if ( isset( $seocart_gateway_for_paypal_found[ $seocart_gateway_for_paypal_id ] ) || $seocart_gateway_for_paypal_reads >= SEOCART_GATEWAY_FOR_PAYPAL_MOST_READS ) {
		continue;
	}

	++$seocart_gateway_for_paypal_reads;

	try {
		$seocart_gateway_for_paypal_found[ $seocart_gateway_for_paypal_id ] = $seocart_gateway_for_paypal_api->get( 'v2/payments/authorizations/' . $seocart_gateway_for_paypal_id );
	} catch ( \RuntimeException ) {
		// A capture's or a refund's id is not an authorization's.
		++$seocart_gateway_for_paypal_unknown;
	}
}

$seocart_gateway_for_paypal_voided = array();

foreach ( $seocart_gateway_for_paypal_found as $seocart_gateway_for_paypal_authorization ) {
	$seocart_gateway_for_paypal_held = CleanupSelection::heldOn( $seocart_gateway_for_paypal_authorization, $seocart_gateway_for_paypal_day );

	if ( null === $seocart_gateway_for_paypal_held ) {
		continue;
	}

	try {
		$seocart_gateway_for_paypal_api->post( 'v2/payments/authorizations/' . $seocart_gateway_for_paypal_held . '/void', array(), (string) $seocart_gateway_for_paypal_authorization['custom_id'] . ':cleanup' );

		$seocart_gateway_for_paypal_after = $seocart_gateway_for_paypal_api->get( 'v2/payments/authorizations/' . $seocart_gateway_for_paypal_held );

		$seocart_gateway_for_paypal_voided[ $seocart_gateway_for_paypal_held ] = is_string( $seocart_gateway_for_paypal_after['status'] ?? null ) ? $seocart_gateway_for_paypal_after['status'] : 'no status';
	} catch ( \RuntimeException $seocart_gateway_for_paypal_failed ) {
		$seocart_gateway_for_paypal_failures[] = $seocart_gateway_for_paypal_failed->getMessage();
	}
}

echo MessageGuard::say( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a command-line script's message, built by MessageGuard.
	"void-held: %1\$d orders named; the transaction search for %2\$s: %3\$s; %4\$d authorizations read by id, %5\$d of them not authorizations; %6\$d authorizations seen in all.\n",
	count( $seocart_gateway_for_paypal_orders ),
	$seocart_gateway_for_paypal_day,
	$seocart_gateway_for_paypal_search,
	$seocart_gateway_for_paypal_reads,
	$seocart_gateway_for_paypal_unknown,
	count( $seocart_gateway_for_paypal_found )
);

foreach ( $seocart_gateway_for_paypal_voided as $seocart_gateway_for_paypal_id => $seocart_gateway_for_paypal_status ) {
	echo MessageGuard::say( "void-held: authorization %1\$s voided; PayPal now says %2\$s.\n", MessageGuard::shown( (string) $seocart_gateway_for_paypal_id ), $seocart_gateway_for_paypal_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.
}

echo MessageGuard::say( "void-held: %d authorizations voided. No webhook was created or removed.\n", count( $seocart_gateway_for_paypal_voided ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.

foreach ( $seocart_gateway_for_paypal_failures as $seocart_gateway_for_paypal_failure ) {
	printf( "void-held: %s\n", $seocart_gateway_for_paypal_failure ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PayPalTestApi's message, built by MessageGuard.
}

exit( array() === $seocart_gateway_for_paypal_failures && ! in_array( 'CREATED', $seocart_gateway_for_paypal_voided, true ) ? 0 : 1 );
