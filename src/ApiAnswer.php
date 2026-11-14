<?php
/**
 * ApiAnswer: one answer from PayPal's API, already decoded
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
 * An answer the adapter can act on: an object PayPal returned, or an error PayPal gave for the request itself.
 *
 * Owns one fact: what PayPal said, as data. PayPalApi builds one only for a success, and for an
 * error that is an answer about the request (HTTP 400, 404 or 422 with an error's `name`); every
 * other failure is a GatewayUnavailable before an answer exists. The decoded body may carry card
 * details of the shopper's card, so it is read field by field and never logged or kept.
 *
 * @since 0.2.0
 */
final readonly class ApiAnswer {

	/**
	 * Records the answer.
	 *
	 * @since 0.2.0
	 *
	 * @param int          $status The HTTP status.
	 * @param array<mixed> $body   The decoded body: the object, or the error `{ "name": …, "details": [ … ], "debug_id": … }`; empty for none.
	 */
	public function __construct(
		public int $status,
		public array $body
	) {
	}

	/**
	 * Tells whether PayPal refused the request.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True for an error answer.
	 */
	public function isError(): bool {
		return $this->status >= 300;
	}

	/**
	 * Returns the error's name, such as `UNPROCESSABLE_ENTITY`, when PayPal documents it.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The name; null for a success or a name PayPal does not document.
	 */
	public function errorName(): ?string {
		return $this->isError() ? PayPalCodes::errorName( Fields::text( $this->body, 'name' ) ) : null;
	}

	/**
	 * Returns the issue of the error's first detail, such as `INSTRUMENT_DECLINED`, when it is one PayPalCodes lists.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The issue; null for a success or an issue not listed.
	 */
	public function issue(): ?string {
		return $this->isError() ? PayPalCodes::issue( Fields::text( $this->body, 'details', '0', 'issue' ) ) : null;
	}

	/**
	 * Returns the error's `debug_id`, PayPal's reference for this one answer, when it has the shape of one.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The reference; null for a success or a reference of another shape.
	 */
	public function debugId(): ?string {
		return $this->isError() ? PayPalCodes::debugId( Fields::text( $this->body, 'debug_id' ) ) : null;
	}

	/**
	 * Tells whether the error is one of some issues.
	 *
	 * @since 0.2.0
	 *
	 * @param string ...$issues The issues, as PayPalCodes lists them.
	 * @return bool True when the error's first detail names one of them.
	 */
	public function isIssue( string ...$issues ): bool {
		return in_array( $this->issue(), $issues, true );
	}

	/**
	 * Tells whether the error is PayPal's final no to the request: an issue PayPalCodes::DECLINES lists.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True for a decline; false for a success or any other error.
	 */
	public function isDecline(): bool {
		return $this->isIssue( ...PayPalCodes::DECLINES );
	}
}
