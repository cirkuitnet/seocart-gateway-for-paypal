<?php
/**
 * WebhookReader: reads a delivery PayPal sent to SEOCart's webhook
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;

/**
 * Verifies a delivery, then reads it: a payment's result, an event SEOCart does not act on, or a rejection.
 *
 * Owns one fact: what each PayPal event the merchant's webhook subscribes to means (EVENTS, the
 * list the readme asks the merchant to subscribe to). In order, and nothing of the body is read
 * before the signature holds: WebhookSignature, with the webhook id of the mode the delivery was
 * addressed to; then the body is decoded and must be an event. A delivery that fails either is
 * rejected with a reason word and nothing else of it. PayPal's events carry no mode: a sandbox
 * delivery sent to the live address fails the signature, since the live webhook's id and
 * certificate host differ.
 *
 * The intent an event is about is the one SEOCart created its order for: the `custom_id` an
 * authorization or a capture carries. An authorization or a capture without one belongs to
 * another integration on the same PayPal account and is ignored. A refund carries the refund's
 * own uuid as its `custom_id` when SEOCart asked for it, and names its order, which SEOCart
 * resolves; one without SEOCart's uuid, such as a refund made in PayPal's dashboard or money
 * PayPal reversed, is a result SEOCart keeps for a person. An approved order is ignored, naming
 * it: the order holds no money until the status query that follows authorizes it, and a delivery
 * never moves money. A dispute is ignored with SEOCart's own reason (WebhookReading::DISPUTE) and
 * no ids, as it names a capture, not an order.
 *
 * Nothing PayPal wrote reaches SEOCart unchecked: the event's id must be a PayPal event's id, an
 * event of a type the gateway does not read is passed on under the type `unhandled_type`, never
 * the type PayPal wrote, and the ids a result names are checked by Results. A delivery that fails
 * a check is rejected as malformed.
 *
 * @since 0.2.0
 */
final class WebhookReader {

	/**
	 * Why an event is ignored, and the type it is passed on under: its type is not one the gateway reads, and the type PayPal wrote is never passed on.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNHANDLED_TYPE = 'unhandled_type';

	/**
	 * Why an event is ignored: its object names no intent of SEOCart's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_OURS = 'not_ours';

	/**
	 * Why an approved order is ignored: the status query that follows authorizes it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AWAITING_AUTHORIZATION = 'awaiting_authorization';

	/**
	 * Why an authorization PayPal is reviewing is ignored: its outcome follows.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AUTHORIZATION_PENDING = 'authorization_pending';

	/**
	 * Why a capture PayPal is reviewing is ignored: its outcome follows.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CAPTURE_PENDING = 'capture_pending';

	/**
	 * Why a reversed approval is ignored: nothing was authorized on it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const APPROVAL_REVERSED = 'approval_reversed';

	/**
	 * The authorization reading of an event.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const AUTHORIZATION = 'authorization';

	/**
	 * The void reading of an event.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const VOID = 'void';

	/**
	 * The capture reading of an event.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CAPTURE = 'capture';

	/**
	 * The reading of a capture PayPal is reviewing.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PENDING_CAPTURE = 'pending_capture';

	/**
	 * The refund reading of an event: a refund, with SEOCart's uuid when SEOCart asked for it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REFUND = 'refund';

	/**
	 * The reading of money PayPal gave back by itself: a refund SEOCart never asked for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REVERSAL = 'reversal';

	/**
	 * The reading of an order the shopper approved.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const APPROVAL = 'approval';

	/**
	 * The reading of an approval PayPal reversed.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REVERSED_APPROVAL = 'reversed_approval';

	/**
	 * The dispute reading of an event: ignored, naming nothing.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DISPUTE = 'dispute';

	/**
	 * Every event type the gateway reads, and how: the one list of the events the merchant's webhook subscribes to.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const EVENTS = array(
		'PAYMENT.AUTHORIZATION.CREATED'      => self::AUTHORIZATION,
		'PAYMENT.AUTHORIZATION.VOIDED'       => self::VOID,
		'PAYMENT.CAPTURE.COMPLETED'          => self::CAPTURE,
		'PAYMENT.CAPTURE.DECLINED'           => self::CAPTURE,
		'PAYMENT.CAPTURE.PENDING'            => self::PENDING_CAPTURE,
		'PAYMENT.CAPTURE.REFUNDED'           => self::REFUND,
		'PAYMENT.CAPTURE.REVERSED'           => self::REVERSAL,
		'CHECKOUT.ORDER.APPROVED'            => self::APPROVAL,
		'CHECKOUT.PAYMENT-APPROVAL.REVERSED' => self::REVERSED_APPROVAL,
		'CUSTOMER.DISPUTE.CREATED'           => self::DISPUTE,
		'CUSTOMER.DISPUTE.UPDATED'           => self::DISPUTE,
		'CUSTOMER.DISPUTE.RESOLVED'          => self::DISPUTE,
	);

	/**
	 * The shape of a uuid SEOCart wrote into a `custom_id`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

	/**
	 * What SEOCart gives the gateway: its settings, clock, HTTP client and logger.
	 *
	 * @since 0.2.0
	 *
	 * @var ExtensionContext
	 */
	private ExtensionContext $context;

	/**
	 * Builds the reader. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param ExtensionContext $context What SEOCart gives the gateway.
	 */
	public function __construct( ExtensionContext $context ) {
		$this->context = $context;
	}

