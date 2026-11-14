<?php
/**
 * PayPalApi: every request the gateway sends to PayPal's REST API
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
use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\OutboundResponse;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\TransportFailure;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a failed call to SEOCart's payment service; they are never HTML.

/**
 * Sends one request to PayPal's REST API, through the HTTP client SEOCart gives the gateway, and says what came back.
 *
 * Owns one fact: how a request to PayPal is made. The mode names the host: the sandbox's for test
 * mode, PayPal's own for live mode, so a live credential never meets the sandbox. Every request
 * carries an access token of that mode (AccessToken), asked for with the mode's client id and
 * secret on the first request that needs one and kept for this request alone; a token PayPal
 * refuses with HTTP 401 is asked for again once, and the request sent again as it was, with the
 * same PayPal-Request-Id. Every POST moves money and carries the PayPal-Request-Id its request
 * object derived, so PayPal answers a repeated request with its first answer. A body is JSON, and
 * PayPal is asked for the whole object in its answer (`Prefer: return=representation`). In test
 * mode alone, a request may carry `PayPal-Mock-Response`, which asks the sandbox for an error.
 *
 * An answer the gateway can act on is returned as an ApiAnswer: a success, or PayPal's error about
 * the request itself (HTTP 400, 404 or 422). Anything else is a GatewayUnavailable: no answer, an
 * answer that is not JSON, credentials PayPal refuses, a rate limit or a fault at PayPal. SEOCart
 * then applies nothing and asks again later.
 *
 * The log lines say which request and which status, and of an error only its name, its issue and
 * its debug id when PayPalCodes allows them: never a header, a body, a message, a description or
 * any other string PayPal wrote, since a request carries the token and an answer may carry card
 * details of the shopper.
 *
 * @since 0.2.0
 */
final class PayPalApi {

	/**
	 * The path of the token call.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TOKEN_PATH = 'v1/oauth2/token';

	/**
	 * The header that carries a request's idempotency key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REQUEST_ID = 'PayPal-Request-Id';

	/**
	 * The header that asks PayPal's sandbox to answer with an error (negative testing).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MOCK_RESPONSE = 'PayPal-Mock-Response';

	/**
	 * The statuses of an error PayPal gives about the request itself, which the operation reads.
	 *
	 * @since 0.2.0
	 *
	 * @var list<int>
	 */
	private const REQUEST_ERRORS = array( 400, 404, 422 );

	/**
	 * What SEOCart gives the gateway: its HTTP client, its settings, its logger.
	 *
	 * @since 0.2.0
	 *
	 * @var ExtensionContext
	 */
	private ExtensionContext $context;

	/**
	 * The access token of each mode asked for in this request, by mode.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, AccessToken>
	 */
	private array $tokens = array();

	/**
	 * Builds the client. Sends nothing and reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param ExtensionContext $context What SEOCart gives the gateway.
	 */
	public function __construct( ExtensionContext $context ) {
		$this->context = $context;
	}

	/**
	 * Sends a GET: reads an object.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When PayPal gave no answer the gateway can act on.
	 *
	 * @param Mode   $mode The mode, which names the host and the credentials.
	 * @param string $path The path, built by path().
	 * @return ApiAnswer The answer.
	 */
	public function get( Mode $mode, string $path ): ApiAnswer {
		return $this->send( $mode, 'GET', $path, null, array() );
	}

	/**
	 * Sends a POST, which moves money: creates, authorizes, captures, voids or refunds.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When PayPal gave no answer the gateway can act on, or a mock is asked for in live mode.
	 *
	 * @param Mode         $mode      The mode, which names the host and the credentials.
	 * @param string       $path      The path, built by path().
	 * @param array<mixed> $body      The JSON body; empty for `{}`.
	 * @param string       $requestId The PayPal-Request-Id: the key PayPal replays the first answer for.
	 * @param string|null  $mock      Optional. The error PayPal's sandbox is asked to answer with (PaymentTokens); test mode only. Default none.
	 * @return ApiAnswer The answer.
	 */
	public function post( Mode $mode, string $path, array $body, string $requestId, ?string $mock = null ): ApiAnswer {
		$headers = array(
			'Content-Type'   => 'application/json',
			'Prefer'         => 'return=representation',
			self::REQUEST_ID => $requestId,
		);

		if ( null !== $mock ) {
			$headers[ self::MOCK_RESPONSE ] = $this->mockHeader( $mode, $mock );
		}

		return $this->send( $mode, 'POST', $path, self::json( array() === $body ? new \stdClass() : $body ), $headers );
	}

