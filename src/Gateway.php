<?php
/**
 * Gateway: the PayPal payment gateway
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\OutboundHost;
use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\CapabilityMatrix;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\IdempotencyProfile;
use SEOCart\Contracts\Payment\MatrixRow;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a failed call to SEOCart's payment service; they are never HTML.

/**
 * The PayPal payment gateway: cards and PayPal through PayPal Complete Payments (Orders v2), authorized first and captured later.
 *
 * Owns one fact: what the gateway is and does, as SEOCart asks it. describe() is its one
 * declaration: two modes, the six settings each mode keeps, the capability matrix of the account
 * countries the gateway is certified for (one row per country and currency), the idempotency
 * profile and the four hosts it calls. A payment is a PayPal order with the intent to authorize:
 * the first call creates it (PaymentTokens says how the shopper pays), the shopper approves it at
 * PayPal or in PayPal's card fields, and the status query that follows authorizes it, once, under
 * the liability policy. Capture, void and refund read the order first, for the authorization or
 * capture they act on. Every answer is mapped by Results; webhook deliveries are read by
 * WebhookReader.
 *
 * The gateway keeps nothing between requests: each request asks PayPal for an access token with
 * the mode's client id and secret (PayPalApi), and every answer is mapped and let go. Its log lines
 * name the operation, the payment's uuid and the HTTP status, and repeat what PayPal answered only
 * as PayPalCodes allows: its documented statuses, issues and reasons, and its ids in the shape of
 * an id; never a body, a header, a message or a description. It sends no card number: a card
 * reaches PayPal only through PayPal's own card fields in the shopper's browser, or as the id of a
 * card PayPal vaulted. It moves money only in the currencies it declares.
 *
 * @since 0.1.0
 * @since 0.2.0 Became the PayPal adapter: it declares what PayPal can do and does it.
 */
final class Gateway implements PaymentGateway {

	/**
	 * The gateway's id, which an intent and the ledger record, and which the registration names.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = 'paypal';

	/**
	 * The version of SEOCart's payment contract this gateway is written against.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CONTRACT = '0.2.0';

	/**
	 * The name of the setting that holds a mode's REST app client id, which PayPal's script in the shopper's browser uses too.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CLIENT_ID = 'client_id';

	/**
	 * The name of the setting that holds a mode's REST app secret.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CLIENT_SECRET = 'client_secret';

	/**
	 * The name of the setting that holds the id of the webhook the merchant created for a mode, part of every delivery's signed text.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const WEBHOOK_ID = 'webhook_id';

	/**
	 * The name of the setting that says when PayPal asks the card's bank to confirm a card payment (3-D Secure).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SCA_POLICY = 'sca_policy';

	/**
	 * The name of the setting that says whether a card payment is authorized when its 3-D Secure check left the liability with the store.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIABILITY_POLICY = 'liability_shift_policy';

	/**
	 * The liability policy that declines a card payment unless the card's bank took the liability.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REQUIRE_SHIFT = 'require';

	/**
	 * The liability policy that authorizes a card payment whatever its 3-D Secure check said.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ACCEPT_ANY = 'accept';

	/**
	 * The countries of the PayPal business accounts the gateway is certified for: the capability matrix's rows, and the values the account country setting allows.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const ACCOUNT_COUNTRIES = array( 'US' );

	/**
	 * The currencies the gateway takes, for an account of each certified country.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const CURRENCIES = array( 'USD', 'GBP', 'EUR' );

	/**
	 * What the gateway does in each cell of its matrix.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const OPERATIONS = array(
		Operations::AUTHORIZE,
		Operations::CAPTURE,
		Operations::PARTIAL_CAPTURE,
		Operations::VOID,
		Operations::REFUND,
		Operations::PARTIAL_REFUND,
		Operations::SCA,
		Operations::QUERY,
		Operations::QUERY_REFUND,
		Operations::WEBHOOKS,
	);

	/**
	 * How long PayPal keeps a PayPal-Request-Id and replays its first answer, in seconds: 45 days, as PayPal's reference says of a refund's.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const KEY_RETENTION_SECONDS = 3888000;

	/**
	 * The smallest amount the gateway takes, in minor units: one cent, penny or euro cent.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MINIMUM_MINOR = 1;

	/**
	 * The largest amount the gateway takes, in minor units: 99,999.99, below what PayPal processes in one card payment.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAXIMUM_MINOR = 9999999;

	/**
	 * The decline code of an authorization asked with a token the gateway does not accept (PaymentTokens).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INVALID_TOKEN = 'invalid_payment_token';

	/**
	 * The longest description PayPal keeps on a purchase unit, in characters.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const DESCRIPTION_LENGTH = 127;

	/**
	 * What the PayPal-Request-Id of the call that authorizes an approved order adds to the intent's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AUTHORIZE_KEY_SUFFIX = ':authorize';

	/**
	 * The ids of the hosts of PayPal's REST API, by mode.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const API_HOSTS = array(
		'test' => 'paypal-api-sandbox',
		'live' => 'paypal-api-live',
	);

	/**
	 * Where every path of PayPal's REST API is resolved, by mode.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const API_BASES = array(
		'test' => 'https://api-m.sandbox.paypal.com/',
		'live' => 'https://api-m.paypal.com/',
	);

	/**
	 * The ids of the hosts that serve the certificates PayPal signs webhook deliveries with, by mode.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const CERT_HOSTS = array(
		'test' => 'paypal-certs-sandbox',
		'live' => 'paypal-certs-live',
	);

	/**
	 * Where the certificates PayPal signs webhook deliveries with are served, by mode: a delivery's certificate address must begin with its mode's.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const CERT_BASES = array(
		'test' => 'https://api.sandbox.paypal.com/v1/notifications/certs/',
		'live' => 'https://api.paypal.com/v1/notifications/certs/',
	);

	/**
	 * What SEOCart gives this gateway: its logger, clock, HTTP client and settings.
	 *
	 * @since 0.1.0
	 *
	 * @var ExtensionContext
	 */
	private ExtensionContext $context;