	/**
	 * Reads one delivery: verified first, then decoded, then mapped.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the certificate the delivery was signed with could not be fetched.
	 *
	 * @param WebhookEnvelope $envelope The delivery, as SEOCart received it.
	 * @return WebhookReading What it says, or why it was rejected or ignored.
	 */
	public function read( WebhookEnvelope $envelope ): WebhookReading {
		$rejection = WebhookSignature::check(
			$envelope->headers,
			$envelope->rawBody,
			(string) $this->context->settings( $envelope->mode )->value( Gateway::WEBHOOK_ID ),
			$envelope->mode,
			$this->context->clock()->now(),
			new CertificateCache( $this->context )
		);

		if ( null !== $rejection ) {
			return WebhookReading::rejected( $rejection );
		}

		$event = self::event( $envelope->rawBody );

		if ( null === $event ) {
			return WebhookReading::rejected( WebhookReading::MALFORMED );
		}

		try {
			return $this->reading( $event );
		} catch ( \UnexpectedValueException ) {
			return WebhookReading::rejected( WebhookReading::MALFORMED );
		}
	}

	/**
	 * Maps a verified event.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When its resource is not what its type says.
	 *
	 * @param array{id: string, type: string, at: \DateTimeImmutable, resource: array<mixed>} $event The event.
	 * @return WebhookReading The reading.
	 */
	private function reading( array $event ): WebhookReading {
		$id       = $event['id'];
		$type     = $event['type'];
		$at       = $event['at'];
		$resource = $event['resource'];
		$kind     = self::EVENTS[ $type ] ?? null;
		$orderId  = PayPalCodes::id( Fields::text( $resource, 'supplementary_data', 'related_ids', 'order_id' ) );

		$ignored = match ( $kind ) {
			null                    => WebhookReading::ignored( $id, self::UNHANDLED_TYPE, self::UNHANDLED_TYPE, $at ),
			self::DISPUTE           => WebhookReading::ignored( $id, $type, WebhookReading::DISPUTE, $at ),
			self::REVERSED_APPROVAL => WebhookReading::ignored( $id, $type, self::APPROVAL_REVERSED, $at ),
			self::PENDING_CAPTURE   => WebhookReading::ignored( $id, $type, self::CAPTURE_PENDING, $at ),
			self::APPROVAL          => WebhookReading::ignored( $id, $type, self::AWAITING_AUTHORIZATION, $at, self::uuid( Fields::text( Results::purchaseUnit( $resource ), 'custom_id' ) ), PayPalCodes::id( Fields::text( $resource, 'id' ) ) ),
			default                 => null,
		};

		if ( null !== $ignored ) {
			return $ignored;
		}

		if ( self::REFUND === $kind || self::REVERSAL === $kind ) {
			return WebhookReading::result( $id, $type, $at, Results::refund( $resource, '', $orderId ), self::REFUND === $kind ? self::uuid( Fields::text( $resource, 'custom_id' ) ) : null );
		}

		$intentUuid = self::uuid( Fields::text( $resource, 'custom_id' ) );

		if ( null === $intentUuid || null === $orderId ) {
			return WebhookReading::ignored( $id, $type, self::NOT_OURS, $at );
		}

		if ( self::AUTHORIZATION === $kind && 'PENDING' === Fields::text( $resource, 'status' ) ) {
			return WebhookReading::ignored( $id, $type, self::AUTHORIZATION_PENDING, $at );
		}

		$result = match ( $kind ) {
			self::AUTHORIZATION => Results::authorization( $resource, $intentUuid, $orderId ),
			self::VOID          => 'VOIDED' === Fields::text( $resource, 'status' ) ? Results::reportedVoid( $resource, $intentUuid, $orderId ) : throw new \UnexpectedValueException( 'A voided authorization\'s event whose authorization is not voided.' ),
			default             => Results::capture( $resource, $intentUuid, $orderId ),
		};

		return WebhookReading::result( $id, $type, $at, $result );
	}

	/**
	 * Returns a `custom_id` when it holds a uuid SEOCart wrote.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value The field.
	 * @return string|null The uuid; null for anything else.
	 */
	private static function uuid( ?string $value ): ?string {
		return null !== $value && 1 === preg_match( self::UUID, $value ) ? $value : null;
	}

	/**
	 * Decodes a verified body into an event, or null when it is not one: its id must be a PayPal event's id (PayPalCodes::eventId()).
	 *
	 * @since 0.2.0
	 *
	 * @param string $rawBody The body.
	 * @return array{id: string, type: string, at: \DateTimeImmutable, resource: array<mixed>}|null The event.
	 */
	private static function event( string $rawBody ): ?array {
		try {
			$event = json_decode( $rawBody, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( \JsonException ) {
			return null;
		}

		if ( ! is_array( $event ) ) {
			return null;
		}

		$id       = PayPalCodes::eventId( Fields::text( $event, 'id' ) );
		$type     = Fields::text( $event, 'event_type' );
		$created  = Fields::text( $event, 'create_time' );
		$resource = Fields::map( $event, 'resource' );

		if ( null === $id || null === $type || null === $created || array() === $resource ) {
			return null;
		}

		try {
			$at = ( new \DateTimeImmutable( $created ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception ) {
			return null;
		}

		return array(
			'id'       => $id,
			'type'     => $type,
			'at'       => $at,
			'resource' => $resource,
		);
	}
}
