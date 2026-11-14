<?php
/**
 * Tests PayPalApi: the token, the hosts, the headers and how an answer is classified
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\TransportFailure;
use SEOCart\GatewayForPayPal\PayPalApi;
use SEOCart\GatewayForPayPal\Tests\Support\FakeContext;
use SEOCart\GatewayForPayPal\Tests\Support\Fixtures;
use SEOCart\GatewayForPayPal\Tests\Support\GatewayTestCase;

/**
 * A request asks PayPal for an access token once (HTTP Basic, the mode's client id and secret, to
 * the mode's host), keeps it for the rest of the request, and sends it as a bearer token; a token
 * PayPal refuses is asked for again once and the request sent again with the same PayPal-Request-Id.
 * Every POST is JSON, asks for the whole object and carries its PayPal-Request-Id; the sandbox's mock
 * header is sent in test mode only. Errors about the request are answers; every other failure is
 * GatewayUnavailable. The token, the secret and PayPal's bodies never reach a log line.
 *
 * Planted violations, each shown red and removed:
 * - the access token logged on the refresh line: the log scan of every test fails;
 * - the mock header sent in live mode: the live mock test fails;
 * - the other mode's secret read for the token call: the mode test fails.
 *
 * @since 0.2.0
 */
final class PayPalApiTest extends GatewayTestCase {

	/**
	 * Tests the token call, and that its token is kept for the request.
	 *
	 * @since 0.2.0
	 */
	public function test_the_token_is_asked_for_once_with_the_modes_credentials_and_sent_as_a_bearer(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-created' ) );
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-created' ) );

		$api = new PayPalApi( $this->context );
		$api->get( Mode::Test, self::orderPath() );
		$api->get( Mode::Test, self::orderPath() );

		$tokens = $this->context->paypal->tokenRequests();
		$this->assertCount( 1, $tokens, 'One token call per request.' );
		$this->assertSame( array( 'paypal-api-sandbox', 'POST', 'https://api-m.sandbox.paypal.com/v1/oauth2/token', 'grant_type=client_credentials' ), array( $tokens[0]->hostId, $tokens[0]->method, $tokens[0]->url, $tokens[0]->body ) );
		$this->assertSame( 'Basic ' . base64_encode( 'AUnitTestClientIdOfTheTestMode:' . FakeContext::TEST_SECRET ), $tokens[0]->headers['Authorization'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $tokens[0]->headers['Content-Type'] );
		$this->assertSame( array( 'test/client_secret' ), $this->context->secretReads );

		foreach ( $this->context->paypal->apiRequests() as $request ) {
			$this->assertSame( 'Bearer ' . Fixtures::ACCESS_TOKEN, $request->headers['Authorization'] );
			$this->assertSame( 'paypal-api-sandbox', $request->hostId );
		}
	}

	/**
	 * Tests that a live request goes to PayPal's live host with the live credentials, never the sandbox's.
	 *
	 * @since 0.2.0
	 */
	public function test_a_live_request_carries_the_live_credentials_to_the_live_host(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 200, Fixtures::text( 'order-created' ) );

		( new PayPalApi( $this->context ) )->get( Mode::Live, self::orderPath() );

		$token = $this->context->paypal->tokenRequests()[0];
		$this->assertSame( array( 'paypal-api-live', 'https://api-m.paypal.com/v1/oauth2/token' ), array( $token->hostId, $token->url ) );
		$this->assertSame( 'Basic ' . base64_encode( 'AUnitTestClientIdOfTheLiveMode:' . FakeContext::LIVE_SECRET ), $token->headers['Authorization'] );
		$this->assertSame( array( 'live/client_secret' ), $this->context->secretReads );
		$this->assertSame( 'https://api-m.paypal.com/' . self::orderPath(), $this->context->paypal->last()->url );
	}

	/**
	 * Tests a POST: JSON, the whole object asked for, the request id; `{}` for an empty body.
	 *
	 * @since 0.2.0
	 */
	public function test_a_post_is_json_asks_for_the_whole_object_and_carries_its_request_id(): void {
		$this->context->paypal->answer( 'POST', self::orderPath( '/authorize' ), 201, Fixtures::text( 'order-authorized' ) );

		( new PayPalApi( $this->context ) )->post( Mode::Test, self::orderPath( '/authorize' ), array(), 'the-key' );

		$sent = $this->context->paypal->last();
		$this->assertSame( '{}', $sent->body );
		$this->assertSame( 'application/json', $sent->headers['Content-Type'] );
		$this->assertSame( 'return=representation', $sent->headers['Prefer'] );
		$this->assertSame( 'the-key', $sent->headers['PayPal-Request-Id'] );
		$this->assertArrayNotHasKey( 'PayPal-Mock-Response', $sent->headers );
	}

