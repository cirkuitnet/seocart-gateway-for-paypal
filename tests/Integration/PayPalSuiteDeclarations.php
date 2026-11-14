<?php
/**
 * What the payment conformance suite is told of PayPal, shared by the suite's test and the live tests
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Integration;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\GatewayForPayPal\CertificateCache;
use SEOCart\GatewayForPayPal\Gateway;
use SEOCart\GatewayForPayPal\PayPalApi;
use SEOCart\GatewayForPayPal\PayPalCodes;
use SEOCart\Tests\Conformance\Payment\NotProducible;
use SEOCart\Tests\Conformance\Payment\Scenario;
use SEOCart\Tests\Conformance\Payment\Tape;
use SEOCart\Tests\Conformance\Payment\TapeScrubber;

/**
 * Tells SEOCart's conformance harness what it needs to know of PayPal: the payment token of each scenario, how a delivery is signed, and what of PayPal's answers is public.
 *
 * The suite's test (ConformanceTest), which replays tests/tapes, and the live tests, which send
 * every request to PayPal's sandbox, both use it, so the two read PayPal's answers by the same
 * declarations.
 *
 * A card is typed only in PayPal's own fields in a browser, so the suite pays with a card PayPal
 * vaulted once on the sandbox account (VAULTED_CARD): its order is processed at once, and the
 * sandbox's negative testing declines it on request. An order awaiting PayPal's card fields is the
 * scenario in which the shopper must act.
 *
 * PayPal signs a webhook delivery with its own key, which no test holds, so the suite reads the
 * two deliveries kept in tests/fixtures/paypal/webhooks, each with the webhook id it was signed for,
 * and the certificate it verifies under, which configure() keeps where the gateway looks for it:
 * the replay fetches nothing. signDelivery() leaves a delivery's signature as it was made, and
 * makes one that cannot verify for the suite's bad-signature cases.
 *
 * The declarations tell the suite's scrubber what of PayPal's answers is public, so a tape holds no
 * secret: the access token of the token call becomes a placeholder, and so do the token call's
 * nonce and scope, and a network's transaction reference, digits the suite could take for a card
 * number; every other run of twenty letters, digits, `_` or `-` must be one of PayPal's words or
 * ids named here.
 *
 * @since 0.2.0
 */
trait PayPalSuiteDeclarations {

	/**
	 * The card PayPal vaulted once on the sandbox account the tapes were recorded with, by the id PayPal gave it.
	 *
	 * It is public as a test card is: it charges nothing without the account's own secret, and
	 * only in the sandbox.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VAULTED_CARD = '5c410600ew807883n';

	/**
	 * The error the sandbox is asked to answer a declined payment with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DECLINE_MOCK = 'INSTRUMENT_DECLINED';

	/**
	 * The delivery fixtures the suite reads, by delivery name: one the gateway reads as a result, one it ignores.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private const DELIVERIES = array(
		'authorized' => 'authorization-created',
		'ignored'    => 'order-approved',
	);

	/**
	 * Returns the payment token of each scenario, or why PayPal's sandbox cannot produce it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string|NotProducible> By scenario.
	 */
	protected function tokens(): array {
		return array(
			Scenario::Approve->value                 => 'vault:' . self::VAULTED_CARD,
			Scenario::Decline->value                 => 'vault:' . self::VAULTED_CARD . ';mock=' . self::DECLINE_MOCK,
			Scenario::RequiresAction->value          => 'card',
			Scenario::RequiresActionCompleted->value => new NotProducible( 'Approving an order needs the shopper\'s browser, in PayPal\'s card fields or on PayPal\'s page.' ),
			Scenario::Expires->value                 => new NotProducible( 'PayPal offers no call that cancels or expires an order awaiting the shopper, and an authorization\'s 29 days cannot be waited for.' ),
			Scenario::Pending->value                 => new NotProducible( 'A card authorization is decided at once: a pending one comes from a risk review the sandbox does not stage.' ),
		);
	}

	/**
	 * Returns the request with which the gateway exchanges its client id and secret for an access token: PayPal's OAuth 2.0 client-credentials call.
	 *
	 * PayPal documents `POST /v1/oauth2/token` (https://developer.paypal.com/api/rest/authentication/)
	 * as the exchange of the REST app's client id and secret, sent as HTTP Basic credentials, for
	 * an access token, with the body `grant_type=client_credentials` and no query. It moves no money,
	 * and the gateway's request names no payment: no PayPal-Request-Id, no uuid, no id of PayPal's
	 * and no amount (PayPalApi::requestToken()).
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, array<string, string>> The request.
	 *
	 * @phpstan-return list<array{method: string, path: string}>
	 */
	protected function credentialRequests(): array {
		return array(
			array(
				'method' => 'POST',
				'path'   => '/' . PayPalApi::TOKEN_PATH,
			),
		);
	}