	/**
	 * The requests to PayPal, built on first use.
	 *
	 * @since 0.2.0
	 *
	 * @var PayPalApi|null
	 */
	private ?PayPalApi $api = null;

	/**
	 * Builds the gateway with its context. Reads nothing and sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ExtensionContext $context What SEOCart gives this gateway: `SEOCart\Contracts\Payment\GatewayRegistry::context()`.
	 */
	public function __construct( ExtensionContext $context ) {
		$this->context = $context;
	}

	/**
	 * Returns the gateway's one declaration, built from constants: no read, no request, no WordPress call.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Declares the modes, the settings, the matrix, the idempotency profile and the hosts.
	 *
	 * @return GatewayDescriptor The descriptor.
	 */
	public function describe(): GatewayDescriptor {
		return new GatewayDescriptor(
			self::ID,
			static fn(): string => __( 'PayPal', 'seocart-gateway-for-paypal' ),
			GatewayDescriptor::TYPE_PAYMENTS,
			self::CONTRACT,
			array( Mode::Test, Mode::Live ),
			self::settings(),
			self::matrix(),
			new IdempotencyProfile( self::KEY_RETENTION_SECONDS, true, 0 ),
			self::hosts()
		);
	}

	/**
	 * Returns the id of the host of PayPal's REST API in a mode.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode.
	 * @return string The host's id.
	 */
	public static function apiHostId( Mode $mode ): string {
		return self::API_HOSTS[ $mode->value ];
	}

	/**
	 * Returns where the paths of PayPal's REST API are resolved in a mode.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode.
	 * @return string The base address, ending with `/`.
	 */
	public static function apiBase( Mode $mode ): string {
		return self::API_BASES[ $mode->value ];
	}

	/**
	 * Tells whether the gateway can take a payment: the mode's client id is set, and the amount is one the gateway takes.
	 *
	 * SEOCart asks only once the mode is configured and the matrix allows the currency for the
	 * account's country; this only narrows that. Pure: it reads the context and nothing else.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Narrows by the client id and the gateway's smallest and largest amounts.
	 *
	 * @param AvailabilityContext $context The payment.
	 * @return bool True when the gateway can take it.
	 */
	public function isAvailable( AvailabilityContext $context ): bool {
		$clientId = $context->account[ self::CLIENT_ID ] ?? null;
		$minor    = $context->amount->minorUnits();

		return is_string( $clientId ) && '' !== $clientId
			&& $context->amount->currency()->equals( $context->currency )
			&& in_array( $context->currency->code(), self::CURRENCIES, true )
			&& $minor >= self::MINIMUM_MINOR
			&& $minor <= self::MAXIMUM_MINOR;
	}

	/**
	 * Creates the payment's PayPal order, with the intent to authorize, as the payment token asks (PaymentTokens).
	 *
	 * An order the shopper approves at PayPal answers with PayPal's page and the order's id, which
	 * PayPal's buttons take; an order of PayPal's card fields answers with the order's id for them; a
	 * vaulted card is processed at once, and the order answers with its authorization. The
	 * PayPal-Request-Id is the intent's uuid, so the same authorization asked again is the same order.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Asks PayPal.
	 *
	 * @throws GatewayUnavailable When PayPal gave no answer the gateway can act on.
	 *
	 * @param PaymentRequest $request The intent and the customer's payment token.
	 * @return GatewayResult PayPal's answer, not yet applied.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		$this->requireCurrency( 'authorize', $request->amount );

		$token = PaymentTokens::read( $request->paymentToken, $request->mode );

		if ( null === $token ) {
			return $this->declined( 'authorize', Results::refused( Operation::Authorize, $request->intentUuid, $request->amount, null, null, self::INVALID_TOKEN ) );
		}

		$answer = $this->api()->post( $request->mode, PayPalApi::path( 'v2', 'checkout', 'orders' ), $this->orderBody( $request, $token ), $request->idempotencyKey(), $token->mock );

		return $this->mapped(
			'authorize',
			$request->amount,
			function () use ( $request, $answer, $token ): GatewayResult {
				if ( $answer->isError() ) {
					return $this->refusal( Operation::Authorize, 'authorize', $request->intentUuid, $request->amount, null, $answer );
				}

				$order = $this->ownOrder( 'authorize', $answer->body, $request->intentUuid );

				if ( PaymentTokens::VAULT === $token->kind ) {
					return $this->vaulted( $order, $request->intentUuid );
				}

				if ( ! in_array( Results::orderStatus( $order ), array( 'CREATED', 'PAYER_ACTION_REQUIRED' ), true ) ) {
					throw new \UnexpectedValueException( 'A new PayPal order that waits for no shopper.' );
				}

				return PaymentTokens::CARD === $token->kind ? Results::cardFields( $order, $request->intentUuid ) : Results::approveAtPayPal( $order, $request->intentUuid );
			}
		);
	}

	/**
	 * Captures the authorized amount asked for, all of it or less: the capture is final, and PayPal releases the rest.
	 *
	 * The amount is always sent, so PayPal captures exactly what SEOCart asks. An authorization
	 * captured already answers that capture again. A capture PayPal is still reviewing is no answer
	 * yet: asked again, it is the same capture, under the same PayPal-Request-Id.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Asks PayPal.
	 *
	 * @throws GatewayUnavailable When PayPal gave no answer the gateway can act on, the intent has no order or the order no authorization, or the capture is pending.
	 *
	 * @param CaptureRequest $request The intent and the amount.
	 * @return GatewayResult PayPal's answer, not yet applied.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$this->requireCurrency( 'capture', $request->amount );

		$orderId         = $this->knownOrderId( 'capture', $request->providerIntentId );
		$authorizationId = $this->authorizationId( 'capture', Results::authorizationOf( $this->readOrder( 'capture', $request->mode, $orderId, $request->intentUuid ) ), $orderId );
		$answer          = $this->api()->post(
			$request->mode,
			PayPalApi::path( 'v2', 'payments', 'authorizations', $authorizationId, 'capture' ),
			array(
				'amount'        => Amounts::toPayPal( $request->amount ),
				'final_capture' => true,
			),
			$request->idempotencyKey()
		);

		return $this->mapped(
			'capture',
			$request->amount,
			function () use ( $request, $answer, $orderId ): GatewayResult {
				if ( ! $answer->isError() ) {
					return $this->captured( $answer->body, $request->intentUuid, $orderId );
				}

				if ( $answer->isIssue( 'AUTHORIZATION_ALREADY_CAPTURED', 'PREVIOUSLY_CAPTURED' ) ) {
					$capture = Results::captureOf( $this->readOrder( 'capture', $request->mode, $orderId, $request->intentUuid ) );

					return array() === $capture ? $this->unexpected( 'capture', 'no_capture', $orderId ) : $this->captured( $capture, $request->intentUuid, $orderId );
				}

				return $this->refusal( Operation::Capture, 'capture', $request->intentUuid, $request->amount, $orderId, $answer );
			}
		);
	}

	/**
	 * Voids the order's authorization, releasing what it holds; an order nothing was authorized on yet needs no request.
	 *
	 * An approved void reports the request's amount: what the void released. PayPal has no call that
	 * cancels an order awaiting the shopper, and such an order holds no money, so it is answered an
	 * approved void named by the order, with nothing sent; the order lapses at PayPal. An
	 * authorization voided or expired already answers that void again.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When PayPal gave no answer the gateway can act on, the intent has no order, or its authorization is captured.
	 *
	 * @param VoidRequest $request The intent and why it is voided.
	 * @return GatewayResult PayPal's answer, not yet applied.
	 */
	public function void( VoidRequest $request ): GatewayResult {
		$orderId       = $this->knownOrderId( 'void', $request->providerIntentId );
		$authorization = Results::authorizationOf( $this->readOrder( 'void', $request->mode, $orderId, $request->intentUuid ) );

		if ( array() === $authorization ) {
			return $this->mapped( 'void', $request->amount, static fn(): GatewayResult => Results::voided( $request->intentUuid, $request->amount, $orderId, $orderId ) );
		}

		$authorizationId = $this->authorizationId( 'void', $authorization, $orderId );
		$status          = Fields::text( $authorization, 'status' );

		if ( in_array( $status, array( 'VOIDED', 'EXPIRED' ), true ) ) {
			return $this->mapped( 'void', $request->amount, static fn(): GatewayResult => Results::voided( $request->intentUuid, $request->amount, $authorizationId, $orderId ) );
		}

		if ( ! in_array( $status, array( 'CREATED', 'PENDING' ), true ) ) {
			return $this->unexpected( 'void', PayPalCodes::paymentStatus( $status ) ?? 'unknown_status', $orderId );
		}

		$answer = $this->api()->post( $request->mode, PayPalApi::path( 'v2', 'payments', 'authorizations', $authorizationId, 'void' ), array(), $request->idempotencyKey() );

		if ( $answer->isError() && ! $answer->isIssue( 'PREVIOUSLY_VOIDED', 'AUTHORIZATION_VOIDED', 'AUTHORIZATION_EXPIRED' ) ) {
			if ( $answer->isIssue( 'PREVIOUSLY_CAPTURED', 'AUTHORIZATION_ALREADY_CAPTURED' ) ) {
				return $this->unexpected( 'void', 'CAPTURED', $orderId );
			}

			$this->refusedRequest( 'void', $answer );
		}

		return $this->mapped( 'void', $request->amount, static fn(): GatewayResult => Results::voided( $request->intentUuid, $request->amount, $authorizationId, $orderId ) );
	}

