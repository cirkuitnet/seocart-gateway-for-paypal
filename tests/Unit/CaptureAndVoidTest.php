<?php
/**
 * Tests capture() and void(): the order read first, and what each answer means
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\GatewayForPayPal\Tests\Support\Fixtures;
use SEOCart\GatewayForPayPal\Tests\Support\GatewayTestCase;

/**
 * A capture reads the order for its authorization, then captures the amount asked, always sent,
 * as the final capture, under `{uuid}:capture`; a capture PayPal is still reviewing is no answer, and
 * an authorization captured already answers its capture. A void reads the order: an authorization
 * holding money is voided under `{uuid}:void` and the approved void reports the amount asked; one
 * voided or expired already is that void again, nothing sent; an order nothing was authorized on is
 * an approved void named by the order, with nothing sent; a captured one is no answer.
 *
 * Planted violations, each shown red and removed:
 * - `final_capture` false: the capture body test fails;
 * - the void of an unauthorized order sending a request: the unauthorized void test fails;
 * - the void reporting the authorization's amount: the partial void amount test fails.
 *
 * @since 0.2.0
 */
final class CaptureAndVoidTest extends GatewayTestCase {

	/**
	 * Tests a capture: the read, the request byte for byte, and the approval named by the capture.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_reads_the_order_then_captures_the_amount_as_final(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-authorized' ) );
		$this->context->paypal->answer( 'POST', self::capturePath(), 201, Fixtures::with( 'capture', array( 'amount' => array( 'value' => '19.99' ) ) ) );

		$result = $this->gateway->capture( self::captureRequest( 1999 ) );

		$this->assertSame( array( 'GET ' . self::orderPath(), 'POST ' . self::capturePath() ), $this->context->paypal->sent() );
		$this->assertSame( '{"amount":{"currency_code":"USD","value":"19.99"},"final_capture":true}', $this->context->paypal->last()->body );
		$this->assertSame( Fixtures::INTENT_UUID . ':capture', $this->headers()['PayPal-Request-Id'] );
		$this->assertResult( $result, Operation::Capture, Outcome::Approved, 1999, Fixtures::CAPTURE_ID );
	}

	/**
	 * Tests a capture PayPal declined, one it is reviewing, and one captured already.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_declined_pending_or_done_already(): void {
		$paypal = $this->context->paypal;
		$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-authorized' ) );
		$paypal->answer( 'POST', self::capturePath(), 201, Fixtures::with( 'capture', array( 'status' => 'DECLINED' ) ) );
		$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-authorized' ) );
		$paypal->answer(
			'POST',
			self::capturePath(),
			201,
			Fixtures::with(
				'capture',
				array(
					'status'         => 'PENDING',
					'status_details' => array( 'reason' => 'PENDING_REVIEW' ),
				)
			)
		);
		$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-authorized' ) );
		$paypal->answer( 'POST', self::capturePath(), 422, Fixtures::issue( 'AUTHORIZATION_ALREADY_CAPTURED' ) );
		$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderCaptured() );

		$this->assertResult( $this->gateway->capture( self::captureRequest() ), Operation::Capture, Outcome::Declined, 2000, Fixtures::CAPTURE_ID, 'declined' );
		$this->unavailable( fn() => $this->gateway->capture( self::captureRequest() ) );
		$this->assertSame( 'PENDING_REVIEW', $this->context->log->contextOf( 'paypal.capture_pending' )['reason'] ?? null );
		$this->assertResult( $this->gateway->capture( self::captureRequest() ), Operation::Capture, Outcome::Approved, 2000, Fixtures::CAPTURE_ID );
		$this->assertTrue( $paypal->isDrained() );
	}

	/**
	 * Tests a capture asked again: the same key and the same body.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_asked_again_sends_the_same_key(): void {
		for ( $ask = 0; $ask < 2; $ask++ ) {
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-authorized' ) );
			$this->context->paypal->answer( 'POST', self::capturePath(), 201, Fixtures::text( 'capture' ) );
			$this->gateway->capture( self::captureRequest() );
		}

		$posts = array_values( array_filter( $this->context->paypal->apiRequests(), static fn( $request ): bool => 'POST' === $request->method ) );
		$this->assertSame( array( $posts[0]->body, $posts[0]->headers['PayPal-Request-Id'] ), array( $posts[1]->body, $posts[1]->headers['PayPal-Request-Id'] ) );
	}

	/**
	 * Tests a capture of an authorization voided or expired: declined with PayPal's issue.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_of_a_voided_or_expired_authorization_is_declined(): void {
		foreach ( array( 'AUTHORIZATION_VOIDED', 'AUTHORIZATION_EXPIRED' ) as $issue ) {
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-authorized' ) );
			$this->context->paypal->answer( 'POST', self::capturePath(), 422, Fixtures::issue( $issue ) );

			$this->assertResult( $this->gateway->capture( self::captureRequest() ), Operation::Capture, Outcome::Declined, 2000, Fixtures::DEBUG_ID, strtolower( $issue ) );
		}
	}

	/**
	 * Tests a capture without an order, of an order without an authorization, and in a currency not declared.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_with_nothing_to_capture_is_unavailable(): void {
		$this->unavailable( fn() => $this->gateway->capture( new CaptureRequest( Fixtures::INTENT_UUID, null, self::money( 2000 ), Mode::Test ) ) );
		$this->unavailable( fn() => $this->gateway->capture( new CaptureRequest( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, self::money( 2000, 'JPY' ), Mode::Test ) ) );
		$this->assertSame( array(), $this->context->paypal->requests );

		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-approved' ) );
		$this->unavailable( fn() => $this->gateway->capture( self::captureRequest() ) );
		$this->assertSame( array( 'GET ' . self::orderPath() ), $this->context->paypal->sent() );
	}

	/**
	 * Tests a void of an authorization holding money: the request, and the approved void of the amount asked, named by the authorization.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_voids_the_authorization_and_reports_the_amount_asked(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-authorized' ) );
		$this->context->paypal->answer( 'POST', self::voidPath(), 200, Fixtures::text( 'authorization-voided' ) );
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithAuthorization( 'PENDING' ) );
		$this->context->paypal->answer( 'POST', self::voidPath(), 204, '' );

		$this->assertResult( $this->gateway->void( self::voidRequest( 1500 ) ), Operation::Void, Outcome::Approved, 1500, Fixtures::AUTHORIZATION_ID );
		$this->assertSame( array( '{}', Fixtures::INTENT_UUID . ':void' ), array( $this->context->paypal->last()->body, $this->headers()['PayPal-Request-Id'] ) );
		$this->assertResult( $this->gateway->void( self::voidRequest() ), Operation::Void, Outcome::Approved, 2000, Fixtures::AUTHORIZATION_ID );
	}

	/**
	 * Tests a void asked again: an authorization voided or expired already answers that void, nothing sent; PayPal's own "voided already" too.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_done_already_is_that_void_again(): void {
		foreach ( array( 'VOIDED', 'EXPIRED' ) as $status ) {
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithAuthorization( $status ) );

			$this->assertResult( $this->gateway->void( self::voidRequest() ), Operation::Void, Outcome::Approved, 2000, Fixtures::AUTHORIZATION_ID );
		}

		$this->assertSame( array_fill( 0, 2, 'GET ' . self::orderPath() ), $this->context->paypal->sent() );

		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-authorized' ) );
		$this->context->paypal->answer( 'POST', self::voidPath(), 422, Fixtures::issue( 'PREVIOUSLY_VOIDED' ) );

		$this->assertResult( $this->gateway->void( self::voidRequest() ), Operation::Void, Outcome::Approved, 2000, Fixtures::AUTHORIZATION_ID );
	}

	/**
	 * Tests a void of an order nothing was authorized on: an approved void named by the order, and no request but the read.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_of_an_unauthorized_order_sends_nothing_and_names_the_order(): void {
		foreach ( array( 'order-created', 'order-payer-action', 'order-approved' ) as $fixture ) {
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( $fixture ) );

			$this->assertResult( $this->gateway->void( self::voidRequest() ), Operation::Void, Outcome::Approved, 2000, Fixtures::ORDER_ID );
		}

		$this->assertSame( array_fill( 0, 3, 'GET ' . self::orderPath() ), $this->context->paypal->sent(), 'PayPal has no call that cancels an order awaiting the shopper; none is sent.' );
	}

	/**
	 * Tests a void of a captured authorization: no answer, and nothing voided.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_of_a_captured_authorization_is_unavailable(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderCaptured() );
		$this->unavailable( fn() => $this->gateway->void( self::voidRequest() ) );

		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-authorized' ) );
		$this->context->paypal->answer( 'POST', self::voidPath(), 422, Fixtures::issue( 'PREVIOUSLY_CAPTURED' ) );
		$this->unavailable( fn() => $this->gateway->void( self::voidRequest() ) );

		$this->unavailable( fn() => $this->gateway->void( new VoidRequest( Fixtures::INTENT_UUID, null, self::money( 2000 ), Mode::Test, 'customer_request' ) ) );
		$this->assertTrue( $this->context->paypal->isDrained() );
	}

	/**
	 * Returns the path of the fixtures' authorization's capture.
	 *
	 * @since 0.2.0
	 *
	 * @return string The path.
	 */
	private static function capturePath(): string {
		return 'v2/payments/authorizations/' . Fixtures::AUTHORIZATION_ID . '/capture';
	}

