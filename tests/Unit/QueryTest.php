<?php
/**
 * Tests query(): where a payment stands, and the resume that authorizes an approved order
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\GatewayForPayPal\Tests\Support\Fixtures;
use SEOCart\GatewayForPayPal\Tests\Support\GatewayTestCase;

/**
 * The status query reads the order. An order still waiting for the shopper is no answer. An approved order
 * is authorized, once, under `{uuid}:authorize`, after the liability policy: a card whose bank took
 * the liability, a card the bank never checked and a PayPal payment are authorized; a checked card
 * whose liability stayed with the store is declined `liability_shift_not_possible` under `require`,
 * and authorized under `accept`. A completed order answers its capture or its authorization; an
 * authorization voided or expired, or a voided order, is declined `expired`, never an approved void.
 * An order PayPal does not know is declined `not_found`; an intent without an order is no answer.
 *
 * Planted violations, each shown red and removed:
 * - query() authorizing a `CREATED` order: the waiting test fails (a request was sent);
 * - a `VOIDED` authorization answered as an approved void: the expiry test fails;
 * - the liability check skipped: the `require` test fails;
 * - a fresh key for the authorize call: the key test fails.
 *
 * @since 0.2.0
 */
final class QueryTest extends GatewayTestCase {

	/**
	 * Tests an order still waiting for the shopper: no answer, and nothing sent but the read.
	 *
	 * @since 0.2.0
	 */
	public function test_an_order_waiting_for_the_shopper_is_no_answer_and_is_not_authorized(): void {
		foreach ( array( 'CREATED', 'SAVED', 'PAYER_ACTION_REQUIRED' ) as $status ) {
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::with( 'order-created', array( 'status' => $status ) ) );

			$this->assertNull( $this->gateway->query( self::query() ), "{$status} waits." );
		}