	/**
	 * Gives back part or all of the captured amount, tagged with the refund's uuid so it can be found again on the order.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Asks PayPal.
	 *
	 * @throws GatewayUnavailable When PayPal gave no answer the gateway can act on, or the intent has no order or the order no capture.
	 *
	 * @param GatewayRefund $request The intent, the amount and the refund's uuid.
	 * @return GatewayResult PayPal's answer, not yet applied: an approval names PayPal's refund.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$this->requireCurrency( 'refund', $request->amount );

		$orderId   = $this->knownOrderId( 'refund', $request->providerIntentId );
		$captureId = PayPalCodes::id( Fields::text( Results::captureOf( $this->readOrder( 'refund', $request->mode, $orderId, $request->intentUuid ) ), 'id' ) ) ?? $this->unexpected( 'refund', 'no_capture', $orderId );
		$answer    = $this->api()->post(
			$request->mode,
			PayPalApi::path( 'v2', 'payments', 'captures', $captureId, 'refund' ),
			array(
				'amount'    => Amounts::toPayPal( $request->amount ),
				'custom_id' => $request->refundUuid,
			),
			$request->idempotencyKey()
		);

		return $this->mapped(
			'refund',
			$request->amount,
			function () use ( $request, $answer, $orderId ): GatewayResult {
				if ( $answer->isError() ) {
					return $this->refusal( Operation::Refund, 'refund', $request->intentUuid, $request->amount, $orderId, $answer );
				}

				$result = Results::refund( $answer->body, $request->intentUuid, $orderId );

				return Outcome::Declined === $result->outcome ? $this->declined( 'refund', $result ) : $result;
			}
		);
	}

	/**
	 * Finds a refund by its uuid among the refunds PayPal lists on the order, which it does at once.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Asks PayPal.
	 *
	 * @throws GatewayUnavailable When PayPal's answer cannot be read, the recorded id is not an order's, the order is another intent's, or a refund it lists is not named by a PayPal id (every one is checked before any is compared).
	 *
	 * @param GatewayRefund $request The refund, as it was asked.
	 * @return GatewayResult|null The refund's result; a refund declined `not_found` when the order holds a
	 *                            capture and no refund under the uuid; null when the intent has no order,
	 *                            the order no capture, or PayPal refused the read.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		if ( null === $request->providerIntentId ) {
			return null;
		}

		$orderId = $this->knownOrderId( 'query_refund', $request->providerIntentId );
		$answer  = $this->api()->get( $request->mode, PayPalApi::path( 'v2', 'checkout', 'orders', $orderId ) );

		if ( $answer->isError() ) {
			return null;
		}

		$order   = $this->ownOrder( 'query_refund', $answer->body, $request->intentUuid );
		$refunds = Results::refundsOf( $order );

		foreach ( $refunds as $refund ) {
			PayPalCodes::id( Fields::text( $refund, 'id' ) ) ?? $this->unexpected( 'query_refund', 'not_a_refund', $orderId );
		}

		foreach ( $refunds as $refund ) {
			if ( Fields::text( $refund, 'custom_id' ) === $request->refundUuid ) {
				return $this->mapped( 'query_refund', $request->amount, static fn(): GatewayResult => Results::refund( $refund, $request->intentUuid, $orderId ) );
			}
		}

		if ( array() === Results::captureOf( $order ) ) {
			return null;
		}

		return $this->mapped( 'query_refund', $request->amount, static fn(): GatewayResult => Results::refundNotFound( $request->intentUuid, $request->amount, $orderId ) );
	}

	/**
	 * Asks where a payment stands, by its order, and authorizes the order once the shopper approved it.
	 *
	 * An order still waiting for the shopper is no answer yet. An approved order holds no money
	 * until it is authorized: the liability policy is checked first, then the order is authorized
	 * under its own PayPal-Request-Id, so two queries at once make one authorization. A completed
	 * order answers its capture or its authorization; a voided one, or an authorization voided or
	 * expired, is declined `expired`. An order PayPal does not know is declined `not_found`. PayPal
	 * cannot be searched by the intent's uuid, so an intent without an order is no answer.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Asks PayPal.
	 *
	 * @throws GatewayUnavailable When PayPal gave no answer the gateway can act on, or the recorded id is not an order's.
	 *
	 * @param PaymentQuery $query The intent.
	 * @return GatewayResult|null The latest answer; null while it is still deciding, or when the intent has no order.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		if ( null === $query->providerIntentId ) {
			return null;
		}

		$orderId = $this->knownOrderId( 'query', $query->providerIntentId );
		$answer  = $this->api()->get( $query->mode, PayPalApi::path( 'v2', 'checkout', 'orders', $orderId ) );

		if ( $answer->isError() ) {
			if ( 404 === $answer->status && 'RESOURCE_NOT_FOUND' === $answer->errorName() ) {
				return $this->mapped( 'query', $query->amount, static fn(): GatewayResult => Results::notFound( $query->intentUuid, $query->amount, $orderId ) );
			}

			$this->refusedRequest( 'query', $answer );
		}

		return $this->mapped( 'query', $query->amount, fn(): ?GatewayResult => $this->queried( $query, $this->ownOrder( 'query', $answer->body, $query->intentUuid ) ) );
	}

	/**
	 * Reads a delivery PayPal sent to SEOCart's webhook: verified first, then parsed.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Reads it (WebhookReader).
	 *
	 * @throws GatewayUnavailable When the certificate the delivery was signed with could not be fetched: SEOCart answers 503, and PayPal sends it again.
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @return WebhookReading What it says, or why it was rejected or ignored.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading {
		return ( new WebhookReader( $this->context ) )->read( $envelope );
	}

	/**
	 * Returns the requests to PayPal, built on first use.
	 *
	 * @since 0.2.0
	 *
	 * @return PayPalApi The requests.
	 */
	private function api(): PayPalApi {
		return $this->api ??= new PayPalApi( $this->context );
	}

