<?php
/**
 * Tests which of an order's authorizations the gateway acts on
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\GatewayForPayPal\Tests\Support\Fixtures;
use SEOCart\GatewayForPayPal\Tests\Support\GatewayTestCase;

/**
 * An order may list more than one authorization, such as one the merchant made in PayPal's
 * dashboard after the first expired. The gateway acts on the one that is not voided, expired or
 * denied, wherever PayPal lists it: the query answers it, the capture and the void are sent to it,
 * and the capture refunded is the one whose `up` link names it. With none live, the newest by its
 * creation time decides the state. With more than one live, the gateway chooses none: no answer, the
 * count logged, no id.
 *
 * Planted violation, shown red and removed: the first authorization listed taken, whatever its
 * state: the query, capture and void test fails.
 *
 * @since 0.2.0
 */
final class OrderAuthorizationTest extends GatewayTestCase {

	/**
	 * An authorization that expired before the live one was made.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EXPIRED = '1EX52740GH8820413';

	/**
	 * A second live authorization.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const OTHER = '6RV48297TS1640522';

	/**
	 * The capture made under the expired authorization while it held money.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EARLIER_CAPTURE = '8MC58520KX7463920';

	/**
	 * Tests the live authorization listed after an expired one: the query answers it, the capture and the void are sent to it.
	 *
	 * @since 0.2.0
	 */
	public function test_query_capture_and_void_act_on_the_live_authorization_wherever_it_is_listed(): void {
		$expired = Fixtures::authorization( self::EXPIRED, 'EXPIRED', '2026-10-01T10:00:00Z' );
		$live    = Fixtures::authorization( Fixtures::AUTHORIZATION_ID, 'CREATED', '2026-11-01T10:00:00Z' );
		$paypal  = $this->context->paypal;

		foreach ( array( array( $expired, $live ), array( $live, $expired ) ) as $listed ) {
			$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithAuthorizations( $listed ) );

			$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
		}