	/**
	 * Tests the sandbox's mock header: sent in test mode, and in live mode never, nothing sent at all.
	 *
	 * @since 0.2.0
	 */
	public function test_the_mock_header_is_sent_in_test_mode_and_refuses_a_live_request(): void {
		$this->context->paypal->answer( 'POST', 'v2/checkout/orders', 422, Fixtures::text( 'error-422' ) );

		$api = new PayPalApi( $this->context );
		$api->post( Mode::Test, 'v2/checkout/orders', array( 'intent' => 'AUTHORIZE' ), 'the-key', 'INSTRUMENT_DECLINED' );

		$this->assertSame( '{"mock_application_codes":"INSTRUMENT_DECLINED"}', $this->context->paypal->last()->headers['PayPal-Mock-Response'] );

		$sent = count( $this->context->paypal->requests );
		$this->unavailable( static fn() => $api->post( Mode::Live, 'v2/checkout/orders', array( 'intent' => 'AUTHORIZE' ), 'the-key', 'INSTRUMENT_DECLINED' ) );

		$this->assertSame( $sent, count( $this->context->paypal->requests ), 'Nothing is sent when live mode is asked for a mock.' );
		$this->assertContains( 'paypal.mock_in_live_mode', $this->context->log->codes() );
	}

	/**
	 * Tests a token PayPal refuses mid-request: one new token, the same request again, the same request id.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refused_token_is_asked_for_again_once_and_the_request_sent_again_as_it_was(): void {
		$this->context->paypal->answer( 'POST', self::orderPath( '/authorize' ), 401, Fixtures::text( 'error-401' ) );
		$this->context->paypal->answer( 'POST', self::orderPath( '/authorize' ), 201, Fixtures::text( 'order-authorized' ) );

		$answer = ( new PayPalApi( $this->context ) )->post( Mode::Test, self::orderPath( '/authorize' ), array(), 'the-key' );

		$this->assertSame( 201, $answer->status );
		$this->assertCount( 2, $this->context->paypal->tokenRequests() );

		$sent = $this->context->paypal->apiRequests();
		$this->assertCount( 2, $sent );
		$this->assertSame( array( $sent[0]->body, $sent[0]->headers['PayPal-Request-Id'] ), array( $sent[1]->body, $sent[1]->headers['PayPal-Request-Id'] ) );
		$this->assertContains( 'paypal.token_refused', $this->context->log->codes() );
	}

	/**
	 * Tests a token refused twice: no third try, GatewayUnavailable.
	 *
	 * @since 0.2.0
	 */
	public function test_a_token_refused_twice_is_unavailable(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 401, Fixtures::text( 'error-401' ) );
		$this->context->paypal->answer( 'GET', self::orderPath(), 401, Fixtures::text( 'error-401' ) );

		$this->unavailable( fn() => ( new PayPalApi( $this->context ) )->get( Mode::Test, self::orderPath() ) );