	/**
	 * Returns the body of the order an authorization creates: one purchase unit, and the payment source the token asks for.
	 *
	 * No card field is ever part of it: a card is typed into PayPal's card fields, or named by the id PayPal vaulted it under.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentRequest $request The authorization.
	 * @param PaymentTokens  $token   What its token asks for.
	 * @return array<string, mixed> The body.
	 */
	private function orderBody( PaymentRequest $request, PaymentTokens $token ): array {
		$unit = array(
			'amount'    => Amounts::toPayPal( $request->amount ),
			'custom_id' => $request->intentUuid,
		);

		if ( '' !== $request->orderNumber ) {
			$unit['description'] = mb_substr( $request->orderNumber, 0, self::DESCRIPTION_LENGTH );
		}

		return array(
			'intent'         => 'AUTHORIZE',
			'purchase_units' => array( $unit ),
			'payment_source' => match ( $token->kind ) {
				PaymentTokens::CARD   => array( 'card' => array( 'attributes' => array( 'verification' => array( 'method' => $this->scaPolicy( $request->mode ) ) ) ) ),
				PaymentTokens::VAULT  => array(
					'card' => array(
						'vault_id'          => $token->vaultId,
						'stored_credential' => array(
							'payment_initiator' => 'CUSTOMER',
							'payment_type'      => 'ONE_TIME',
							'usage'             => 'SUBSEQUENT',
						),
					),
				),
				default               => array( 'paypal' => array( 'experience_context' => self::experience( $request->returnUrl ) ) ),
			},
		);
	}

	/**
	 * Returns how PayPal's page presents an order the shopper approves there, and where it sends them back.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $returnUrl Where the store takes the shopper back; null for an API client, which takes them back itself.
	 * @return array<string, string> The experience context.
	 */
	private static function experience( ?string $returnUrl ): array {
		$context = array(
			'user_action'         => 'PAY_NOW',
			'shipping_preference' => 'NO_SHIPPING',
		);

		return null === $returnUrl ? $context : $context + array(
			'return_url' => $returnUrl,
			'cancel_url' => $returnUrl,
		);
	}

	/**
	 * Returns when a mode asks the card's bank to confirm a card payment: its setting, or PayPal's default for one PayPal does not know.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode.
	 * @return string `SCA_WHEN_REQUIRED` or `SCA_ALWAYS`.
	 */
	private function scaPolicy( Mode $mode ): string {
		$policy = $this->context->settings( $mode )->value( self::SCA_POLICY );

		return in_array( $policy, PayPalCodes::SCA_METHODS, true ) ? (string) $policy : PayPalCodes::SCA_METHODS[0];
	}

