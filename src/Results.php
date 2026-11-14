<?php
/**
 * Results: what a PayPal order, authorization, capture or refund means, as SEOCart applies it
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\NextAction;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Support\Money;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report an answer the gateway cannot read, which it turns into GatewayUnavailable; they are never HTML.

/**
 * Maps PayPal's objects, as PayPal answered them, to the GatewayResult SEOCart applies.
 *
 * Owns one fact: what each of PayPal's states means for a payment. One mapping serves the answer
 * to a request, the answer to a status query and a webhook delivery, so the same state always
 * means the same result. Pure: it reads only the arrays it is given, keeps nothing and logs nothing.
 *
 * The order is the result's provider intent: the object every later request reads. The object of
 * a result is PayPal's object for that outcome: the authorization for an authorization (approved,
 * declined or voided), the capture for a capture, the refund for a refund, and the order itself for
 * a void that needed no request, as nothing was authorized yet. A decline carries PayPal's reason
 * or issue as a lower-case code, never its message, which is written for the shopper. A request for
 * the shopper to act says what they must do: approve the order on PayPal's page, or in PayPal's
 * card fields with the order's id as the handle.
 *
 * Every id a result names is a PayPal id (PayPalCodes::id()), and every amount one of the
 * currencies the gateway declares (Amounts). A state or a shape the mapping does not know is an
 * \UnexpectedValueException, which the gateway turns into GatewayUnavailable: nothing is applied
 * from an answer the adapter cannot read.
 *
 * @since 0.2.0
 */
final class Results {

	/**
	 * The decline code of an order or an authorization PayPal voided or let expire while SEOCart waited: SEOCart ends the placement.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const EXPIRED = 'expired';

	/**
	 * The decline code of a card payment whose 3-D Secure check left the liability with the store, under the policy that requires it to move.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIABILITY_NOT_SHIFTED = 'liability_shift_not_possible';

	/**
	 * The decline code of an order or an authorization whose amount or currency is not the intent's: the shopper did not approve the payment the store asked for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AMOUNT_CHANGED = 'amount_changed';

	/**
	 * The decline code when PayPal gave no reason the gateway knows.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DECLINED = 'declined';

	/**
	 * The states of an authorization that holds no money and never will again.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const ENDED_AUTHORIZATIONS = array( 'VOIDED', 'EXPIRED', 'DENIED' );

	/**
	 * Returns an order's status.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When it has none PayPal documents.
	 *
	 * @param array<mixed> $order The order.
	 * @return string The status.
	 */
	public static function orderStatus( array $order ): string {
		return PayPalCodes::orderStatus( Fields::text( $order, 'status' ) ) ?? throw new \UnexpectedValueException( 'A PayPal order without a status the adapter knows.' );
	}

	/**
	 * Returns an order's id.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When it holds no PayPal id.
	 *
	 * @param array<mixed> $order The order.
	 * @return string The id.
	 */
	public static function orderId( array $order ): string {
		return PayPalCodes::id( Fields::text( $order, 'id' ) ) ?? throw new \UnexpectedValueException( 'A PayPal order without an id.' );
	}

	/**
	 * Returns the order's one purchase unit.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $order The order.
	 * @return array<mixed> The purchase unit; empty when there is none.
	 */
	public static function purchaseUnit( array $order ): array {
		return Fields::map( $order, 'purchase_units', '0' );
	}

	/**
	 * Tells whether the shopper finished paying by card on an order PayPal still lists as `CREATED`: the bank's 3-D Secure check came back, complete.
	 *
	 * PayPal leaves an order of its card fields `CREATED` once the shopper passed the bank's
	 * challenge, with the check's result on the card; one approved with no check reads `APPROVED`.
	 * While the challenge is still on the shopper's screen the order already holds the card's
	 * details, but no result: the shopper has not finished, and the order is still waiting.
	 *
	 * The rule: the order is finished only when its payment source is a card (`payment_source.card`;
	 * no other source is read) whose `authentication_result` names a liability shift PayPal
	 * documents (YES, POSSIBLE, NO or UNKNOWN) and whose `three_d_secure.authentication_status` is
	 * not `C`, PayPal's "challenge required", which its 3-D Secure table pairs with `UNKNOWN`: a
	 * check still waiting for the shopper. The card's own details, such as its last digits, are
	 * optional in PayPal's answer and are not read.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $order The order, `CREATED`.
	 * @return bool True when the shopper finished, and the order may be authorized.
	 */
	public static function cardFinished( array $order ): bool {
		$result = Fields::map( $order, 'payment_source', 'card', 'authentication_result' );

		return null !== PayPalCodes::liabilityShift( Fields::text( $result, 'liability_shift' ) )
			&& 'C' !== Fields::text( $result, 'three_d_secure', 'authentication_status' );
	}

