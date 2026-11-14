<?php
/**
 * GatewayTestCase: a unit test of the gateway against the fake PayPal
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\GatewayForPayPal\Gateway;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * Builds the gateway on a fake context for each test and, after each, scans everything it logged and every message it threw.
 *
 * The scan is the card-data and secrets rule applied to every test: a test of any operation fails
 * when the gateway handed its logger card details, a header, the client secret, an access token or
 * a piece of a body PayPal sent. WordPress's functions the gateway calls are stood in for: the
 * translation functions return their text, the JSON and URL helpers are PHP's own, and the
 * transients live in $transients for the test.
 *
 * @since 0.2.0
 */
abstract class GatewayTestCase extends TestCase {

	/**
	 * What the gateway is given.
	 *
	 * @since 0.2.0
	 *
	 * @var FakeContext
	 */
	protected FakeContext $context;

	/**
	 * The gateway.
	 *
	 * @since 0.2.0
	 *
	 * @var Gateway
	 */
	protected Gateway $gateway;

	/**
	 * The messages of the exceptions the gateway threw in this test.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	protected array $thrown = array();

	/**
	 * The transients the gateway set in this test, by name.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array{value: mixed, seconds: int}>
	 */
	protected array $transients = array();

	/**
	 * Builds the context and the gateway, and stands in for WordPress's functions.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component ) );
		Functions\when( 'get_transient' )->alias( fn( string $name ): mixed => $this->transients[ $name ]['value'] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $name, mixed $value, int $seconds ): bool {
				$this->transients[ $name ] = array(
					'value'   => $value,
					'seconds' => $seconds,
				);

				return true;
			}
		);

		$this->context = new FakeContext();
		$this->gateway = new Gateway( $this->context );
	}

	/**
	 * Scans what the gateway wrote and threw, then tears Brain Monkey down.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		$findings = LogScan::findings( $this->written(), array_merge( $this->context->credentials(), array( Fixtures::ACCESS_TOKEN ) ), $this->context->paypal->served );

		Monkey\tearDown();
		parent::tearDown();

		$this->assertSame( array(), $findings, 'The gateway logged or threw what it must never write.' );
	}

	/**
	 * Runs a call that must throw GatewayUnavailable, and returns the exception.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $call The call.
	 * @return GatewayUnavailable The exception.
	 */
	protected function unavailable( \Closure $call ): GatewayUnavailable {
		try {
			$call();
		} catch ( GatewayUnavailable $unavailable ) {
			$this->thrown[] = $unavailable->getMessage();

			return $unavailable;
		}

		$this->fail( 'The gateway answered where it had no answer to give.' );
	}

	/**
	 * Returns a request's headers.
	 *
	 * @since 0.2.0
	 *
	 * @param OutboundRequest|null $request Optional. The request. Default the last but a token call.
	 * @return array<string, string> The headers.
	 */
	protected function headers( ?OutboundRequest $request = null ): array {
		return ( $request ?? $this->context->paypal->last() )->headers;
	}

	/**
	 * Returns an amount in a currency.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $minor    The amount in minor units.
	 * @param string $currency Optional. The currency. Default USD.
	 * @return Money The amount.
	 */
	protected static function money( int $minor, string $currency = 'USD' ): Money {
		return Money::of( $minor, Currency::of( $currency ) );
	}

	/**
	 * Returns the path of an order under the API.
	 *
	 * @since 0.2.0
	 *
	 * @param string $suffix Optional. What follows the order's id, such as `/authorize`. Default none.
	 * @return string The path.
	 */
	protected static function orderPath( string $suffix = '' ): string {
		return 'v2/checkout/orders/' . Fixtures::ORDER_ID . $suffix;
	}

	/**
	 * Returns everything the gateway wrote in this test: every log line and every message it threw, encoded.
	 *
	 * @since 0.2.0
	 *
	 * @return string The text.
	 */
	protected function written(): string {
		return (string) json_encode( array( $this->context->log->lines, $this->thrown ), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
	}

	/**
	 * Returns the mode the tests use.
	 *
	 * @since 0.2.0
	 *
	 * @return Mode Test.
	 */
	protected static function test(): Mode {
		return Mode::Test;
	}
}