	/**
	 * Maps the order a vaulted card's authorization created: PayPal processed it at once, and its authorization says how it went.
	 *
	 * A card whose bank asks the shopper to confirm the payment answers with PayPal's page for it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the order is in a state the mapping does not know, or completed without an authorization.
	 *
	 * @param array<mixed> $order      The order.
	 * @param string       $intentUuid The intent.
	 * @return GatewayResult The result.
	 */
	private function vaulted( array $order, string $intentUuid ): GatewayResult {
		$status = Results::orderStatus( $order );

		if ( 'PAYER_ACTION_REQUIRED' === $status ) {
			return Results::approveAtPayPal( $order, $intentUuid );
		}

		$authorization = Results::authorizationOf( $order );

		if ( 'COMPLETED' !== $status || array() === $authorization ) {
			throw new \UnexpectedValueException( 'A vaulted card\'s order that PayPal did not process.' );
		}

		$result = Results::authorization( $authorization, $intentUuid, Results::orderId( $order ) );

		return Outcome::Declined === $result->outcome ? $this->declined( 'authorize', $result ) : $result;
	}

	/**
	 * Answers a query from the order as PayPal holds it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the order is in a state or a shape the mapping does not know.
	 * @throws GatewayUnavailable        When authorizing the approved order got no answer the gateway can act on.
	 *
	 * @param PaymentQuery $query The query.
	 * @param array<mixed> $order The order.
	 * @return GatewayResult|null The answer; null while the shopper or PayPal has not decided.
	 */
	private function queried( PaymentQuery $query, array $order ): ?GatewayResult {
		return match ( Results::orderStatus( $order ) ) {
			'CREATED', 'SAVED', 'PAYER_ACTION_REQUIRED' => null,
			'APPROVED'                                  => $this->resume( $query, $order ),
			'VOIDED'                                    => Results::expiredOrder( $order, $query->intentUuid ),
			default                                     => $this->settled( $order, $query->intentUuid ),
		};
	}

	/**
	 * Authorizes an order the shopper approved, once the liability policy allows it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When PayPal's answer cannot be read.
	 * @throws GatewayUnavailable        When PayPal gave no answer the gateway can act on.
	 *
	 * @param PaymentQuery $query The query.
	 * @param array<mixed> $order The approved order.
	 * @return GatewayResult|null The authorization's result; null while PayPal reviews it.
	 */
	private function resume( PaymentQuery $query, array $order ): ?GatewayResult {
		$orderId = Results::orderId( $order );

		if ( ! $this->liabilityAllows( $query->mode, $order, $query->intentUuid ) ) {
			return $this->declined( 'query', Results::liabilityNotShifted( $order, $query->intentUuid ) );
		}

		$answer = $this->api()->post( $query->mode, PayPalApi::path( 'v2', 'checkout', 'orders', $orderId, 'authorize' ), array(), $query->intentUuid . self::AUTHORIZE_KEY_SUFFIX );

		if ( ! $answer->isError() ) {
			return $this->settled( $this->ownOrder( 'query', $answer->body, $query->intentUuid ), $query->intentUuid );
		}

		if ( $answer->isIssue( 'ORDER_ALREADY_AUTHORIZED' ) ) {
			return $this->settled( $this->readOrder( 'query', $query->mode, $orderId, $query->intentUuid ), $query->intentUuid );
		}

		if ( $answer->isIssue( 'ORDER_EXPIRED', 'ORDER_COMPLETED_OR_VOIDED' ) ) {
			return $this->declined( 'query', Results::expiredOrder( $order, $query->intentUuid ) );
		}

		return $this->refusal( Operation::Authorize, 'query', $query->intentUuid, $query->amount, $orderId, $answer );
	}

	/**
	 * Tells whether the liability policy lets an approved order be authorized.
	 *
	 * A payment by PayPal, and a card PayPal did not ask the bank to check, are authorized. A card
	 * whose 3-D Secure check moved the liability to the bank (`YES`, `POSSIBLE`) is too; otherwise
	 * (`NO`, `UNKNOWN`, or no result for a checked card) the policy `require` declines it and
	 * `accept` authorizes it, logging the result's word.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode         $mode       The payment's mode, whose policy applies.
	 * @param array<mixed> $order      The approved order.
	 * @param string       $intentUuid The intent, for the log.
	 * @return bool True when the order may be authorized.
	 */
	private function liabilityAllows( Mode $mode, array $order, string $intentUuid ): bool {
		$checked = Fields::map( $order, 'payment_source', 'card', 'authentication_result' );

		if ( array() === $checked ) {
			return true;
		}

		$shift = PayPalCodes::liabilityShift( Fields::text( $checked, 'liability_shift' ) );

		if ( in_array( $shift, PayPalCodes::LIABILITY_SHIFTED, true ) ) {
			return true;
		}

		$allows = self::ACCEPT_ANY === $this->context->settings( $mode )->value( self::LIABILITY_POLICY );

		$this->context->logger()->info(
			'paypal.liability_shift',
			$allows ? 'A card payment\'s bank check left the liability with the store, and the policy accepts it.' : 'A card payment\'s bank check left the liability with the store, so the policy declines it.',
			array(
				'intent_uuid'        => $intentUuid,
				'provider_intent_id' => PayPalCodes::logged( Fields::text( $order, 'id' ) ),
				'liability_shift'    => $shift,
				'policy'             => $allows ? self::ACCEPT_ANY : self::REQUIRE_SHIFT,
			)
		);

		return $allows;
	}

	/**
	 * Answers from an order PayPal processed: its capture once taken, otherwise its authorization.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the order holds neither, or one in a state the mapping does not know.
	 *
	 * @param array<mixed> $order      The order.
	 * @param string       $intentUuid The intent.
	 * @return GatewayResult|null The result; null while PayPal reviews the authorization or the capture.
	 */
	private function settled( array $order, string $intentUuid ): ?GatewayResult {
		$orderId = Results::orderId( $order );
		$capture = Results::captureOf( $order );
		$status  = Fields::text( $capture, 'status' );

		if ( in_array( $status, array( 'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED' ), true ) ) {
			return Results::capture( $capture, $intentUuid, $orderId );
		}

		$authorization = Results::authorizationOf( $order );

		if ( array() === $authorization ) {
			throw new \UnexpectedValueException( 'A PayPal order processed without an authorization.' );
		}

		if ( 'PENDING' === $status || 'PENDING' === Fields::text( $authorization, 'status' ) ) {
			return null;
		}

		$result = Results::authorization( $authorization, $intentUuid, $orderId );

		return Outcome::Declined === $result->outcome && Results::EXPIRED !== $result->errorCode ? $this->declined( 'query', $result ) : $result;
	}