	/**
	 * Returns the order's live authorizations: each not voided, expired or denied, so it holds the money or took it, in PayPal's order.
	 *
	 * The gateway authorizes an order once; PayPal may list another authorization on it, such as
	 * one the merchant made in PayPal's dashboard after the first expired. The gateway acts on the one
	 * live authorization and chooses among none (Gateway::orderAuthorization()).
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $order The order.
	 * @return list<array<mixed>> The live authorizations; empty when the order has none.
	 */
	public static function liveAuthorizations( array $order ): array {
		return array_values( array_filter( self::authorizationsOf( $order ), static fn( array $authorization ): bool => ! in_array( Fields::text( $authorization, 'status' ), self::ENDED_AUTHORIZATIONS, true ) ) );
	}

	/**
	 * Returns the order's newest authorization by its `create_time`: the one whose state is the order's when none is live.
	 *
	 * Of two created at the same second, or without a time PayPal wrote, the one PayPal lists later.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $order The order.
	 * @return array<mixed> The authorization; empty when the order has none.
	 */
	public static function newestAuthorization( array $order ): array {
		$newest = array();
		$latest = PHP_INT_MIN;

		foreach ( self::authorizationsOf( $order ) as $authorization ) {
			$created = self::createdAt( $authorization );

			if ( $created >= $latest ) {
				$newest = $authorization;
				$latest = $created;
			}
		}

		return $newest;
	}

	/**
	 * Returns the captures made under an authorization of the order: each whose `up` link names that authorization, whatever its status.
	 *
	 * The link is what tells captures apart: every capture of the order carries the purchase unit's
	 * `custom_id`, the intent's uuid, whether the store made it or the merchant did in PayPal's
	 * dashboard. The gateway does not choose among several (Gateway::onlyCapture()).
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $order         The order.
	 * @param array<mixed> $authorization The authorization the gateway acts on (Gateway::orderAuthorization()); empty for none.
	 * @return list<array<mixed>> The captures, in PayPal's order; empty when there is no authorization or it no capture.
	 */
	public static function capturesOf( array $order, array $authorization ): array {
		$authorizationId = PayPalCodes::id( Fields::text( $authorization, 'id' ) );

		if ( null === $authorizationId ) {
			return array();
		}

		$up = '/v2/payments/authorizations/' . $authorizationId;

		return array_values( array_filter( Fields::objects( self::purchaseUnit( $order ), 'payments', 'captures' ), static fn( array $capture ): bool => self::linksUpTo( $capture, $up ) ) );
	}

	/**
	 * Returns the order's refunds, as PayPal lists them on the order.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $order The order.
	 * @return list<array<mixed>> The refunds.
	 */
	public static function refundsOf( array $order ): array {
		return Fields::objects( self::purchaseUnit( $order ), 'payments', 'refunds' );
	}

	/**
	 * Maps an authorization: approved while it holds the money, declined once PayPal denied it, voided or let it expire, pending while PayPal reviews it.
	 *
	 * An authorization voided or expired is answered a declined authorization `expired`, never an
	 * approved void: SEOCart applies a void only as an answer to its own void.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the authorization is in a state or a shape the mapping does not know.
	 *
	 * @param array<mixed> $authorization The authorization.
	 * @param string       $intentUuid    The intent it is for.
	 * @param string       $orderId       The order it belongs to.
	 * @return GatewayResult The result.
	 */
	public static function authorization( array $authorization, string $intentUuid, string $orderId ): GatewayResult {
		$amount = Amounts::money( Fields::map( $authorization, 'amount' ) );
		$id     = self::idOf( $authorization, 'An authorization without an id.' );

		return match ( Fields::text( $authorization, 'status' ) ) {
			'CREATED', 'CAPTURED', 'PARTIALLY_CAPTURED' => self::result( Operation::Authorize, Outcome::Approved, $intentUuid, $amount, $id, $orderId ),
			'DENIED'                                    => self::result( Operation::Authorize, Outcome::Declined, $intentUuid, $amount, $id, $orderId, self::reasonOf( $authorization ) ),
			'VOIDED', 'EXPIRED'                         => self::result( Operation::Authorize, Outcome::Declined, $intentUuid, $amount, $id, $orderId, self::EXPIRED ),
			'PENDING'                                   => self::result( Operation::Authorize, Outcome::Pending, $intentUuid, $amount, null, $orderId ),
			default                                     => throw new \UnexpectedValueException( 'A PayPal authorization in a state the adapter does not know.' ),
		};
	}

