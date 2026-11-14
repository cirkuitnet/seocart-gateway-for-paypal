<?php
/**
 * Tests the amounts as PayPal writes them, both ways
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForPayPal\Amounts;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * Minor units become a decimal string with the currency's decimals, by digits, and a decimal
 * string PayPal answered becomes minor units again; a value that is no decimal of its currency, or
 * a currency the gateway does not take, is an answer the gateway cannot read.
 *
 * @since 0.2.0
 */
final class AmountsTest extends TestCase {

	/**
	 * Tests minor units written as PayPal's values.
	 *
	 * @since 0.2.0
	 */
	public function test_minor_units_are_written_with_the_currencys_decimals(): void {
		$this->assertSame( '20.00', Amounts::toValue( 2000, 2 ) );
		$this->assertSame( '0.01', Amounts::toValue( 1, 2 ) );
		$this->assertSame( '0.00', Amounts::toValue( 0, 2 ) );
		$this->assertSame( '99999.99', Amounts::toValue( 9999999, 2 ) );
		$this->assertSame( '1500', Amounts::toValue( 1500, 0 ) );
		$this->assertSame( '1.234', Amounts::toValue( 1234, 3 ) );
		$this->assertSame(
			array(
				'currency_code' => 'GBP',
				'value'         => '12.34',
			),
			Amounts::toPayPal( Money::of( 1234, Currency::of( 'GBP' ) ) )
		);
	}

	/**
	 * Tests PayPal's values read as minor units.
	 *
	 * @since 0.2.0
	 */
	public function test_values_are_read_as_minor_units(): void {
		$this->assertSame( 2000, Amounts::toMinor( '20.00', 2 ) );
		$this->assertSame( 2000, Amounts::toMinor( '20.0', 2 ) );
		$this->assertSame( 2000, Amounts::toMinor( '20', 2 ) );
		$this->assertSame( 1, Amounts::toMinor( '0.01', 2 ) );
		$this->assertSame( 1500, Amounts::toMinor( '1500', 0 ) );

		$read = Amounts::money(
			array(
				'currency_code' => 'EUR',
				'value'         => '19.99',
			)
		);

		$this->assertSame( array( 1999, 'EUR' ), array( $read->minorUnits(), $read->currency()->code() ) );
	}

	/**
	 * Tests the values and currencies the gateway refuses to read.
	 *
	 * @since 0.2.0
	 */
	public function test_a_value_or_currency_it_cannot_read_is_refused(): void {
		foreach ( array( '20.001', '-1.00', '1e3', '20,00', ' 20.00', '20.', '.5', '' ) as $value ) {
			try {
				Amounts::toMinor( $value, 2 );
				$this->fail( "`{$value}` is no decimal of a currency of two decimals." );
			} catch ( \UnexpectedValueException $refused ) {
				$this->assertStringContainsString( 'not a decimal', $refused->getMessage() );
			}
		}

		$this->expectException( \UnexpectedValueException::class );
		Amounts::money(
			array(
				'currency_code' => 'JPY',
				'value'         => '1500',
			)
		);
	}

	/**
	 * Tests an amount compared with PayPal's: the same currency and minor units, by digits; anything else, or anything unreadable, is another amount.
	 *
	 * @since 0.2.0
	 */
	public function test_an_amount_matches_only_the_same_currency_and_minor_units(): void {
		$asked = Money::of( 2000, Currency::of( 'USD' ) );

		foreach ( array( '20.00', '20.0', '20' ) as $value ) {
			$this->assertTrue( Amounts::matches( self::paypal( 'USD', $value ), $asked ), "`{$value}` is 20 dollars." );
		}

		foreach ( array( array( 'USD', '19.99' ), array( 'USD', '20.01' ), array( 'EUR', '20.00' ), array( 'JPY', '2000' ), array( 'USD', '20.001' ), array( 'USD', '' ) ) as list( $currency, $value ) ) {
			$this->assertFalse( Amounts::matches( self::paypal( $currency, $value ), $asked ), "{$currency} `{$value}` is not 20 dollars." );
		}

		$this->assertFalse( Amounts::matches( array(), $asked ), 'No amount is not the amount.' );
	}

	/**
	 * Returns PayPal's money object.
	 *
	 * @since 0.2.0
	 *
	 * @param string $currency The currency.
	 * @param string $value    The value.
	 * @return array{currency_code: string, value: string} The object.
	 */
	private static function paypal( string $currency, string $value ): array {
		return array(
			'currency_code' => $currency,
			'value'         => $value,
		);
	}
}