	/**
	 * Maps a capture PayPal answered: a capture PayPal is still reviewing is no answer yet.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the capture is pending: SEOCart asks again later, under the same PayPal-Request-Id.
	 *
	 * @param array<mixed> $capture    The capture.
	 * @param string       $intentUuid The intent.
	 * @param string       $orderId    The order.
	 * @return GatewayResult The result.
	 */
	private function captured( array $capture, string $intentUuid, string $orderId ): GatewayResult {
		if ( 'PENDING' === Fields::text( $capture, 'status' ) ) {
			$this->context->logger()->warning(
				'paypal.capture_pending',
				'PayPal is still reviewing a capture, so nothing was applied yet; the capture is asked for again later.',
				array(
					'intent_uuid'        => $intentUuid,
					'provider_intent_id' => PayPalCodes::logged( $orderId ),
					'provider_object_id' => PayPalCodes::logged( Fields::text( $capture, 'id' ) ),
					'reason'             => PayPalCodes::reason( Fields::text( $capture, 'status_details', 'reason' ) ),
				)
			);

			throw new GatewayUnavailable( 'PayPal is still reviewing the capture.' );
		}

		$result = Results::capture( $capture, $intentUuid, $orderId );

		return Outcome::Declined === $result->outcome ? $this->declined( 'capture', $result ) : $result;
	}

	/**
	 * Reads an order, and checks it is the one SEOCart created for this intent.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When PayPal refused the read or gave no answer, or the order is another intent's.
	 *
	 * @param string $operation  The operation, for the log.
	 * @param Mode   $mode       The mode.
	 * @param string $orderId    The order.
	 * @param string $intentUuid The intent.
	 * @return array<mixed> The order.
	 */
	private function readOrder( string $operation, Mode $mode, string $orderId, string $intentUuid ): array {
		$answer = $this->api()->get( $mode, PayPalApi::path( 'v2', 'checkout', 'orders', $orderId ) );

		if ( $answer->isError() ) {
			$this->refusedRequest( $operation, $answer );
		}

		return $this->ownOrder( $operation, $answer->body, $intentUuid );
	}

	/**
	 * Returns the id of an order's authorization, which a capture and a void act on.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the order has no authorization, or its id is not a PayPal id.
	 *
	 * @param string       $operation     The operation, for the log.
	 * @param array<mixed> $authorization The order's authorization (Results::authorizationOf()); empty for none.
	 * @param string       $orderId       The order's id.
	 * @return string The authorization's id.
	 */
	private function authorizationId( string $operation, array $authorization, string $orderId ): string {
		if ( array() === $authorization ) {
			return $this->unexpected( $operation, 'no_authorization', $orderId );
		}

		return PayPalCodes::id( Fields::text( $authorization, 'id' ) ) ?? $this->unexpected( $operation, 'not_an_authorization', $orderId );
	}

	/**
	 * Refuses, before any request is built, to move money in a currency the gateway does not declare.
	 *
	 * SEOCart refuses such an operation itself, before it asks the gateway (its capability
	 * matrix); this keeps a direct call from sending PayPal a currency the matrix never offered.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the amount is in another currency than CURRENCIES names.
	 *
	 * @param string $operation The operation, for the log.
	 * @param Money  $amount    The amount the request would move.
	 */
	private function requireCurrency( string $operation, Money $amount ): void {
		$code = $amount->currency()->code();

		if ( in_array( $code, self::CURRENCIES, true ) ) {
			return;
		}

		$this->context->logger()->warning(
			'paypal.currency_refused',
			'The gateway was asked to move money in a currency it does not take, so nothing was sent.',
			array(
				'operation' => $operation,
				'currency'  => $code,
			)
		);

		throw new GatewayUnavailable( sprintf( 'The PayPal gateway takes %1$s, not %2$s.', implode( ', ', self::CURRENCIES ), $code ) );
	}

	/**
	 * Returns the order an operation is for, as SEOCart recorded it, when it has the shape of a PayPal order's id.
	 *
	 * The id comes from SEOCart's ledger; what is not a PayPal id, such as a card number, is
	 * refused before any request is built, so it never reaches PayPal or a log line.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the intent has none, or the id is not a PayPal id.
	 *
	 * @param string      $operation The operation, for the log.
	 * @param string|null $orderId   The order's id, as SEOCart recorded it.
	 * @return string The id.
	 */
	private function knownOrderId( string $operation, ?string $orderId ): string {
		if ( null === $orderId ) {
			return $this->unexpected( $operation, 'no_order', null );
		}

		return PayPalCodes::id( $orderId ) ?? $this->unexpected( $operation, 'not_an_order', null );
	}

	/**
	 * Returns an order PayPal answered with, after checking it is the one SEOCart created for this intent: its purchase unit's `custom_id` is the intent's uuid.
	 *
	 * A wrong id recorded on an intent must not capture, void or report someone else's payment.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When it is not an order, or names another intent.
	 *
	 * @param string       $operation  The operation, for the log.
	 * @param array<mixed> $order      The object PayPal answered with.
	 * @param string       $intentUuid The intent the request was for.
	 * @return array<mixed> The order.
	 */
	private function ownOrder( string $operation, array $order, string $intentUuid ): array {
		if ( null !== PayPalCodes::id( Fields::text( $order, 'id' ) ) && Fields::text( Results::purchaseUnit( $order ), 'custom_id' ) === $intentUuid ) {
			return $order;
		}

		$this->context->logger()->error(
			'paypal.foreign_order',
			'PayPal answered with an order that was not created for this payment, so nothing was applied.',
			array(
				'operation'          => $operation,
				'intent_uuid'        => $intentUuid,
				'provider_intent_id' => PayPalCodes::logged( Fields::text( $order, 'id' ) ),
			)
		);

		throw new GatewayUnavailable( 'PayPal answered with an order that was not created for this payment.' );
	}

