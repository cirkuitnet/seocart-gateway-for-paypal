<?php
/**
 * Amounts: SEOCart's money as PayPal writes it, and back
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Support\Currency;
use SEOCart\Support\Money;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report an answer the gateway cannot read, which it turns into GatewayUnavailable; they are never HTML.

/**
 * Writes an amount as PayPal's `{ currency_code, value }`, and reads one PayPal answered.
 *
 * Owns one fact: PayPal counts money as a decimal string with as many decimals as the currency has
 * (`"20.00"` for USD), where SEOCart counts whole minor units. The conversion is exact, by digits,
 * never through a float. Only a currency the gateway declares (Gateway::CURRENCIES) is read: an
 * answer in any other is not one of this gateway's payments.
 *
 * @since 0.2.0
 */
final class Amounts {

	/**
	 * Returns an amount as PayPal's money object.
	 *
	 * @since 0.2.0
	 *
	 * @param Money $amount The amount.
	 * @return array{currency_code: string, value: string} The money object.
	 */
	public static function toPayPal( Money $amount ): array {
		return array(
			'currency_code' => $amount->currency()->code(),
			'value'         => self::toValue( $amount->minorUnits(), $amount->currency()->exponent() ),
		);
	}

	/**
	 * Writes minor units as a decimal string with the currency's decimals: 2000 and 2 as `20.00`.
	 *
	 * @since 0.2.0
	 *
	 * @param int $minor    The amount in minor units, zero or more.
	 * @param int $exponent The currency's number of decimals.
	 * @return string The decimal string.
	 */
	public static function toValue( int $minor, int $exponent ): string {
		$digits = str_pad( (string) $minor, $exponent + 1, '0', STR_PAD_LEFT );

		return 0 === $exponent ? $digits : substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent );
	}

	/**
	 * Reads PayPal's money object as SEOCart's money.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the object has no currency the gateway declares, or a value that is not a decimal of that currency's decimals at most.
	 *
	 * @param array<mixed> $money The money object, such as an authorization's `amount`.
	 * @return Money The amount.
	 */
	public static function money( array $money ): Money {
		$code  = Fields::text( $money, 'currency_code' );
		$value = Fields::text( $money, 'value' );

		if ( null === $code || ! in_array( $code, Gateway::CURRENCIES, true ) ) {
			throw new \UnexpectedValueException( 'A PayPal amount in a currency the gateway does not take.' );
		}

		$currency = Currency::of( $code );

		return Money::of( self::toMinor( (string) $value, $currency->exponent() ), $currency );
	}

	/**
	 * Tells whether PayPal's money object is exactly an amount: the same currency and the same minor units, compared by digits.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $money  The money object, such as an order's or an authorization's `amount`.
	 * @param Money        $amount The amount it must be.
	 * @return bool True when it is; false for another amount or currency, or one the gateway cannot read.
	 */
	public static function matches( array $money, Money $amount ): bool {
		try {
			return self::money( $money )->equals( $amount );
		} catch ( \UnexpectedValueException ) {
			return false;
		}
	}

	/**
	 * Reads a decimal string as minor units: `20.00`, `20.0` and `20` as 2000 for a currency of two decimals.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the text is not a non-negative decimal of the currency's decimals at most, or is too large.
	 *
	 * @param string $value    The decimal string.
	 * @param int    $exponent The currency's number of decimals.
	 * @return int The amount in minor units.
	 */
	public static function toMinor( string $value, int $exponent ): int {
		$pattern = 0 === $exponent ? '/^(\d{1,15})\z/' : '/^(\d{1,15})(?:\.(\d{1,' . $exponent . '}))?\z/';

		if ( 1 !== preg_match( $pattern, $value, $parts ) ) {
			throw new \UnexpectedValueException( 'A PayPal amount whose value is not a decimal of its currency.' );
		}

		return (int) ( $parts[1] . str_pad( $parts[2] ?? '', $exponent, '0' ) );
	}
}
