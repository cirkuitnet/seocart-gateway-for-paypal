<?php
/**
 * Tests authorize(): the order it creates for each payment token, and what each answer means
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\NextAction;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\GatewayForPayPal\Tests\Support\Fixtures;
use SEOCart\GatewayForPayPal\Tests\Support\GatewayTestCase;

/**
 * An authorization creates one order with the intent to authorize, keyed by the intent's uuid, whose one
 * purchase unit carries the amount, the intent's uuid as `custom_id` and the order number as its
 * description, and no invoice id. The payment source is the token's: PayPal's experience for
 * `paypal`, the 3-D Secure policy alone for `card`, the vault id and a stored credential for a
 * vaulted card. No card field is ever part of it. Each answer maps to one result; of PayPal's
 * refusals, only the payer's or the card's is a decline.
 *
 * Planted violations, each shown red and removed:
 * - a card number in a `vault:` token reaching the request: the card-number test fails;
 * - a fresh uuid as the PayPal-Request-Id: the key test fails;
 * - the order's id as the authorization's object: the approval test fails;
 * - every refusal with an issue answered as a decline: the refusal test fails.
 *
 * @since 0.2.0
 */
final class AuthorizeTest extends GatewayTestCase {

	/**
	 * The return address the requests carry.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const RETURN_URL = 'https://shop.example/?seocart_payment=0199c0de-0000-7000-8000-00000000a001';

	/**
	 * Tests the order of PayPal's page: its body, byte for byte, and the redirect with the order's id.
	 *
	 * @since 0.2.0
	 */
	public function test_a_paypal_payment_creates_an_order_the_shopper_approves_at_paypal(): void {
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 200, Fixtures::text( 'order-payer-action' ) );

		$result = $this->gateway->authorize( self::request( 'paypal' ) );
		$sent   = $this->context->paypal->last();