	/**
	 * Maps an answer, turning a shape or a state the mapping does not know, or a result in another currency than the request's, into GatewayUnavailable.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the answer cannot be read.
	 *
	 * @template T of GatewayResult|null
	 *
	 * @param string   $operation The operation, for the log.
	 * @param Money    $asked     The request's amount: a result in another currency is not this request's.
	 * @param \Closure $map       Maps the answer.
	 * @return GatewayResult|null What the mapping returned.
	 *
	 * @phpstan-param \Closure(): T $map
	 * @phpstan-return T
	 */
	private function mapped( string $operation, Money $asked, \Closure $map ): ?GatewayResult {
		try {
			$result = $map();

			self::requireCurrencyOf( $result, $asked );

			return $result;
		} catch ( \UnexpectedValueException $unreadable ) {
			$this->context->logger()->warning(
				'paypal.unexpected_answer',
				'PayPal answered in a shape or a state the gateway does not read, so nothing was applied.',
				array(
					'operation' => $operation,
					'problem'   => get_class( $unreadable ),
				)
			);

			throw new GatewayUnavailable( 'PayPal answered in a shape or a state the gateway does not read.', 0, $unreadable );
		}
	}

	/**
	 * Refuses a result in another currency than the request's: it is not this request's answer.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the result's currency is not the request's.
	 *
	 * @param GatewayResult|null $result The result; null for none.
	 * @param Money              $asked  The request's amount.
	 */
	private static function requireCurrencyOf( ?GatewayResult $result, Money $asked ): void {
		if ( null !== $result && $result->amount->currency()->code() !== $asked->currency()->code() ) {
			throw new \UnexpectedValueException( 'PayPal answered in another currency than the request\'s.' );
		}
	}

	/**
	 * Maps an error PayPal gave about a request that moves money: a decline of the amount asked, named by PayPal's reference for the answer, with its issue as the code.
	 *
	 * An error other than a business refusal (HTTP 422) is logged too: the gateway built a request
	 * PayPal could not take.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the contract refuses the result.
	 *
	 * @param Operation   $kind       The operation declined.
	 * @param string      $operation  The operation, for the log.
	 * @param string      $intentUuid The intent.
	 * @param Money       $amount     The amount asked.
	 * @param string|null $orderId    The order, when the request named one.
	 * @param ApiAnswer   $answer     PayPal's error.
	 * @return GatewayResult The decline.
	 */
	private function refusal( Operation $kind, string $operation, string $intentUuid, Money $amount, ?string $orderId, ApiAnswer $answer ): GatewayResult {
		if ( 422 !== $answer->status ) {
			$this->noteRefused( $operation, $answer );
		}

		$issue = $answer->issue();

		return $this->declined( $operation, Results::refused( $kind, $intentUuid, $amount, $answer->debugId(), $orderId, null === $issue ? Results::DECLINED : strtolower( $issue ) ) );
	}

	/**
	 * Logs a decline, and returns it.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $operation The operation.
	 * @param GatewayResult $result    The decline.
	 * @return GatewayResult The same decline.
	 */
	private function declined( string $operation, GatewayResult $result ): GatewayResult {
		$this->context->logger()->info(
			'paypal.declined',
			'PayPal declined a request.',
			array(
				'operation'          => $operation,
				'intent_uuid'        => $result->intentUuid,
				'provider_intent_id' => PayPalCodes::logged( $result->providerIntentId ),
				'provider_object_id' => PayPalCodes::logged( $result->providerObjectId ),
				'error_code'         => null !== $result->errorCode && 1 === preg_match( '/^[a-z0-9_]{1,64}\z/', $result->errorCode ) ? $result->errorCode : null,
			)
		);

		return $result;
	}

	/**
	 * Stops a request PayPal refused that has no meaning SEOCart applies, such as a read of an order PayPal does not know.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable Always.
	 *
	 * @param string    $operation The operation.
	 * @param ApiAnswer $answer    PayPal's error.
	 * @return never
	 */
	private function refusedRequest( string $operation, ApiAnswer $answer ): never {
		$this->noteRefused( $operation, $answer );

		throw new GatewayUnavailable( sprintf( 'PayPal refused the %1$s request: HTTP %2$d.', $operation, $answer->status ) );
	}

	/**
	 * Logs a request PayPal refused as malformed or unknown: a request the gateway should not have sent as it did.
	 *
	 * @since 0.2.0
	 *
	 * @param string    $operation The operation.
	 * @param ApiAnswer $answer    PayPal's error.
	 */
	private function noteRefused( string $operation, ApiAnswer $answer ): void {
		$this->context->logger()->error(
			'paypal.request_refused',
			'PayPal refused a request the gateway sent, so nothing was applied.',
			array(
				'operation'   => $operation,
				'http_status' => $answer->status,
				'error_name'  => $answer->errorName(),
				'issue'       => $answer->issue(),
				'debug_id'    => PayPalCodes::logged( $answer->debugId() ),
			)
		);
	}

	/**
	 * Stops an operation that met a state it cannot act on.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable Always.
	 *
	 * @param string      $operation The operation.
	 * @param string      $state     What was met: a word of the gateway's, such as `no_order`, or a status PayPal documents.
	 * @param string|null $orderId   The order, when there is one.
	 * @return never
	 */
	private function unexpected( string $operation, string $state, ?string $orderId ): never {
		$this->context->logger()->warning(
			'paypal.unexpected_state',
			'The gateway met a state it cannot act on, so nothing was applied.',
			array(
				'operation'          => $operation,
				'status'             => $state,
				'provider_intent_id' => PayPalCodes::logged( $orderId ),
			)
		);

		throw new GatewayUnavailable( sprintf( 'The PayPal %1$s met a state it cannot act on: %2$s.', $operation, $state ) );
	}

