<?php
/**
 * Tests the webhook event map: what each event PayPal delivers means
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\GatewayForPayPal\CertificateCache;
use SEOCart\GatewayForPayPal\Tests\Support\Fixtures;
use SEOCart\GatewayForPayPal\Tests\Support\GatewayTestCase;
use SEOCart\GatewayForPayPal\Tests\Support\TestDeliveries;
use SEOCart\GatewayForPayPal\WebhookReader;

/**
 * An authorization created is its approval, voided its void; a capture completed, declined or
 * denied is that capture; a refund is a refund result with SEOCart's uuid when the refund carries
 * it, and a reversal one without; a failed refund is the refund's decline; an approved order is
 * ignored, naming the order and its intent, since the status query authorizes it; a pending
 * authorization, capture or refund, a reversed approval and a dispute are ignored, a dispute naming
 * nothing; any other type is ignored as `unhandled_type`, the type PayPal wrote never passed on. An
 * event's id that is not a PayPal event's is malformed.
 *
 * The readme asks the merchant to subscribe to exactly these events.
 *
 * Planted violations, each shown red and removed:
 * - the approval event read as an authorization's result: the approval test fails, as a delivery
 *   would move money nothing authorized;
 * - an event left out of the readme's list: the readme test fails.
 *
 * @since 0.2.0
 */
final class WebhookReaderTest extends GatewayTestCase {

	/**
	 * An event's id, in PayPal's shape.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EVENT_ID = 'WH-2WR32451HC0233532-67976317FL4543714';

	/**
	 * The key pair of the test.
	 *
	 * @since 0.2.0
	 *
	 * @var TestDeliveries
	 */
	private TestDeliveries $keys;

	/**
	 * Makes the key pair, keeps its certificate, and sets the clock.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->keys = new TestDeliveries();
		$this->context->clock->setTo( $this->keys->validFrom->modify( '+60 seconds' ) );
		$this->transients[ CertificateCache::key( TestDeliveries::CERT_URL ) ] = array(
			'value'   => $this->keys->certificate,
			'seconds' => CertificateCache::SECONDS,
		);
	}

	/**
	 * Tests that the map holds exactly the events the gateway reads.
	 *
	 * @since 0.2.0
	 */
	public function test_the_map_holds_the_events_the_merchants_webhook_subscribes_to(): void {
		$this->assertSame(
			array(
				'PAYMENT.AUTHORIZATION.CREATED',
				'PAYMENT.AUTHORIZATION.VOIDED',
				'PAYMENT.CAPTURE.COMPLETED',
				'PAYMENT.CAPTURE.DECLINED',
				'PAYMENT.CAPTURE.DENIED',
				'PAYMENT.CAPTURE.PENDING',
				'PAYMENT.CAPTURE.REFUNDED',
				'PAYMENT.REFUND.PENDING',
				'PAYMENT.REFUND.FAILED',
				'PAYMENT.CAPTURE.REVERSED',
				'CHECKOUT.ORDER.APPROVED',
				'CHECKOUT.PAYMENT-APPROVAL.REVERSED',
				'CUSTOMER.DISPUTE.CREATED',
				'CUSTOMER.DISPUTE.UPDATED',
				'CUSTOMER.DISPUTE.RESOLVED',
			),
			array_keys( WebhookReader::EVENTS )
		);
	}

	/**
	 * Tests that the readme asks the merchant's webhook to subscribe to exactly the events the gateway reads, each named once.
	 *
	 * @since 0.2.0
	 */
	public function test_the_readme_names_exactly_the_events_the_gateway_reads(): void {
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' );

		preg_match_all( '/\b[A-Z]{3,}(?:\.[A-Z][A-Z\-]*[A-Z]){2}\b/', $readme, $named );

		$events = array_keys( WebhookReader::EVENTS );

		sort( $events );

		$listed = $named[0];

		sort( $listed );

		$this->assertSame( $events, $listed, 'The readme\'s list of events to subscribe to is not the gateway\'s.' );
	}

