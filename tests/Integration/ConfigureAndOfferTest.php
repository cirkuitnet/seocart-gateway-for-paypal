<?php
/**
 * Tests the gateway as SEOCart configures it, offers it and asks it, through SEOCart's own services
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Integration;

use SEOCart\Checkout\Interfaces\Rest\WebhookRoute;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\GatewayForPayPal\Amounts;
use SEOCart\GatewayForPayPal\Gateway;
use SEOCart\GatewayForPayPal\Tests\Integration\Support\PayPalAtTransport;
use SEOCart\GatewayForPayPal\Tests\Support\Fixtures;
use SEOCart\GatewayForPayPal\Tests\Support\TestDeliveries;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\WebhookAddress;
use SEOCart\Payment\Infrastructure\Cli\GatewayCommand;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\Payment\GatewayCommandRecorder;
use SEOCart\Tests\Support\Payment\GatewayKernel;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

// SEOCart's integration bootstrap autoloads this plugin's src/ alone: the test's PayPal and the unit tests' fixtures are loaded by their path.
require_once __DIR__ . '/Support/PayPalAtTransport.php';
require_once dirname( __DIR__ ) . '/Support/Fixtures.php';
require_once dirname( __DIR__ ) . '/Support/TestDeliveries.php';

/**
 * Runs on SEOCart's integration bootstrap with this extension loaded, as WordPress loads it: SEOCart's own registry
 * registers the gateway through the main file's hook, and SEOCart's own `wp seocart gateway configure` writes the
 * settings from a file only its owner can read, with a fake client id and secret. PayPal is answered at
 * `pre_http_request`, so nothing reaches the network.
 *
 * Before configure SEOCart does not offer the gateway; after it, it offers it in test mode in USD, GBP and EUR for a
 * US account, and configure sent PayPal nothing: the merchant creates the webhook. A country PayPal accounts are not
 * certified for is refused by configure. A capture of an authorized payment goes through SEOCart's payment service to
 * PayPal outside any transaction, with a token of the test mode's and the derived key. A delivery whose certificate
 * cannot be fetched is answered 503 by SEOCart's webhook route, so PayPal sends it again.
 *
 * Planted violation, shown red and removed: readWebhook() answering a certificate it could not fetch as a bad
 * signature: the route answers 400, and PayPal would drop a genuine delivery; the 503 test fails.
 *
 * @since 0.2.0
 */
final class ConfigureAndOfferTest extends PaymentTestCase {

	/**
	 * The fake client secret the settings file gives.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SECRET = 'EIntegrationTestSecretOnly';

	/**
	 * The production wiring, with the test's data keys.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

	/**
	 * PayPal.
	 *
	 * @since 0.2.0
	 *
	 * @var PayPalAtTransport
	 */
	private PayPalAtTransport $paypal;

