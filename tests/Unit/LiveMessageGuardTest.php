<?php
/**
 * Tests the guard on the live tests' messages, which a public log shows
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForPayPal\Tests\Support\Live\MessageGuard;

/**
 * A message refuses an argument shaped like a credential (48 letters and digits or more, as
 * PayPal's client ids, secrets and access tokens are), an authorization header, an address with a
 * query, or a card number (a run of 13 to 19 digits that passes the Luhn check), and redact()
 * blanks the same shapes out of any text; PayPal's ids, of 17 characters or of an event's 38,
 * pass, as does a run of digits that is no card.
 *
 * Planted violation, shown red and removed: the card shape without its Luhn check: the order id
 * with a long run of digits that no card has is refused, and the id test fails.
 *
 * @since 0.2.0
 */
final class LiveMessageGuardTest extends TestCase {

	/**
	 * Tests the shapes refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_credential_a_header_an_address_with_a_query_and_a_card_number_are_refused(): void {
		$values = array(
			'a credential'            => 'AUnitTestClientIdLongerThanFortyCharactersForTheTestOnly',
			'an authorization header' => 'Bearer UnitTestAccessTokenOfTheFixturesOnly',
			'an address with a query' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T',
			'a card number'           => '4012 8888 8888 1881',
		);

		foreach ( $values as $shape => $value ) {
			$this->assertSame( $shape, MessageGuard::shapeOf( $value ) );

			try {
				MessageGuard::say( 'The value %s.', $value );
				$this->fail( "A value shaped like {$shape} was printed." );
			} catch ( \LogicException $refused ) {
				$this->assertStringNotContainsString( $value, $refused->getMessage() );
			}

			$this->assertSame( 'x [redacted: ' . $shape . '] y', MessageGuard::redact( 'x ' . $value . ' y' ) );
		}
	}

	/**
	 * Tests PayPal's ids and words: printed as they are.
	 *
	 * @since 0.2.0
	 */
	public function test_paypals_ids_and_words_are_printed(): void {
		foreach ( array( '5O190127TN364715T', '0C527818AF292391J', 'WH-2WR32451HC0233532-67976317FL4543714', '0199c0de-0000-7000-8000-00000000a001', '7390d37aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'PAYER_CANNOT_PAY', '1234567890123' ) as $value ) {
			$this->assertNull( MessageGuard::shapeOf( $value ), $value . ' is printed.' );
			$this->assertSame( 'The value ' . $value . '.', MessageGuard::say( 'The value %s.', $value ) );
		}
	}

	/**
	 * Tests an id whose digits read as a card: withheld when shown.
	 *
	 * @since 0.2.0
	 */
	public function test_an_id_whose_digits_read_as_a_card_is_withheld(): void {
		$this->assertSame( '(withheld: shaped like a card number)', MessageGuard::shown( '0VF52814937998046' ) );
		$this->assertSame( '5O190127TN364715T', MessageGuard::shown( '5O190127TN364715T' ) );
	}
}
