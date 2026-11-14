<?php
/**
 * Tests the webhook signature: verified locally with PayPal's certificate, before the body is read
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\Contracts\TransportFailure;
use SEOCart\GatewayForPayPal\CertificateCache;
use SEOCart\GatewayForPayPal\Gateway;
use SEOCart\GatewayForPayPal\Tests\Support\GatewayTestCase;
use SEOCart\GatewayForPayPal\Tests\Support\TestDeliveries;

/**
 * Every test signs with a key pair made for it, kept in memory and never written, and checks the
 * delivery through readWebhook(), the production path: the headers' shapes, the transmission time
 * within five minutes either way, the certificate (fetched once from the mode's certificate host,
 * then kept a day) naming a host of paypal.com and valid at that time, then the signature over
 * `id|time|webhook id|CRC-32 of the raw body`, then the decode. The clock is set from the
 * certificate, so no test depends on today's date.
 *
 * Planted violations, each shown red and removed:
 * - the body decoded before openssl_verify: the malformed-body test answers malformed for a forged body;
 * - the CRC taken over the body decoded and encoded again: the re-encoded body test passes when it must fail;
 * - the window check removed: the stale tests fail;
 * - the certificate host loosened to any host of paypal.com: the other-host test fails.
 *
 * @since 0.2.0
 */
final class WebhookSignatureTest extends GatewayTestCase {

	/**
	 * The key pair of the test.
	 *
	 * @since 0.2.0
	 *
	 * @var TestDeliveries
	 */
	private TestDeliveries $keys;

	/**
	 * When the test's delivery is sent: a minute after its certificate became valid.
	 *
	 * @since 0.2.0
	 *
	 * @var \DateTimeImmutable
	 */
	private \DateTimeImmutable $sentAt;

	/**
	 * Makes the key pair, keeps its certificate as fetched, and sets the clock to the delivery's time.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->keys   = new TestDeliveries();
		$this->sentAt = $this->keys->validFrom->modify( '+60 seconds' );
		$this->context->clock->setTo( $this->sentAt->modify( '+2 seconds' ) );
		$this->keep( TestDeliveries::CERT_URL, $this->keys->certificate );
	}

	/**
	 * Tests a delivery signed for this webhook: verified, then read.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_signed_for_this_webhook_is_verified_then_read(): void {
		$body = self::body();

		$reading = $this->read( $this->keys->headers( $body, $this->sentAt ), $body );

		$this->assertSame( WebhookReadingKind::Result, $reading->kind );
		$this->assertSame( array(), $this->context->paypal->requests, 'A kept certificate needs no request, and no token.' );
	}

	/**
	 * Tests a byte flipped in the body, and in the signature.
	 *
	 * @since 0.2.0
	 */
	public function test_a_flipped_byte_is_a_bad_signature(): void {
		$body    = self::body();
		$headers = $this->keys->headers( $body, $this->sentAt );

		$this->assertRejected( WebhookReading::BAD_SIGNATURE, $headers, str_replace( '"20.00"', '"20.01"', $body ) );
		$this->assertRejected( WebhookReading::BAD_SIGNATURE, $headers, '{"truncated":', 'A forged body is refused for its signature, whatever it holds: nothing of it is read first.' );

		$signature                          = base64_decode( $headers['paypal-transmission-sig'] );
		$signature[10]                      = chr( ord( $signature[10] ) ^ 0x01 );
		$headers['paypal-transmission-sig'] = base64_encode( $signature );

		$this->assertRejected( WebhookReading::BAD_SIGNATURE, $headers, $body );
	}