		$this->assertSame( array_fill( 0, 3, 'GET ' . self::orderPath() ), $this->context->paypal->sent() );
	}

	/**
	 * Tests the resume: an approved card order whose bank took the liability is authorized under `{uuid}:authorize`.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approved_order_is_authorized_once_under_its_own_key(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-approved' ) );
		$this->context->paypal->answer( 'POST', self::orderPath( '/authorize' ), 201, Fixtures::text( 'order-authorized' ) );

		$result = $this->gateway->query( self::query() );

		$this->assertSame( array( 'GET ' . self::orderPath(), 'POST ' . self::orderPath( '/authorize' ) ), $this->context->paypal->sent() );
		$this->assertSame( Fixtures::INTENT_UUID . ':authorize', $this->headers()['PayPal-Request-Id'] );
		$this->assertSame( '{}', $this->context->paypal->last()->body );
		$this->assertResult( $result, Operation::Authorize, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
	}

	/**
	 * Tests a second resume racing the first: PayPal says the order is authorized already, and the order is read again for it.
	 *
	 * @since 0.2.0
	 */
	public function test_an_order_authorized_already_answers_its_authorization_from_a_fresh_read(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-approved' ) );
		$this->context->paypal->answer( 'POST', self::orderPath( '/authorize' ), 422, Fixtures::issue( 'ORDER_ALREADY_AUTHORIZED' ) );
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-authorized' ) );

		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
		$this->assertTrue( $this->context->paypal->isDrained() );
	}

	/**
	 * Tests the liability table under `require`: authorized where the liability moved or no check was made, declined otherwise with nothing sent.
	 *
	 * @since 0.2.0
	 */
	public function test_under_require_a_checked_card_whose_liability_stayed_is_declined_and_not_authorized(): void {
		foreach ( array( 'NO', 'UNKNOWN', null ) as $shift ) {
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, self::approved( $shift ) );

			$result = $this->gateway->query( self::query() );

			$this->assertResult( $result, Operation::Authorize, Outcome::Declined, Fixtures::ORDER_ID, 'liability_shift_not_possible' );
		}

		$this->assertNotContains( 'POST ' . self::orderPath( '/authorize' ), $this->context->paypal->sent(), 'Nothing is authorized, so nothing is held.' );

		foreach ( array( 'YES', 'POSSIBLE' ) as $shift ) {
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, self::approved( $shift ) );
			$this->context->paypal->answer( 'POST', self::orderPath( '/authorize' ), 201, Fixtures::text( 'order-authorized' ) );

			$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
		}
	}

	/**
	 * Tests the liability table under `accept`: authorized whatever the check said, the word logged.
	 *
	 * @since 0.2.0
	 */
	public function test_under_accept_a_checked_card_is_authorized_and_the_result_logged(): void {
		$this->context->settings['test']['liability_shift_policy'] = 'accept';
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, self::approved( 'NO' ) );
		$this->context->paypal->answer( 'POST', self::orderPath( '/authorize' ), 201, Fixtures::text( 'order-authorized' ) );

		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
		$this->assertSame( array( 'NO', 'accept' ), array( $this->context->log->contextOf( 'paypal.liability_shift' )['liability_shift'] ?? null, $this->context->log->contextOf( 'paypal.liability_shift' )['policy'] ?? null ) );
	}

	/**
	 * Tests a card the bank never checked, and a PayPal payment: authorized under `require`.
	 *
	 * @since 0.2.0
	 */
	public function test_an_unchecked_card_and_a_paypal_payment_are_authorized_under_require(): void {
		$unchecked = Fixtures::object( 'order-approved' );
		unset( $unchecked['payment_source']['card']['authentication_result'] );
		$wallet                   = Fixtures::object( 'order-approved' );
		$wallet['payment_source'] = array( 'paypal' => array( 'account_status' => 'VERIFIED' ) );

		foreach ( array( $unchecked, $wallet ) as $order ) {
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::encode( $order ) );
			$this->context->paypal->answer( 'POST', self::orderPath( '/authorize' ), 201, Fixtures::text( 'order-authorized' ) );

			$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
		}

		$this->assertNull( $this->context->log->contextOf( 'paypal.liability_shift' ) );
	}

	/**
	 * Tests the authorize call's refusals: declined, expired.
	 *
	 * @since 0.2.0
	 */
	public function test_an_authorize_call_refused_declines(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-approved' ) );
		$this->context->paypal->answer( 'POST', self::orderPath( '/authorize' ), 422, Fixtures::issue( 'INSTRUMENT_DECLINED' ) );
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-approved' ) );
		$this->context->paypal->answer( 'POST', self::orderPath( '/authorize' ), 422, Fixtures::issue( 'ORDER_EXPIRED' ) );

		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Declined, Fixtures::DEBUG_ID, 'instrument_declined' );
		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Declined, Fixtures::ORDER_ID, 'expired' );
	}

	/**
	 * Tests a completed order: its capture once taken, otherwise its authorization; pending is no answer.
	 *
	 * @since 0.2.0
	 */
	public function test_a_completed_order_answers_its_capture_or_its_authorization(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-authorized' ) );
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderCaptured() );
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithAuthorization( 'DENIED' ) );
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithAuthorization( 'PENDING' ) );
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderCaptured( array(), array( 'status' => 'PENDING' ) ) );

		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
		$this->assertResult( $this->gateway->query( self::query() ), Operation::Capture, Outcome::Approved, Fixtures::CAPTURE_ID );
		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Declined, Fixtures::AUTHORIZATION_ID, 'declined' );
		$this->assertNull( $this->gateway->query( self::query() ) );
		$this->assertNull( $this->gateway->query( self::query() ) );
		$this->assertSame( array_fill( 0, 5, 'GET ' . self::orderPath() ), $this->context->paypal->sent(), 'A completed order is never authorized again.' );
	}

	/**
	 * Tests an authorization voided or expired, and a voided order: declined `expired`, never an approved void.
	 *
	 * @since 0.2.0
	 */
	public function test_a_voided_or_expired_authorization_is_declined_expired_never_an_approved_void(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithAuthorization( 'VOIDED' ) );
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::orderWithAuthorization( 'EXPIRED' ) );
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::with( 'order-created', array( 'status' => 'VOIDED' ) ) );

		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Declined, Fixtures::AUTHORIZATION_ID, 'expired' );
		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Declined, Fixtures::AUTHORIZATION_ID, 'expired' );
		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Declined, Fixtures::ORDER_ID, 'expired' );
	}

	/**
	 * Tests an order PayPal does not know: declined `not_found`; and an intent without an order: no answer, nothing sent.
	 *
	 * @since 0.2.0
	 */
	public function test_an_unknown_order_is_not_found_and_an_intent_without_one_is_no_answer(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 404, Fixtures::text( 'error-404' ) );

		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Declined, null, 'not_found' );

		$sent = count( $this->context->paypal->requests );
		$this->assertNull( $this->gateway->query( new PaymentQuery( Fixtures::INTENT_UUID, null, self::money( 2000 ), Mode::Test ) ) );
		$this->assertSame( $sent, count( $this->context->paypal->requests ) );
	}

	/**
	 * Tests a recorded id that is not an order's, and an order of another intent.
	 *
	 * @since 0.2.0
	 */
	public function test_a_wrong_id_or_another_intents_order_is_unavailable(): void {
		$this->unavailable( fn() => $this->gateway->query( new PaymentQuery( Fixtures::INTENT_UUID, '4111111111111111', self::money( 2000 ), Mode::Test ) ) );
		$this->assertSame( array(), $this->context->paypal->requests );

		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::with( 'order-approved', array( 'purchase_units' => array( array( 'custom_id' => '0199c0de-0000-7000-8000-00000000ffff' ) ) ) ) );
		$this->unavailable( fn() => $this->gateway->query( self::query() ) );
		$this->assertSame( array( 'GET ' . self::orderPath() ), $this->context->paypal->sent(), 'Another intent\'s approved order is never authorized.' );
	}

	/**
	 * Returns the approved order with a liability result; none for a check without one.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $shift The result.
	 * @return string The order, encoded.
	 */
	private static function approved( ?string $shift ): string {
		$order = Fixtures::object( 'order-approved' );

		if ( null === $shift ) {
			unset( $order['payment_source']['card']['authentication_result']['liability_shift'] );
		} else {
			$order['payment_source']['card']['authentication_result']['liability_shift'] = $shift;
		}

		return Fixtures::encode( $order );
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
	 * Asserts a result.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult|null $result    The result.
	 * @param Operation          $operation The operation.
	 * @param Outcome            $outcome   The outcome.
	 * @param string|null        $objectId    The object.
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
