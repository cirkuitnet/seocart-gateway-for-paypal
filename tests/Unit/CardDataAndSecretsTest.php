<?php
/**
 * Tests that no card data, credential or token reaches a log line or an exception
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\GatewayForPayPal\Tests\Support\FakeContext;
use SEOCart\GatewayForPayPal\Tests\Support\Fixtures;
use SEOCart\GatewayForPayPal\Tests\Support\GatewayTestCase;
use SEOCart\GatewayForPayPal\Tests\Support\LogScan;

/**
 * Every operation runs against answers in which PayPal planted what no log may hold: a card's
 * `number`, its `last_digits` and `security_code`, the access token and the client secret in fields
 * the gateway does not read, and in every error message. Every answer is refused, declined or
 * approved, and nothing of it reaches a line the gateway logs or a message it throws (the scan
 * GatewayTestCase runs after every test, and this test's own). The scan itself is shown to find each.
 *
 * Planted violations, each shown red and removed:
 * - the access token logged on the token-refused line: this test's scan fails;
 * - an error's `message` logged raw on the request-refused line: this test's scan fails.
 *
 * @since 0.2.0
 */
final class CardDataAndSecretsTest extends GatewayTestCase {

	/**
	 * A published test card number, planted in PayPal's answers.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CARD = '4111111111111111';

	/**
	 * Tests every operation over planted answers: nothing planted is written.
	 *
	 * @since 0.2.0
	 */
	public function test_nothing_planted_in_an_answer_reaches_a_log_line_or_an_exception(): void {
		$card    = array(
			'number'        => self::CARD,
			'last_digits'   => '1111',
			'security_code' => '123',
			'leak'          => Fixtures::ACCESS_TOKEN . ' ' . FakeContext::TEST_SECRET,
		);
		$planted = static fn( string $fixture ): string => Fixtures::with( $fixture, array( 'payment_source' => array( 'card' => $card ) ) );
		$error   = Fixtures::with( 'error-422', array( 'message' => 'Card ' . self::CARD . ' refused for ' . Fixtures::ACCESS_TOKEN ) );
		$paypal  = $this->context->paypal;

		$paypal->answer( 'POST', 'v1/oauth2/token', 200, Fixtures::with( 'token', array( 'scope' => FakeContext::TEST_SECRET ) ) );
		$paypal->answer( 'POST', 'v2/checkout/orders', 201, $planted( 'order-authorized' ) );
		$paypal->answer( 'POST', 'v2/checkout/orders', 422, $error );
		$paypal->answer( 'POST', 'v2/checkout/orders', 400, Fixtures::with( 'error-400', array( 'message' => FakeContext::TEST_SECRET ) ) );
		$paypal->answer( 'GET', self::orderPath(), 200, $planted( 'order-approved' ) );
		$paypal->answer( 'POST', self::orderPath( '/authorize' ), 401, Fixtures::text( 'error-401' ) );
		$paypal->answer( 'POST', self::orderPath( '/authorize' ), 422, $error );
		$paypal->answer( 'GET', self::orderPath(), 200, $planted( 'order-authorized' ) );
		$paypal->answer( 'POST', 'v2/payments/authorizations/' . Fixtures::AUTHORIZATION_ID . '/capture', 500, Fixtures::with( 'error-500', array( 'message' => self::CARD ) ) );
		$paypal->answer( 'GET', self::orderPath(), 200, $planted( 'order-authorized' ) );
		$paypal->answer( 'POST', 'v2/payments/authorizations/' . Fixtures::AUTHORIZATION_ID . '/void', 400, $error );
		$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderCaptured() );
		$paypal->answer( 'POST', 'v2/payments/captures/' . Fixtures::CAPTURE_ID . '/refund', 422, $error );
		$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::with( 'order-authorized', array( 'purchase_units' => array( array( 'custom_id' => self::CARD ) ) ) ) );

		$money = self::money( 2000 );

		$this->gateway->authorize( new PaymentRequest( Fixtures::INTENT_UUID, $money, 'vault:' . Fixtures::VAULT_ID, Mode::Test, '0199c0de-0000-7000-8000-00000000d001', '1001', null ) );
		$this->gateway->authorize( new PaymentRequest( Fixtures::INTENT_UUID, $money, 'vault:' . Fixtures::VAULT_ID, Mode::Test, '0199c0de-0000-7000-8000-00000000d001', '1001', null ) );
		$this->gateway->authorize( new PaymentRequest( Fixtures::INTENT_UUID, $money, 'card', Mode::Test, '0199c0de-0000-7000-8000-00000000d001', '1001', null ) );
		$this->gateway->query( new PaymentQuery( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, $money, Mode::Test ) );
		$this->unavailable( fn() => $this->gateway->capture( new CaptureRequest( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, $money, Mode::Test ) ) );
		$this->unavailable( fn() => $this->gateway->void( new VoidRequest( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, $money, Mode::Test, 'customer_request' ) ) );
		$this->gateway->refund( new GatewayRefund( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, self::money( 1000 ), Fixtures::REFUND_UUID, Mode::Test ) );
		$this->unavailable( fn() => $this->gateway->query( new PaymentQuery( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, $money, Mode::Test ) ) );

		$this->assertTrue( $paypal->isDrained(), 'Every planted answer was served.' );
		$this->assertNotSame( array(), $this->context->log->lines, 'The operations logged, so the scan read something.' );
		$this->assertSame( array(), LogScan::findings( $this->written(), array( Fixtures::ACCESS_TOKEN, FakeContext::TEST_SECRET, FakeContext::LIVE_SECRET ), $paypal->served ) );
	}

	/**
	 * Tests that the scan finds each thing it looks for, so a clean scan means something.
	 *
	 * @since 0.2.0
	 */
	public function test_the_scan_finds_each_thing_it_looks_for(): void {
		$body = Fixtures::text( 'order-authorized' );

		$this->assertNotSame( array(), LogScan::findings( 'card ' . self::CARD, array(), array() ) );
		$this->assertNotSame( array(), LogScan::findings( '{"last_digits":"1111"}', array(), array() ) );
		$this->assertNotSame( array(), LogScan::findings( '{"number":"x"}', array(), array() ) );
		$this->assertNotSame( array(), LogScan::findings( 'Bearer x', array(), array() ) );
		$this->assertNotSame( array(), LogScan::findings( 'token ' . Fixtures::ACCESS_TOKEN, array( Fixtures::ACCESS_TOKEN ), array() ) );
		$this->assertNotSame( array(), LogScan::findings( base64_encode( FakeContext::TEST_SECRET ), array( FakeContext::TEST_SECRET ), array() ) );
		$this->assertNotSame( array(), LogScan::findings( substr( $body, 100, 60 ), array(), array( $body ) ) );
		$this->assertSame( array(), LogScan::findings( 'intent 0199c0de-0000-7000-8000-00000000a001 order ' . Fixtures::ORDER_ID, array( FakeContext::TEST_SECRET ), array() ) );
	}
}
