<?php
/**
 * LivePayPalCase: the plugin, built as the conformance suite builds it, with every request of the gateway sent to PayPal's sandbox
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Integration\Live;

use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\GatewayForPayPal\Gateway;
use SEOCart\GatewayForPayPal\Tests\Integration\PayPalSuiteDeclarations;
use SEOCart\GatewayForPayPal\Tests\Support\Live\CleanupSelection;
use SEOCart\GatewayForPayPal\Tests\Support\Live\PayPalRun;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Conformance\Payment\ConformanceHarness;
use SEOCart\Tests\Conformance\Payment\Exchange;
use SEOCart\Tests\Conformance\Payment\Live\LiveRun;
use SEOCart\Tests\Conformance\Payment\RecordingTransport;

// SEOCart's integration bootstrap autoloads this plugin's src/ alone: the suite's declarations, the live tests' support and their own client are loaded by their path.
require_once dirname( __DIR__ ) . '/PayPalSuiteDeclarations.php';
require_once __DIR__ . '/PayPalTestApi.php';

foreach ( array( 'PayPalRun', 'CleanupSelection' ) as $seocart_gateway_for_paypal_class ) {
	require_once dirname( __DIR__, 2 ) . '/Support/Live/' . $seocart_gateway_for_paypal_class . '.php';
}

/**
 * The live tests' base: SEOCart's conformance harness recording against PayPal's sandbox, a client of the tests' own for what they check at PayPal, and the browser that approves an order in PayPal's card fields.
 *
 * Every request the gateway sends goes to the sandbox through SEOCart's own client and the
 * recording transport, which writes its tapes to a directory of the run's own, never kept. Every
 * order a placement creates goes on the run list, so the job's cleanup voids what it left
 * authorized. A live test never skips: without the recording's settings it fails, saying how it
 * runs.
 *
 * @since 0.2.0
 */
abstract class LivePayPalCase extends ConformanceHarness {

	use PayPalSuiteDeclarations;

	/**
	 * PayPal's published sandbox Visa whose 3-D Secure challenge succeeds (a step-up).
	 *
	 * @since 0.2.0
	 *
	 * @var array{number: string, expiry: string, cvv: string, name: string}
	 */
	protected const STEP_UP_SUCCEEDS = array(
		'number' => '4868719166101368',
		'expiry' => '12/30',
		'cvv'    => '123',
		'name'   => 'Step Up Succeeds',
	);

	/**
	 * PayPal's published sandbox Visa whose 3-D Secure challenge fails: the liability stays with the store.
	 *
	 * @since 0.2.0
	 *
	 * @var array{number: string, expiry: string, cvv: string, name: string}
	 */
	protected const STEP_UP_FAILS = array(
		'number' => '4868719181895556',
		'expiry' => '12/30',
		'cvv'    => '123',
		'name'   => 'Step Up Fails',
	);

	/**
	 * The run's tape directory.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private static ?string $tapes = null;

	/**
	 * The test's own requests to PayPal.
	 *
	 * @since 0.2.0
	 *
	 * @var PayPalTestApi
	 */
	protected PayPalTestApi $paypal;

	/**
	 * Returns the id the plugin registers the gateway under.
	 *
	 * @since 0.2.0
	 *
	 * @return string The gateway's id.
	 */
	protected function gatewayId(): string {
		return Gateway::ID;
	}

	/**
	 * Returns this run's tape directory: a fresh one under the system's temporary directory, readable by its owner alone, never kept.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When the directory cannot be made.
	 *
	 * @return string The directory.
	 */
	protected function tapes(): string {
		if ( null === self::$tapes ) {
			$directory = sys_get_temp_dir() . '/paypal-live-' . bin2hex( random_bytes( 8 ) );

			if ( ! mkdir( $directory, 0700 ) ) {
				throw new \RuntimeException( 'The live tests could not make their tape directory.' );
			}

			self::$tapes = $directory;
		}

		return self::$tapes;
	}

