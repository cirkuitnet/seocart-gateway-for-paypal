<?php
/**
 * Tests the payment token's grammar
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\GatewayForPayPal\PaymentTokens;

/**
 * No token and `paypal` ask for PayPal's page or buttons, `card` for PayPal's card fields, and
 * `vault:<id>` for a card PayPal vaulted, with a mocked error after `;mock=` in test mode only.
 * Anything else, a card number in place of a vault id above all, is no token.
 *
 * Planted violation, shown red and removed: the vault id read without the card-number check: the
 * card-number test fails.
 *
 * @since 0.2.0
 */
final class PaymentTokensTest extends TestCase {

	/**
	 * Tests the three kinds.
	 *
	 * @since 0.2.0
	 */
	public function test_each_kind_reads_as_itself(): void {
		$this->assertSame( array( 'paypal', null, null ), self::read( null ) );
		$this->assertSame( array( 'paypal', null, null ), self::read( 'paypal' ) );
		$this->assertSame( array( 'card', null, null ), self::read( 'card' ) );
		$this->assertSame( array( 'vault', '8kk8451t', null ), self::read( 'vault:8kk8451t' ) );
		$this->assertSame( array( 'vault', '8kk8451t', null ), self::read( 'vault:8kk8451t', Mode::Live ) );
	}

	/**
	 * Tests the sandbox's mock, read in test mode only.
	 *
	 * @since 0.2.0
	 */
	public function test_a_mock_is_read_in_test_mode_only(): void {
		$this->assertSame( array( 'vault', '8kk8451t', 'INSTRUMENT_DECLINED' ), self::read( 'vault:8kk8451t;mock=INSTRUMENT_DECLINED' ) );
		$this->assertNull( PaymentTokens::read( 'vault:8kk8451t;mock=INSTRUMENT_DECLINED', Mode::Live ), 'Live mode takes no mock.' );
		$this->assertNull( PaymentTokens::read( 'vault:8kk8451t;mock=instrument_declined', Mode::Test ), 'A mock names an error in PayPal\'s capitals.' );
		$this->assertNull( PaymentTokens::read( 'vault:8kk8451t;mock=', Mode::Test ) );
		$this->assertNull( PaymentTokens::read( 'card;mock=INSTRUMENT_DECLINED', Mode::Test ), 'A mock follows a vaulted card only.' );
	}

	/**
	 * Tests that a card number in place of a vault id is no token.
	 *
	 * @since 0.2.0
	 */
	public function test_a_card_number_is_no_vault_id(): void {
		$this->assertNull( PaymentTokens::read( 'vault:4111111111111111', Mode::Test ) );
		$this->assertNull( PaymentTokens::read( 'vault:4868719166101368', Mode::Test ) );
		$this->assertNull( PaymentTokens::read( 'vault:378282246310005', Mode::Test ) );
	}

	/**
	 * Tests what is no token at all.
	 *
	 * @since 0.2.0
	 */
	public function test_anything_else_is_no_token(): void {
		foreach ( array( '', 'PAYPAL', 'Card', 'pm_card_visa', 'vault:', 'vault:a/b', 'vault:8kk8451t;x=1', 'vault:8kk 8451t', ' vault:8kk8451t', 'vault:' . str_repeat( 'a', 65 ) ) as $token ) {
			$this->assertNull( PaymentTokens::read( $token, Mode::Test ), "`{$token}` is no token." );
		}
	}

	/**
	 * Reads a token as kind, vault id and mock.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $token The token.
	 * @param Mode        $mode  Optional. The mode. Default test.
	 * @return array{string, string|null, string|null}|null What it asks for.
	 */
	private static function read( ?string $token, Mode $mode = Mode::Test ): ?array {
		$read = PaymentTokens::read( $token, $mode );

		return null === $read ? null : array( $read->kind, $read->vaultId, $read->mock );
	}
}
