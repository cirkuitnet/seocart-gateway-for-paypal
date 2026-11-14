<?php
/**
 * PayPalAtTransport: PayPal's REST API, answered at `pre_http_request`, for integration tests
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Integration\Support;

use SEOCart\GatewayForPayPal\Gateway;
use SEOCart\GatewayForPayPal\PayPalApi;

/**
 * Answers the gateway's requests to PayPal after SEOCart's own HTTP client has checked them, so nothing reaches the network.
 *
 * The token call is answered with a token of the test's; any other request from a queue. Every
 * request is recorded: its method, its path under the API's base, its headers, its body and the
 * transaction depth it was sent at.
 *
 * @since 0.2.0
 */
final class PayPalAtTransport {

	/**
	 * The access token every token call is answered with: the shape of one, nothing more.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TOKEN = 'IntegrationTestAccessTokenOnly';

	/**
	 * Every request received, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{method: string, path: string, headers: array<string, string>, body: string, depth: int}>
	 */
	public array $requests = array();

	/**
	 * The answers waiting: method and path, then status and body.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{method: string, path: string, status: int, body: string}>
	 */
	private array $queue = array();

	/**
	 * Starts answering.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $depth Returns the open transaction levels of the test's connection (int).
	 */
	public function __construct( private \Closure $depth ) {
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * Queues the answer to a request.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method The method.
	 * @param string $path   The path under the API's base.
	 * @param int    $status The status.
	 * @param string $body   The body.
	 */
	public function queue( string $method, string $path, int $status, string $body ): void {
		$this->queue[] = array(
			'method' => $method,
			'path'   => $path,
			'status' => $status,
			'body'   => $body,
		);
	}

	/**
	 * Answers a request to PayPal's API in either mode; leaves any other to WordPress. Filters `pre_http_request`.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $pre  What an earlier filter answered.
	 * @param mixed  $args The request's arguments.
	 * @param string $url  The URL.
	 * @return mixed The answer, in the shape WordPress's HTTP API returns; `$pre` for another host.
	 */
	public function answer( mixed $pre, mixed $args, string $url ): mixed {
		$path = null;

		foreach ( Gateway::API_BASES as $base ) {
			if ( str_starts_with( $url, $base ) ) {
				$path = substr( $url, strlen( $base ) );
			}
		}

		if ( null === $path ) {
			return $pre;
		}

		$args   = (array) $args;
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );

		$this->requests[] = array(
			'method'  => $method,
			'path'    => $path,
			'headers' => (array) ( $args['headers'] ?? array() ),
			'body'    => (string) ( $args['body'] ?? '' ),
			'depth'   => (int) ( $this->depth )(),
		);

		if ( PayPalApi::TOKEN_PATH === $path ) {
			return self::response(
				200,
				(string) wp_json_encode(
					array(
						'access_token' => self::TOKEN,
						'token_type'   => 'Bearer',
						'expires_in'   => 32400,
					)
				)
			);
		}

		foreach ( $this->queue as $index => $queued ) {
			if ( $queued['method'] === $method && $queued['path'] === $path ) {
				array_splice( $this->queue, $index, 1 );

				return self::response( $queued['status'], $queued['body'] );
			}
		}

		return self::response( 500, '{"name":"INTERNAL_SERVER_ERROR","message":"The test queued no answer."}' );
	}

	/**
	 * Returns the requests as `METHOD path`, in order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The requests.
	 */
	public function sent(): array {
		return array_map( static fn( array $request ): string => $request['method'] . ' ' . $request['path'], $this->requests );
	}

	/**
	 * Builds an answer in the shape WordPress's HTTP API returns.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $status The status.
	 * @param string $body   The body.
	 * @return array<string, mixed> The answer.
	 */
	private static function response( int $status, string $body ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => $status < 300 ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
