<?php
/**
 * MessageGuard: the messages of the live tests, which a public log shows
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Support\Live;

/**
 * Builds every message the live tests fail with, refusing an argument shaped like a credential or a card number, and blanks such text out of a log.
 *
 * The PayPal sandbox workflow runs in a public repository, so its log is public. A failure message
 * is built from ids, counts and PayPal's one-word codes; an argument that looks like a client id or
 * secret, an access token, an authorization header, a card number or any address with a query is
 * refused instead of printed, and the message says which kind it refused, never the value.
 * redact() applies the same shapes to text the workflow did not build, such as PHPUnit's output.
 *
 * PayPal's ids often hold a run of fourteen digits; a run is taken for a card number only when 13
 * to 19 of its digits pass the Luhn check, as SEOCart's own log reads it, so most ids pass and the
 * rare one that reads as a card is withheld (shown()).
 *
 * @since 0.2.0
 */
final class MessageGuard {

	/**
	 * The shapes refused, each a regular expression, by what it is; a card number is found apart (holdsCard()).
	 *
	 * An address with a query comes first: redact() blanks it whole before it blanks a secret its
	 * query may hold. Each slash after the scheme may carry the backslash JSON puts before it. A
	 * credential of PayPal's (a client id, a secret, an access token) is a run of 48 letters, digits,
	 * `_` and `-` or more; PayPal's ids are shorter (an event's, the longest, has 38), and so is a
	 * commit's 40-character hash, which a verdict names.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const SHAPES = array(
		'an address with a query' => '#[a-z][a-z0-9+.\-]*:(?:\\\\?/){2}[^\s?\#"\'<>]*\?[^\s"\'<>]*#i',
		'an authorization header' => '/\b(?:Bearer|Basic)\s+[A-Za-z0-9+\/=._\-]{8,}/',
		'a credential'            => '/[A-Za-z0-9_\-]{48,}/',
	);

	/**
	 * What a card number is called in a refusal and a redaction.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CARD = 'a card number';

	/**
	 * A run of digits long enough to hold a card number, spaces allowed between them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DIGIT_RUN = '/(?<![0-9])[0-9](?: ?[0-9]){12,}(?![0-9])/';

	/**
	 * Formats a message, refusing any argument shaped like a credential or a card number.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When an argument matches a shape: the message names the shape, never the value.
	 *
	 * @param string           $format    The format, as sprintf() takes it; it is the test's own text.
	 * @param string|int|float ...$values The values.
	 * @return string The message.
	 */
	public static function say( string $format, string|int|float ...$values ): string {
		foreach ( $values as $position => $value ) {
			$shape = self::shapeOf( (string) $value );

			if ( null !== $shape ) {
				throw new \LogicException( sprintf( 'A message of the live tests was refused: its argument %1$d is shaped like %2$s, which is never printed.', $position + 1, $shape ) );
			}
		}

		return vsprintf( $format, $values );
	}

	/**
	 * Returns a value as a message may show it: the value, or a note that it was withheld and why.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value, such as an order's id.
	 * @return string The value, or `(withheld: shaped like …)`.
	 */
	public static function shown( string $value ): string {
		$shape = self::shapeOf( $value );

		return null === $shape ? $value : '(withheld: shaped like ' . $shape . ')';
	}

	/**
	 * Returns the shape a value matches, if any.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value.
	 * @return string|null What it is shaped like; null for none.
	 */
	public static function shapeOf( string $value ): ?string {
		foreach ( self::SHAPES as $shape => $pattern ) {
			if ( 1 === preg_match( $pattern, $value ) ) {
				return $shape;
			}
		}

		preg_match_all( self::DIGIT_RUN, $value, $runs );

		foreach ( $runs[0] as $run ) {
			if ( self::holdsCard( str_replace( ' ', '', $run ) ) ) {
				return self::CARD;
			}
		}

		return null;
	}

	/**
	 * Blanks out every value shaped like a credential or a card number in a text, leaving the rest as it was.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text, such as a line of PHPUnit's output.
	 * @return string The text, each such value replaced by `[redacted: <what it is>]`.
	 */
	public static function redact( string $text ): string {
		foreach ( self::SHAPES as $shape => $pattern ) {
			$text = (string) preg_replace( $pattern, '[redacted: ' . $shape . ']', $text );
		}

		return (string) preg_replace_callback(
			self::DIGIT_RUN,
			static fn( array $run ): string => self::holdsCard( str_replace( ' ', '', $run[0] ) ) ? '[redacted: ' . self::CARD . ']' : $run[0],
			$text
		);
	}

	/**
	 * Tells whether a run of digits holds 13 to 19 consecutive digits that pass the Luhn check.
	 *
	 * @since 0.2.0
	 *
	 * @param string $digits The digits.
	 * @return bool True when it does.
	 */
	private static function holdsCard( string $digits ): bool {
		$total = strlen( $digits );

		for ( $start = 0; $start + 13 <= $total; $start++ ) {
			for ( $length = 13; $length <= 19 && $start + $length <= $total; $length++ ) {
				if ( self::passesLuhn( substr( $digits, $start, $length ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Tells whether digits pass the Luhn check.
	 *
	 * @since 0.2.0
	 *
	 * @param string $digits The digits.
	 * @return bool True when they do.
	 */
	private static function passesLuhn( string $digits ): bool {
		$sum    = 0;
		$double = false;

		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			$digit = (int) $digits[ $i ];

			if ( $double ) {
				$digit = $digit * 2 > 9 ? $digit * 2 - 9 : $digit * 2;
			}

			$sum   += $digit;
			$double = ! $double;
		}

		return 0 === $sum % 10;
	}
}
