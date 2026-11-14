<?php
/**
 * Tests the status query of an order of PayPal's card fields, which PayPal leaves `CREATED` once the shopper passed the bank's challenge
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
 * PayPal's sandbox, read at each step of a card payment made in its card fields with 3-D Secure,
 * shows: before the card, the order is `CREATED` with no card on it; while the bank's challenge is
 * on the shopper's screen, `CREATED` with the card's details and no check result; once the shopper
 * passed it, still `CREATED`, with the check's result and its liability shift. An order approved
 * with no check reads `APPROVED`. So a `CREATED` order is resumed only once its card source carries
 * a check's result with a documented liability shift and an authentication status other than `C`
 * (challenge required), whether or not the card's details are there: before that, the
 * reconciliation job, which asks with no shopper there, must neither authorize it nor decline it.
 * Once resumed, it goes the way of an approved order: the amount checked, then the liability
 * policy, then the authorization under `{uuid}:authorize`.
 *
 * Planted violations, each shown red and removed:
 * - any card on a `CREATED` order taken as finished: the mid-challenge test fails, as the order is
 *   authorized while the shopper is still answering the bank;
 * - a check still requiring the challenge taken as finished: the challenge-required test fails.
 *
 * @since 0.2.0
 */
final class CardFieldsQueryTest extends GatewayTestCase {

	/**
	 * Tests an order with no card yet, or an empty card source: still waiting, nothing sent but the read.
	 *
	 * @since 0.2.0
	 */
	public function test_an_order_without_a_card_on_it_is_still_waiting(): void {
		$none  = Fixtures::object( 'order-created' );
		$empty = Fixtures::object( 'order-created' );

		$empty['payment_source'] = array( 'card' => array() );

		foreach ( array( $none, $empty ) as $order ) {
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::encode( $order ) );

			$this->assertNull( $this->gateway->query( self::query() ) );
		}