	/**
	 * Tests the CRC over the raw bytes: the same event re-encoded, with the original's signature, fails.
	 *
	 * @since 0.2.0
	 */
	public function test_the_crc_is_over_the_raw_bytes_never_a_body_encoded_again(): void {
		$body    = self::body();
		$headers = $this->keys->headers( $body, $this->sentAt );

		$this->assertRejected( WebhookReading::BAD_SIGNATURE, $headers, (string) json_encode( json_decode( $body, true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Tests a transmission time outside the window, either way: stale, checked before the certificate.
	 *
	 * @since 0.2.0
	 */
	public function test_a_transmission_time_outside_five_minutes_either_way_is_stale(): void {
		$body = self::body();

		$this->assertRejected( WebhookReading::STALE, $this->keys->headers( $body, $this->sentAt->modify( '-302 seconds' ) ), $body );
		$this->assertRejected( WebhookReading::STALE, $this->keys->headers( $body, $this->sentAt->modify( '+303 seconds' ) ), $body );

		$this->transients = array();
		$this->assertRejected( WebhookReading::STALE, $this->keys->headers( $body, $this->sentAt->modify( '-1 hour' ) ), $body );
		$this->assertSame( array(), $this->context->paypal->requests, 'A stale delivery fetches no certificate.' );
	}

	/**
	 * Tests a delivery signed for another webhook: the signed text holds the mode's webhook id.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_signed_for_another_webhook_is_a_bad_signature(): void {
		$body = self::body();

		$this->assertRejected( WebhookReading::BAD_SIGNATURE, $this->keys->headers( $body, $this->sentAt, '9LIVEWEBHOOK00001' ), $body );

		$this->context->settings['test']['webhook_id'] = '';
		$this->assertRejected( WebhookReading::BAD_SIGNATURE, $this->keys->headers( $body, $this->sentAt ), $body );
	}

	/**
	 * Tests a certificate address on another host, or another mode's, or not a certificate's: rejected before any fetch.
	 *
	 * @since 0.2.0
	 */
	public function test_a_certificate_address_off_the_modes_certificate_host_is_a_bad_signature(): void {
		$body = self::body();

		foreach ( array(
			'https://evil.example/v1/notifications/certs/CERT-test-0001',
			'https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-test-0001',
			'https://www.sandbox.paypal.com/v1/notifications/certs/CERT-test-0001',
			Gateway::CERT_BASES['live'] . 'CERT-test-0001',
			'http://api.sandbox.paypal.com/v1/notifications/certs/CERT-test-0001',
			'https://api.sandbox.paypal.com.evil.example/v1/notifications/certs/CERT-test-0001',
			'https://api.sandbox.paypal.com:8443/v1/notifications/certs/CERT-test-0001',
			Gateway::CERT_BASES['test'] . 'CERT-test-0001?x=1',
			Gateway::CERT_BASES['test'] . '../keys/CERT-test-0001',
			Gateway::CERT_BASES['test'] . 'not-a-cert',
		) as $url ) {
			$this->keep( $url, $this->keys->certificate );
			$this->assertRejected( WebhookReading::BAD_SIGNATURE, $this->keys->headers( $body, $this->sentAt, TestDeliveries::WEBHOOK_ID, $url ), $body );
		}

		$this->assertSame( array(), $this->context->paypal->requests );
	}

	/**
	 * Tests a certificate that names a host outside paypal.com, and one expired at the delivery's time.
	 *
	 * @since 0.2.0
	 */
	public function test_a_certificate_of_another_name_or_expired_is_a_bad_signature(): void {
		$body     = self::body();
		$stranger = new TestDeliveries( 3650, 'messageverificationcerts.paypal.com.evil.example' );
		$this->keep( TestDeliveries::CERT_URL, $stranger->certificate );

		$this->assertRejected( WebhookReading::BAD_SIGNATURE, $stranger->headers( $body, $this->sentAt ), $body );

		$expiring = new TestDeliveries( 1 );
		$after    = $expiring->validTo->modify( '+1 hour' );
		$this->keep( TestDeliveries::CERT_URL, $expiring->certificate );
		$this->context->clock->setTo( $after );

		$this->assertRejected( WebhookReading::BAD_SIGNATURE, $expiring->headers( $body, $after ), $body );
	}

	/**
	 * Tests headers missing or out of shape, and another algorithm.
	 *
	 * @since 0.2.0
	 */
	public function test_headers_missing_or_out_of_shape_are_a_bad_signature(): void {
		$body    = self::body();
		$headers = $this->keys->headers( $body, $this->sentAt );

		foreach ( array_keys( $headers ) as $name ) {
			if ( 'content-type' !== $name ) {
				$this->assertRejected( WebhookReading::BAD_SIGNATURE, array_diff_key( $headers, array( $name => true ) ), $body );
			}
		}

		$changes = array(
			'paypal-auth-algo'         => 'SHA1withRSA',
			'paypal-transmission-id'   => 'not-a-uuid',
			'paypal-transmission-time' => '14 November 2026',
			'paypal-transmission-sig'  => 'not base64!',
		);

		foreach ( $changes as $name => $value ) {
			$this->assertRejected( WebhookReading::BAD_SIGNATURE, array( $name => $value ) + $headers, $body );
		}
	}

	/**
	 * Tests a body that is not an event, signed: verified first, then malformed.
	 *
	 * @since 0.2.0
	 */
	public function test_a_signed_body_that_is_not_an_event_is_malformed(): void {
		foreach ( array( '{"truncated":', '[]', '{"id":"not-an-event-id","event_type":"PAYMENT.CAPTURE.COMPLETED","create_time":"2026-11-14T12:00:00Z","resource":{"id":"x"}}' ) as $body ) {
			$this->assertRejected( WebhookReading::MALFORMED, $this->keys->headers( $body, $this->sentAt ), $body );
		}
	}

	/**
	 * Tests the certificate's fetch on a miss: one GET to the mode's certificate host, the certificate kept a day.
	 *
	 * @since 0.2.0
	 */
	public function test_a_certificate_not_kept_is_fetched_once_from_the_modes_host_and_kept_a_day(): void {
		$this->transients = array();
		$this->context->paypal->answer( 'GET', TestDeliveries::CERT_URL, 200, $this->keys->certificate );

		$body = self::body();
		$this->assertSame( WebhookReadingKind::Result, $this->read( $this->keys->headers( $body, $this->sentAt ), $body )->kind );
		$this->assertSame( WebhookReadingKind::Result, $this->read( $this->keys->headers( $body, $this->sentAt ), $body )->kind );

		$fetch = $this->context->paypal->requests;
		$this->assertCount( 1, $fetch, 'Fetched once, then kept.' );
		$this->assertSame( array( 'paypal-certs-sandbox', 'GET', TestDeliveries::CERT_URL, null ), array( $fetch[0]->hostId, $fetch[0]->method, $fetch[0]->url, $fetch[0]->body ) );
		$this->assertArrayNotHasKey( 'Authorization', $fetch[0]->headers, 'A certificate is public: no token is asked for or sent.' );

		$kept = $this->transients[ CertificateCache::key( TestDeliveries::CERT_URL ) ] ?? null;
		$this->assertSame( array( $this->keys->certificate, 86400 ), array( $kept['value'] ?? null, $kept['seconds'] ?? null ) );
		$this->assertStringStartsWith( 'seocart_paypal_cert_', CertificateCache::key( TestDeliveries::CERT_URL ) );
	}

	/**
	 * Tests a fetch that fails: the delivery is neither accepted nor refused (SEOCart answers 503), and nothing is kept.
	 *
	 * @since 0.2.0
	 */
	public function test_a_certificate_that_cannot_be_fetched_is_unavailable(): void {
		$this->transients = array();
		$this->context->paypal->fail( 'GET', TestDeliveries::CERT_URL, TransportFailure::TIMEOUT );
		$this->context->paypal->answer( 'GET', TestDeliveries::CERT_URL, 404, '{"name":"RESOURCE_NOT_FOUND"}' );
		$this->context->paypal->answer( 'GET', TestDeliveries::CERT_URL, 200, 'not a certificate' );

		$body = self::body();

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$this->unavailable( fn() => $this->read( $this->keys->headers( $body, $this->sentAt ), $body ) );
		}

		$this->assertSame( array(), $this->transients );
		$this->assertSame( array( 'paypal.certificate_unavailable' ), array_values( array_unique( $this->context->log->codes() ) ) );
	}

	/**
	 * Keeps a certificate for an address, as a fetch would.
	 *
	 * @since 0.2.0
	 *
	 * @param string $url The address.
	 * @param string $pem The certificate.
	 */
	private function keep( string $url, string $pem ): void {
		$this->transients[ CertificateCache::key( $url ) ] = array(
			'value'   => $pem,
			'seconds' => CertificateCache::SECONDS,
		);
	}

	/**
	 * Reads a delivery in test mode through the gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers The headers.
	 * @param string                $body    The body.
	 * @return WebhookReading The reading.
	 */
	private function read( array $headers, string $body ): WebhookReading {
		return $this->gateway->readWebhook( new WebhookEnvelope( 'paypal', Mode::Test, $headers, $body, $this->context->clock->now() ) );
	}

	/**
	 * Asserts a delivery is rejected for a reason.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $reason  The reason.
	 * @param array<string, string> $headers The headers.
	 * @param string                $body    The body.
	 * @param string                $message Optional. What the failure says. Default none.
	 */
	private function assertRejected( string $reason, array $headers, string $body, string $message = '' ): void {
		$reading = $this->read( $headers, $body );

		$this->assertSame( array( WebhookReadingKind::Rejected, $reason ), array( $reading->kind, $reading->reason ), $message );
	}

	/**
	 * Returns the body of an authorization's event.
	 *
	 * @since 0.2.0
	 *
	 * @return string The body.
	 */
	private static function body(): string {
		return TestDeliveries::event( 'PAYMENT.AUTHORIZATION.CREATED', TestDeliveries::authorization(), 'WH-2WR32451HC0233532-67976317FL4543714', new \DateTimeImmutable( '2026-11-14T12:00:00Z' ) );
	}
}