	/**
	 * Maps a capture: approved once PayPal took the money (and while it is refunded), declined once it failed.
	 *
	 * A capture PayPal is still reviewing is no answer: the gateway turns it into GatewayUnavailable
	 * before it is mapped, and asks again later.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the capture is in a state or a shape the mapping does not know, or is pending.
	 *
	 * @param array<mixed> $capture    The capture.
	 * @param string       $intentUuid The intent it is for.
	 * @param string       $orderId    The order it belongs to.
	 * @return GatewayResult The result.
	 */
	public static function capture( array $capture, string $intentUuid, string $orderId ): GatewayResult {
		$amount = Amounts::money( Fields::map( $capture, 'amount' ) );
		$id     = self::idOf( $capture, 'A capture without an id.' );

		return match ( Fields::text( $capture, 'status' ) ) {
			'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED' => self::result( Operation::Capture, Outcome::Approved, $intentUuid, $amount, $id, $orderId ),
			'DECLINED', 'FAILED'                          => self::result( Operation::Capture, Outcome::Declined, $intentUuid, $amount, $id, $orderId, self::reasonOf( $capture ) ),
			default                                       => throw new \UnexpectedValueException( 'A PayPal capture in a state the adapter maps to no result.' ),
		};
	}

	/**
	 * Maps a refund: approved once PayPal gave the money back, declined once it failed or was cancelled.
	 *
	 * A refund PayPal is still giving back is no answer yet: it may still fail, which PayPal reports
	 * with an event of its own (PAYMENT.REFUND.FAILED).
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the refund is in a state or a shape the mapping does not know.
	 *
	 * @param array<mixed> $refund     The refund.
	 * @param string       $intentUuid The intent it is for; empty when only the order is known, as in a webhook.
	 * @param string|null  $orderId    The order it belongs to, when known.
	 * @return GatewayResult|null The result, named by the refund; null while PayPal is still giving it back.
	 */
	public static function refund( array $refund, string $intentUuid, ?string $orderId ): ?GatewayResult {
		$amount = Amounts::money( Fields::map( $refund, 'amount' ) );
		$id     = self::idOf( $refund, 'A refund without an id.' );

		return match ( Fields::text( $refund, 'status' ) ) {
			'COMPLETED'           => self::result( Operation::Refund, Outcome::Approved, $intentUuid, $amount, $id, $orderId ),
			'PENDING'             => null,
			'CANCELLED', 'FAILED' => self::result( Operation::Refund, Outcome::Declined, $intentUuid, $amount, $id, $orderId, self::reasonOf( $refund ) ),
			default               => throw new \UnexpectedValueException( 'A PayPal refund in a state the adapter does not know.' ),
		};
	}

	/**
	 * Maps an approved void: the amount the void request asked to release, named by what was voided.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the contract refuses the result.
	 *
	 * @param string $intentUuid The intent.
	 * @param Money  $released   The amount the void request asked to release.
	 * @param string $objectId   The authorization voided, or the order when nothing was authorized.
	 * @param string $orderId    The order.
	 * @return GatewayResult The approved void.
	 */
	public static function voided( string $intentUuid, Money $released, string $objectId, string $orderId ): GatewayResult {
		return self::result( Operation::Void, Outcome::Approved, $intentUuid, $released, $objectId, $orderId );
	}

	/**
	 * Maps a voided authorization PayPal reports: approved, for the authorization's amount.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the authorization cannot be read.
	 *
	 * @param array<mixed> $authorization The authorization, `VOIDED`.
	 * @param string       $intentUuid    The intent it is for.
	 * @param string|null  $orderId       The order, when known.
	 * @return GatewayResult The approved void.
	 */
	public static function reportedVoid( array $authorization, string $intentUuid, ?string $orderId ): GatewayResult {
		return self::result( Operation::Void, Outcome::Approved, $intentUuid, Amounts::money( Fields::map( $authorization, 'amount' ) ), self::idOf( $authorization, 'An authorization without an id.' ), $orderId );
	}