		$this->assertSame( array_fill( 0, 2, 'GET ' . self::orderPath() ), $this->context->paypal->sent() );
	}

	/**
	 * Tests an order whose shopper is still answering the bank's challenge, as the sandbox shows it (the card on it, no check result), and one whose result names no liability shift: still waiting under either policy, nothing authorized and nothing declined.
	 *
	 * @since 0.2.0
	 */
	public function test_an_order_mid_challenge_is_still_waiting_under_either_policy(): void {
		$partial = self::created( null );

		$partial['payment_source']['card']['authentication_result'] = array( 'three_d_secure' => array( 'enrollment_status' => 'Y' ) );

		foreach ( array( 'require', 'accept' ) as $policy ) {
			$this->context->settings['test']['liability_shift_policy'] = $policy;

			foreach ( array( self::created( null ), $partial ) as $order ) {
				$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::encode( $order ) );

				$this->assertNull( $this->gateway->query( self::query() ), "Under {$policy}, the shopper has not finished." );
			}
		}

		$this->assertSame( array_fill( 0, 4, 'GET ' . self::orderPath() ), $this->context->paypal->sent(), 'Nothing is authorized while the shopper answers the bank.' );
		$this->assertNotContains( 'paypal.declined', $this->context->log->codes() );
	}

	/**
	 * Tests a check PayPal reports as still requiring the challenge (authentication status `C`, liability `UNKNOWN`, from PayPal's 3-D Secure table): still waiting under either policy, nothing authorized and nothing declined.
	 *
	 * @since 0.2.0
	 */
	public function test_a_check_still_requiring_the_challenge_is_still_waiting_under_either_policy(): void {
		$challenge = self::created( 'UNKNOWN' );

		$challenge['payment_source']['card']['authentication_result']['three_d_secure']['authentication_status'] = 'C';

		foreach ( array( 'require', 'accept' ) as $policy ) {
			$this->context->settings['test']['liability_shift_policy'] = $policy;
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::encode( $challenge ) );

			$this->assertNull( $this->gateway->query( self::query() ), "Under {$policy}, the challenge is not answered yet." );
		}

		$this->assertSame( array_fill( 0, 2, 'GET ' . self::orderPath() ), $this->context->paypal->sent() );
		$this->assertNotContains( 'paypal.declined', $this->context->log->codes() );
	}

	/**
	 * Tests a finished check on a card whose details PayPal left out: resumed, as the details are optional in PayPal's answer.
	 *
	 * @since 0.2.0
	 */
	public function test_a_finished_check_without_the_cards_details_is_resumed(): void {
		$order = self::created( 'POSSIBLE' );

		$order['payment_source']['card'] = array( 'authentication_result' => self::result( 'POSSIBLE' ) );

		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::encode( $order ) );
		$this->context->paypal->answer( 'POST', self::orderPath( '/authorize' ), 201, Fixtures::text( 'order-authorized' ) );

		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
	}

	/**
	 * Tests a `CREATED` order whose payment source is not a card, though it carries a check's result: never read by this rule, so still waiting.
	 *
	 * @since 0.2.0
	 */
	public function test_a_source_other_than_a_card_is_not_read_by_the_rule(): void {
		$order = self::created( 'POSSIBLE' );

		$order['payment_source'] = array( 'paypal' => array( 'authentication_result' => self::result( 'POSSIBLE' ) ) );

		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::encode( $order ) );

		$this->assertNull( $this->gateway->query( self::query() ) );
		$this->assertSame( array( 'GET ' . self::orderPath() ), $this->context->paypal->sent() );
	}

	/**
	 * Tests an order whose shopper passed the challenge, the liability moved to the bank: authorized under its own key, under either policy.
	 *
	 * @since 0.2.0
	 */
	public function test_a_finished_order_whose_liability_moved_is_authorized_under_its_key(): void {
		foreach ( array( 'require', 'accept' ) as $policy ) {
			$this->context->settings['test']['liability_shift_policy'] = $policy;

			foreach ( array( 'POSSIBLE', 'YES' ) as $shift ) {
				$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::encode( self::created( $shift ) ) );
				$this->context->paypal->answer( 'POST', self::orderPath( '/authorize' ), 201, Fixtures::text( 'order-authorized' ) );

				$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
				$this->assertSame( Fixtures::INTENT_UUID . ':authorize', $this->headers()['PayPal-Request-Id'] );
			}
		}
	}

	/**
	 * Tests an order whose shopper finished the challenge with the liability left with the store: declined under `require`, nothing authorized; authorized under `accept`.
	 *
	 * @since 0.2.0
	 */
	public function test_a_finished_order_whose_liability_stayed_follows_the_policy(): void {
		foreach ( array( 'NO', 'UNKNOWN' ) as $shift ) {
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::encode( self::created( $shift ) ) );

			$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Declined, Fixtures::ORDER_ID, 'liability_shift_not_possible' );
		}

		$this->assertNotContains( 'POST ' . self::orderPath( '/authorize' ), $this->context->paypal->sent(), 'Under require, nothing is authorized.' );

		$this->context->settings['test']['liability_shift_policy'] = 'accept';

		foreach ( array( 'NO', 'UNKNOWN' ) as $shift ) {
			$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::encode( self::created( $shift ) ) );
			$this->context->paypal->answer( 'POST', self::orderPath( '/authorize' ), 201, Fixtures::text( 'order-authorized' ) );

			$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
		}
	}

	/**
	 * Tests a finished order whose amount changed meanwhile: declined `amount_changed`, nothing authorized.
	 *
	 * @since 0.2.0
	 */
	public function test_a_finished_order_for_another_amount_is_declined_and_never_authorized(): void {
		$order = self::created( 'POSSIBLE' );

		$order['purchase_units'][0]['amount']['value'] = '19.99';

		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::encode( $order ) );

		$this->assertResult( $this->gateway->query( self::query() ), Operation::Authorize, Outcome::Declined, Fixtures::ORDER_ID, 'amount_changed' );
		$this->assertSame( array( 'GET ' . self::orderPath() ), $this->context->paypal->sent() );
	}

	/**
	 * Returns the order of the card fields as PayPal holds it `CREATED`: the card on it, with a check whose liability shift is given, or with no check result for none.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $shift The check's liability shift; null while the shopper answers the challenge.
	 * @return array<mixed> The order.
	 */
	private static function created( ?string $shift ): array {
		$order           = Fixtures::object( 'order-approved' );
		$order['status'] = 'CREATED';

		if ( null === $shift ) {
			unset( $order['payment_source']['card']['authentication_result'] );
		} else {
			$order['payment_source']['card']['authentication_result']['liability_shift'] = $shift;
		}

		return $order;
	}

	/**
	 * Returns a 3-D Secure check's result as PayPal writes it on a card once the shopper passed the challenge.
	 *
	 * @since 0.2.0
	 *
	 * @param string $shift The liability shift.
	 * @return array<string, mixed> The result.
	 */
	private static function result( string $shift ): array {
		return array(
			'liability_shift' => $shift,
			'three_d_secure'  => array(
				'enrollment_status'     => 'Y',
				'authentication_status' => 'Y',
			),
		);
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