		$this->assertSame( array( 'paypal-api-sandbox', 'https://api-m.sandbox.paypal.com/v2/checkout/orders' ), array( $sent->hostId, $sent->url ) );
		$this->assertSame( Fixtures::INTENT_UUID, $sent->headers['PayPal-Request-Id'], 'The key is the intent\'s uuid, as the request derives it.' );
		$this->assertSame(
			'{"intent":"AUTHORIZE","purchase_units":[{"amount":{"currency_code":"USD","value":"20.00"},"custom_id":"' . Fixtures::INTENT_UUID . '","description":"1001"}],'
				. '"payment_source":{"paypal":{"experience_context":{"user_action":"PAY_NOW","shipping_preference":"NO_SHIPPING","return_url":"' . self::RETURN_URL . '","cancel_url":"' . self::RETURN_URL . '"}}}}',
			$sent->body
		);
		$this->assertResult( $result, Outcome::RequiresAction, 2000, null );
		$this->assertEquals( NextAction::redirect( 'https://www.sandbox.paypal.com/checkoutnow?token=' . Fixtures::ORDER_ID, Fixtures::ORDER_ID ), $result->nextAction );
	}

	/**
	 * Tests that no token is PayPal's page too, and that an API client's request without a return address sends none.
	 *
	 * @since 0.2.0
	 */
	public function test_without_a_token_or_a_return_address_the_shopper_approves_at_paypal(): void {
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 200, Fixtures::text( 'order-payer-action' ) );

		$result = $this->gateway->authorize( self::request( null, null ) );

		$this->assertSame(
			array(
				'user_action'         => 'PAY_NOW',
				'shipping_preference' => 'NO_SHIPPING',
			),
			$this->context->paypal->body()['payment_source']['paypal']['experience_context']
		);
		$this->assertSame( NextAction::REDIRECT, $result->nextAction?->type );
	}

	/**
	 * Tests the order of PayPal's card fields: the 3-D Secure policy and no card field; the step of PayPal's script with the order's id.
	 *
	 * @since 0.2.0
	 */
	public function test_a_card_payment_creates_an_order_for_paypals_card_fields_without_any_card_field(): void {
		$this->context->settings['test']['sca_policy'] = 'SCA_ALWAYS';
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 200, Fixtures::text( 'order-created' ) );

		$result = $this->gateway->authorize( self::request( 'card' ) );

		$this->assertSame( array( 'card' => array( 'attributes' => array( 'verification' => array( 'method' => 'SCA_ALWAYS' ) ) ) ), $this->context->paypal->body()['payment_source'] );
		$this->assertResult( $result, Outcome::RequiresAction, 2000, null );
		$this->assertEquals( NextAction::sdk( Fixtures::ORDER_ID ), $result->nextAction );
	}

	/**
	 * Tests the 3-D Secure policy PayPal does not know, which falls back to its default.
	 *
	 * @since 0.2.0
	 */
	public function test_an_unknown_policy_asks_when_required(): void {
		$this->context->settings['test']['sca_policy'] = 'SOMETIMES';
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 200, Fixtures::text( 'order-created' ) );

		$this->gateway->authorize( self::request( 'card' ) );

		$this->assertSame( 'SCA_WHEN_REQUIRED', $this->context->paypal->body()['payment_source']['card']['attributes']['verification']['method'] );
	}

	/**
	 * Tests a vaulted card: the vault id and the stored credential; the authorization PayPal made at once, named by its id.
	 *
	 * @since 0.2.0
	 */
	public function test_a_vaulted_card_is_authorized_at_once_and_its_approval_names_the_authorization(): void {
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 201, Fixtures::text( 'order-authorized' ) );

		$result = $this->gateway->authorize( self::request( 'vault:' . Fixtures::VAULT_ID ) );

		$this->assertSame(
			array(
				'card' => array(
					'vault_id'          => Fixtures::VAULT_ID,
					'stored_credential' => array(
						'payment_initiator' => 'CUSTOMER',
						'payment_type'      => 'ONE_TIME',
						'usage'             => 'SUBSEQUENT',
					),
				),
			),
			$this->context->paypal->body()['payment_source']
		);
		$this->assertArrayNotHasKey( 'PayPal-Mock-Response', $this->headers() );
		$this->assertResult( $result, Outcome::Approved, 2000, Fixtures::AUTHORIZATION_ID );
	}

	/**
	 * Tests the vaulted card's other answers: denied, pending, and the bank's check.
	 *
	 * @since 0.2.0
	 */
	public function test_a_vaulted_cards_authorization_maps_to_one_result_each(): void {
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 201, Fixtures::orderWithAuthorization( 'DENIED', array( 'status_details' => array( 'reason' => 'DECLINED_BY_RISK_FRAUD_FILTERS' ) ) ) );
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 201, Fixtures::orderWithAuthorization( 'PENDING', array( 'status_details' => array( 'reason' => 'PENDING_REVIEW' ) ) ) );
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 201, Fixtures::with( 'order-payer-action', array( 'payment_source' => array( 'card' => array() ) ) ) );

		$denied  = $this->gateway->authorize( self::request( 'vault:' . Fixtures::VAULT_ID ) );
		$pending = $this->gateway->authorize( self::request( 'vault:' . Fixtures::VAULT_ID ) );
		$checked = $this->gateway->authorize( self::request( 'vault:' . Fixtures::VAULT_ID ) );

		$this->assertResult( $denied, Outcome::Declined, 2000, Fixtures::AUTHORIZATION_ID, 'declined_by_risk_fraud_filters' );
		$this->assertResult( $pending, Outcome::Pending, 2000, null );
		$this->assertResult( $checked, Outcome::RequiresAction, 2000, null );
		$this->assertSame( NextAction::REDIRECT, $checked->nextAction?->type );
	}

	/**
	 * Tests a vaulted card's authorization for another amount than the intent's: voided at once, and declined `amount_changed`.
	 *
	 * @since 0.2.0
	 */
	public function test_a_vaulted_cards_authorization_for_another_amount_is_voided_and_declined(): void {
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 201, Fixtures::orderWithAuthorization( 'CREATED', array( 'amount' => array( 'value' => '2.00' ) ) ) );
		$this->context->paypal->answer( 'POST', 'v2/payments/authorizations/' . Fixtures::AUTHORIZATION_ID . '/void', 200, Fixtures::text( 'authorization-voided' ) );

		$this->assertResult( $this->gateway->authorize( self::request( 'vault:' . Fixtures::VAULT_ID ) ), Outcome::Declined, 2000, Fixtures::AUTHORIZATION_ID, 'amount_changed' );
		$this->assertSame( Fixtures::INTENT_UUID . ':amount-void', $this->headers()['PayPal-Request-Id'] );
		$this->assertTrue( $this->context->paypal->isDrained() );
	}

	/**
	 * Tests the sandbox's mocked decline: the mock header, and the decline named by PayPal's reference for the answer, with the issue as its code.
	 *
	 * @since 0.2.0
	 */
	public function test_a_mocked_decline_is_declined_with_the_issue_and_named_by_the_answers_debug_id(): void {
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 422, Fixtures::text( 'error-422' ) );

		$result = $this->gateway->authorize( self::request( 'vault:' . Fixtures::VAULT_ID . ';mock=INSTRUMENT_DECLINED' ) );

		$this->assertSame( '{"mock_application_codes":"INSTRUMENT_DECLINED"}', $this->headers()['PayPal-Mock-Response'] );
		$this->assertResult( $result, Outcome::Declined, 2000, Fixtures::DEBUG_ID, 'instrument_declined', null );
		$this->assertSame( array( 'paypal.declined' ), $this->context->log->codes() );
	}

	/**
	 * Tests the refusals of the order's creation: PayPal's final no to the payer or the card is a decline with its issue; anything else, such as a feature the merchant's account lacks or a request PayPal could not read, is no answer, logged by its issue.
	 *
	 * @since 0.2.0
	 */
	public function test_only_a_refusal_of_the_payer_or_the_card_is_a_decline(): void {
		foreach ( array( 'PAYER_CANNOT_PAY', 'TRANSACTION_REFUSED', 'CARD_EXPIRED', 'PAYMENT_DENIED' ) as $issue ) {
			$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 422, Fixtures::issue( $issue ) );

			$this->assertResult( $this->gateway->authorize( self::request( 'vault:' . Fixtures::VAULT_ID ) ), Outcome::Declined, 2000, Fixtures::DEBUG_ID, strtolower( $issue ), null );
		}

		$this->assertNotContains( 'paypal.request_refused', $this->context->log->codes() );

		$refused = array(
			array( 422, Fixtures::issue( 'AUTH_CAPTURE_NOT_ENABLED' ), 'AUTH_CAPTURE_NOT_ENABLED' ),
			array( 422, Fixtures::issue( 'PAYEE_ACCOUNT_RESTRICTED' ), 'PAYEE_ACCOUNT_RESTRICTED' ),
			array( 422, Fixtures::issue( 'AN_ISSUE_PAYPAL_ADDS_LATER' ), null ),
			array( 400, Fixtures::text( 'error-400' ), 'DECIMAL_PRECISION' ),
		);

		foreach ( $refused as list( $status, $body, $issue ) ) {
			$this->context->log->lines = array();
			$this->context->paypal->answer( 'POST', 'v2/checkout/orders', $status, $body );

			$this->unavailable( fn() => $this->gateway->authorize( self::request( 'vault:' . Fixtures::VAULT_ID ) ) );

			$line = $this->context->log->contextOf( 'paypal.request_refused' );
			$this->assertSame( array( $status, $issue ), array( $line['http_status'] ?? null, $line['issue'] ?? null ) );
			$this->assertNotContains( 'paypal.declined', $this->context->log->codes(), 'Not the shopper\'s refusal, so never told to them as one.' );
		}
	}

	/**
	 * Tests the tokens the gateway does not take: declined `invalid_payment_token`, and nothing sent.
	 *
	 * @since 0.2.0
	 */
	public function test_an_invalid_token_or_a_card_number_is_declined_with_nothing_sent(): void {
		foreach ( array( 'pm_card_visa', 'vault:4111111111111111', 'vault:' . Fixtures::VAULT_ID . ';mock=x', 'vault:a/b' ) as $token ) {
			$result = $this->gateway->authorize( self::request( $token ) );

			$this->assertResult( $result, Outcome::Declined, 2000, null, 'invalid_payment_token', null );
		}

		$live = $this->gateway->authorize( self::request( 'vault:' . Fixtures::VAULT_ID . ';mock=INSTRUMENT_DECLINED', self::RETURN_URL, Mode::Live ) );

		$this->assertSame( 'invalid_payment_token', $live->errorCode, 'Live mode takes no mock.' );
		$this->assertSame( array(), $this->context->paypal->requests, 'Nothing is sent, not even the token call.' );
	}

	/**
	 * Tests the same authorization asked twice: the same key and the same body.
	 *
	 * @since 0.2.0
	 */
	public function test_the_same_authorization_asked_again_sends_the_same_key_and_body(): void {
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 201, Fixtures::text( 'order-authorized' ) );
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 201, Fixtures::text( 'order-authorized' ) );

		$this->gateway->authorize( self::request( 'vault:' . Fixtures::VAULT_ID ) );
		$this->gateway->authorize( self::request( 'vault:' . Fixtures::VAULT_ID ) );

		list( $first, $second ) = $this->context->paypal->apiRequests();
		$this->assertSame( array( $first->body, $first->headers['PayPal-Request-Id'] ), array( $second->body, $second->headers['PayPal-Request-Id'] ) );
	}

	/**
	 * Tests an order created for another intent: nothing applied.
	 *
	 * @since 0.2.0
	 */
	public function test_an_order_of_another_intent_is_unavailable(): void {
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 201, Fixtures::with( 'order-authorized', array( 'purchase_units' => array( array( 'custom_id' => '0199c0de-0000-7000-8000-00000000ffff' ) ) ) ) );

		$this->unavailable( fn() => $this->gateway->authorize( self::request( 'vault:' . Fixtures::VAULT_ID ) ) );
		$this->assertContains( 'paypal.foreign_order', $this->context->log->codes() );
	}

	/**
	 * Tests a currency the gateway does not declare: refused before any request.
	 *
	 * @since 0.2.0
	 */
	public function test_a_currency_it_does_not_take_is_refused_before_any_request(): void {
		$this->unavailable( fn() => $this->gateway->authorize( new PaymentRequest( Fixtures::INTENT_UUID, self::money( 2000, 'JPY' ), 'paypal', Mode::Test, '0199c0de-0000-7000-8000-00000000d001', '1001', null ) ) );

		$this->assertSame( array(), $this->context->paypal->requests );
	}

	/**
	 * Tests the description: the order number, cut to PayPal's 127 characters, and none for an empty number.
	 *
	 * @since 0.2.0
	 */
	public function test_the_description_is_the_order_number_cut_to_127_characters(): void {
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 201, Fixtures::text( 'order-created' ) );
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 201, Fixtures::text( 'order-created' ) );

		$this->gateway->authorize( new PaymentRequest( Fixtures::INTENT_UUID, self::money( 2000 ), 'card', Mode::Test, '0199c0de-0000-7000-8000-00000000d001', str_repeat( 'n', 200 ), null ) );
		$long = $this->context->paypal->body()['purchase_units'][0];
		$this->gateway->authorize( new PaymentRequest( Fixtures::INTENT_UUID, self::money( 2000 ), 'card', Mode::Test, '0199c0de-0000-7000-8000-00000000d001', '', null ) );
		$none = $this->context->paypal->body()['purchase_units'][0];

		$this->assertSame( 127, strlen( $long['description'] ) );
		$this->assertArrayNotHasKey( 'description', $none );
		$this->assertArrayNotHasKey( 'invoice_id', $long, 'No invoice id: a second order for the same store order must not be refused as a duplicate.' );
	}

	/**
	 * Builds an authorization request.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $token     The payment token.
	 * @param string|null $returnUrl Optional. The return address. Default RETURN_URL.
	 * @param Mode        $mode      Optional. The mode. Default test.
	 * @return PaymentRequest The request.
	 */
	private static function request( ?string $token, ?string $returnUrl = self::RETURN_URL, Mode $mode = Mode::Test ): PaymentRequest {
		return new PaymentRequest( Fixtures::INTENT_UUID, self::money( 2000 ), $token, $mode, '0199c0de-0000-7000-8000-00000000d001', '1001', $returnUrl );
	}

	/**
	 * Asserts a result of an authorization.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result  The result.
	 * @param Outcome       $outcome The outcome.
	 * @param int           $minor   The amount.
	 * @param string|null   $objectId The object.
	 * @param string|null   $code    Optional. The decline code. Default none.
	 * @param string|null   $order   Optional. The order. Default the fixtures'.
	 */
	private function assertResult( GatewayResult $result, Outcome $outcome, int $minor, ?string $objectId, ?string $code = null, ?string $order = Fixtures::ORDER_ID ): void {
		$this->assertSame(
			array( 'paypal', Operation::Authorize, $outcome, Fixtures::INTENT_UUID, $minor, 'USD', $objectId, $order, $code ),
			array( $result->provider, $result->operation, $result->outcome, $result->intentUuid, $result->amount->minorUnits(), $result->amount->currency()->code(), $result->providerObjectId, $result->providerIntentId, $result->errorCode )
		);
	}
}
