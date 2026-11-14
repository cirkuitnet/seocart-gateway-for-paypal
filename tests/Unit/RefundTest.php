<?php
/**
 * Tests refund() and queryRefund(): the refund of the order's capture, tagged with its uuid, and found again by it
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\GatewayForPayPal\Tests\Support\Fixtures;
use SEOCart\GatewayForPayPal\Tests\Support\GatewayTestCase;

/**
 * A refund reads the order for the one capture of its authorization, then refunds the amount asked,
 * always sent, with the refund's uuid as its `custom_id` and its PayPal-Request-Id; with no such
 * capture, or several, it refunds nothing. A refund PayPal completed or is giving is approved, one
 * that failed or was cancelled declined, and PayPal's refusals declined with their issue.
 * queryRefund() finds the refund among those PayPal lists on the order by its `custom_id`; an
 * order with no such refund is no answer, never a refund made up, and never `not_found`, as PayPal
 * cannot be searched by the refund's uuid.
 *
 * Planted violations, each shown red and removed:
 * - the refund sent without `custom_id`: the body test fails;
 * - queryRefund() answering the order's first refund whatever its uuid: the never-made test fails;
 * - the first of several captures refunded: the one-capture test fails.
 *
 * @since 0.2.0
 */
final class RefundTest extends GatewayTestCase {