	/**
	 * Maps a refused request: a decline of the amount asked, with PayPal's issue as its code.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the contract refuses the result.
	 *
	 * @param Operation   $operation  The operation refused.
	 * @param string      $intentUuid The intent it is for.
	 * @param Money       $amount     The amount the request asked for.
	 * @param string|null $objectId   What names the decline: PayPal's reference for the answer, when it gave one.
	 * @param string|null $orderId    The order, when the request named one.
	 * @param string      $code       The decline's code.
	 * @return GatewayResult The decline.
	 */
	public static function refused( Operation $operation, string $intentUuid, Money $amount, ?string $objectId, ?string $orderId, string $code ): GatewayResult {
		return self::result( $operation, Outcome::Declined, $intentUuid, $amount, $objectId, $orderId, $code );
	}

	/**
	 * Maps an order PayPal voided before anything was authorized: an authorization declined `expired`.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the order's amount cannot be read.
	 *
	 * @param array<mixed> $order      The order.
	 * @param string       $intentUuid The intent it is for.
	 * @return GatewayResult The decline, named by the order.
	 */
	public static function expiredOrder( array $order, string $intentUuid ): GatewayResult {
		$orderId = self::orderId( $order );

		return self::result( Operation::Authorize, Outcome::Declined, $intentUuid, self::orderAmount( $order ), $orderId, $orderId, self::EXPIRED );
	}

	/**
	 * Maps an order the liability policy refuses to authorize: declined `liability_shift_not_possible`, named by the order.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the order's amount cannot be read.
	 *
	 * @param array<mixed> $order      The order.
	 * @param string       $intentUuid The intent it is for.
	 * @return GatewayResult The decline.
	 */
	public static function liabilityNotShifted( array $order, string $intentUuid ): GatewayResult {
		$orderId = self::orderId( $order );

		return self::result( Operation::Authorize, Outcome::Declined, $intentUuid, self::orderAmount( $order ), $orderId, $orderId, self::LIABILITY_NOT_SHIFTED );
	}

	/**
	 * Maps an order the shopper must approve on PayPal's page: the page, with the order's id as the handle PayPal's buttons take.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the order names no page of PayPal's to approve it on, or its amount cannot be read.
	 *
	 * @param array<mixed> $order      The order.
	 * @param string       $intentUuid The intent it is for.
	 * @return GatewayResult The request for the customer to act.
	 */
	public static function approveAtPayPal( array $order, string $intentUuid ): GatewayResult {
		$orderId = self::orderId( $order );

		return self::waiting( $order, $intentUuid, $orderId, static fn(): NextAction => NextAction::redirect( self::approveLink( $order ), $orderId ) );
	}

	/**
	 * Maps an order the shopper completes in PayPal's card fields: a step of PayPal's script, with the order's id as its handle.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the order's amount cannot be read.
	 *
	 * @param array<mixed> $order      The order.
	 * @param string       $intentUuid The intent it is for.
	 * @return GatewayResult The request for the customer to act.
	 */
	public static function cardFields( array $order, string $intentUuid ): GatewayResult {
		$orderId = self::orderId( $order );

		return self::waiting( $order, $intentUuid, $orderId, static fn(): NextAction => NextAction::sdk( $orderId ) );
	}

	/**
	 * Returns the page of PayPal's where the shopper approves an order, or confirms a vaulted card with their bank: its `payer-action` link, or its `approve` link.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the order names no https page on a host of PayPal's.
	 *
	 * @param array<mixed> $order The order.
	 * @return string The page's address.
	 */
	public static function approveLink( array $order ): string {
		foreach ( array( 'payer-action', 'approve' ) as $relation ) {
			foreach ( Fields::objects( $order, 'links' ) as $link ) {
				$href = Fields::text( $link, 'href' );

				if ( Fields::text( $link, 'rel' ) === $relation && null !== $href && self::isPayPalPage( $href ) ) {
					return $href;
				}
			}
		}

		throw new \UnexpectedValueException( 'A PayPal order waiting for the shopper names no PayPal page to send them to.' );
	}

	/**
	 * Returns an order's amount: its purchase unit's.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When it cannot be read.
	 *
	 * @param array<mixed> $order The order.
	 * @return Money The amount.
	 */
	public static function orderAmount( array $order ): Money {
		return Amounts::money( Fields::map( self::purchaseUnit( $order ), 'amount' ) );
	}

	/**
	 * Returns every authorization the order lists, in PayPal's order.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $order The order.
	 * @return list<array<mixed>> The authorizations.
	 */
	private static function authorizationsOf( array $order ): array {
		return Fields::objects( self::purchaseUnit( $order ), 'payments', 'authorizations' );
	}

