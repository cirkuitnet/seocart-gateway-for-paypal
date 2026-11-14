<?php
/**
 * FakePayPal: an HTTP client that answers the gateway's requests from fixtures
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Support;

use SEOCart\Contracts\HttpClient;
use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\OutboundResponse;
use SEOCart\Contracts\TransportFailure;
use SEOCart\GatewayForPayPal\Gateway;
use SEOCart\GatewayForPayPal\PayPalApi;

/**
 * Stands in for SEOCart's HTTP client: answers each request with the next answer queued for its method and path, and records every request.
 *
 * A request's path is its address under the API's base of either mode (`v2/checkout/orders`), or
 * the whole address for any other host. The token call is answered by itself unless a test queues
 * another answer for it, so a test queues only the requests it is about. A request nothing was
 * queued for fails the test: the gateway sent something the test did not expect. Every body it
 * served is kept, so a test can check that none of it reached a log.
 *
 * @since 0.2.0
 */
final class FakePayPal implements HttpClient {

	/**
	 * Every request received, the token calls included, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<OutboundRequest>
	 */
	public array $requests = array();

	/**
	 * Every body served, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public array $served = array();

	/**
	 * The answers waiting, in order: method, path, then a status and body, or a transport failure's kind.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{method: string, path: string, status: int, body: string, failure: string|null}>
	 */
	private array $queue = array();

	/**
	 * Queues an answer.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method The request's method.
	 * @param string $path   The request's path under the API's base, or a whole address on another host.
	 * @param int    $status The HTTP status.
	 * @param string $body   The body.
	 * @return self The client.
	 */
	public function answer( string $method, string $path, int $status, string $body ): self {
		$this->queue[] = array(
			'method'  => $method,
			'path'    => $path,
			'status'  => $status,
			'body'    => $body,
			'failure' => null,
		);

		return $this;
	}

	/**
	 * Queues a request that gets no answer.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method The request's method.
	 * @param string $path   The request's path.
	 * @param string $kind   The TransportFailure kind.
	 * @return self The client.
	 */
	public function fail( string $method, string $path, string $kind ): self {
		$this->queue[] = array(
			'method'  => $method,
			'path'    => $path,
			'status'  => 0,
			'body'    => '',
			'failure' => $kind,
		);

		return $this;
	}

	/**
	 * Answers a request with the first answer queued for its method and path; a token call nothing was queued for gets a token.
	 *
	 * @since 0.2.0
	 *
	 * @throws TransportFailure  When the answer queued is a failure.
	 * @throws \LogicException   When nothing was queued for the request.
	 *
	 * @param OutboundRequest $request The request.
	 * @return OutboundResponse The answer.
	 */
	public function send( OutboundRequest $request ): OutboundResponse {
		$this->requests[] = $request;
		$path             = self::pathOf( $request );

		foreach ( $this->queue as $index => $queued ) {
			if ( $queued['method'] !== $request->method || $queued['path'] !== $path ) {
				continue;
			}

			array_splice( $this->queue, $index, 1 );

			if ( null !== $queued['failure'] ) {
				throw new TransportFailure( $queued['failure'], (string) parse_url( $request->url, PHP_URL_HOST ) );
			}

			return $this->serve( $queued['status'], $queued['body'] );
		}

		if ( 'POST' === $request->method && PayPalApi::TOKEN_PATH === $path ) {
			return $this->serve( 200, Fixtures::text( 'token' ) );
		}

		throw new \LogicException( "The gateway sent {$request->method} {$path}, which the test did not expect." );
	}

	/**
	 * Returns every request but the token calls as `METHOD path`, in order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The requests.
	 */
	public function sent(): array {
		return array_map( static fn( OutboundRequest $request ): string => $request->method . ' ' . self::pathOf( $request ), $this->apiRequests() );
	}

	/**
	 * Returns every request but the token calls.
	 *
	 * @since 0.2.0
	 *
	 * @return list<OutboundRequest> The requests.
	 */
	public function apiRequests(): array {
		return array_values( array_filter( $this->requests, static fn( OutboundRequest $request ): bool => PayPalApi::TOKEN_PATH !== self::pathOf( $request ) ) );
	}

	/**
	 * Returns the token calls.
	 *
	 * @since 0.2.0
	 *
	 * @return list<OutboundRequest> The requests.
	 */
	public function tokenRequests(): array {
		return array_values( array_filter( $this->requests, static fn( OutboundRequest $request ): bool => PayPalApi::TOKEN_PATH === self::pathOf( $request ) ) );
	}

	/**
	 * Returns the last request but a token call.
	 *
	 * @since 0.2.0
	 *
	 * @return OutboundRequest The request.
	 */
	public function last(): OutboundRequest {
		$requests = $this->apiRequests();

		return $requests[ count( $requests ) - 1 ];
	}

	/**
	 * Returns the JSON body of a request, decoded.
	 *
	 * @since 0.2.0
	 *
	 * @param OutboundRequest|null $request Optional. The request. Default the last.
	 * @return array<mixed> The body.
	 */
	public function body( ?OutboundRequest $request = null ): array {
		return (array) json_decode( (string) ( $request ?? $this->last() )->body, true );
	}

	/**
	 * Tells whether every queued answer was asked for.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when the queue is empty.
	 */
	public function isDrained(): bool {
		return array() === $this->queue;
	}

	/**
	 * Returns a request's path under the API's base of either mode, or its whole address.
	 *
	 * @since 0.2.0
	 *
	 * @param OutboundRequest $request The request.
	 * @return string The path.
	 */
	public static function pathOf( OutboundRequest $request ): string {
		foreach ( Gateway::API_BASES as $base ) {
			if ( str_starts_with( $request->url, $base ) ) {
				return substr( $request->url, strlen( $base ) );
			}
		}

		return $request->url;
	}

	/**
	 * Serves a body, keeping it.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $status The status.
	 * @param string $body   The body.
	 * @return OutboundResponse The answer.
	 */
	private function serve( int $status, string $body ): OutboundResponse {
		$this->served[] = $body;

		return new OutboundResponse( $status, array( 'content-type' => 'application/json' ), $body );
	}
}
