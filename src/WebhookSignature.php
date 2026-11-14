<?php
/**
 * WebhookSignature: checks the signature PayPal puts on a webhook delivery, locally, with PayPal's own certificate
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\WebhookReading;

/**
 * Verifies that a delivery was signed by PayPal for this site's webhook, and signed recently, before anything of its body is read.
 *
 * Owns one fact: PayPal's signature scheme. PayPal signs, with RSA and SHA-256, the text
 * `{transmission id}|{transmission time}|{webhook id}|{CRC-32 of the raw body, in decimal}` and
 * names the certificate whose key checks it. In order: the five headers must be present and of
 * their shape, the algorithm exactly `SHA256withRSA`, and the certificate's address an https
 * address on the mode's certificate host of PayPal's (a delivery cannot name a key of its own);
 * the transmission time must be within TOLERANCE_SECONDS of the site's clock, either way; the
 * certificate, kept or fetched (CertificateCache), must name a host of paypal.com and be valid at
 * that time; and the signature must verify over the text built with the mode's webhook id, which
 * only the merchant's own webhook carries. The CRC is of the body's bytes as they arrived, never of
 * a body decoded and encoded again. Nothing here decodes the body, and no request is made but the
 * certificate's fetch, once a day.
 *
 * Checking locally, rather than asking PayPal's verification call, is the method PayPal prefers,
 * and keeps an unauthenticated flood of deliveries from costing a request to PayPal each.
 *
 * @since 0.2.0
 */
final class WebhookSignature {

	/**
	 * How far the transmission time may be from the site's clock, either way, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const TOLERANCE_SECONDS = 300;

	/**
	 * The headers a delivery is checked by, by their lower-case names.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const HEADERS = array(
		'id'        => 'paypal-transmission-id',
		'time'      => 'paypal-transmission-time',
		'signature' => 'paypal-transmission-sig',
		'algorithm' => 'paypal-auth-algo',
		'cert'      => 'paypal-cert-url',
	);

	/**
	 * The path, under the certificate host, of the certificates PayPal signs with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CERT_PATH = '/v1/notifications/certs/';

	/**
	 * The shape of a certificate's name in its address, such as `CERT-360caa42-fca2a594-1d93a270`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CERT_NAME = '/^CERT-[A-Za-z0-9]{1,32}(?:-[A-Za-z0-9]{1,32}){0,7}\z/';

	/**
	 * The shape of a transmission id: a uuid.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TRANSMISSION_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

	/**
	 * The shape of a transmission time: an RFC 3339 date and time.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TRANSMISSION_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})\z/';

	/**
	 * The shape of a signature: base64 of 20 to 1024 characters.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SIGNATURE = '/^(?=.{20,1024}\z)(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?\z/';

	/**
	 * Checks a delivery's headers, its time, its certificate and its signature, in that order.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the certificate is not kept and could not be fetched: the delivery is neither accepted nor refused, and PayPal sends it again.
	 *
	 * @param array<string, string> $headers   The delivery's headers, by lower-case name.
	 * @param string                $rawBody   The body, byte for byte as it arrived.
	 * @param string                $webhookId The id of the mode's webhook; empty when none is set, which verifies nothing.
	 * @param Mode                  $mode      The mode the delivery was addressed to.
	 * @param \DateTimeImmutable    $now       The time now, from the context's clock.
	 * @param CertificateCache      $certs     Where the certificate comes from.
	 * @return string|null Null when the delivery is PayPal's and recent; otherwise why it is rejected:
	 *                     WebhookReading::BAD_SIGNATURE or WebhookReading::STALE.
	 */
	public static function check( array $headers, string $rawBody, string $webhookId, Mode $mode, \DateTimeImmutable $now, CertificateCache $certs ): ?string {
		$id        = $headers[ self::HEADERS['id'] ] ?? '';
		$time      = $headers[ self::HEADERS['time'] ] ?? '';
		$signature = $headers[ self::HEADERS['signature'] ] ?? '';
		$certUrl   = self::certUrl( $headers[ self::HEADERS['cert'] ] ?? '', $mode );
		$sentAt    = 1 === preg_match( self::TRANSMISSION_TIME, $time ) ? self::time( $time ) : null;

		if ( '' === $webhookId
			|| 1 !== preg_match( self::TRANSMISSION_ID, $id )
			|| null === $sentAt
			|| 1 !== preg_match( self::SIGNATURE, $signature )
			|| PayPalCodes::AUTH_ALGO !== ( $headers[ self::HEADERS['algorithm'] ] ?? '' )
			|| null === $certUrl
		) {
			return WebhookReading::BAD_SIGNATURE;
		}

		if ( abs( $now->getTimestamp() - $sentAt->getTimestamp() ) > self::TOLERANCE_SECONDS ) {
			return WebhookReading::STALE;
		}

		$key = self::publicKey( $certs->certificate( $mode, $certUrl ), $now );

		if ( null === $key ) {
			return WebhookReading::BAD_SIGNATURE;
		}

		$signed = $id . '|' . $time . '|' . $webhookId . '|' . sprintf( '%u', crc32( $rawBody ) );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- PayPal sends its RSA signature base64-encoded.
		return 1 === openssl_verify( $signed, (string) base64_decode( $signature, true ), $key, OPENSSL_ALGO_SHA256 ) ? null : WebhookReading::BAD_SIGNATURE;
	}