	/**
	 * Declares the settings each mode keeps.
	 *
	 * @since 0.2.0
	 *
	 * @return list<FieldSpec> The settings.
	 */
	private static function settings(): array {
		return array(
			new FieldSpec(
				name: self::CLIENT_ID,
				type: FieldType::String,
				description: 'The client id of the PayPal REST app for this mode, which PayPal\'s script in the shopper\'s browser uses too.',
				label: static fn(): string => __( 'Client ID', 'seocart-gateway-for-paypal' ),
				example: 'AExampleClientId',
				max_length: 128
			),
			new FieldSpec(
				name: self::CLIENT_SECRET,
				type: FieldType::String,
				description: 'The secret of the PayPal REST app for this mode, with which the gateway asks PayPal for an access token.',
				label: static fn(): string => __( 'Secret', 'seocart-gateway-for-paypal' ),
				example: 'EExampleSecret',
				max_length: 255,
				privacy: Privacy::Secret
			),
			new FieldSpec(
				name: self::WEBHOOK_ID,
				type: FieldType::String,
				description: 'The id of the webhook created in the PayPal REST app for this mode\'s address, which every delivery\'s signature covers.',
				label: static fn(): string => __( 'Webhook ID', 'seocart-gateway-for-paypal' ),
				example: '1JE4291016473214C',
				max_length: 64
			),
			new FieldSpec(
				name: GatewayDescriptor::ACCOUNT_COUNTRY,
				type: FieldType::String,
				description: 'The country of the PayPal business account, which selects what the gateway can do.',
				label: static fn(): string => __( 'PayPal account country', 'seocart-gateway-for-paypal' ),
				example: self::ACCOUNT_COUNTRIES[0],
				allowed: self::ACCOUNT_COUNTRIES,
				max_length: 2
			),
			new FieldSpec(
				name: self::SCA_POLICY,
				type: FieldType::String,
				description: 'When PayPal asks the card\'s bank to confirm a card payment (3-D Secure): when the bank or the law requires it, or always.',
				label: static fn(): string => __( '3-D Secure', 'seocart-gateway-for-paypal' ),
				example: PayPalCodes::SCA_METHODS[0],
				default_value: PayPalCodes::SCA_METHODS[0],
				allowed: PayPalCodes::SCA_METHODS,
				max_length: 32
			),
			new FieldSpec(
				name: self::LIABILITY_POLICY,
				type: FieldType::String,
				description: 'Whether a card payment whose 3-D Secure check left the liability for fraud with the store is declined (require) or authorized (accept).',
				label: static fn(): string => __( 'Liability shift', 'seocart-gateway-for-paypal' ),
				example: self::REQUIRE_SHIFT,
				default_value: self::REQUIRE_SHIFT,
				allowed: array( self::REQUIRE_SHIFT, self::ACCEPT_ANY ),
				max_length: 16
			),
		);
	}

	/**
	 * Declares the capability matrix: every operation, in every currency, for an account of every certified country.
	 *
	 * @since 0.2.0
	 *
	 * @return CapabilityMatrix The matrix.
	 */
	private static function matrix(): CapabilityMatrix {
		$rows = array();

		foreach ( self::ACCOUNT_COUNTRIES as $country ) {
			foreach ( self::CURRENCIES as $currency ) {
				$rows[] = new MatrixRow( Currency::of( $currency ), $country, self::OPERATIONS );
			}
		}

		return new CapabilityMatrix( $rows );
	}

	/**
	 * Declares the four services the gateway calls, as the plugin's readme discloses them: PayPal's REST API and the address of its webhook certificates, each in the sandbox and live.
	 *
	 * @since 0.2.0
	 *
	 * @return list<OutboundHost> The hosts.
	 */
	private static function hosts(): array {
		$api   = array(
			'purpose'  => 'PayPal is a payment processing service. SEOCart Gateway for PayPal uses its REST API to create the store\'s PayPal orders and to authorize, capture, void and refund their payments, and to look up a payment whose answer was lost.',
			'dataSent' => 'The REST app\'s client id and secret (for an access token), the order\'s amount and currency, its order number and SEOCart\'s reference for the payment, the address PayPal returns the shopper to, the id of a card PayPal saved, and refund amounts and references. No card number, name, address or email address.',
			'sentWhen' => 'When a customer places an order paid with PayPal; when SEOCart checks a payment the customer approved at PayPal or whose answer it did not receive; and when an order\'s payment is captured, voided or refunded. Nothing is sent before PayPal is configured.',
		);
		$certs = array(
			'purpose'  => 'PayPal signs each webhook delivery it sends the store. SEOCart Gateway for PayPal downloads the public certificate a delivery names, from PayPal, to check the signature, and keeps it for a day.',
			'dataSent' => 'The address of the certificate, as PayPal names it in the delivery. Nothing about the store or its customers.',
			'sentWhen' => 'When PayPal delivers a webhook signed with a certificate the store has not downloaded in the last day.',
		);

		return array(
			self::host( self::API_HOSTS['live'], 'PayPal', 'https://api-m.paypal.com/*', $api ),
			self::host( self::API_HOSTS['test'], 'PayPal (sandbox)', 'https://api-m.sandbox.paypal.com/*', $api ),
			self::host( self::CERT_HOSTS['live'], 'PayPal webhook certificates', self::CERT_BASES['live'] . '*', $certs ),
			self::host( self::CERT_HOSTS['test'], 'PayPal webhook certificates (sandbox)', self::CERT_BASES['test'] . '*', $certs ),
		);
	}

	/**
	 * Builds one declared host.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $id       The host's id.
	 * @param string                $service  The service's name.
	 * @param string                $endpoint The address pattern.
	 * @param array<string, string> $texts    What it is for, what it is sent and when.
	 * @return OutboundHost The host.
	 */
	private static function host( string $id, string $service, string $endpoint, array $texts ): OutboundHost {
		return new OutboundHost(
			id: $id,
			service: $service,
			purpose: $texts['purpose'],
			endpoint: $endpoint,
			dataSent: $texts['dataSent'],
			sentWhen: $texts['sentWhen'],
			termsUrl: 'https://www.paypal.com/legalhub/paypal/useragreement-full',
			privacyUrl: 'https://www.paypal.com/myaccount/privacy/privacyhub',
			timeoutSeconds: 30
		);
	}
}
