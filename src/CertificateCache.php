<?php
/**
 * CertificateCache: the certificates PayPal signs webhook deliveries with, fetched from PayPal and kept for a day
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
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\TransportFailure;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a failed call to SEOCart's payment service; they are never HTML.

/**
 * Returns the PEM certificate at an address PayPal names in a delivery, from a transient or fetched from PayPal's certificate host of the delivery's mode.
 *
 * Owns one fact: where the certificates come from and how long they are kept. The address is
 * checked against the mode's certificate host before this is asked (WebhookSignature); the fetch
 * goes through the HTTP client SEOCart gives the gateway, to that declared host alone. A
 * certificate is public, so it is kept in a transient named by a digest of its address, for a day:
 * the gateway's one stored value, which uninstall.php removes. A flood of deliveries costs one
 * cached read each, never a request. A fetch that fails, or answers anything but a certificate,
 * throws GatewayUnavailable: SEOCart answers the delivery 503, and PayPal sends it again later.
 *
 * @since 0.2.0
 */
final class CertificateCache {

	/**
	 * The prefix of every transient this cache keeps.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PREFIX = 'seocart_paypal_cert_';

	/**
	 * How long a certificate is kept, in seconds: a day.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const SECONDS = 86400;

	/**
	 * The largest certificate read, in bytes: one certificate, or a short chain, is a few kilobytes.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const MAX_BYTES = 16384;

	/**
	 * What SEOCart gives the gateway: its HTTP client and logger.
	 *
	 * @since 0.2.0
	 *
	 * @var ExtensionContext
	 */
	private ExtensionContext $context;

	/**
	 * Builds the cache. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param ExtensionContext $context What SEOCart gives the gateway.
	 */
	public function __construct( ExtensionContext $context ) {
		$this->context = $context;
	}

	/**
	 * Returns the certificate at an address, kept or fetched.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When it is not kept and could not be fetched.
	 *
	 * @param Mode   $mode The delivery's mode, whose certificate host the address is on.
	 * @param string $url  The certificate's address, already checked (WebhookSignature::certUrl()).
	 * @return string The certificate, PEM.
	 */
	public function certificate( Mode $mode, string $url ): string {
		$key  = self::key( $url );
		$kept = get_transient( $key );

		if ( is_string( $kept ) && self::isCertificate( $kept ) ) {
			return $kept;
		}

		$pem = $this->fetch( $mode, $url );

		set_transient( $key, $pem, self::SECONDS );

		return $pem;
	}

	/**
	 * Returns the name of the transient an address's certificate is kept in.
	 *
	 * @since 0.2.0
	 *
	 * @param string $url The certificate's address.
	 * @return string The transient's name: PREFIX and 32 hexadecimal digits of the address's SHA-256.
	 */
	public static function key( string $url ): string {
		return self::PREFIX . substr( hash( 'sha256', $url ), 0, 32 );
	}

	/**
	 * Tells whether a text is one PEM certificate (or a short chain beginning with one) that OpenSSL reads.
	 *
	 * @since 0.2.0
	 *
	 * @param string $pem The text.
	 * @return bool True when it is.
	 */
	public static function isCertificate( string $pem ): bool {
		return strlen( $pem ) <= self::MAX_BYTES
			&& str_starts_with( ltrim( $pem ), '-----BEGIN CERTIFICATE-----' )
			&& false !== openssl_x509_read( $pem );
	}

	/**
	 * Fetches a certificate from the mode's certificate host.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the fetch got no answer, or an answer that is not a certificate.
	 *
	 * @param Mode   $mode The mode.
	 * @param string $url  The address.
	 * @return string The certificate, PEM.
	 */
	private function fetch( Mode $mode, string $url ): string {
		$line = array( 'mode' => $mode->value );

		try {
			$response = $this->context->http()->send( new OutboundRequest( Gateway::CERT_HOSTS[ $mode->value ], 'GET', $url ) );
		} catch ( TransportFailure $failure ) {
			$this->context->logger()->warning( 'paypal.certificate_unavailable', 'The certificate a webhook delivery was signed with could not be fetched from PayPal.', $line + array( 'failure' => $failure->kind ) );

			throw new GatewayUnavailable( 'The certificate of a PayPal webhook delivery could not be fetched.', 0, $failure );
		}

		if ( 200 !== $response->status || ! self::isCertificate( $response->body ) ) {
			$this->context->logger()->warning( 'paypal.certificate_unavailable', 'PayPal answered a certificate\'s address with no certificate.', $line + array( 'http_status' => $response->status ) );

			throw new GatewayUnavailable( sprintf( 'PayPal answered a webhook certificate\'s address with HTTP %d and no certificate.', $response->status ) );
		}

		return $response->body;
	}
}