	/**
	 * Returns the path of the fixtures' authorization's void.
	 *
	 * @since 0.2.0
	 *
	 * @return string The path.
	 */
	private static function voidPath(): string {
		return 'v2/payments/authorizations/' . Fixtures::AUTHORIZATION_ID . '/void';
	}

	/**
	 * Builds a capture of the fixtures' order.
	 *
	 * @since 0.2.0
	 *
	 * @param int $minor Optional. The amount. Default the whole.
	 * @return CaptureRequest The request.
	 */
	private static function captureRequest( int $minor = 2000 ): CaptureRequest {
		return new CaptureRequest( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, self::money( $minor ), Mode::Test );
	}

	/**
	 * Builds a void of the fixtures' order.
	 *
	 * @since 0.2.0
	 *
	 * @param int $minor Optional. The amount. Default the whole.
	 * @return VoidRequest The request.
	 */
	private static function voidRequest( int $minor = 2000 ): VoidRequest {
		return new VoidRequest( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, self::money( $minor ), Mode::Test, 'action_window_ended' );
	}

	/**
	 * Asserts a result.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result    The result.
	 * @param Operation     $operation The operation.
	 * @param Outcome       $outcome   The outcome.
	 * @param int           $minor     The amount.
	 * @param string|null   $objectId    The object.
	 * @param string|null   $code      Optional. The decline code. Default none.
	 */
	private function assertResult( GatewayResult $result, Operation $operation, Outcome $outcome, int $minor, ?string $objectId, ?string $code = null ): void {
		$this->assertSame(
			array( $operation, $outcome, Fixtures::INTENT_UUID, $minor, $objectId, Fixtures::ORDER_ID, $code ),
			array( $result->operation, $result->outcome, $result->intentUuid, $result->amount->minorUnits(), $result->providerObjectId, $result->providerIntentId, $result->errorCode )
		);
	}
}