	/**
	 * Returns a certificate's address when it is one of PayPal's for the mode: https, the mode's certificate host, the certificates' path and a certificate's name, nothing else.
	 *
	 * @since 0.2.0
	 *
	 * @param string $url  The `paypal-cert-url` header.
	 * @param Mode   $mode The delivery's mode.
	 * @return string|null The address; null for any other.
	 */
	public static function certUrl( string $url, Mode $mode ): ?string {
		$base = Gateway::CERT_BASES[ $mode->value ];

		if ( ! str_starts_with( $url, $base ) || strlen( $url ) > 512 ) {
			return null;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$path = (string) ( $parts['path'] ?? '' );

		return str_starts_with( $path, self::CERT_PATH ) && 1 === preg_match( self::CERT_NAME, substr( $path, strlen( self::CERT_PATH ) ) ) ? $url : null;
	}

	/**
	 * Returns the public key of a certificate that names a host of paypal.com and is valid at a time.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $pem The certificate.
	 * @param \DateTimeImmutable $now The time.
	 * @return \OpenSSLAsymmetricKey|null The key; null when the certificate is another's, not valid then, or unreadable.
	 */
	private static function publicKey( string $pem, \DateTimeImmutable $now ): ?\OpenSSLAsymmetricKey {
		$certificate = openssl_x509_read( $pem );
		$parsed      = false === $certificate ? false : openssl_x509_parse( $certificate );

		if ( false === $certificate || ! is_array( $parsed ) ) {
			return null;
		}

		$name  = $parsed['subject']['CN'] ?? '';
		$from  = $parsed['validFrom_time_t'] ?? null;
		$until = $parsed['validTo_time_t'] ?? null;
		$at    = $now->getTimestamp();

		if ( ! is_string( $name ) || ! ( 'paypal.com' === $name || str_ends_with( $name, '.paypal.com' ) ) || ! is_int( $from ) || ! is_int( $until ) || $at < $from || $at > $until ) {
			return null;
		}

		$key = openssl_pkey_get_public( $certificate );

		return false === $key ? null : $key;
	}

	/**
	 * Reads a transmission time.
	 *
	 * @since 0.2.0
	 *
	 * @param string $time The time, RFC 3339.
	 * @return \DateTimeImmutable|null The time; null when it is no real date.
	 */
	private static function time( string $time ): ?\DateTimeImmutable {
		try {
			return new \DateTimeImmutable( $time );
		} catch ( \Exception ) {
			return null;
		}
	}
}