	/**
	 * Refuses to run without the recording's settings, then builds the plugin with every request of the gateway sent to PayPal's sandbox.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		if ( RecordingTransport::RECORD !== getenv( RecordingTransport::ENVIRONMENT ) ) {
			self::fail( sprintf( 'The live tests send every request to PayPal\'s sandbox: run them with %1$s=%2$s and the sandbox\'s settings in a file %3$s names, as bin/ci/paypal-sandbox.sh does.', RecordingTransport::ENVIRONMENT, RecordingTransport::RECORD, ConformanceHarness::SETTINGS_FILE ) );
		}

		$this->paypal = PayPalTestApi::fromSettingsFile( (string) getenv( ConformanceHarness::SETTINGS_FILE ) );

		LiveRun::file( LiveRun::RUN_LIST );
		LiveRun::file( LiveRun::REPORT );

		parent::set_up();
	}

	/**
	 * Places an order of one unit, paid through PayPal with a payment token, as a shopper places it, and puts the order PayPal made on the run list.
	 *
	 * @since 0.2.0
	 *
	 * @param string $token The payment token: `card`, `paypal` or `vault:<id>`.
	 * @return array{order_uuid: string, order_id: int, intent_uuid: string, paypal_order: string|null, outcome: string, next_action: array<string, mixed>|null} The placement.
	 *
	 * @throws CodedException When the placement is refused for another reason than a decline.
	 */
	protected function placeLive( string $token ): array {
		$variant = $this->sellable( 10, self::PRICE );
		$cart    = $this->startCart( array( $variant => 1 ) );

		$this->writeCheckout( $cart->version, Gateway::ID );

		$sentBefore = count( $this->transport->exchanges() );

		try {
			$answer = $this->freshPlacement()->place( $this->placeInput( 'live-' . $variant, $token ), self::guest() );
		} catch ( CodedException $declined ) {
			if ( CheckoutError::PaymentDeclined !== $declined->errorCode() ) {
				throw $declined;
			}

			$answer = array(
				'order_uuid'  => (string) ( $declined->details()['order_uuid'] ?? '' ),
				'outcome'     => 'declined',
				'next_action' => null,
			);
		} finally {
			// Whatever became of the placement, PayPal may hold its order.
			$created = CleanupSelection::createdBy( array_map( static fn( Exchange $exchange ): array => $exchange->toTape(), array_slice( $this->transport->exchanges(), $sentBefore ) ) );

			foreach ( $created as $orderId => $intentUuid ) {
				PayPalRun::created( $orderId, $intentUuid );
			}
		}

		$orderId = (int) $this->db->fetchValue( 'SELECT id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), (string) $answer['order_uuid'] );
		$uuid    = (string) $this->db->fetchValue( 'SELECT uuid FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT 1', $this->table( PaymentTables::INTENTS ), $orderId );
		$paypal  = array_search( $uuid, $created, true );

		return array(
			'order_uuid'   => (string) $answer['order_uuid'],
			'order_id'     => $orderId,
			'intent_uuid'  => $uuid,
			'paypal_order' => is_string( $paypal ) ? $paypal : null,
			'outcome'      => (string) $answer['outcome'],
			'next_action'  => is_array( $answer['next_action'] ?? null ) ? $answer['next_action'] : null,
		);
	}

	/**
	 * Approves a placed order in PayPal's card fields with a sandbox test card, in a browser.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>  $placed  The placement, whose next action names the order.
	 * @param array<string, string> $card    The card.
	 * @param string                $control `complete` or `fail`, for a 3-D Secure challenge.
	 * @param int                   $attempt 1 for the payment's first try, 2 for the one more PayPalRun::mayTryAgain() allows.
	 * @return string What the browser script answered: one of PayPalRun::CARD_WORDS, or an error's name.
	 */
	protected function approveInBrowser( array $placed, array $card, string $control, int $attempt ): string {
		$action = (array) ( $placed['next_action'] ?? array() );

		$this->assertSame( 'requires_action', $placed['outcome'], 'The card placement did not wait for the shopper.' );
		$this->assertSame( 'sdk', $action['type'] ?? null, 'The card placement asked for no step of PayPal\'s script.' );
		$this->assertSame( $placed['paypal_order'], $action['client_token'] ?? null, 'The step\'s handle is not the order PayPal made.' );

		return PayPalRun::approveCard( $this->paypal->clientId(), (string) $placed['paypal_order'], self::CURRENCY, $card, $control, $attempt );
	}

	/**
	 * Places a card payment and approves it in PayPal's card fields, in a browser; when the fields refused the card before any challenge, on the first try, places a fresh payment and tries once more.
	 *
	 * The run's report names each answer `card-fields-<label>`: a first try the fields refused before
	 * any challenge as `refused <ISSUE>, tried again`, and the second try's answer as
	 * `card-fields-<label>-again`. Never a third try, and never one after a challenge was shown:
	 * the second answer is the test's, so two refusals fail it. Every order placed is on the run
	 * list for the cleanup.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $card    The card.
	 * @param string                $label   What the run's report names the card fields' answer by.
	 * @param string                $control Optional. `complete` or `fail`, for a 3-D Secure challenge. Default `complete`.
	 * @return array{0: array<string, mixed>, 1: string} The placement the answer is about, and the answer: one of PayPalRun::CARD_WORDS, or an error's name.
	 */
	protected function placeAndApprove( array $card, string $label, string $control = 'complete' ): array {
		$placed = $this->placeLive( 'card' );
		$word   = $this->approveInBrowser( $placed, $card, $control, 1 );

		if ( ! PayPalRun::mayTryAgain() ) {
			LiveRun::report( PayPalRun::declaration(), 'card-fields-' . $label, PayPalRun::lastCard() );

			return array( $placed, $word );
		}

		LiveRun::report( PayPalRun::declaration(), 'card-fields-' . $label, PayPalRun::lastCard() . ', tried again' );

		$placed = $this->placeLive( 'card' );
		$word   = $this->approveInBrowser( $placed, $card, $control, 2 );

		LiveRun::report( PayPalRun::declaration(), 'card-fields-' . $label . '-again', PayPalRun::lastCard() );

		return array( $placed, $word );
	}

	/**
	 * Returns an order as PayPal holds it now.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderId The order's id.
	 * @return array<mixed> The order.
	 */
	protected function paypalOrder( string $orderId ): array {
		return $this->paypal->get( 'v2/checkout/orders/' . $orderId );
	}

	/**
	 * Reads an order's ledger as `operation:result:applied`, row by row.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The store's order.
	 * @return list<string> The rows.
	 */
	protected function ledgerOf( int $orderId ): array {
		return array_map( static fn( array $row ): string => $row[0] . ':' . $row[1] . ':' . $row[2], $this->ledgerShape( $orderId ) );
	}

	/**
	 * Returns the id of the webhook the repository's sandbox app delivers to, from the settings file the workflow wrote.
	 *
	 * @since 0.2.0
	 *
	 * @return string The id; empty when the repository holds none.
	 */
	protected function ownersWebhookId(): string {
		return (string) ( parent::recordingSettings()[ Gateway::WEBHOOK_ID ] ?? '' );
	}

	/**
	 * Says a value of PayPal's in a failure message, withheld when it is shaped like a secret or a card number.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value.
	 * @return string The value as a message may show it.
	 */
	protected static function shown( string $value ): string {
		return PayPalRun::guard()->shown( $value );
	}
}
