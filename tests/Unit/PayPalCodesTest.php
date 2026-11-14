<?php
/**
 * Tests PayPalCodes: the words and ids of PayPal's the gateway repeats, and nothing else
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForPayPal\PayPalCodes;

/**
 * An id is repeated only in its shape, and never when it reads as a card number; a status, an
 * issue, a reason or an error's name only when PayPal documents it; a path holds only PayPal's
 * words and ids. Anything else, a key, a token or a card number above all, is repeated as nothing.
 *
 * @since 0.2.0
 */
final class PayPalCodesTest extends TestCase {

	/**
	 * Tests the ids of orders, authorizations, captures and refunds.
	 *
	 * @since 0.2.0
	 */
	public function test_an_id_is_seventeen_capitals_and_digits_never_a_card_number(): void {
		foreach ( array( '5O190127TN364715T', '0VF52814937998046', '2GG279541U471931P' ) as $id ) {
			$this->assertSame( $id, PayPalCodes::id( $id ) );
		}

		foreach ( array( null, '', '5o190127tn364715t', '5O190127TN364715', '5O190127TN364715TT', '5O190127TN-64715T', 'A21AAFixtureToken', '4111111111111111', 'sk_live_12345678901' ) as $value ) {
			$this->assertNull( PayPalCodes::id( $value ), (string) $value . ' is no id.' );
		}

		$this->assertTrue( PayPalCodes::readsAsCard( '4111111111111111' ) );
		$this->assertFalse( PayPalCodes::readsAsCard( '4111111111111112' ) );
		$this->assertFalse( PayPalCodes::readsAsCard( '0VF52814937998046' ) );
	}

	/**
	 * Tests vault ids, event ids and debug ids.
	 *
	 * @since 0.2.0
	 */
	public function test_vault_event_and_debug_ids_have_their_own_shapes(): void {
		$this->assertSame( '8kk8451t', PayPalCodes::vaultId( '8kk8451t' ) );
		$this->assertNull( PayPalCodes::vaultId( '4111111111111111' ) );
		$this->assertNull( PayPalCodes::vaultId( 'a-b' ) );
		$this->assertSame( 'WH-2WR32451HC0233532-67976317FL4543714', PayPalCodes::eventId( 'WH-2WR32451HC0233532-67976317FL4543714' ) );
		$this->assertNull( PayPalCodes::eventId( 'evt_1Stripe' ) );
		$this->assertSame( 'f1b5bd3a51f2b', PayPalCodes::debugId( 'f1b5bd3a51f2b' ) );
		$this->assertNull( PayPalCodes::debugId( 'The request failed.' ) );
	}

	/**
	 * Tests the documented words: repeated as themselves, anything else as nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_documented_word_is_repeated_and_anything_else_is_not(): void {
		$this->assertSame( 'COMPLETED', PayPalCodes::orderStatus( 'COMPLETED' ) );
		$this->assertSame( 'DENIED', PayPalCodes::paymentStatus( 'DENIED' ) );
		$this->assertSame( 'PENDING_REVIEW', PayPalCodes::reason( 'PENDING_REVIEW' ) );
		$this->assertSame( 'UNPROCESSABLE_ENTITY', PayPalCodes::errorName( 'UNPROCESSABLE_ENTITY' ) );
		$this->assertSame( 'INSTRUMENT_DECLINED', PayPalCodes::issue( 'INSTRUMENT_DECLINED' ) );
		$this->assertSame( 'POSSIBLE', PayPalCodes::liabilityShift( 'POSSIBLE' ) );

		foreach ( array( 'completed', 'The card was declined.', 'A21AAFixtureToken', '4111111111111111', 'COMPLETED ' ) as $value ) {
			$this->assertNull( PayPalCodes::orderStatus( $value ) );
			$this->assertNull( PayPalCodes::issue( $value ) );
		}

		$this->assertContains( 'SHA256withRSA', PayPalCodes::words() );
		$this->assertSame( array_values( array_unique( PayPalCodes::words() ) ), PayPalCodes::words() );
	}

	/**
	 * Tests the declines: each an issue the gateway knows, listed once, and no issue of the merchant's account or of a request among them.
	 *
	 * @since 0.2.0
	 */
	public function test_the_declines_are_known_issues_and_no_merchant_issue_is_one(): void {
		foreach ( PayPalCodes::DECLINES as $issue ) {
			$this->assertSame( $issue, PayPalCodes::issue( $issue ) );
		}

		foreach ( array( 'AUTH_CAPTURE_NOT_ENABLED', 'PAYEE_ACCOUNT_RESTRICTED', 'PERMISSION_DENIED', 'DECIMAL_PRECISION', 'DUPLICATE_INVOICE_ID', 'ORDER_NOT_APPROVED', 'MAX_CAPTURE_AMOUNT_EXCEEDED' ) as $issue ) {
			$this->assertNotContains( $issue, PayPalCodes::DECLINES );
		}

		$this->assertSame( array_values( array_unique( PayPalCodes::ISSUES ) ), PayPalCodes::ISSUES, 'Each issue is listed once.' );
	}

	/**
	 * Tests a path's segments.
	 *
	 * @since 0.2.0
	 */
	public function test_a_path_segment_is_a_word_of_paypals_paths_or_an_id(): void {
		foreach ( array( 'v2', 'checkout', 'orders', 'oauth2', 'payment-tokens', '5O190127TN364715T' ) as $segment ) {
			$this->assertSame( $segment, PayPalCodes::pathSegment( $segment ) );
		}

		foreach ( array( '4111111111111111', '..', 'a/b', '2v', '', 'Orders' ) as $segment ) {
			$this->assertNull( PayPalCodes::pathSegment( $segment ) );
		}
	}
}
