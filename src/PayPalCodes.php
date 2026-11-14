<?php
/**
 * PayPalCodes: the words PayPal documents, against which anything PayPal answers is checked before the gateway repeats it
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

/**
 * PayPal's documented enumerations (statuses, error names, issues, reasons) and the shapes of its ids and of the words of its paths.
 *
 * Owns one fact: which of PayPal's words the gateway may repeat. An answer is a string PayPal
 * wrote, and a field meant for a code can hold anything, a card number or a token included; so the
 * gateway repeats a status, an issue or a reason, in a log line or in a result's decline code, only
 * when it is one of the values listed here, and an id only when it has the shape of PayPal's ids;
 * anything else it repeats as nothing. A message, a description or a link from an answer is never
 * repeated at all. A request's path, likewise, holds only words of PayPal's paths and PayPal ids
 * (pathSegment()).
 *
 * The lists are a snapshot of PayPal's REST reference (Orders v2 and Payments v2: the statuses of
 * an order, an authorization, a capture and a refund; the error names and issues of
 * https://developer.paypal.com/api/rest/reference/orders/v2/errors/ and of the Payments v2 errors;
 * the 3-D Secure result of an order's card), so a value PayPal adds later is repeated as nothing,
 * and a result takes the gateway's own code, until it is listed here.
 *
 * @since 0.2.0
 */
final class PayPalCodes {

