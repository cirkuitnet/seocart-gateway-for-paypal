<?php
/**
 * PayPalTestApi: the live tests' own requests to PayPal's sandbox, beside the gateway's
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Integration\Live;

use SEOCart\GatewayForPayPal\Gateway;
use SEOCart\GatewayForPayPal\PayPalApi;
use SEOCart\GatewayForPayPal\Tests\Support\Live\MessageGuard;

/**
 * Asks PayPal's sandbox what the live tests check, makes the one vaulted card, and ends what a run leaves open: reads, an order to vault a card, its authorization and void.
 *
 * These requests are the tests', not the gateway's, so they go around WordPress's HTTP API, which
 * the conformance harness watches, and around the recording transport. They use PHP's curl, to the
 * sandbox's API host alone, with an access token asked for once with the sandbox app's client id
 * and secret and kept in this object. A failure names the method, the path with its ids as `{id}`,
 * and PayPal's status and error name, never a body, a header or a query.
 *
 * @since 0.2.0
 */
final class PayPalTestApi {

	/**
	 * How long a request may take to connect, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const CONNECT_SECONDS = 10;

	/**
	 * How long a request may take in all, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const TOTAL_SECONDS = 30;

	/**
	 * The sandbox app's client id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $clientId;

	/**
	 * The sandbox app's secret.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * The access token, once asked for.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private ?string $token = null;

	/**
	 * Builds the client over the sandbox app's credentials.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When either is empty.
	 *
	 * @param string $clientId The client id.
	 * @param string $secret   The secret.
	 */
	public function __construct( #[\SensitiveParameter] string $clientId, #[\SensitiveParameter] string $secret ) {
		if ( '' === $clientId || '' === $secret ) {
			throw new \InvalidArgumentException( 'The live tests call PayPal\'s sandbox with its app\'s client id and secret.' );
		}

		$this->clientId = $clientId;
		$this->secret   = $secret;
	}

	/**
	 * Builds the client from a settings file of the test mode, as the workflow writes it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the file holds no client id and secret.
	 *
	 * @param string $file The settings file.
	 * @return self The client.
	 */
	public static function fromSettingsFile( string $file ): self {
		$settings = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

		return new self( (string) ( $settings[ Gateway::CLIENT_ID ] ?? '' ), (string) ( $settings[ Gateway::CLIENT_SECRET ] ?? '' ) );
	}

	/**
	 * Reads an object or a list.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $path  The path, such as `v2/checkout/orders/5O190127TN364715T`.
	 * @param array<string, mixed> $query Optional. The query. Default none.
	 * @return array<mixed> The answer.
	 */
	public function get( string $path, array $query = array() ): array {
		return $this->send( 'GET', $path . ( array() === $query ? '' : '?' . http_build_query( $query ) ), null, null );
	}

	/**
	 * Sends a change, with its PayPal-Request-Id.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $path      The path.
	 * @param array<mixed> $body      The JSON body; empty for `{}`.
	 * @param string       $requestId The PayPal-Request-Id.
	 * @return array<mixed> The answer; empty for 204.
	 */
	public function post( string $path, array $body, string $requestId ): array {
		return $this->send( 'POST', $path, (string) json_encode( array() === $body ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES ), $requestId );
	}

	/**
	 * Returns the sandbox app's client id, which PayPal's script in the browser is loaded with.
	 *
	 * @since 0.2.0
	 *
	 * @return string The client id.
	 */
	public function clientId(): string {
		return $this->clientId;
	}

	/**
	 * Asks for the access token, once.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When PayPal's sandbox answered without a token.
	 *
	 * @return string The token.
	 */
	private function token(): string {
		if ( null === $this->token ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials are base64 by definition.
			$answer      = $this->exchange( 'POST', PayPalApi::TOKEN_PATH, 'grant_type=client_credentials', array( 'Authorization: Basic ' . base64_encode( $this->clientId . ':' . $this->secret ), 'Content-Type: application/x-www-form-urlencoded' ) );
			$this->token = is_string( $answer['access_token'] ?? null ) ? $answer['access_token'] : throw new \RuntimeException( 'PayPal\'s sandbox answered the token call without a token.' );
		}

		return $this->token;
	}

	/**
	 * Sends a request with the token.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $method    The method.
	 * @param string      $target    The path, with its query for a GET.
	 * @param string|null $body      The JSON body; null for none.
	 * @param string|null $requestId The PayPal-Request-Id; null for none.
	 * @return array<mixed> The answer.
	 */
	private function send( string $method, string $target, ?string $body, ?string $requestId ): array {
		$headers = array( 'Authorization: Bearer ' . $this->token(), 'Content-Type: application/json', 'Prefer: return=representation' );

		if ( null !== $requestId ) {
			$headers[] = PayPalApi::REQUEST_ID . ': ' . $requestId;
		}

		return $this->exchange( $method, $target, $body, $headers );
	}

	/**
	 * Sends a request to the sandbox's API host and decodes the answer.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When PayPal could not be reached or answered other than 2xx JSON: the method, the path, the status and the error's name only.
	 *
	 * @param string      $method  The method.
	 * @param string      $target  The path, with its query for a GET.
	 * @param string|null $body    The body; null for none.
	 * @param string[]    $headers The headers.
	 * @return array<mixed> The answer; empty for 204.
	 *
	 * @phpstan-param list<string> $headers
	 */
	private function exchange( string $method, string $target, ?string $body, array $headers ): array {
		// A failure names the path with its ids as `{id}`: an id of PayPal's may hold a run of digits MessageGuard refuses to print.
		$path = (string) preg_replace( '#/[A-Z0-9]{17}(?=/|$)#', '/{id}', explode( '?', $target, 2 )[0] );

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_errno -- the test's own requests, outside WordPress's HTTP API, which the conformance harness keeps for the gateway's.
		$curl = curl_init( Gateway::API_BASES['test'] . $target );

		if ( false === $curl ) {
			throw new \RuntimeException( MessageGuard::say( 'A request for %1$s %2$s could not be made.', $method, $path ) );
		}

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => self::CONNECT_SECONDS,
				CURLOPT_TIMEOUT        => self::TOTAL_SECONDS,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_POSTFIELDS     => $body,
			)
		);

		$answer = curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$failed = curl_errno( $curl );
		// phpcs:enable

		if ( 0 !== $failed || ! is_string( $answer ) ) {
			throw new \RuntimeException( MessageGuard::say( 'PayPal\'s sandbox could not be reached for %1$s %2$s (curl error %3$d).', $method, $path, $failed ) );
		}

		$decoded = '' === trim( $answer ) ? array() : json_decode( $answer, true );

		if ( $status < 200 || $status > 299 || ! is_array( $decoded ) ) {
			$name  = is_array( $decoded ) && is_string( $decoded['name'] ?? null ) && 1 === preg_match( '/^[A-Z_]{1,64}$/D', $decoded['name'] ) ? $decoded['name'] : 'none';
			$issue = is_array( $decoded ) && is_string( $decoded['details'][0]['issue'] ?? null ) && 1 === preg_match( '/^[A-Z_]{1,64}$/D', $decoded['details'][0]['issue'] ) ? $decoded['details'][0]['issue'] : 'none';

			throw new \RuntimeException( MessageGuard::say( 'PayPal\'s sandbox answered %1$s %2$s with HTTP %3$d (%4$s, issue %5$s).', $method, $path, $status, $name, $issue ) );
		}

		return $decoded;
	}
}