	/**
	 * Tells the suite that PayPal's declined payment leaves nothing to ask about: PayPal refuses to create the order, so the decline names no order.
	 *
	 * The recorded decline shows it: tests/tapes/rule08-query-answers-usd-us/2.json is the create
	 * of the declined payment's order, `POST /v2/checkout/orders` with the sandbox's mocked
	 * `INSTRUMENT_DECLINED`, answered HTTP 422 `UNPROCESSABLE_ENTITY` whose body holds the issue, a
	 * message, PayPal's debug id and a link to the issue's page, and no order: no `id`, no `status`,
	 * no purchase unit. The gateway's decline therefore names no order, and query() of it has
	 * nothing to read.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True.
	 */
	protected function declineLeavesNoPayment(): bool {
		return true;
	}

	/**
	 * Returns a stand-in for the secret setting no recording holds: the client secret of a mode.
	 *
	 * @since 0.2.0
	 *
	 * @param string $field The setting's name.
	 * @param Mode   $mode  The mode.
	 * @return string The stand-in.
	 */
	protected function placeholder( string $field, Mode $mode ): string {
		return 'conformance-' . $mode->value . '-' . str_replace( '_', '-', $field );
	}

	/**
	 * Keeps a delivery's signature, which only PayPal's key (or the key the fixtures were signed with) makes, for the suite's published secret; makes one that cannot verify for any other.
	 *
	 * The suite asks for a signature with its published secret when it records a delivery, and
	 * with another secret for the deliveries it must see rejected. PayPal signs with its own key,
	 * so the signature a delivery was recorded with stands, and a delivery asked to be signed with
	 * any other secret gets a signature of PayPal's shape that no certificate verifies.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers The delivery's headers.
	 * @param string                $body    The raw body.
	 * @param string                $secret  The suite's signing secret.
	 * @param \DateTimeImmutable    $at      When it is signed.
	 * @return array<string, string> The headers.
	 */
	protected function signDelivery( array $headers, string $body, #[\SensitiveParameter] string $secret, \DateTimeImmutable $at ): array {
		unset( $at );

		if ( TapeScrubber::PUBLISHED_WEBHOOK_SECRET === $secret ) {
			return $headers;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PayPal's signature is base64; this one is of the same shape and verifies under no key.
		return array( 'paypal-transmission-sig' => base64_encode( str_repeat( hash( 'sha256', $secret . $body, true ), 8 ) ) ) + $headers;
	}

	/**
	 * Returns the deliveries the suite reads: the two kept in tests/fixtures/paypal/webhooks.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>> Each delivery (mode, signed_at, headers, body) by name.
	 */
	protected function freshDeliveries(): array {
		$deliveries = array();

		foreach ( self::DELIVERIES as $name => $fixture ) {
			$delivery = self::deliveryFixture( $fixture );

			$deliveries[ $name ] = array_intersect_key( $delivery, array_flip( array( 'mode', 'signed_at', 'headers', 'body' ) ) );
		}

		return $deliveries;
	}

	/**
	 * Configures the gateway as the harness does, then keeps the certificate the delivery fixtures were signed under where the gateway looks for it, so no delivery fetches one.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode        $mode             The mode new payments are created in.
	 * @param string|null $accountCountry   The account's country; null for the recorded or example one.
	 * @param bool        $publishedWebhook Whether the webhook secret is the published one.
	 */
	protected function configure( Mode $mode, ?string $accountCountry = null, bool $publishedWebhook = false ): void {
		parent::configure( $mode, $accountCountry, $publishedWebhook );

		$certificate = (string) file_get_contents( self::deliveryDirectory() . '/cert.pem' );

		foreach ( self::DELIVERIES as $fixture ) {
			set_transient( CertificateCache::key( (string) self::deliveryFixture( $fixture )['headers']['paypal-cert-url'] ), $certificate, CertificateCache::SECONDS );
		}
	}

	/**
	 * Returns the settings a recording uses, with the webhook id the delivery fixtures were signed for: the signed text holds it, so the suite's deliveries verify only under it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, int|string> The settings by name.
	 */
	protected function recordingSettings(): array {
		return array( Gateway::WEBHOOK_ID => (string) self::deliveryFixture( self::DELIVERIES['authorized'] )['webhook_id'] ) + parent::recordingSettings();
	}

	/**
	 * Returns what PayPal issues in its answers that a tape keeps only as its placeholder.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each one's regular expression, by its placeholder's name.
	 */
	protected function providerSecrets(): array {
		return array(
			// The token call's access token: a bearer credential until it expires.
			'access_token'      => '/"access_token"\s*:\s*"\K[^"]+(?=")/',
			// The token call's nonce and scope: not secrets, and the gateway reads neither; the nonce is a run of letters and digits, and the scope a list of addresses.
			'token_nonce'       => '/"nonce"\s*:\s*"\K[^"]+(?=")/',
			'token_scope'       => '/"scope"\s*:\s*"\K[^"]+(?=")/',
			// Not a secret: the card network's reference for an authorization, digits the suite could take for a card number; the gateway reads none of it.
			'network_reference' => '/"network_transaction_reference"\s*:\s*\{[^{}]*?"id"\s*:\s*"\K[^"]+(?=")/',
		);
	}

	/**
	 * Returns the shapes of PayPal's ids a tape holds, each the letters and digits after its prefix.
	 *
	 * Orders, authorizations, captures and refunds are named by 17 capitals and digits, with no
	 * prefix (PayPalCodes::id()): below the scrubber's twenty, so no token rule needs their shape,
	 * but they often hold a run of 13 to 16 digits that passes a card number's checksum, and the
	 * shape lets such an id, as a whole value or a whole segment of a URL's path, be read as the id
	 * it is.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, int|array{int, int}> The shapes, by prefix.
	 */
	protected function publicIdShapes(): array {
		return array(
			// An order's, an authorization's, a capture's or a refund's id.
			''     => 17,
			// The REST app's id, in the token call's answer.
			'APP-' => 17,
		);
	}

	/**
	 * Returns the field whose whole value is PayPal's signature over a public delivery.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The fields' names.
	 */
	protected function publicSignatureFields(): array {
		return array( 'paypal-transmission-sig' );
	}

	/**
	 * Returns PayPal's words a tape may hold and a log line may carry: every documented word the adapter knows, the page of each issue in PayPal's reference, the ids of the delivery fixtures, and the run's client id.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The words.
	 */
	protected function publicCodes(): array {
		return array_values(
			array_unique(
				array_merge(
					PayPalCodes::words(),
					// The last part of an error's information link, such as https://developer.paypal.com/docs/api/orders/v2/#error-INSTRUMENT_DECLINED.
					array_map( static fn( string $issue ): string => 'error-' . $issue, PayPalCodes::ISSUES ),
					// The name of the field of the sandbox's mock header (`PayPal-Mock-Response`), which the decline's request carries.
					array( 'mock_application_codes' ),
					self::deliveryWords(),
					$this->clientIdOfRun()
				)
			)
		);
	}

	/**
	 * Returns PayPal's response headers a tape keeps beside `content-type`: none, as the gateway reads none of them.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The headers' names.
	 */
	protected function publicHeaders(): array {
		return array();
	}

	/**
	 * Returns the client id of the run, which a tape's settings keep: public by design, as PayPal's script in the shopper's browser is loaded with it.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The client id; none before the transport exists, or when no run names one.
	 */
	private function clientIdOfRun(): array {
		if ( ! isset( $this->transport ) ) {
			return array();
		}

		$settings = $this->transport->records() ? $this->recordingSettings() : ( new Tape( (string) $this->tapes() ) )->settings();
		$clientId = $settings[ Gateway::CLIENT_ID ] ?? null;

		return is_string( $clientId ) && '' !== $clientId ? array( $clientId ) : array();
	}

	/**
	 * Returns the ids the delivery fixtures name that are no shape a prefix declares: each event's id (`WH-`, two runs joined by a dash) and the name of the certificate it was signed under.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The ids.
	 */
	private static function deliveryWords(): array {
		$words = array();

		foreach ( self::DELIVERIES as $fixture ) {
			$delivery = self::deliveryFixture( $fixture );
			$event    = json_decode( (string) $delivery['body'], true );
			$words[]  = is_array( $event ) ? (string) ( $event['id'] ?? '' ) : '';
			$words[]  = basename( (string) ( $delivery['headers']['paypal-cert-url'] ?? '' ) );
		}

		return array_values( array_filter( $words ) );
	}

	/**
	 * Returns a delivery fixture: the webhook id it was signed for, its mode, when it was signed, its headers and its body.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the fixture is missing or unreadable.
	 *
	 * @param string $name The fixture's name.
	 * @return array<string, mixed> The fixture.
	 */
	private static function deliveryFixture( string $name ): array {
		$fixture = json_decode( (string) file_get_contents( self::deliveryDirectory() . '/' . $name . '.json' ), true );

		if ( ! is_array( $fixture ) || ! is_array( $fixture['headers'] ?? null ) || ! is_string( $fixture['body'] ?? null ) ) {
			throw new \LogicException( "The delivery fixture {$name} is missing or unreadable." );
		}

		return $fixture;
	}

	/**
	 * Returns the directory of the delivery fixtures.
	 *
	 * @since 0.2.0
	 *
	 * @return string The directory.
	 */
	private static function deliveryDirectory(): string {
		return dirname( __DIR__ ) . '/fixtures/paypal/webhooks';
	}
}