	/**
	 * The settings files the test wrote.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $files = array();

	/**
	 * Plants the boot record, builds the kernel and answers PayPal.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		GatewayKernel::deleteOptions();

		( new BootOption( $this->db, $this->reporter() ) )->mutate( static fn(): BootRecord => KernelTestCase::installedRecord() );

		$this->kernel = GatewayKernel::over( $this->db, $this->reporter(), $this->publisherOver( $this->db ) );
		$this->paypal = new PayPalAtTransport( fn(): int => $this->db->depth() );
	}

	/**
	 * Deletes the files, the boot record and the gateway's document.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		$wpdb->delete( $wpdb->options, array( 'option_name' => BootOption::NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Removes the record the test planted.
		GatewayKernel::deleteOptions();

		parent::tear_down();
	}

	/**
	 * Tests the offer before and after configure, and that configure sent PayPal nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_the_gateway_is_offered_in_usd_gbp_and_eur_once_configured_and_not_before(): void {
		$gateways = $this->kernel->get( Gateways::class );

		$this->assertSame( array(), $gateways->refused(), 'SEOCart accepted the gateway.' );
		$this->assertContains( Gateway::ID, $gateways->ids() );

		foreach ( array( 'USD', 'GBP', 'EUR' ) as $code ) {
			$this->assertNull( $gateways->availableMode( Gateway::ID, Money::of( 1000, Currency::of( $code ) ), 'US', 'storefront' ), "Not offered in {$code} before configure." );
		}

		$command = new GatewayCommandRecorder( $this->kernel );

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( $command, Mode::Test ), implode( "\n", $command->lines ) );
		$this->assertSame( array(), $this->paypal->requests, 'Configure sends PayPal nothing: the merchant creates the webhook and pastes its id.' );
		$this->assertSame( self::SECRET, $gateways->context( Gateway::ID )->settings( Mode::Test )->secret( Gateway::CLIENT_SECRET ) );
		$this->assertSame( TestDeliveries::WEBHOOK_ID, $gateways->context( Gateway::ID )->settings( Mode::Test )->value( Gateway::WEBHOOK_ID ) );

		foreach ( array( 'USD', 'GBP', 'EUR' ) as $code ) {
			$this->assertSame( Mode::Test, $gateways->availableMode( Gateway::ID, Money::of( 1000, Currency::of( $code ) ), 'US', 'storefront' ), "Offered in {$code} after configure." );
		}

		$this->assertNull( $gateways->availableMode( Gateway::ID, Money::of( Gateway::MAXIMUM_MINOR + 1, Currency::of( 'USD' ) ), 'US', 'storefront' ), 'Not for more than the gateway takes.' );
		$this->assertNull( $gateways->availableMode( Gateway::ID, Money::of( 1000, Currency::of( 'JPY' ) ), 'US', 'storefront' ), 'Not in a currency the matrix does not declare.' );
	}

	/**
	 * Tests an account country the gateway is not certified for: refused by configure, nothing written, nothing sent.
	 *
	 * @since 0.2.0
	 */
	public function test_an_account_country_not_certified_is_refused(): void {
		$command = new GatewayCommandRecorder( $this->kernel );

		$this->assertSame( GatewayCommand::EXIT_FAILED, $this->configure( $command, Mode::Test, array( GatewayDescriptor::ACCOUNT_COUNTRY => 'GB' ) ) );
		$this->assertSame( array(), $this->paypal->requests );
		$this->assertSame( array(), GatewayKernel::storedDocument( Gateway::ID ) );
	}