		$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithAuthorizations( array( $expired, $live ) ) );
		$paypal->answer( 'POST', self::authorizationPath( Fixtures::AUTHORIZATION_ID, 'capture' ), 201, Fixtures::text( 'capture' ) );
		$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithAuthorizations( array( $expired, $live ) ) );
		$paypal->answer( 'POST', self::authorizationPath( Fixtures::AUTHORIZATION_ID, 'void' ), 200, Fixtures::text( 'authorization-voided' ) );

		$this->assertResult( $this->gateway->capture( new CaptureRequest( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, self::money( 2000 ), Mode::Test ) ), Operation::Capture, Outcome::Approved, Fixtures::CAPTURE_ID );
		$this->assertResult( $this->gateway->void( self::voidRequest() ), Operation::Void, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
		$this->assertTrue( $paypal->isDrained(), 'Nothing was sent to the expired authorization.' );
	}

	/**
	 * Tests an order with no live authorization: the newest decides, wherever it is listed.
	 *
	 * @since 0.2.0
	 */
	public function test_with_no_live_authorization_the_newest_decides(): void {
		$older  = '2026-10-01T10:00:00Z';
		$newer  = '2026-11-01T10:00:00Z';
		$paypal = $this->context->paypal;

		$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithAuthorizations( array( Fixtures::authorization( Fixtures::AUTHORIZATION_ID, 'EXPIRED', $newer ), Fixtures::authorization( self::EXPIRED, 'DENIED', $older ) ) ) );
		$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithAuthorizations( array( Fixtures::authorization( self::EXPIRED, 'EXPIRED', $older ), Fixtures::authorization( Fixtures::AUTHORIZATION_ID, 'DENIED', $newer ) ) ) );
		$paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithAuthorizations( array( Fixtures::authorization( self::EXPIRED, 'EXPIRED', $older ), Fixtures::authorization( Fixtures::AUTHORIZATION_ID, 'VOIDED', $newer ) ) ) );

		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Declined, Fixtures::AUTHORIZATION_ID, 'expired' );
		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Declined, Fixtures::AUTHORIZATION_ID, 'declined' );
		$this->assertResult( $this->gateway->void( self::voidRequest() ), Operation::Void, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
		$this->assertSame( array_fill( 0, 3, 'GET ' . self::orderPath() ), $paypal->sent(), 'Nothing holds money, so nothing is voided.' );
	}

	/**
	 * Tests an order with two live authorizations: no answer from the query, the capture or the void, nothing sent but the reads, and the count logged, never an id.
	 *
	 * @since 0.2.0
	 */
	public function test_more_than_one_live_authorization_is_no_answer_and_names_none(): void {
		$order = Fixtures::orderWithAuthorizations(
			array(
				Fixtures::authorization( Fixtures::AUTHORIZATION_ID, 'CREATED', '2026-11-01T10:00:00Z' ),
				Fixtures::authorization( self::OTHER, 'CREATED', '2026-11-02T10:00:00Z' ),
			)
		);

		for ( $read = 0; $read < 3; $read++ ) {
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, $order );
		}

		$this->unavailable( fn() => $this->gateway->query( self::query() ) );
		$this->unavailable( fn() => $this->gateway->capture( new CaptureRequest( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, self::money( 2000 ), Mode::Test ) ) );
		$this->unavailable( fn() => $this->gateway->void( self::voidRequest() ) );

		$this->assertSame( array_fill( 0, 3, 'GET ' . self::orderPath() ), $this->context->paypal->sent() );
		$this->assertSame( array( 'query', 2 ), array( $this->context->log->contextOf( 'paypal.authorization_unresolved' )['operation'] ?? null, $this->context->log->contextOf( 'paypal.authorization_unresolved' )['authorizations'] ?? null ) );
		$this->assertStringNotContainsString( self::OTHER, $this->written() );
		$this->assertStringNotContainsString( Fixtures::AUTHORIZATION_ID, $this->written() );
	}

	/**
	 * Tests the capture refunded when the expired authorization had a capture too: the one whose `up` link names the live authorization.
	 *
	 * @since 0.2.0
	 */
	public function test_the_capture_refunded_is_the_one_under_the_live_authorization(): void {
		$order = Fixtures::orderWithAuthorizations(
			array(
				Fixtures::authorization( self::EXPIRED, 'EXPIRED', '2026-10-01T10:00:00Z' ),
				Fixtures::authorization( Fixtures::AUTHORIZATION_ID, 'CAPTURED', '2026-11-01T10:00:00Z' ),
			),
			array(
				Fixtures::captureOf( self::EARLIER_CAPTURE, self::EXPIRED ),
				Fixtures::captureOf( Fixtures::CAPTURE_ID ),
			)
		);

		$this->context->paypal->answer( 'GET', self::orderPath(), 200, $order );
		$this->context->paypal->answer( 'POST', 'v2/payments/captures/' . Fixtures::CAPTURE_ID . '/refund', 201, Fixtures::text( 'refund' ) );

		$result = $this->gateway->refund( new GatewayRefund( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, self::money( 1000 ), Fixtures::REFUND_UUID, Mode::Test ) );

		$this->assertSame( array( Operation::Refund, Outcome::Approved, Fixtures::REFUND_ID ), array( $result->operation, $result->outcome, $result->providerObjectId ) );
		$this->assertTrue( $this->context->paypal->isDrained(), 'The capture of the expired authorization was not refunded.' );
	}

	/**
	 * Returns the path of a call on an authorization.
	 *
	 * @since 0.2.0
	 *
	 * @param string $authorizationId The authorization.
	 * @param string $call            `capture` or `void`.
	 * @return string The path.
	 */
	private static function authorizationPath( string $authorizationId, string $call ): string {
		return 'v2/payments/authorizations/' . $authorizationId . '/' . $call;
	}

	/**
	 * Builds the query of the fixtures' order.
	 *
	 * @since 0.2.0
	 *
	 * @return PaymentQuery The query.
	 */
	private static function query(): PaymentQuery {
		return new PaymentQuery( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, self::money( 2000 ), Mode::Test );
	}

	/**
	 * Builds the void of the fixtures' order.
	 *
	 * @since 0.2.0
	 *
	 * @return VoidRequest The request.
	 */
	private static function voidRequest(): VoidRequest {
		return new VoidRequest( Fixtures::INTENT_UUID, Fixtures::ORDER_ID, self::money( 2000 ), Mode::Test, 'customer_request' );
	}

	/**
	 * Asserts a result of the fixtures' intent and order, for 20.00.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult|null $result    The result.
	 * @param Operation          $operation The operation.
	 * @param Outcome            $outcome   The outcome.
	 * @param string|null        $objectId  The object.
	 * @param string|null        $code      Optional. The decline code. Default none.
	 */
	private function assertResult( ?GatewayResult $result, Operation $operation, Outcome $outcome, ?string $objectId, ?string $code = null ): void {
		$this->assertNotNull( $result );
		$this->assertSame(
			array( $operation, $outcome, Fixtures::INTENT_UUID, 2000, $objectId, Fixtures::ORDER_ID, $code ),
			array( $result->operation, $result->outcome, $result->intentUuid, $result->amount->minorUnits(), $result->providerObjectId, $result->providerIntentId, $result->errorCode )
		);
	}
}
