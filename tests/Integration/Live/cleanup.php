<?php
/**
 * Voids what a run against PayPal's sandbox left authorized, and checks nothing of it still holds money
 *
 * Usage: php cleanup.php <settings file> <run list>
 *
 * Reads each order on the run list as PayPal holds it, voids each authorization of the run's
 * orders still `CREATED`, then reads them all again: the cleanup fails when one still holds money,
 * or when PayPal could not be asked. Nothing the run did not create is touched (CleanupSelection),
 * nothing is deleted, and no webhook is created or removed. Prints counts and ids only.
 * bin/ci/paypal-sandbox.sh runs it at the end of every job that called PayPal, whatever became of
 * the job, and then removes the settings file and the run list.
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\GatewayForPayPal\Tests\Integration\Live\PayPalTestApi;
use SEOCart\GatewayForPayPal\Tests\Support\Live\CleanupSelection;
use SEOCart\GatewayForPayPal\Tests\Support\Live\PayPalRun;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// The unit tests' bootstrap: SEOCart's development autoloader and this plugin's src/ and tests/.
require dirname( __DIR__, 2 ) . '/bootstrap.php';
require_once __DIR__ . '/PayPalTestApi.php';

$seocart_gateway_for_paypal_run_list = PayPalRun::orders( is_readable( $argv[2] ?? '' ) ? (string) file_get_contents( $argv[2] ) : '' );

if ( ! is_readable( $argv[1] ?? '' ) ) {
	printf( "cleanup: no settings file, so the run made no request to PayPal; %d orders on the run list.\n", count( $seocart_gateway_for_paypal_run_list ) );
	exit( array() === $seocart_gateway_for_paypal_run_list ? 0 : 1 );
}

$seocart_gateway_for_paypal_api      = PayPalTestApi::fromSettingsFile( $argv[1] );
$seocart_gateway_for_paypal_failures = array();

/**
 * Reads the run's orders as PayPal holds them now.
 *
 * @since 0.2.0
 *
 * @param PayPalTestApi         $api      The client.
 * @param array<string, string> $runList  The run's orders.
 * @param string[]              $failures Each request PayPal did not answer, to which this adds.
 * @return list<array<mixed>> The orders.
 * @phpstan-param list<string> $failures
 */
function seocart_gateway_for_paypal_read_back( PayPalTestApi $api, array $runList, array &$failures ): array {
	$orders = array();

	foreach ( array_keys( $runList ) as $id ) {
		try {
			$orders[] = $api->get( 'v2/checkout/orders/' . $id );
		} catch ( \RuntimeException $failed ) {
			$failures[] = $failed->getMessage();
		}
	}

	return $orders;
}

$seocart_gateway_for_paypal_void = CleanupSelection::authorizationsToVoid( $seocart_gateway_for_paypal_run_list, seocart_gateway_for_paypal_read_back( $seocart_gateway_for_paypal_api, $seocart_gateway_for_paypal_run_list, $seocart_gateway_for_paypal_failures ) );

foreach ( $seocart_gateway_for_paypal_void as $seocart_gateway_for_paypal_order => $seocart_gateway_for_paypal_authorization ) {
	try {
		$seocart_gateway_for_paypal_api->post( 'v2/payments/authorizations/' . $seocart_gateway_for_paypal_authorization . '/void', array(), $seocart_gateway_for_paypal_run_list[ $seocart_gateway_for_paypal_order ] . ':cleanup' );
	} catch ( \RuntimeException $seocart_gateway_for_paypal_failed ) {
		$seocart_gateway_for_paypal_failures[] = $seocart_gateway_for_paypal_failed->getMessage();
	}
}

$seocart_gateway_for_paypal_held = CleanupSelection::authorizationsToVoid( $seocart_gateway_for_paypal_run_list, seocart_gateway_for_paypal_read_back( $seocart_gateway_for_paypal_api, $seocart_gateway_for_paypal_run_list, $seocart_gateway_for_paypal_failures ) );

echo PayPalRun::guard()->say( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a command-line script's message, built by MessageGuard.
	"cleanup: %1\$d orders on the run list, %2\$d authorizations voided; afterwards %3\$d still held (%4\$s). No webhook was created or removed.\n",
	count( $seocart_gateway_for_paypal_run_list ),
	count( $seocart_gateway_for_paypal_void ),
	count( $seocart_gateway_for_paypal_held ),
	implode( ', ', $seocart_gateway_for_paypal_held )
);

foreach ( $seocart_gateway_for_paypal_failures as $seocart_gateway_for_paypal_failure ) {
	printf( "::error::%s\n", $seocart_gateway_for_paypal_failure ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a command-line script's message, built by MessageGuard.
}

exit( array() === $seocart_gateway_for_paypal_failures && array() === $seocart_gateway_for_paypal_held ? 0 : 1 );
