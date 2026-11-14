<?php
/**
 * TestDeliveries: webhook deliveries signed as PayPal signs them, with a key pair made for the test
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Support;

use SEOCart\GatewayForPayPal\Gateway;

/**
 * Makes a key pair and a certificate, and signs webhook deliveries with them exactly as PayPal signs its own.
 *
 * The private key lives in memory for the test that made it and is never written: the unit tests
 * make a pair each run, and the conformance suite's two deliveries in tests/fixtures/paypal/webhooks
 * were signed by a pair write-test-deliveries.php made once, of which only the certificate was kept.
 * The certificate names a host of paypal.com, as the gateway requires, and its organisation says it
 * is a test fixture; it is valid from the moment it is made.
 *
 * @since 0.2.0
 */
final class TestDeliveries {

	/**
	 * The webhook id the deliveries are signed for, which the unit tests' test mode and the suite's recording carry.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const WEBHOOK_ID = '0TESTWEBHOOK00001';

	/**
	 * The address the deliveries name for their certificate, on the sandbox's certificate host.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CERT_URL = Gateway::CERT_BASES['test'] . 'CERT-test-0001';

	/**
	 * The intent the deliveries' payments were created for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INTENT_UUID = '0199c0de-0000-7000-8000-00000000c001';

	/**
	 * The private key of the pair, in memory only.
	 *
	 * @since 0.2.0
	 *
	 * @var \OpenSSLAsymmetricKey
	 */
	private \OpenSSLAsymmetricKey $key;

	/**
	 * The certificate of the pair, PEM.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public readonly string $certificate;

	/**
	 * When the certificate becomes valid.
	 *
	 * @since 0.2.0
	 *
	 * @var \DateTimeImmutable
	 */
	public readonly \DateTimeImmutable $validFrom;

	/**
	 * When the certificate stops being valid.
	 *
	 * @since 0.2.0
	 *
	 * @var \DateTimeImmutable
	 */
	public readonly \DateTimeImmutable $validTo;

	/**
	 * Makes a key pair and its certificate.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When OpenSSL cannot make them.
	 *
	 * @param int    $days       Optional. How many days the certificate is valid. Default 3650.
	 * @param string $commonName Optional. The host the certificate names. Default the sandbox's message verification host.
	 */
	public function __construct( int $days = 3650, string $commonName = 'messageverificationcerts.sandbox.paypal.com' ) {
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		if ( false === $key ) {
			throw new \RuntimeException( 'OpenSSL could not make a key pair.' );
		}

		$request = openssl_csr_new(
			array(
				'commonName'       => $commonName,
				'organizationName' => 'SEOCart test fixture',
			),
			$key,
			array( 'digest_alg' => 'sha256' )
		);
		$signed  = false === $request || true === $request ? false : openssl_csr_sign( $request, null, $key, $days, array( 'digest_alg' => 'sha256' ), random_int( 1, PHP_INT_MAX ) );

		if ( false === $signed || ! openssl_x509_export( $signed, $pem ) ) {
			throw new \RuntimeException( 'OpenSSL could not make a certificate.' );
		}

		$parsed = (array) openssl_x509_parse( $signed );

		$this->key         = $key;
		$this->certificate = $pem;
		$this->validFrom   = new \DateTimeImmutable( '@' . (int) ( $parsed['validFrom_time_t'] ?? 0 ) );
		$this->validTo     = new \DateTimeImmutable( '@' . (int) ( $parsed['validTo_time_t'] ?? 0 ) );
	}

	/**
	 * Returns the headers PayPal sends with a body: its transmission, the time, the signature, the algorithm and the certificate's address.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $body      The raw body, as it will be sent.
	 * @param \DateTimeImmutable $at        The transmission time.
	 * @param string             $webhookId Optional. The webhook id signed for. Default WEBHOOK_ID.
	 * @param string             $certUrl   Optional. The certificate's address. Default CERT_URL.
	 * @return array<string, string> The headers, by lower-case name.
	 */
	public function headers( string $body, \DateTimeImmutable $at, string $webhookId = self::WEBHOOK_ID, string $certUrl = self::CERT_URL ): array {
		$id   = self::uuid();
		$time = $at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );

		return array(
			'content-type'             => 'application/json',
			'paypal-transmission-id'   => $id,
			'paypal-transmission-time' => $time,
			'paypal-transmission-sig'  => $this->sign( $id . '|' . $time . '|' . $webhookId . '|' . sprintf( '%u', crc32( $body ) ) ),
			'paypal-auth-algo'         => 'SHA256withRSA',
			'paypal-cert-url'          => $certUrl,
		);
	}

	/**
	 * Signs a text as PayPal does: RSA with SHA-256, base64.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When OpenSSL cannot sign.
	 *
	 * @param string $text The text.
	 * @return string The signature.
	 */
	public function sign( string $text ): string {
		if ( ! openssl_sign( $text, $signature, $this->key, OPENSSL_ALGO_SHA256 ) ) {
			throw new \RuntimeException( 'OpenSSL could not sign.' );
		}

		return base64_encode( $signature );
	}

	/**
	 * Returns a delivery's body: an event of a type, about a resource.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $type     The event's type, such as `PAYMENT.AUTHORIZATION.CREATED`.
	 * @param array<mixed>       $payload  The resource.
	 * @param string             $id       The event's id.
	 * @param \DateTimeImmutable $at       When it happened.
	 * @return string The body, as PayPal sends it: compact JSON.
	 */
	public static function event( string $type, array $payload, string $id, \DateTimeImmutable $at ): string {
		return json_encode(
			array(
				'id'               => $id,
				'event_version'    => '1.0',
				'create_time'      => $at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.v\Z' ),
				'resource_type'    => strtolower( explode( '.', $type )[1] ?? 'order' ),
				'resource_version' => '2.0',
				'event_type'       => $type,
				'summary'          => 'A test event of the type ' . $type . '.',
				'resource'         => $payload,
				'links'            => array(
					array(
						'href'   => 'https://api.sandbox.paypal.com/v1/notifications/webhooks-events/' . $id,
						'rel'    => 'self',
						'method' => 'GET',
					),
				),
			),
			JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
		);
	}

	/**
	 * Returns the resource of an authorization event: the fixtures' authorization, for an intent.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid Optional. The intent its `custom_id` names. Default INTENT_UUID.
	 * @return array<mixed> The authorization.
	 */
	public static function authorization( string $intentUuid = self::INTENT_UUID ): array {
		$authorization = Fixtures::object( 'order-authorized' )['purchase_units'][0]['payments']['authorizations'][0];

		$authorization['custom_id']          = $intentUuid;
		$authorization['supplementary_data'] = array( 'related_ids' => array( 'order_id' => Fixtures::ORDER_ID ) );

		return $authorization;
	}

	/**
	 * Returns the resource of an approval event: the fixtures' approved order, for an intent.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid Optional. The intent its purchase unit's `custom_id` names. Default INTENT_UUID.
	 * @return array<mixed> The order.
	 */
	public static function approvedOrder( string $intentUuid = self::INTENT_UUID ): array {
		$order = Fixtures::object( 'order-approved' );

		$order['purchase_units'][0]['custom_id'] = $intentUuid;

		return $order;
	}

	/**
	 * Returns a version 4 uuid, as PayPal's transmission ids are.
	 *
	 * @since 0.2.0
	 *
	 * @return string The uuid.
	 */
	public static function uuid(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ord( $bytes[6] ) & 0x0f | 0x40 );
		$bytes[8] = chr( ord( $bytes[8] ) & 0x3f | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}
