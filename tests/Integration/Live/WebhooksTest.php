<?php
/**
 * Tests the webhook part against PayPal's sandbox: the delivery fixtures verified with a certificate PayPal serves, the owner's webhook, and the run's events
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Integration\Live;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\GatewayForPayPal\CertificateCache;
use SEOCart\GatewayForPayPal\Gateway;
use SEOCart\GatewayForPayPal\Tests\Support\Live\LiveRun;
use SEOCart\GatewayForPayPal\WebhookReader;

require_once __DIR__ . '/LivePayPalCase.php';

/**
 * Against PayPal's sandbox, read only at PayPal: the two delivery fixtures, signed by PayPal for
 * the owner's sandbox webhook, verify through the gateway with the certificate fetched from
 * PayPal's certificate host, not one kept; the owner's webhook, which the merchant creates, is
 * listed by PayPal with every event the gateway reads; and the events of a payment this run makes
 * are listed by PayPal for that webhook, each of a type and an object the store's ledger knows.
 * No webhook is created, changed or removed. The part runs only once the repository holds the
 * webhook's id (the workflow's webhook-id step says so otherwise).
 *
 * @group paypal-live
 *
 * @since 0.2.0
 */
final class WebhooksTest extends LivePayPalCase {

	/**
	 * How long PayPal may take to list a payment's event, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const EVENTS_WAIT = 180;

	/**
	 * Tests the fixtures: each verifies with the certificate fetched from PayPal, and reads as the suite reads it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_delivery_fixtures_verify_with_a_certificate_fetched_from_paypal(): void {
		$this->begin( 'live-fixtures' );

		$expected = array(
			'authorization-created' => WebhookReadingKind::Result,
			'order-approved'        => WebhookReadingKind::Ignored,
		);

		foreach ( $expected as $name => $kind ) {
			$fixture = (array) json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/paypal/webhooks/' . $name . '.json' ), true );
			$headers = (array) ( $fixture['headers'] ?? array() );

			$this->assertStringNotContainsString( 'made for the test', (string) ( $fixture['signed_by'] ?? '' ), 'The delivery fixtures were signed by a key pair made for the test: replace them with two deliveries PayPal signed (tests/fixtures/paypal/webhooks/README.md).' );

			// The certificate the suite keeps is forgotten, so the gateway fetches it from PayPal; the test mode's webhook id is the fixtures' (PayPalSuiteDeclarations::recordingSettings()).
			delete_transient( CertificateCache::key( (string) ( $headers['paypal-cert-url'] ?? '' ) ) );

			$at = ( new \DateTimeImmutable( (string) ( $fixture['signed_at'] ?? '' ) ) )->modify( '+1 second' );
			$this->gatewayClock->setTo( $at );

			$before  = count( $this->transport->exchanges() );
			$reading = $this->gateway()->readWebhook( new WebhookEnvelope( Gateway::ID, Mode::Test, $headers, (string) ( $fixture['body'] ?? '' ), $at ) );

			$this->assertSame( $kind, $reading->kind, sprintf( 'The fixture %1$s read as %2$s.', $name, $reading->kind->value ) );
			$this->assertGreaterThan( $before, count( $this->transport->exchanges() ), 'The certificate was fetched from PayPal, not kept.' );
		}

		$this->end();
	}

	/**
	 * Tests that PayPal lists the owner's webhook, subscribed to every event the gateway reads.
	 *
	 * @since 0.2.0
	 */
	public function test_the_owners_webhook_subscribes_to_every_event_the_gateway_reads(): void {
		$webhookId = $this->ownersWebhookId();
		$found     = null;

		foreach ( (array) ( $this->paypal->get( 'v1/notifications/webhooks' )['webhooks'] ?? array() ) as $webhook ) {
			if ( ( $webhook['id'] ?? null ) === $webhookId ) {
				$found = (array) $webhook;
			}
		}

		$this->assertNotNull( $found, 'PayPal lists no webhook of the sandbox app under the repository\'s webhook id.' );

		$subscribed = array_map( static fn( array $type ): string => (string) ( $type['name'] ?? '' ), array_filter( (array) $found['event_types'], 'is_array' ) );
		$missing    = array_values( array_diff( array_keys( WebhookReader::EVENTS ), $subscribed ) );

		$this->assertSame( array(), $missing, 'The owner\'s webhook does not subscribe to every event the gateway reads.' );
	}

	/**
	 * Tests that the event of a payment this run makes is listed by PayPal, of the type and the object the ledger holds.
	 *
	 * @since 0.2.0
	 */
	public function test_the_runs_events_are_listed_by_type_and_object_as_the_ledger_holds_them(): void {
		$this->begin( 'live-events' );

		$since  = gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 );
		$placed = $this->placeLive( 'vault:' . self::VAULTED_CARD );
		$ledger = $this->ledgerRowOf( $placed['order_id'], 'authorize' );

		$this->assertSame( 'approved', $placed['outcome'] );

		$listed = array();

		$until = time() + self::EVENTS_WAIT;
		$query = array(
			'start_time' => $since,
			'event_type' => 'PAYMENT.AUTHORIZATION.CREATED',
		);

		while ( array() === $listed && time() < $until ) {
			foreach ( (array) ( $this->paypal->get( 'v1/notifications/webhooks-events', $query )['events'] ?? array() ) as $event ) {
				if ( ( $event['resource']['id'] ?? null ) === $ledger[0] ) {
					$listed[] = (string) ( $event['event_type'] ?? '' );
				}
			}

			if ( array() === $listed ) {
				sleep( 10 );
			}
		}

		LiveRun::report( 'events', count( $listed ) . ' listed for the run\'s authorization' );
		$this->assertSame( array( 'PAYMENT.AUTHORIZATION.CREATED' ), $listed, 'PayPal lists no event of the run\'s authorization, of the type and object the ledger holds.' );

		$this->void( $placed['intent_uuid'] );
		$this->end();
	}
}