	/**
	 * Returns when PayPal created an object, as seconds since the epoch.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $source The object.
	 * @return int The time; PHP_INT_MIN when it has none PayPal wrote that can be read.
	 */
	private static function createdAt( array $source ): int {
		$created = Fields::text( $source, 'create_time' );

		if ( null === $created ) {
			return PHP_INT_MIN;
		}

		try {
			return ( new \DateTimeImmutable( $created ) )->getTimestamp();
		} catch ( \Exception ) {
			return PHP_INT_MIN;
		}
	}

	/**
	 * Tells whether an object's `up` link names a path on a host of PayPal's: the object it was made from.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $source The object, such as a capture.
	 * @param string       $path   The path its `up` link must name, such as an authorization's.
	 * @return bool True when it does.
	 */
	private static function linksUpTo( array $source, string $path ): bool {
		foreach ( Fields::objects( $source, 'links' ) as $link ) {
			$href = Fields::text( $link, 'href' );

			if ( 'up' === Fields::text( $link, 'rel' ) && null !== $href && self::isPayPalPage( $href ) && wp_parse_url( $href, PHP_URL_PATH ) === $path ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether an address is an https page on PayPal's own domain.
	 *
	 * @since 0.2.0
	 *
	 * @param string $url The address.
	 * @return bool True for `https://www.paypal.com/…` or `https://www.sandbox.paypal.com/…` and their like.
	 */
	private static function isPayPalPage( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		return str_starts_with( $url, 'https://' ) && ( 'paypal.com' === $host || str_ends_with( $host, '.paypal.com' ) );
	}

	/**
	 * Builds a request for the shopper to act, for the order's amount.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the order's amount cannot be read, or the contract refuses the step.
	 *
	 * @param array<mixed> $order      The order.
	 * @param string       $intentUuid The intent it is for.
	 * @param string       $orderId    The order's id.
	 * @param \Closure     $action     Builds the step.
	 * @return GatewayResult The request for the customer to act, naming no object.
	 *
	 * @phpstan-param \Closure(): NextAction $action
	 */
	private static function waiting( array $order, string $intentUuid, string $orderId, \Closure $action ): GatewayResult {
		try {
			$step = $action();
		} catch ( \InvalidArgumentException $refused ) {
			throw new \UnexpectedValueException( 'A PayPal order waiting for the shopper names a step the payment contract does not accept.', 0, $refused );
		}

		return self::result( Operation::Authorize, Outcome::RequiresAction, $intentUuid, self::orderAmount( $order ), null, $orderId, null, $step );
	}

	/**
	 * Returns the decline code of an object PayPal declined: its documented reason, lower-cased, or `declined`.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $source The authorization, capture or refund.
	 * @return string The code.
	 */
	private static function reasonOf( array $source ): string {
		$reason = PayPalCodes::reason( Fields::text( $source, 'status_details', 'reason' ) );

		return null === $reason ? self::DECLINED : strtolower( $reason );
	}

	/**
	 * Returns the PayPal id an object's `id` holds.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When it holds none.
	 *
	 * @param array<mixed> $source  The object.
	 * @param string       $missing What the exception says.
	 * @return string The id.
	 */
	private static function idOf( array $source, string $missing ): string {
		return PayPalCodes::id( Fields::text( $source, 'id' ) ) ?? throw new \UnexpectedValueException( $missing );
	}

	/**
	 * Builds a result of this gateway, turning a value the contract refuses into an answer the gateway cannot read.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the contract refuses the result, such as an approved refund of nothing.
	 *
	 * @param Operation       $operation        The operation.
	 * @param Outcome         $outcome          The outcome.
	 * @param string          $intentUuid       The intent.
	 * @param Money           $amount           The amount.
	 * @param string|null     $providerObjectId The object of the outcome.
	 * @param string|null     $providerIntentId The order.
	 * @param string|null     $errorCode        Optional. The decline's code. Default none.
	 * @param NextAction|null $nextAction       Optional. What the shopper must do, for a request to act. Default none.
	 * @return GatewayResult The result.
	 */
	private static function result( Operation $operation, Outcome $outcome, string $intentUuid, Money $amount, ?string $providerObjectId, ?string $providerIntentId, ?string $errorCode = null, ?NextAction $nextAction = null ): GatewayResult {
		try {
			return new GatewayResult( Gateway::ID, $operation, $outcome, $intentUuid, $amount, $providerObjectId, $providerIntentId, $errorCode, null, $nextAction );
		} catch ( \InvalidArgumentException $refused ) {
			throw new \UnexpectedValueException( 'An answer the payment contract does not accept as a result.', 0, $refused );
		}
	}
}