		$this->assertCount( 2, $this->context->paypal->apiRequests() );
		$this->assertContains( 'paypal.unauthorized', $this->context->log->codes() );
	}

	/**
	 * Tests credentials PayPal refuses at the token call, and a mode without a client id.
	 *
	 * @since 0.2.0
	 */
	public function test_refused_or_missing_credentials_send_nothing_further(): void {
		$this->context->paypal->answer( 'POST', 'v1/oauth2/token', 401, Fixtures::text( 'error-401-token' ) );

		$this->unavailable( fn() => ( new PayPalApi( $this->context ) )->get( Mode::Test, self::orderPath() ) );
		$this->assertSame( array(), $this->context->paypal->apiRequests() );
		$this->assertSame( 'paypal.unauthorized', $this->context->log->codes()[0] );

		$this->context->settings['live']['client_id'] = '';
		$sent = count( $this->context->paypal->requests );

		$this->unavailable( fn() => ( new PayPalApi( $this->context ) )->get( Mode::Live, self::orderPath() ) );
		$this->assertSame( $sent, count( $this->context->paypal->requests ) );
		$this->assertContains( 'paypal.not_configured', $this->context->log->codes() );
	}

	/**
	 * Tests a token call answered without a token.
	 *
	 * @since 0.2.0
	 */
	public function test_a_token_answer_without_a_bearer_token_is_unavailable(): void {
		$this->context->paypal->answer( 'POST', 'v1/oauth2/token', 200, Fixtures::with( 'token', array( 'token_type' => 'MAC' ) ) );

		$this->unavailable( fn() => ( new PayPalApi( $this->context ) )->get( Mode::Test, self::orderPath() ) );
		$this->assertSame( array(), $this->context->paypal->apiRequests() );
	}

	/**
	 * Tests the errors that are answers, and those that are not.
	 *
	 * @since 0.2.0
	 */
	public function test_errors_about_the_request_are_answers_and_every_other_failure_is_unavailable(): void {
		$paypal = $this->context->paypal;
		$paypal->answer( 'GET', self::orderPath(), 404, Fixtures::text( 'error-404' ) );
		$paypal->answer( 'GET', self::orderPath(), 422, Fixtures::text( 'error-422' ) );
		$paypal->answer( 'GET', self::orderPath(), 400, Fixtures::text( 'error-400' ) );
		$paypal->answer( 'GET', self::orderPath(), 500, Fixtures::text( 'error-500' ) );
		$paypal->answer( 'GET', self::orderPath(), 429, '{"name":"RATE_LIMIT_REACHED"}' );
		$paypal->answer( 'GET', self::orderPath(), 200, '<html>maintenance</html>' );
		$paypal->answer( 'GET', self::orderPath(), 422, '{"message":"no name"}' );
		$paypal->fail( 'GET', self::orderPath(), TransportFailure::TIMEOUT );

		$api = new PayPalApi( $this->context );

		$this->assertSame( array( 404, 'RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID', Fixtures::DEBUG_ID ), self::described( $api->get( Mode::Test, self::orderPath() ) ) );
		$this->assertSame( array( 422, 'UNPROCESSABLE_ENTITY', 'INSTRUMENT_DECLINED', Fixtures::DEBUG_ID ), self::described( $api->get( Mode::Test, self::orderPath() ) ) );
		$this->assertSame( array( 400, 'INVALID_REQUEST', 'DECIMAL_PRECISION', Fixtures::DEBUG_ID ), self::described( $api->get( Mode::Test, self::orderPath() ) ) );

		for ( $failure = 0; $failure < 5; $failure++ ) {
			$this->unavailable( fn() => $api->get( Mode::Test, self::orderPath() ) );
		}

		$this->assertTrue( $paypal->isDrained() );
	}

	/**
	 * Tests that a path is built of PayPal's words and ids only.
	 *
	 * @since 0.2.0
	 */
	public function test_a_path_holds_only_paypals_words_and_ids(): void {
		$this->assertSame( 'v2/checkout/orders/' . Fixtures::ORDER_ID . '/authorize', PayPalApi::path( 'v2', 'checkout', 'orders', Fixtures::ORDER_ID, 'authorize' ) );

		foreach ( array( '4111111111111111', 'a/b', '..', '5O190127TN364715T?x=1', '' ) as $segment ) {
			$this->unavailable( static fn() => PayPalApi::path( 'v2', 'checkout', 'orders', $segment ) );
		}
	}

	/**
	 * Returns an answer's status, error name, issue and debug id.
	 *
	 * @since 0.2.0
	 *
	 * @param \SEOCart\GatewayForPayPal\ApiAnswer $answer The answer.
	 * @return array{int, string|null, string|null, string|null} What it says.
	 */
	private static function described( \SEOCart\GatewayForPayPal\ApiAnswer $answer ): array {
		return array( $answer->status, $answer->errorName(), $answer->issue(), $answer->debugId() );
	}

	/**
	 * Tests that a GatewayUnavailable says nothing of a body.
	 *
	 * @since 0.2.0
	 */
	public function test_an_unavailable_answer_names_its_status_only(): void {
		$this->context->paypal->answer( 'GET', self::orderPath(), 500, Fixtures::text( 'error-500' ) );

		$unavailable = $this->unavailable( fn() => ( new PayPalApi( $this->context ) )->get( Mode::Test, self::orderPath() ) );

		$this->assertInstanceOf( GatewayUnavailable::class, $unavailable );
		$this->assertSame( 'PayPal answered HTTP 500.', $unavailable->getMessage() );
		$this->assertSame( array( 'method', 'path', 'mode', 'http_status', 'error_name', 'issue', 'debug_id' ), array_keys( (array) $this->context->log->contextOf( 'paypal.unavailable' ) ) );
	}
}