	/**
	 * Tests the authorization's events: created is its approval, pending ignored, voided its void.
	 *
	 * @since 0.2.0
	 */
	public function test_an_authorization_created_is_its_approval_and_voided_its_void(): void {
		$created = $this->event( 'PAYMENT.AUTHORIZATION.CREATED', TestDeliveries::authorization() );
		$voided  = $this->event( 'PAYMENT.AUTHORIZATION.VOIDED', array( 'status' => 'VOIDED' ) + TestDeliveries::authorization() );
		$pending = $this->event( 'PAYMENT.AUTHORIZATION.CREATED', array( 'status' => 'PENDING' ) + TestDeliveries::authorization() );

		$this->assertResult( $created, 'PAYMENT.AUTHORIZATION.CREATED', Operation::Authorize, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
		$this->assertResult( $voided, 'PAYMENT.AUTHORIZATION.VOIDED', Operation::Void, Outcome::Approved, Fixtures::AUTHORIZATION_ID );
		$this->assertIgnored( $pending, 'PAYMENT.AUTHORIZATION.CREATED', WebhookReader::AUTHORIZATION_PENDING );
		$this->assertSame( WebhookReading::MALFORMED, $this->event( 'PAYMENT.AUTHORIZATION.VOIDED', TestDeliveries::authorization() )->reason, 'A voided event whose authorization is not voided.' );
	}

	/**
	 * Tests the capture's events.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_completed_or_declined_is_that_capture_and_pending_is_ignored(): void {
		$capture = array( 'custom_id' => TestDeliveries::INTENT_UUID ) + Fixtures::object( 'capture' );

		$this->assertResult( $this->event( 'PAYMENT.CAPTURE.COMPLETED', $capture ), 'PAYMENT.CAPTURE.COMPLETED', Operation::Capture, Outcome::Approved, Fixtures::CAPTURE_ID );
		$this->assertResult( $this->event( 'PAYMENT.CAPTURE.DECLINED', array( 'status' => 'DECLINED' ) + $capture ), 'PAYMENT.CAPTURE.DECLINED', Operation::Capture, Outcome::Declined, Fixtures::CAPTURE_ID );
		$this->assertIgnored( $this->event( 'PAYMENT.CAPTURE.PENDING', array( 'status' => 'PENDING' ) + $capture ), 'PAYMENT.CAPTURE.PENDING', WebhookReader::CAPTURE_PENDING );
	}

	/**
	 * Tests an authorization or a capture without SEOCart's uuid, or without its order: another integration's, ignored.
	 *
	 * @since 0.2.0
	 */
	public function test_an_object_without_seocarts_uuid_or_order_is_ignored(): void {
		$foreign = array( 'custom_id' => 'order-1001' ) + TestDeliveries::authorization();
		$orphan  = TestDeliveries::authorization();
		unset( $orphan['supplementary_data'] );

		$this->assertIgnored( $this->event( 'PAYMENT.AUTHORIZATION.CREATED', $foreign ), 'PAYMENT.AUTHORIZATION.CREATED', WebhookReader::NOT_OURS );
		$this->assertIgnored( $this->event( 'PAYMENT.AUTHORIZATION.CREATED', $orphan ), 'PAYMENT.AUTHORIZATION.CREATED', WebhookReader::NOT_OURS );
	}

	/**
	 * Tests refunds: SEOCart's carries its uuid; one made elsewhere, and a reversal, carry none.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_is_a_refund_result_with_seocarts_uuid_when_it_carries_one(): void {
		$ours     = $this->event( 'PAYMENT.CAPTURE.REFUNDED', Fixtures::object( 'refund' ) );
		$external = $this->event( 'PAYMENT.CAPTURE.REFUNDED', array( 'custom_id' => 'dashboard' ) + Fixtures::object( 'refund' ) );
		$reversal = $this->event( 'PAYMENT.CAPTURE.REVERSED', Fixtures::object( 'refund' ) );

		$this->assertResult( $ours, 'PAYMENT.CAPTURE.REFUNDED', Operation::Refund, Outcome::Approved, Fixtures::REFUND_ID, '' );
		$this->assertSame( Fixtures::REFUND_UUID, $ours->refundUuid );
		$this->assertNull( $external->refundUuid );
		$this->assertResult( $reversal, 'PAYMENT.CAPTURE.REVERSED', Operation::Refund, Outcome::Approved, Fixtures::REFUND_ID, '' );
		$this->assertNull( $reversal->refundUuid, 'Money PayPal gave back was no refund SEOCart asked for.' );
	}

	/**
	 * Tests a refund's other states and a denied capture: a refund PayPal is still giving back is ignored, whichever event reports it; a failed one is the refund's decline, with SEOCart's uuid; a denied capture is that capture's decline.
	 *
	 * @since 0.2.0
	 */
	public function test_a_pending_refund_is_ignored_and_a_failed_refund_or_a_denied_capture_declined(): void {
		$pending = array(
			'status'         => 'PENDING',
			'status_details' => array( 'reason' => 'ECHECK' ),
		) + Fixtures::object( 'refund' );
		$denied  = array(
			'custom_id' => TestDeliveries::INTENT_UUID,
			'status'    => 'DECLINED',
		) + Fixtures::object( 'capture' );

		$this->assertIgnored( $this->event( 'PAYMENT.REFUND.PENDING', $pending ), 'PAYMENT.REFUND.PENDING', WebhookReader::REFUND_PENDING );
		$this->assertIgnored( $this->event( 'PAYMENT.CAPTURE.REFUNDED', $pending ), 'PAYMENT.CAPTURE.REFUNDED', WebhookReader::REFUND_PENDING );

		$failed = $this->event( 'PAYMENT.REFUND.FAILED', array( 'status' => 'FAILED' ) + Fixtures::object( 'refund' ) );

		$this->assertResult( $failed, 'PAYMENT.REFUND.FAILED', Operation::Refund, Outcome::Declined, Fixtures::REFUND_ID, '' );
		$this->assertSame( array( Fixtures::REFUND_UUID, 'declined' ), array( $failed->refundUuid, $failed->result?->errorCode ) );
		$this->assertResult( $this->event( 'PAYMENT.CAPTURE.DENIED', $denied ), 'PAYMENT.CAPTURE.DENIED', Operation::Capture, Outcome::Declined, Fixtures::CAPTURE_ID );
	}

	/**
	 * Tests the approved order: ignored, naming the order and its intent, as the status query authorizes it.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approved_order_is_ignored_awaiting_its_authorization(): void {
		$reading = $this->event( 'CHECKOUT.ORDER.APPROVED', TestDeliveries::approvedOrder() );

		$this->assertIgnored( $reading, 'CHECKOUT.ORDER.APPROVED', WebhookReader::AWAITING_AUTHORIZATION );
		$this->assertSame( array( TestDeliveries::INTENT_UUID, Fixtures::ORDER_ID ), array( $reading->intentUuid, $reading->providerIntentId ) );
		$this->assertIgnored( $this->event( 'CHECKOUT.PAYMENT-APPROVAL.REVERSED', TestDeliveries::approvedOrder() ), 'CHECKOUT.PAYMENT-APPROVAL.REVERSED', WebhookReader::APPROVAL_REVERSED );
	}

	/**
	 * Tests a dispute: ignored with SEOCart's reason, naming nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_dispute_is_ignored_and_names_nothing(): void {
		foreach ( array( 'CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.UPDATED', 'CUSTOMER.DISPUTE.RESOLVED' ) as $type ) {
			$reading = $this->event(
				$type,
				array(
					'dispute_id'            => 'PP-D-12345',
					'disputed_transactions' => array( array( 'seller_transaction_id' => Fixtures::CAPTURE_ID ) ),
				)
			);

			$this->assertIgnored( $reading, $type, WebhookReading::DISPUTE );
			$this->assertSame( array( null, null ), array( $reading->intentUuid, $reading->providerIntentId ) );
		}
	}

	/**
	 * Tests an event of a type the gateway does not read: ignored under `unhandled_type`, PayPal's type not passed on.
	 *
	 * @since 0.2.0
	 */
	public function test_any_other_type_is_ignored_and_its_type_never_passed_on(): void {
		$reading = $this->event( 'BILLING.SUBSCRIPTION.CREATED', array( 'id' => 'I-BW452GLLEP1G' ) );

		$this->assertIgnored( $reading, WebhookReader::UNHANDLED_TYPE, WebhookReader::UNHANDLED_TYPE );
	}

	/**
	 * Tests an event whose id is not one of PayPal's: malformed.
	 *
	 * @since 0.2.0
	 */
	public function test_an_event_id_of_another_shape_is_malformed(): void {
		foreach ( array( 'evt_1Stripe', 'WH-4111111111111111', 'WH-' . Fixtures::ORDER_ID ) as $id ) {
			$this->assertSame( WebhookReading::MALFORMED, $this->event( 'PAYMENT.AUTHORIZATION.CREATED', TestDeliveries::authorization(), $id )->reason );
		}
	}

	/**
	 * Reads an event of a type, signed for the test mode's webhook.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $type     The event's type.
	 * @param array<mixed> $payload  The resource.
	 * @param string       $id       Optional. The event's id. Default EVENT_ID.
	 * @return WebhookReading The reading.
	 */
	private function event( string $type, array $payload, string $id = self::EVENT_ID ): WebhookReading {
		$now  = $this->context->clock->now();
		$body = TestDeliveries::event( $type, $payload, $id, $now );

		return $this->gateway->readWebhook( new WebhookEnvelope( 'paypal', Mode::Test, $this->keys->headers( $body, $now ), $body, $now ) );
	}

	/**
	 * Asserts a result reading.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookReading $reading    The reading.
	 * @param string         $type       The event's type.
	 * @param Operation      $operation  The result's operation.
	 * @param Outcome        $outcome    The result's outcome.
	 * @param string         $objectId     The result's object.
	 * @param string         $intentUuid Optional. The result's intent. Default the deliveries'.
	 */
	private function assertResult( WebhookReading $reading, string $type, Operation $operation, Outcome $outcome, string $objectId, string $intentUuid = TestDeliveries::INTENT_UUID ): void {
		$this->assertSame( array( WebhookReadingKind::Result, self::EVENT_ID, $type ), array( $reading->kind, $reading->eventId, $reading->eventType ) );
		$this->assertNotNull( $reading->result );
		$this->assertSame(
			array( 'paypal', $operation, $outcome, $intentUuid, $objectId, Fixtures::ORDER_ID ),
			array( $reading->result->provider, $reading->result->operation, $reading->result->outcome, $reading->result->intentUuid, $reading->result->providerObjectId, $reading->result->providerIntentId )
		);
	}

	/**
	 * Asserts an ignored reading.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookReading $reading The reading.
	 * @param string         $type    The type passed on.
	 * @param string         $reason  The reason.
	 */
	private function assertIgnored( WebhookReading $reading, string $type, string $reason ): void {
		$this->assertSame( array( WebhookReadingKind::Ignored, self::EVENT_ID, $type, $reason ), array( $reading->kind, $reading->eventId, $reading->eventType, $reading->reason ) );
		$this->assertNull( $reading->result );
	}
}