	/**
	 * A capture the merchant made in PayPal's dashboard.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DASHBOARD_CAPTURE = '8MC58520KX7463920';

	/**
	 * An authorization other than the order's first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const OTHER_AUTHORIZATION = '6RV48297TS1640522';

	/**
	 * Tests a refund: the read, the request byte for byte, and the approval named by the refund.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_reads_the_capture_then_refunds_the_amount_tagged_with_its_uuid(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderCaptured() );
		$this->context->paypal->answer( 'POST', self::refundPath(), 201, Fixtures::text( 'refund' ) );

		$result = $this->gateway->refund( self::refund() );

		$this->assertSame( array( 'GET ' . self::orderPath(), 'POST ' . self::refundPath() ), $this->context->paypal->sent() );
		$this->assertSame( '{"amount":{"currency_code":"USD","value":"10.00"},"custom_id":"' . Fixtures::REFUND_UUID . '"}', $this->context->paypal->last()->body );
		$this->assertSame( Fixtures::REFUND_UUID, $this->headers()['PayPal-Request-Id'] );
		$this->assertResult( $result, Outcome::Approved, Fixtures::REFUND_ID );
	}

	/**
	 * Tests each refund status, and PayPal's refusals.
	 *
	 * @since 0.2.0
	 */
	public function test_each_status_and_refusal_maps_to_one_result(): void {
		$answers = array(
			array(
				201,
				Fixtures::with(
					'refund',
					array(
						'status'         => 'PENDING',
						'status_details' => array( 'reason' => 'ECHECK' ),
					)
				),
				Outcome::Approved,
				Fixtures::REFUND_ID,
				null,
			),
			array( 201, Fixtures::with( 'refund', array( 'status' => 'FAILED' ) ), Outcome::Declined, Fixtures::REFUND_ID, 'declined' ),
			array( 201, Fixtures::with( 'refund', array( 'status' => 'CANCELLED' ) ), Outcome::Declined, Fixtures::REFUND_ID, 'declined' ),
			array( 422, Fixtures::issue( 'CAPTURE_FULLY_REFUNDED' ), Outcome::Declined, Fixtures::DEBUG_ID, 'capture_fully_refunded' ),
			array( 422, Fixtures::issue( 'REFUND_AMOUNT_EXCEEDED' ), Outcome::Declined, Fixtures::DEBUG_ID, 'refund_amount_exceeded' ),
			array( 422, Fixtures::issue( 'REFUND_TIME_LIMIT_EXCEEDED' ), Outcome::Declined, Fixtures::DEBUG_ID, 'refund_time_limit_exceeded' ),
			array( 422, Fixtures::issue( 'MAX_NUMBER_OF_REFUNDS_EXCEEDED' ), Outcome::Declined, Fixtures::DEBUG_ID, 'max_number_of_refunds_exceeded' ),
		);

		foreach ( $answers as list( $status, $body, $outcome, $objectId, $code ) ) {
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderCaptured() );
			$this->context->paypal->answer( 'POST', self::refundPath(), $status, $body );

			$this->assertResult( $this->gateway->refund( self::refund() ), $outcome, $objectId, $code );
		}
	}

	/**
	 * Tests a refund of an order without a capture, without an order, or in a currency not declared.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_with_nothing_to_refund_is_unavailable(): void {
		$this->unavailable( fn() => $this->gateway->refund( new GatewayRefund( Fixtures::INTENT_UUID, null, self::money( 1000 ), Fixtures::REFUND_UUID, Mode::Test ) ) );
		$this->unavailable( fn() => $this->gateway->refund( new GatewayRefund( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, self::money( 1000, 'JPY' ), Fixtures::REFUND_UUID, Mode::Test ) ) );
		$this->assertSame( array(), $this->context->paypal->requests );

		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-authorized' ) );
		$this->unavailable( fn() => $this->gateway->refund( self::refund() ) );
		$this->assertSame( array( 'GET ' . self::orderPath() ), $this->context->paypal->sent() );
	}

	/**
	 * Tests the capture refunded: the one made under the order's authorization, whatever else the order holds; none or more than one is no answer, nothing sent, the count logged and no capture named.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_refunds_only_the_one_capture_of_the_orders_authorization(): void {
		$dashboard = Fixtures::captureOf( self::DASHBOARD_CAPTURE );
		$elsewhere = Fixtures::captureOf( self::DASHBOARD_CAPTURE, self::OTHER_AUTHORIZATION );
		$paypal    = $this->context->paypal;

		$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithCaptures( $elsewhere, Fixtures::captureOf( Fixtures::CAPTURE_ID ) ) );
		$paypal->answer( 'POST', self::refundPath(), 201, Fixtures::text( 'refund' ) );

		$this->assertResult( $this->gateway->refund( self::refund() ), Outcome::Approved, Fixtures::REFUND_ID );

		$counts = array();

		foreach ( array( Fixtures::orderWithCaptures( Fixtures::captureOf( Fixtures::CAPTURE_ID ), $dashboard ), Fixtures::orderWithCaptures( $elsewhere ), Fixtures::text( 'order-authorized' ) ) as $order ) {
			$this->context->log->lines = array();
			$paypal->answer( 'GET', self::orderPath(), 200, $order );

			$this->unavailable( fn() => $this->gateway->refund( self::refund() ) );
			$counts[] = $this->context->log->contextOf( 'paypal.capture_unresolved' )['captures'] ?? null;
		}

		$this->assertSame( array( 2, 0, 0 ), $counts );
		$this->assertSame( array( 'GET ' . self::orderPath(), 'POST ' . self::refundPath(), 'GET ' . self::orderPath(), 'GET ' . self::orderPath(), 'GET ' . self::orderPath() ), $paypal->sent(), 'Nothing is refunded when the gateway would have to choose.' );
		$this->assertStringNotContainsString( self::DASHBOARD_CAPTURE, $this->written() );

		$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithCaptures( $elsewhere ) );

		$this->assertNull( $this->gateway->queryRefund( self::refund() ), 'No capture of the order\'s authorization, so no refund of it can be said to be missing.' );
	}

	/**
	 * Tests finding a refund by its uuid among the order's.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_is_found_on_the_order_by_its_uuid(): void {
		$other              = Fixtures::object( 'refund' );
		$other['id']        = '9ZZ08902781691499';
		$other['custom_id'] = '0199c0de-0000-7000-8000-00000000b999';

		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderCaptured( array( $other, Fixtures::object( 'refund' ) ) ) );

		$this->assertResult( $this->gateway->queryRefund( self::refund() ), Outcome::Approved, Fixtures::REFUND_ID );
		$this->assertSame( array( 'GET ' . self::orderPath() ), $this->context->paypal->sent() );
	}

	/**
	 * Tests a refund never made: no answer, never a refund made up, and never `not_found`, as PayPal cannot be searched by the refund's uuid.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_never_made_is_no_answer_never_made_up(): void {
		$other              = Fixtures::object( 'refund' );
		$other['custom_id'] = '0199c0de-0000-7000-8000-00000000b999';

		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderCaptured( array( $other ) ) );
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderCaptured() );
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-authorized' ) );
		$this->context->paypal->answer( 'GET', self::orderPath(), 404, Fixtures::text( 'error-404' ) );

		$this->assertNull( $this->gateway->queryRefund( self::refund() ), 'Another refund is not this one.' );
		$this->assertNull( $this->gateway->queryRefund( self::refund() ), 'A capture without refunds: PayPal made none, but the gateway does not say so.' );
		$this->assertNull( $this->gateway->queryRefund( self::refund() ), 'No capture, so no refund.' );
		$this->assertNull( $this->gateway->queryRefund( self::refund() ), 'A refused read is no answer.' );
		$this->assertNull( $this->gateway->queryRefund( new GatewayRefund( Fixtures::INTENT_UUID, null, self::money( 1000 ), Fixtures::REFUND_UUID, Mode::Test ) ) );
		$this->assertTrue( $this->context->paypal->isDrained() );
	}

	/**
	 * Tests a refund PayPal lists that is not named by one of its ids: no answer, before any is compared.
	 *
	 * @since 0.2.0
	 */
	public function test_a_listed_refund_without_a_paypal_id_is_unavailable(): void {
		$odd       = Fixtures::object( 'refund' );
		$odd['id'] = '4111111111111111';

		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderCaptured( array( Fixtures::object( 'refund' ), $odd ) ) );

		$this->unavailable( fn() => $this->gateway->queryRefund( self::refund() ) );
	}

	/**
	 * Returns the path of the fixtures' capture's refund.
	 *
	 * @since 0.2.0
	 *
	 * @return string The path.
	 */
	private static function refundPath(): string {
		return 'v2/payments/captures/' . Fixtures::CAPTURE_ID . '/refund';
	}

	/**
	 * Builds the fixtures' refund.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayRefund The refund.
	 */
	private static function refund(): GatewayRefund {
		return new GatewayRefund( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, self::money( 1000 ), Fixtures::REFUND_UUID, Mode::Test );
	}

	/**
	 * Asserts a refund's result.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult|null $result  The result.
	 * @param Outcome            $outcome The outcome.
	 * @param string|null        $objectId The object.
	 * @param string|null        $code    Optional. The decline code. Default none.
	 */
	private function assertResult( ?GatewayResult $result, Outcome $outcome, ?string $objectId, ?string $code = null ): void {
		$this->assertNotNull( $result );
		$this->assertSame(
			array( Operation::Refund, $outcome, Fixtures::INTENT_UUID, 1000, $objectId, Fixtures::ORDER_ID, $code ),
			array( $result->operation, $result->outcome, $result->intentUuid, $result->amount->minorUnits(), $result->providerObjectId, $result->providerIntentId, $result->errorCode )
		);
	}
}
