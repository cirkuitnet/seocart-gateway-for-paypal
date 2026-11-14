<?php
/**
 * Tests isAvailable(): when the gateway takes a payment
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\GatewayForPayPal\Gateway;
use SEOCart\GatewayForPayPal\Tests\Support\GatewayTestCase;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * The gateway is offered when the payment's mode has a client id, the amount is in a declared
 * currency, and it lies between one minor unit and 99,999.99. It reads only the context SEOCart
 * hands it: the mode's own settings, never the other mode's, never a request.
 *
 * Planted violation, shown red and removed: isAvailable() reading the client id of the other mode
 * through the context's settings: the purity test fails (a setting was read) and the mode test
 * fails (a live payment is offered on the test mode's client id).
 *
 * @since 0.2.0
 */
final class AvailabilityTest extends GatewayTestCase {

	/**
	 * Tests the bounds and the currencies.
	 *
	 * @since 0.2.0
	 */
	public function test_it_takes_one_minor_unit_to_99999_99_in_a_declared_currency(): void {
		foreach ( array( 'USD', 'GBP', 'EUR' ) as $code ) {
			$this->assertTrue( $this->gateway->isAvailable( self::payment( 2000, $code ) ), "{$code} is taken." );
			$this->assertTrue( $this->gateway->isAvailable( self::payment( Gateway::MINIMUM_MINOR, $code ) ) );
			$this->assertTrue( $this->gateway->isAvailable( self::payment( Gateway::MAXIMUM_MINOR, $code ) ) );
			$this->assertFalse( $this->gateway->isAvailable( self::payment( 0, $code ) ), 'Nothing to pay is not a payment.' );
			$this->assertFalse( $this->gateway->isAvailable( self::payment( Gateway::MAXIMUM_MINOR + 1, $code ) ) );
		}

		$this->assertFalse( $this->gateway->isAvailable( self::payment( 2000, 'JPY' ) ), 'A currency the gateway does not declare.' );
	}

	/**
	 * Tests that a mode without a client id is not offered.
	 *
	 * @since 0.2.0
	 */
	public function test_a_mode_without_a_client_id_is_not_offered(): void {
		$this->assertFalse( $this->gateway->isAvailable( self::payment( 2000, 'USD', Mode::Test, array( 'client_id' => '' ) ) ) );
		$this->assertFalse( $this->gateway->isAvailable( self::payment( 2000, 'USD', Mode::Test, array() ) ) );
	}

	/**
	 * Tests that each mode is judged by its own client id, as the context hands it, and nothing else is read.
	 *
	 * @since 0.2.0
	 */
	public function test_it_reads_the_context_alone_and_each_mode_by_its_own_client_id(): void {
		$this->context->settings['test']['client_id'] = '';

		$this->assertTrue( $this->gateway->isAvailable( self::payment( 2000, 'USD', Mode::Live, array( 'client_id' => 'ALiveClientId' ) ) ) );
		$this->assertFalse( $this->gateway->isAvailable( self::payment( 2000, 'USD', Mode::Live, array( 'client_id' => '' ) ) ), 'A live payment is judged by the live client id, whatever the test mode holds.' );
		$this->assertSame( array(), $this->context->reads, 'isAvailable() reads no setting: SEOCart hands it the mode\'s.' );
		$this->assertSame( array(), $this->context->paypal->requests );
	}

	/**
	 * Tests an amount in another currency than the context's.
	 *
	 * @since 0.2.0
	 */
	public function test_an_amount_in_another_currency_than_the_payment_is_not_taken(): void {
		$payment = new AvailabilityContext( Currency::of( 'USD' ), Money::of( 2000, Currency::of( 'EUR' ) ), 'US', 'storefront', Mode::Test, array( 'client_id' => 'AClientId' ) );

		$this->assertFalse( $this->gateway->isAvailable( $payment ) );
	}

	/**
	 * Builds a payment's context.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $minor    The amount.
	 * @param string               $currency The currency.
	 * @param Mode                 $mode     Optional. The mode. Default test.
	 * @param array<string, mixed> $account  Optional. The mode's settings. Default a client id.
	 * @return AvailabilityContext The context.
	 */
	private static function payment( int $minor, string $currency, Mode $mode = Mode::Test, array $account = array( 'client_id' => 'AClientId' ) ): AvailabilityContext {
		return new AvailabilityContext( Currency::of( $currency ), Money::of( $minor, Currency::of( $currency ) ), 'US', 'storefront', $mode, $account + array( 'account_country' => 'US' ) );
	}
}