	/**
	 * The states of an order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const ORDER_STATUSES = array( 'CREATED', 'SAVED', 'APPROVED', 'VOIDED', 'COMPLETED', 'PAYER_ACTION_REQUIRED' );

	/**
	 * The states of an authorization.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const AUTHORIZATION_STATUSES = array( 'CREATED', 'CAPTURED', 'DENIED', 'PARTIALLY_CAPTURED', 'VOIDED', 'PENDING', 'EXPIRED' );

	/**
	 * The states of a capture.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const CAPTURE_STATUSES = array( 'COMPLETED', 'DECLINED', 'PARTIALLY_REFUNDED', 'PENDING', 'REFUNDED', 'FAILED' );

	/**
	 * The states of a refund.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const REFUND_STATUSES = array( 'CANCELLED', 'FAILED', 'PENDING', 'COMPLETED' );

	/**
	 * Why an authorization, a capture or a refund is pending or was declined (`status_details.reason`).
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const STATUS_REASONS = array(
		'BUYER_COMPLAINT',
		'CHARGEBACK',
		'DECLINED_BY_RISK_FRAUD_FILTERS',
		'ECHECK',
		'INTERNATIONAL_WITHDRAWAL',
		'OTHER',
		'PENDING_REVIEW',
		'RECEIVING_PREFERENCE_MANDATES_MANUAL_ACTION',
		'REFUNDED',
		'TRANSACTION_APPROVED_AWAITING_FUNDING',
		'UNILATERAL',
		'VERIFICATION_REQUIRED',
	);

	/**
	 * The names of PayPal's error answers.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const ERROR_NAMES = array(
		'AUTHENTICATION_FAILURE',
		'INTERNAL_SERVER_ERROR',
		'INVALID_REQUEST',
		'INVALID_RESOURCE_ID',
		'METHOD_NOT_SUPPORTED',
		'MEDIA_TYPE_NOT_ACCEPTABLE',
		'NOT_AUTHORIZED',
		'PERMISSION_DENIED',
		'RATE_LIMIT_REACHED',
		'RESOURCE_NOT_FOUND',
		'SERVICE_UNAVAILABLE',
		'UNPROCESSABLE_ENTITY',
		'UNSUPPORTED_MEDIA_TYPE',
	);

	/**
	 * The issues that are PayPal's final no to a request, which the gateway answers as a decline with the issue as its code.
	 *
	 * The payer's or their account's refusal, or its funding's; the card's, its bank's or its
	 * processor's; a risk check's or a payment limit's, PayPal's or the merchant's own; and an
	 * authorization or a capture that can no longer give what was asked: voided or expired, or
	 * refunded in full, beyond its amount, its count or its time. Each ends the payment as it was
	 * asked, so the shopper is told and may pay another way. Any other issue, such as a feature or a
	 * receiving limit of the merchant's account, a permission the app lacks or a request PayPal could
	 * not read, says nothing about the shopper's payment: the gateway logs it for a person and answers
	 * GatewayUnavailable, never a decline. From the issues of PayPal's Orders v2 and Payments v2 errors.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const DECLINES = array(
		// The payer, or their account or its funding.
		'DOMESTIC_TRANSACTION_REQUIRED',
		'PAYER_ACCOUNT_LOCKED_OR_CLOSED',
		'PAYER_ACCOUNT_RESTRICTED',
		'PAYER_ACTION_REQUIRED',
		'PAYER_CANNOT_PAY',
		'PAYER_NOT_SUPPORTED_BY_PAYMENT_SOURCE',
		'REDIRECT_PAYER_FOR_ALTERNATE_FUNDING',
		// The card, its bank or its processor.
		'ALIAS_DECLINED_BY_PROCESSOR',
		'CARD_BRAND_NOT_SUPPORTED',
		'CARD_COUNTRY_NOT_SUPPORTED',
		'CARD_DATA_NOT_FOUND',
		'CARD_EXPIRED',
		'CARD_TYPE_NOT_SUPPORTED',
		'CONTINGENCY_NOT_SUCCESSFUL',
		'COUNTRY_NOT_SUPPORTED_BY_PAYMENT_SOURCE',
		'CURRENCY_NOT_SUPPORTED_FOR_CARD_TYPE',
		'INSTRUMENT_DECLINED',
		'INVALID_VAULT_ID',
		'PAYMENT_SOURCE_CANNOT_BE_USED',
		'PAYMENT_SOURCE_DECLINED_BY_PROCESSOR',
		'PAYMENT_SOURCE_INFO_CANNOT_BE_VERIFIED',
		'REFERENCED_CARD_EXPIRED',
		// A risk check or a limit, PayPal's or the merchant's.
		'COMPLIANCE_VIOLATION',
		'MAX_NUMBER_OF_PAYMENT_ATTEMPTS_EXCEEDED',
		'PAYEE_BLOCKED_TRANSACTION',
		'PAYMENT_DENIED',
		'TRANSACTION_BLOCKED_BY_PAYEE',
		'TRANSACTION_LIMIT_EXCEEDED',
		'TRANSACTION_REFUSED',
		// An authorization or a capture that can no longer give what was asked.
		'AUTHORIZATION_EXPIRED',
		'AUTHORIZATION_VOIDED',
		'CAPTURE_FULLY_REFUNDED',
		'MAX_NUMBER_OF_REFUNDS_EXCEEDED',
		'REFUND_AMOUNT_EXCEEDED',
		'REFUND_TIME_LIMIT_EXCEEDED',
	);

	/**
	 * The issues of an error's details the gateway reads or may repeat: the declines, and the others it reads or names in a log line.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const ISSUES = array(
		...self::DECLINES,
		'AMOUNT_MISMATCH',
		'AUTH_CAPTURE_NOT_ENABLED',
		'AUTHORIZATION_ALREADY_CAPTURED',
		'CANNOT_BE_NEGATIVE',
		'CANNOT_BE_ZERO_OR_NEGATIVE',
		'CURRENCY_MISMATCH',
		'CURRENCY_NOT_SUPPORTED',
		'DECIMAL_PRECISION',
		'DUPLICATE_INVOICE_ID',
		'DUPLICATE_REQUEST_ID',
		'INVALID_PARAMETER_SYNTAX',
		'INVALID_PARAMETER_VALUE',
		'INVALID_RESOURCE_ID',
		'INVALID_STRING_LENGTH',
		'MAX_AUTHORIZATION_COUNT_EXCEEDED',
		'MAX_CAPTURE_AMOUNT_EXCEEDED',
		'MAX_CAPTURE_COUNT_EXCEEDED',
		'MISSING_REQUIRED_PARAMETER',
		'NOT_ENABLED_FOR_CARD_PROCESSING',
		'NOT_ENABLED_TO_VAULT_PAYMENT_SOURCE',
		'ORDER_ALREADY_AUTHORIZED',
		'ORDER_ALREADY_CAPTURED',
		'ORDER_COMPLETED_OR_VOIDED',
		'ORDER_EXPIRED',
		'ORDER_NOT_APPROVED',
		'PAYEE_ACCOUNT_RESTRICTED',
		'PERMISSION_DENIED',
		'PREVIOUSLY_CAPTURED',
		'PREVIOUSLY_VOIDED',
		'REFUND_NOT_ALLOWED',
		'RESOURCE_NOT_FOUND',
		'TRANSACTION_DISPUTED',
		'TRANSACTION_RECEIVING_LIMIT_EXCEEDED',
		'UNSUPPORTED_PARAMETER',
	);

	/**
	 * Whether a card's 3-D Secure check moved the liability for a fraudulent payment to the card's bank (`authentication_result.liability_shift`).
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const LIABILITY_SHIFTS = array( 'YES', 'POSSIBLE', 'NO', 'UNKNOWN' );

	/**
	 * The results of a liability shift on which a card payment may go on: the bank took the liability, or may.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const LIABILITY_SHIFTED = array( 'YES', 'POSSIBLE' );

	/**
	 * When PayPal asks the shopper's bank to confirm a card payment (`attributes.verification.method`).
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const SCA_METHODS = array( 'SCA_WHEN_REQUIRED', 'SCA_ALWAYS' );

	/**
	 * What an order is for (`intent`).
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const INTENTS = array( 'CAPTURE', 'AUTHORIZE' );

	/**
	 * The relations of the links PayPal adds to an object (`links[].rel`) and their methods.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const LINK_WORDS = array( 'self', 'approve', 'payer-action', 'update', 'capture', 'authorize', 'void', 'reauthorize', 'refund', 'up', 'GET', 'POST', 'PATCH', 'DELETE' );

	/**
	 * The algorithm of a webhook delivery's signature, the one the gateway verifies (`paypal-auth-algo`).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AUTH_ALGO = 'SHA256withRSA';

	/**
	 * The shape of an enumerated word of PayPal's: capitals, digits and underscores.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WORD = '/^[A-Z][A-Z0-9_]{0,63}\z/';

	/**
	 * The shape of the id of an order, an authorization, a capture or a refund: 17 capitals and digits.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ID = '/^[A-Z0-9]{17}\z/';

	/**
	 * The shape of the id of a card PayPal vaulted: letters and digits.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const VAULT_ID = '/^[A-Za-z0-9]{4,64}\z/';

	/**
	 * The shape of a webhook event's id: `WH-`, then two runs of capitals and digits joined by a dash.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EVENT_ID = '/^WH-[A-Z0-9]{8,32}-[A-Z0-9]{8,32}\z/';

	/**
	 * The shape of an answer's `debug_id`, PayPal's reference for one answer: lower-case hexadecimal.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DEBUG_ID = '/^[0-9a-f]{8,32}\z/';

	/**
	 * The shape of a word of PayPal's paths, such as `v2`, `orders` or `oauth2`: lower-case letters, digits and dashes, beginning with a letter.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PATH_WORD = '/^[a-z][a-z0-9\-]{0,31}\z/';

	/**
	 * Returns the id of an order, an authorization, a capture or a refund, when it has the shape of one and does not read as a card number.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer or the ledger holds.
	 * @return string|null The id; null for anything else.
	 */
	public static function id( ?string $value ): ?string {
		return null !== $value && 1 === preg_match( self::ID, $value ) && ! self::readsAsCard( $value ) ? $value : null;
	}