	/**
	 * Builds a path from its segments, each a word of PayPal's paths or a PayPal id (PayPalCodes::pathSegment()), so no value can change where a request goes or carry anything else into it.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable Before any request is built, when a segment is neither, such as a card number or an id with a slash.
	 *
	 * @param string ...$segments The segments, such as `v2`, `checkout`, `orders`, the order's id and `authorize`.
	 * @return string The path, such as `v2/checkout/orders/5O190127TN364715T/authorize`.
	 */
	public static function path( string ...$segments ): string {
		foreach ( $segments as $segment ) {
			if ( null === PayPalCodes::pathSegment( $segment ) ) {
				throw new GatewayUnavailable( 'A PayPal request was asked for with a reference that is neither a word of PayPal\'s paths nor a PayPal id, so nothing was sent.' );
			}
		}

		return implode( '/', $segments );
	}

	/**
	 * Returns the value of the mock header for a test-mode request.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable In live mode: PayPal's live API takes no mock, and a request that asks for one is never sent.
	 *
	 * @param Mode   $mode The mode.
	 * @param string $mock The error's name, as PaymentTokens read it.
	 * @return string The header's value.
	 */
	private function mockHeader( Mode $mode, string $mock ): string {
		if ( Mode::Test !== $mode ) {
			$this->context->logger()->error( 'paypal.mock_in_live_mode', 'A live-mode request asked for a sandbox mock answer, so nothing was sent.', array( 'mode' => $mode->value ) );

			throw new GatewayUnavailable( 'A mock answer is a sandbox feature, never asked of PayPal in live mode.' );
		}

		return self::json( array( 'mock_application_codes' => $mock ) );
	}

	/**
	 * Sends a request with the mode's token, asking for a new token and sending the request again, once, when PayPal refuses the token.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When PayPal gave no answer the gateway can act on.
	 *
	 * @param Mode                  $mode    The mode.
	 * @param string                $method  The HTTP method.
	 * @param string                $path    The path.
	 * @param string|null           $body    The JSON body; null for none.
	 * @param array<string, string> $headers The headers beside Authorization.
	 * @return ApiAnswer The answer.
	 */
	private function send( Mode $mode, string $method, string $path, ?string $body, array $headers ): ApiAnswer {
		$line     = array(
			'method' => $method,
			'path'   => self::loggedPath( $path ),
			'mode'   => $mode->value,
		);
		$response = $this->exchange( $mode, $method, $path, $body, array( 'Authorization' => $this->token( $mode )->header() ) + $headers, $line );

		if ( 401 === $response->status ) {
			unset( $this->tokens[ $mode->value ] );
			$this->context->logger()->info( 'paypal.token_refused', 'PayPal refused the access token, so a new one was asked for and the request sent again.', $line );

			$response = $this->exchange( $mode, $method, $path, $body, array( 'Authorization' => $this->token( $mode )->header() ) + $headers, $line );
		}

		return $this->answer( $response, $line );
	}

	/**
	 * Returns the mode's access token, asking PayPal for one when this request has none yet.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the mode's credentials are missing, or PayPal issued no token.
	 *
	 * @param Mode $mode The mode.
	 * @return AccessToken The token.
	 */
	private function token( Mode $mode ): AccessToken {
		return $this->tokens[ $mode->value ] ??= $this->requestToken( $mode );
	}

