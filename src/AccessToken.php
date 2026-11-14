<?php
/**
 * AccessToken: the bearer token PayPal issues for a mode's client id and secret, held in memory for one request
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
 * An OAuth access token PayPal issued, read from the token call's answer and handed only to the Authorization header.
 *
 * Owns one fact: what a usable token is. PayPal answers `POST /v1/oauth2/token` with a bearer
 * token valid for hours; the gateway keeps it in memory for the request it serves and nowhere else
 * (never an option, a transient or a log line), since it is a credential: anyone holding it can
 * move the account's money until it expires. Dumped by var_dump() or print_r(), it shows nothing of
 * its value.
 *
 * @since 0.2.0
 */
final class AccessToken {

	/**
	 * The shape of a token the gateway accepts: printable characters without a space, of a sane length.
	 *
	 * PayPal's tokens are opaque; what matters is that the value can travel in a header and nothing else with it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SHAPE = '/^[!-~]{16,4096}\z/';

	/**
	 * Keeps the token.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The token.
	 */
	private function __construct( #[\SensitiveParameter] private string $value ) {
	}

	/**
	 * Reads the token from the token call's answer.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $answer The decoded answer: `token_type` and `access_token`.
	 * @return self|null The token; null when the answer holds no bearer token of a sane shape.
	 */
	public static function fromAnswer( array $answer ): ?self {
		$type  = Fields::text( $answer, 'token_type' );
		$value = Fields::text( $answer, 'access_token' );

		if ( null === $type || 0 !== strcasecmp( $type, 'Bearer' ) || null === $value || 1 !== preg_match( self::SHAPE, $value ) ) {
			return null;
		}

		return new self( $value );
	}

	/**
	 * Returns the value of the Authorization header that carries the token.
	 *
	 * @since 0.2.0
	 *
	 * @return string `Bearer` and the token.
	 */
	public function header(): string {
		return 'Bearer ' . $this->value;
	}

	/**
	 * Shows nothing of the token to var_dump() and print_r().
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> A placeholder.
	 */
	public function __debugInfo(): array {
		return array( 'value' => '[hidden]' );
	}
}