	/**
	 * Returns the id of a card PayPal vaulted, when it has the shape of one and does not read as a card number.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value The id, as a payment token carries it.
	 * @return string|null The id; null for anything else.
	 */
	public static function vaultId( ?string $value ): ?string {
		return null !== $value && 1 === preg_match( self::VAULT_ID, $value ) && ! self::readsAsCard( $value ) ? $value : null;
	}

	/**
	 * Returns a webhook event's id, when it has the shape of one.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the delivery holds.
	 * @return string|null The id; null for anything else.
	 */
	public static function eventId( ?string $value ): ?string {
		return null !== $value && 1 === preg_match( self::EVENT_ID, $value ) ? $value : null;
	}

	/**
	 * Returns an answer's `debug_id`, when it has the shape of one.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The reference; null for anything else.
	 */
	public static function debugId( ?string $value ): ?string {
		return null !== $value && 1 === preg_match( self::DEBUG_ID, $value ) ? $value : null;
	}

	/**
	 * Returns a segment of a request's path, when it is a word of PayPal's paths or a PayPal id.
	 *
	 * A digit run such as a card number is neither, so it never reaches a request's path or the
	 * log line that names one.
	 *
	 * @since 0.2.0
	 *
	 * @param string $segment The segment, such as `orders`, an order's id or `authorize`.
	 * @return string|null The segment; null for anything else.
	 */
	public static function pathSegment( string $segment ): ?string {
		return 1 === preg_match( self::PATH_WORD, $segment ) ? $segment : self::id( $segment );
	}

	/**
	 * Returns an order's status, when it is one PayPal documents.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The status; null for anything else.
	 */
	public static function orderStatus( ?string $value ): ?string {
		return self::oneOf( $value, self::ORDER_STATUSES );
	}

