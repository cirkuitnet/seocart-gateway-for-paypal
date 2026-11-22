<?php
/**
 * Tests what the plugin states of its live workflow, and what its own classes add to SEOCart's for a run
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForPayPal\Tests\Support\Live\PayPalRun;
use SEOCart\Tests\Conformance\Payment\Live\LiveRun;

/**
 * The guard built from tests/live.php refuses an argument shaped like a credential (48 letters and digits or more, as PayPal's client ids, secrets and access tokens are), an authorization header, an address with a query, or a card number, and blanks the same shapes out of any text; PayPal's ids, of 17 characters or of an event's 38, pass, as does a run of digits that is no card, and an id whose digits read as a card is withheld when shown. The run list keeps one order per line with the store uuid it was made for, and reads back only lines of that shape; the card script's line is read as its word and the issue PayPal named; and a uuid the live tests make is a version 4 uuid.
 *
 * Planted violation, shown red and removed: the declaration's credential shape taken out: the
 * credential is printed, and the shapes' test fails.
 *
 * @since 0.2.0
 */
final class LiveDeclarationTest extends TestCase {

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
			$this->assertSame( $shape, PayPalRun::guard()->shapeOf( $value ) );

			try {
				PayPalRun::guard()->say( 'The value %s.', $value );
				$this->fail( "A value shaped like {$shape} was printed." );
			} catch ( \LogicException $refused ) {
				$this->assertStringNotContainsString( $value, $refused->getMessage() );
			}

			$this->assertSame( 'x [redacted: ' . $shape . '] y', PayPalRun::guard()->redact( 'x ' . $value . ' y' ) );
		}
	}

	/**
	 * Tests PayPal's ids and words: printed as they are.
	 *
	 * @since 0.2.0
	 */
	public function test_paypals_ids_and_words_are_printed(): void {
		foreach ( array( '5O190127TN364715T', '0C527818AF292391J', 'WH-2WR32451HC0233532-67976317FL4543714', '0199c0de-0000-7000-8000-00000000a001', '7390d37aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'PAYER_CANNOT_PAY', '1234567890123' ) as $value ) {
			$this->assertNull( PayPalRun::guard()->shapeOf( $value ), $value . ' is printed.' );
			$this->assertSame( 'The value ' . $value . '.', PayPalRun::guard()->say( 'The value %s.', $value ) );
		}
	}

	/**
	 * Tests an id whose digits read as a card: withheld when shown.
	 *
	 * @since 0.2.0
	 */
	public function test_an_id_whose_digits_read_as_a_card_is_withheld(): void {
		$this->assertSame( '(withheld: shaped like a card number)', PayPalRun::guard()->shown( '0VF52814937998046' ) );
		$this->assertSame( '5O190127TN364715T', PayPalRun::guard()->shown( '5O190127TN364715T' ) );
	}

	/**
	 * Tests the run list: each order with its uuid, written whole even when its digits read as a card number, and nothing of another shape written or read back.
	 *
	 * @since 0.2.0
	 */
	public function test_a_run_list_is_written_and_read_back(): void {
		$file = tempnam( sys_get_temp_dir(), 'live-run-list-' );

		$this->assertIsString( $file );
		putenv( LiveRun::RUN_LIST . '=' . $file );

		try {
			PayPalRun::created( '5O190127TN364715T', '0199c0de-0000-7000-8000-00000000a001' );
			file_put_contents( $file, "not an order\n4111111111111111 0199c0de-0000-7000-8000-00000000a001\n", FILE_APPEND );
			PayPalRun::created( '21P35652VJ9135157', '0199c0de-0000-7000-8000-00000000a002' );
			// An id of PayPal's whose digits pass a card number's checksum, as the sandbox gave one: written whole, so the cleanup finds it.
			PayPalRun::created( '0TP3831016068574K', '0199c0de-0000-7000-8000-00000000a003' );

			$this->assertSame(
				array(
					'5O190127TN364715T' => '0199c0de-0000-7000-8000-00000000a001',
					'21P35652VJ9135157' => '0199c0de-0000-7000-8000-00000000a002',
					'0TP3831016068574K' => '0199c0de-0000-7000-8000-00000000a003',
				),
				PayPalRun::orders( (string) file_get_contents( $file ) )
			);

			foreach ( array( array( '41111111111111111', '0199c0de-0000-7000-8000-00000000a004' ), array( '5O190127TN364715T', 'not-a-uuid' ) ) as list( $orderId, $uuid ) ) {
				try {
					PayPalRun::created( $orderId, $uuid );
					$this->fail( 'The run list took what is not an order\'s id and a store uuid.' );
				} catch ( \LogicException $refused ) {
					$this->assertStringContainsString( 'nothing else', $refused->getMessage() );
				}
			}
		} finally {
			putenv( LiveRun::RUN_LIST );
			unlink( $file );
		}
	}

	/**
	 * Tests the card script's line: its word returned, and the issue PayPal named kept beside it, never anything else.
	 *
	 * @since 0.2.0
	 */
	public function test_the_card_scripts_line_is_read_as_its_word_and_the_issue_paypal_named(): void {
		$answers = array(
			array( 'refused INSTRUMENT_DECLINED', 'refused', 'refused INSTRUMENT_DECLINED' ),
			array( 'challenge-passed', 'challenge-passed', 'challenge-passed' ),
			array( 'refused token=abc', 'refused', 'refused' ),
			array( 'not-typed cvv', 'not-typed', 'not-typed cvv' ),
			array( 'not-typed 4868719166101368', 'not-typed', 'not-typed' ),
			array( 'refused cvv', 'refused', 'refused' ),
			array( '4868719196829038', 'Error', 'Error' ),
			array( 'TypeError', 'TypeError', 'TypeError' ),
		);

		foreach ( $answers as list( $line, $word, $kept ) ) {
			$this->assertSame( array( $word, $kept, false ), array( PayPalRun::cardAnswer( $line ), PayPalRun::lastCard(), PayPalRun::mayTryAgain() ), $line );
		}
	}

	/**
	 * Tests the one more try: only a refusal before any challenge that the card script lets be tried again, never anything after a challenge.
	 *
	 * @since 0.2.0
	 */
	public function test_only_a_refusal_before_any_challenge_may_be_tried_once_more(): void {
		$answers = array(
			array( 'refused INVALID_CVV again', 'refused', 'refused INVALID_CVV', true ),
			array( 'refused again', 'refused', 'refused', true ),
			array( 'refused INVALID_CVV', 'refused', 'refused INVALID_CVV', false ),
			array( 'challenge-failed INSTRUMENT_DECLINED again', 'challenge-failed', 'challenge-failed INSTRUMENT_DECLINED', false ),
			array( 'not-typed cvv again', 'not-typed', 'not-typed cvv', false ),
		);

		foreach ( $answers as list( $line, $word, $kept, $again ) ) {
			$this->assertSame( array( $word, $kept, $again ), array( PayPalRun::cardAnswer( $line ), PayPalRun::lastCard(), PayPalRun::mayTryAgain() ), $line );
		}
	}

	/**
	 * Tests the uuids the live tests make.
	 *
	 * @since 0.2.0
	 */
	public function test_a_uuid_is_a_version_4_uuid(): void {
		$uuid = PayPalRun::uuid();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid );
		$this->assertNotSame( $uuid, PayPalRun::uuid() );
	}
}