	/**
	 * Asks PayPal for an access token with the mode's client id and secret (HTTP Basic), opened for this call only.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the mode's client id is not set, its secret does not open, PayPal refused them, or answered no token.
	 *
	 * @param Mode $mode The mode.
	 * @return AccessToken The token.
	 */
	private function requestToken( Mode $mode ): AccessToken {
		$settings = $this->context->settings( $mode );
		$clientId = $settings->value( Gateway::CLIENT_ID );
		$line     = array(
			'method' => 'POST',
			'path'   => self::TOKEN_PATH,
			'mode'   => $mode->value,
		);

		if ( ! is_string( $clientId ) || '' === $clientId ) {
			$this->context->logger()->error( 'paypal.not_configured', 'The client id of a mode is not set, so nothing was sent.', array( 'mode' => $mode->value ) );

			throw new GatewayUnavailable( sprintf( 'The PayPal setting %1$s of the %2$s mode is not set.', Gateway::CLIENT_ID, $mode->value ) );
		}

		$response = $this->exchange(
			$mode,
			'POST',
			self::TOKEN_PATH,
			'grant_type=client_credentials',
			array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials are base64 by definition (RFC 7617).
				'Authorization' => 'Basic ' . base64_encode( $clientId . ':' . $settings->secret( Gateway::CLIENT_SECRET ) ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			$line
		);

		if ( 401 === $response->status || 403 === $response->status ) {
			$this->context->logger()->error( 'paypal.unauthorized', 'PayPal refused the client id and secret saved for this mode.', $line + array( 'http_status' => $response->status ) );

			throw new GatewayUnavailable( sprintf( 'PayPal refused the %s mode\'s client id and secret.', $mode->value ) );
		}

		$token = null;

		if ( 200 === $response->status ) {
			try {
				$token = AccessToken::fromAnswer( $response->json() );
			} catch ( \UnexpectedValueException ) {
				$token = null;
			}
		}

		if ( null === $token ) {
			$this->context->logger()->warning( 'paypal.unavailable', 'PayPal answered the token call without a token.', $line + array( 'http_status' => $response->status ) );

			throw new GatewayUnavailable( sprintf( 'PayPal answered the token call with HTTP %d and no token.', $response->status ) );
		}

		return $token;
	}

	/**
	 * Sends one request through the context's client, to the mode's API host.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the request got no answer.
	 *
	 * @param Mode                      $mode    The mode.
	 * @param string                    $method  The HTTP method.
	 * @param string                    $path    The path.
	 * @param string|null               $body    The body; null for none.
	 * @param array<string, string>     $headers The headers.
	 * @param array<string, int|string> $line    The request's method, path and mode, for the log.
	 * @return OutboundResponse The answer.
	 */
	private function exchange( Mode $mode, string $method, string $path, ?string $body, array $headers, array $line ): OutboundResponse {
		try {
			return $this->context->http()->send( new OutboundRequest( Gateway::apiHostId( $mode ), $method, Gateway::apiBase( $mode ) . $path, $headers, $body ) );
		} catch ( TransportFailure $failure ) {
			$this->context->logger()->warning( 'paypal.unavailable', 'A request to PayPal got no answer.', $line + array( 'failure' => $failure->kind ) );

			throw new GatewayUnavailable( 'A request to PayPal got no answer.', 0, $failure );
		}
	}

	/**
	 * Classifies an answer: one the operation reads, or a GatewayUnavailable.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the answer is not one the gateway can act on.
	 *
	 * @param OutboundResponse          $response The answer.
	 * @param array<string, int|string> $line     The request's method, path and mode, for the log.
	 * @return ApiAnswer The answer.
	 */
	private function answer( OutboundResponse $response, array $line ): ApiAnswer {
		try {
			$body = '' === trim( $response->body ) ? array() : $response->json();
		} catch ( \UnexpectedValueException $unreadable ) {
			$this->context->logger()->warning( 'paypal.unavailable', 'PayPal answered with a body that is not JSON.', $line + array( 'http_status' => $response->status ) );

			throw new GatewayUnavailable( sprintf( 'PayPal answered HTTP %d with a body that could not be read.', $response->status ), 0, $unreadable );
		}

		$answer = new ApiAnswer( $response->status, $body );

		if ( ! $answer->isError() ) {
			return $answer;
		}

		if ( in_array( $response->status, self::REQUEST_ERRORS, true ) && null !== Fields::text( $body, 'name' ) ) {
			return $answer;
		}

		$described = $line + array(
			'http_status' => $response->status,
			'error_name'  => $answer->errorName(),
			'issue'       => $answer->issue(),
			'debug_id'    => PayPalCodes::logged( $answer->debugId() ),
		);

		if ( 401 === $response->status || 403 === $response->status ) {
			$this->context->logger()->error( 'paypal.unauthorized', 'PayPal refused the credentials of this mode for a request.', $described );
		} else {
			$this->context->logger()->warning( 'paypal.unavailable', 'PayPal could not answer the request.', $described );
		}

		throw new GatewayUnavailable( sprintf( 'PayPal answered HTTP %d.', $response->status ) );
	}

	/**
	 * Returns a request's path as a log line may hold it: its words, with `{id}` for every other segment.
	 *
	 * The ids a request is about are logged on their own, where they are checked (PayPalCodes::logged()):
	 * a path built by path() holds only PayPal's words and ids, and this keeps a path written any other
	 * way from carrying anything else into the log.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path The path.
	 * @return string The path, such as `v2/checkout/orders/{id}/authorize`.
	 */
	private static function loggedPath( string $path ): string {
		$segments = explode( '/', $path );

		foreach ( $segments as $index => $segment ) {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9\-]{0,31}\z/', $segment ) ) {
				$segments[ $index ] = '{id}';
			}
		}

		return implode( '/', $segments );
	}

	/**
	 * Encodes a body as JSON.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When it cannot be encoded, which a body the gateway built never is.
	 *
	 * @param array<mixed>|\stdClass $data The body.
	 * @return string The JSON.
	 */
	private static function json( array|\stdClass $data ): string {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $json ) ) {
			throw new GatewayUnavailable( 'A PayPal request\'s body could not be encoded, so nothing was sent.' );
		}

		return $json;
	}
}