	/**
	 * Returns a status of an authorization, a capture or a refund, when it is one PayPal documents for any of them.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The status; null for anything else.
	 */
	public static function paymentStatus( ?string $value ): ?string {
		return self::oneOf( $value, array_merge( self::AUTHORIZATION_STATUSES, self::CAPTURE_STATUSES, self::REFUND_STATUSES ) );
	}

	/**
	 * Returns why a payment is pending or was declined, when it is a reason PayPal documents.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The reason; null for anything else.
	 */
	public static function reason( ?string $value ): ?string {
		return self::oneOf( $value, self::STATUS_REASONS );
	}

	/**
	 * Returns an error's name, when it is one PayPal documents.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The name; null for anything else.
	 */
	public static function errorName( ?string $value ): ?string {
		return self::oneOf( $value, self::ERROR_NAMES );
	}

	/**
	 * Returns an error's issue, when it is one listed here.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The issue; null for anything else.
	 */
	public static function issue( ?string $value ): ?string {
		return self::oneOf( $value, self::ISSUES );
	}

	/**
	 * Returns the result of a liability shift, when it is one PayPal documents.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value What the answer holds.
	 * @return string|null The result; null for anything else.
	 */
	public static function liabilityShift( ?string $value ): ?string {
		return self::oneOf( $value, self::LIABILITY_SHIFTS );
	}

	/**
	 * Returns every word of PayPal's the gateway may repeat or read, for a test's declaration of what is public.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The words, each once.
	 */
	public static function words(): array {
		return array_values(
			array_unique(
				array_merge(
					self::ORDER_STATUSES,
					self::AUTHORIZATION_STATUSES,
					self::CAPTURE_STATUSES,
					self::REFUND_STATUSES,
					self::STATUS_REASONS,
					self::ERROR_NAMES,
					self::ISSUES,
					self::LIABILITY_SHIFTS,
					self::SCA_METHODS,
					self::INTENTS,
					self::LINK_WORDS,
					array( self::AUTH_ALGO )
				)
			)
		);
	}

	/**
	 * Returns an id of PayPal's as a log line may carry it: the id, unless a run of its digits reads as a card number.
	 *
	 * PayPal's ids of authorizations and refunds often hold a run of fourteen digits, and one in ten
	 * such runs passes the Luhn check. SEOCart's log removes a run like that wherever it is, so a
	 * line names such an id as nothing rather than as a card number.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value The id: an order's, an authorization's, a capture's, a refund's or an answer's debug id.
	 * @return string|null The id; null for anything else.
	 */
	public static function logged( ?string $value ): ?string {
		$id = self::id( $value ) ?? self::debugId( $value );

		return null === $id || self::holdsCardRun( $id ) ? null : $id;
	}

	/**
	 * Tells whether a value holds a run of 13 to 19 digits that passes the Luhn check, anywhere in it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value.
	 * @return bool True when it does.
	 */
	public static function holdsCardRun( string $value ): bool {
		preg_match_all( '/\d{13,}/', $value, $runs );

		foreach ( $runs[0] as $run ) {
			$digits = strlen( $run );

			for ( $start = 0; $start + 13 <= $digits; $start++ ) {
				for ( $length = 13; $length <= 19 && $start + $length <= $digits; $length++ ) {
					if ( self::readsAsCard( substr( $run, $start, $length ) ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Tells whether a value is a run of 12 to 19 digits that passes the Luhn check: a card number, never an id.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value.
	 * @return bool True when it reads as a card number.
	 */
	public static function readsAsCard( string $value ): bool {
		if ( 1 !== preg_match( '/^\d{12,19}\z/', $value ) ) {
			return false;
		}

		$sum    = 0;
		$double = false;

		for ( $i = strlen( $value ) - 1; $i >= 0; $i-- ) {
			$digit = (int) $value[ $i ];

			if ( $double ) {
				$digit = $digit * 2 > 9 ? $digit * 2 - 9 : $digit * 2;
			}

			$sum   += $digit;
			$double = ! $double;
		}

		return 0 === $sum % 10;
	}

	/**
	 * Returns a value when a list holds it.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value The value.
	 * @param string[]    $known The values PayPal documents.
	 * @return string|null The value; null when it is not one of them.
	 *
	 * @phpstan-param list<string> $known
	 */
	private static function oneOf( ?string $value, array $known ): ?string {
		return null !== $value && 1 === preg_match( self::WORD, $value ) && in_array( $value, $known, true ) ? $value : null;
	}
}