	/**
	 * Tests an authorization and its capture through SEOCart's payment service: outside any transaction, with a test-mode token and the derived keys.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_reaches_paypal_outside_any_transaction_with_the_derived_key(): void {
		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( new GatewayCommandRecorder( $this->kernel ), Mode::Test ) );

		$payments = $this->kernel->get( PaymentService::class );
		$uuid     = GatewayKernel::openIntent( $this->kernel, Gateway::ID, Mode::Test );
		$row      = $this->intentRow( $uuid );
		$amount   = array(
			'currency_code' => (string) $row['currency'],
			'value'         => Amounts::toValue( (int) $row['amount_minor'], 2 ),
		);
		$order    = Fixtures::object( 'order-authorized' );

		$order['purchase_units'][0]['custom_id']                               = $uuid;
		$order['purchase_units'][0]['amount']                                  = $amount;
		$order['purchase_units'][0]['payments']['authorizations'][0]['amount'] = $amount;

		$capture           = Fixtures::object( 'capture' );
		$capture['amount'] = $amount;

		$this->paypal->queue( 'POST', 'v2/checkout/orders', 201, (string) wp_json_encode( $order ) );
		$this->paypal->queue( 'GET', 'v2/checkout/orders/' . Fixtures::ORDER_ID, 200, (string) wp_json_encode( $order ) );
		$this->paypal->queue( 'POST', 'v2/payments/authorizations/' . Fixtures::AUTHORIZATION_ID . '/capture', 201, (string) wp_json_encode( $capture ) );

		$authorized = $payments->authorize( $uuid, array( PaymentService::PAYMENT_TOKEN => 'vault:' . Fixtures::VAULT_ID ), self::ORDER_UUID, self::ORDER_NUMBER );
		$this->db->transaction( fn() => $payments->applyGatewayResult( $authorized, self::system() ) );
		$payments->capture( $uuid, $this->userWithRole( 'administrator' ) );

		$this->assertSame( array( Operation::Authorize, Outcome::Approved, Fixtures::AUTHORIZATION_ID ), array( $authorized->operation, $authorized->outcome, $authorized->providerObjectId ) );
		$sent = $this->paypal->sent();

		$this->assertSame( 'POST v1/oauth2/token', $sent[0], 'A token is asked for before the first request.' );
		$this->assertSame(
			array( 'POST v2/checkout/orders', 'GET v2/checkout/orders/' . Fixtures::ORDER_ID, 'POST v2/payments/authorizations/' . Fixtures::AUTHORIZATION_ID . '/capture' ),
			array_values( array_diff( $sent, array( 'POST v1/oauth2/token' ) ) ),
			'The capture reads the order first.'
		);

		foreach ( $this->paypal->requests as $request ) {
			$this->assertSame( 0, $request['depth'], 'PayPal is called outside any transaction.' );
		}

		$capture = $this->paypal->requests[ count( $this->paypal->requests ) - 1 ];
		$this->assertSame( $uuid . ':capture', $capture['headers']['PayPal-Request-Id'] ?? null );
		$this->assertSame( 'Bearer ' . PayPalAtTransport::TOKEN, $capture['headers']['Authorization'] ?? null );
		$this->assertSame( 'captured', $this->intentRow( $uuid )['status'] ?? null, 'SEOCart applied the capture.' );
	}

	/**
	 * Tests a delivery whose certificate cannot be fetched: SEOCart's route answers 503, so PayPal sends it again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_whose_certificate_cannot_be_fetched_is_answered_503(): void {
		$this->assertSame( GatewayCommand::EXIT_OK, $this->configure( new GatewayCommandRecorder( $this->kernel ), Mode::Test ) );

		$fetched = array();
		$refuse  = static function ( mixed $pre, mixed $args, string $url ) use ( &$fetched ): mixed {
			if ( ! str_starts_with( $url, Gateway::CERT_BASES['test'] ) ) {
				return $pre;
			}

			$fetched[] = $url;

			return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		};
		add_filter( 'pre_http_request', $refuse, 5, 3 );

		$keys = new TestDeliveries();
		$now  = new \DateTimeImmutable();
		$body = TestDeliveries::event( 'PAYMENT.AUTHORIZATION.CREATED', TestDeliveries::authorization(), 'WH-2WR32451HC0233532-67976317FL4543714', $now );

		$request = new \WP_REST_Request( 'POST', '/' . WebhookAddress::NAMESPACE . WebhookAddress::path( Gateway::ID, Mode::Test ) );
		$request->set_body( $body );

		foreach ( $keys->headers( $body, $now > $keys->validFrom ? $now : $keys->validFrom ) as $name => $value ) {
			$request->set_header( $name, $value );
		}

		$route  = $this->kernel->get( WebhookRoute::class );
		$server = $_SERVER;

		KernelHooks::detach( 'rest_api_init' );
		add_action( 'rest_api_init', array( $route, 'register' ) );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the next dispatch builds a REST server with the route registered afresh.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		try {
			$response = rest_do_request( $request );
		} finally {
			remove_action( 'rest_api_init', array( $route, 'register' ) );
			remove_filter( 'pre_http_request', $refuse, 5 );
			$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- as above.
			$_SERVER                   = $server; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the request globals put back as they were.
		}

		$this->assertSame( 503, $response->get_status(), 'A certificate that cannot be fetched is no answer: PayPal sends the delivery again.' );
		$this->assertSame( array( TestDeliveries::CERT_URL ), $fetched, 'The certificate was asked for once, from the sandbox\'s certificate host.' );
		$this->assertSame( array(), $this->paypal->requests, 'No call to PayPal\'s API: the delivery is checked locally.' );
	}

	/**
	 * Runs configure for a mode as an administrator, from a file only its owner can read.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayCommandRecorder $command The command.
	 * @param Mode                   $mode    The mode.
	 * @param array<string, string>  $values  Optional. Settings to give besides, or instead of, the usual ones. Default none.
	 * @return int The exit code.
	 */
	private function configure( GatewayCommandRecorder $command, Mode $mode, array $values = array() ): int {
		$file = (string) wp_tempnam( 'seocart-paypal' );

		$this->files[] = $file;

		file_put_contents(
			$file,
			(string) wp_json_encode(
				$values + array(
					Gateway::CLIENT_ID                 => 'AIntegrationTestClientId',
					Gateway::CLIENT_SECRET             => self::SECRET,
					Gateway::WEBHOOK_ID                => TestDeliveries::WEBHOOK_ID,
					GatewayDescriptor::ACCOUNT_COUNTRY => 'US',
				)
			)
		);
		chmod( $file, 0600 );

		return $command->run(
			array( 'configure', Gateway::ID ),
			array(
				'mode'      => $mode->value,
				'from-file' => $file,
			),
			$this->userWithRole( 'administrator' )->userId()
		);
	}
}
