<?php
/**
 * FakeContext: what SEOCart gives the gateway, for unit tests
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Support;

use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\HttpClient;
use SEOCart\Contracts\Logger;
use SEOCart\Contracts\Payment\CredentialUnavailable;
use SEOCart\Contracts\Payment\GatewaySettings;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Support\Clock;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * The context a unit test builds the gateway with: the fake PayPal client, a recording logger, a frozen clock and settings per mode.
 *
 * The settings hold a fake client id and secret for each mode, which a test may replace; every
 * credential read is counted by mode and name, and so is every read of a setting.
 *
 * @since 0.2.0
 */
final class FakeContext implements ExtensionContext {

	/**
	 * The fake client secret of the test mode.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TEST_SECRET = 'EUnitTestSecretOfTheTestMode';

	/**
	 * The fake client secret of the live mode.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIVE_SECRET = 'EUnitTestSecretOfTheLiveMode';

	/**
	 * The fake webhook id of the test mode, the one TestDeliveries signs for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TEST_WEBHOOK_ID = TestDeliveries::WEBHOOK_ID;

	/**
	 * The fake PayPal.
	 *
	 * @since 0.2.0
	 *
	 * @var FakePayPal
	 */
	public FakePayPal $paypal;

	/**
	 * The logger.
	 *
	 * @since 0.2.0
	 *
	 * @var RecordingLogger
	 */
	public RecordingLogger $log;

	/**
	 * The clock.
	 *
	 * @since 0.2.0
	 *
	 * @var FrozenClock
	 */
	public FrozenClock $clock;

	/**
	 * The settings of each mode, by mode and then name: values and credentials alike.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array<string, string>>
	 */
	public array $settings;

	/**
	 * Every setting read, values and credentials alike, as `mode/name`, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public array $reads = array();

	/**
	 * Every credential read, as `mode/name`, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public array $secretReads = array();

	/**
	 * Builds the context, with the clock at a fixed instant.
	 *
	 * @since 0.2.0
	 */
	public function __construct() {
		$this->paypal   = new FakePayPal();
		$this->log      = new RecordingLogger();
		$this->clock    = new FrozenClock( new \DateTimeImmutable( '2026-11-14 12:00:00', new \DateTimeZone( 'UTC' ) ) );
		$this->settings = array(
			'test' => array(
				'client_id'              => 'AUnitTestClientIdOfTheTestMode',
				'client_secret'          => self::TEST_SECRET,
				'webhook_id'             => self::TEST_WEBHOOK_ID,
				'account_country'        => 'US',
				'sca_policy'             => 'SCA_WHEN_REQUIRED',
				'liability_shift_policy' => 'require',
			),
			'live' => array(
				'client_id'              => 'AUnitTestClientIdOfTheLiveMode',
				'client_secret'          => self::LIVE_SECRET,
				'webhook_id'             => '9LIVEWEBHOOK00001',
				'account_country'        => 'US',
				'sca_policy'             => 'SCA_WHEN_REQUIRED',
				'liability_shift_policy' => 'require',
			),
		);
	}

	/**
	 * Returns the extension's id.
	 *
	 * @since 0.2.0
	 *
	 * @return string `paypal`.
	 */
	public function extensionId(): string {
		return 'paypal';
	}

	/**
	 * Returns the contract version.
	 *
	 * @since 0.2.0
	 *
	 * @return string PaymentGateway::CONTRACT_VERSION.
	 */
	public function contractVersion(): string {
		return PaymentGateway::CONTRACT_VERSION;
	}

	/**
	 * Returns the recording logger.
	 *
	 * @since 0.2.0
	 *
	 * @return Logger The logger.
	 */
	public function logger(): Logger {
		return $this->log;
	}

	/**
	 * Returns the frozen clock.
	 *
	 * @since 0.2.0
	 *
	 * @return Clock The clock.
	 */
	public function clock(): Clock {
		return $this->clock;
	}

	/**
	 * Returns the fake PayPal.
	 *
	 * @since 0.2.0
	 *
	 * @return HttpClient The client.
	 */
	public function http(): HttpClient {
		return $this->paypal;
	}

	/**
	 * Returns a mode's settings, read from this context when asked.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode.
	 * @return GatewaySettings The settings.
	 */
	public function settings( Mode $mode ): GatewaySettings {
		$context = $this;

		return new class( $context, $mode ) implements GatewaySettings {

			/**
			 * Records which context and mode to read.
			 *
			 * @param FakeContext $context The context.
			 * @param Mode        $mode    The mode.
			 */
			public function __construct( private FakeContext $context, private Mode $mode ) {
			}

			/**
			 * Returns a setting, counting the read.
			 *
			 * @param string $name The name.
			 * @return string|null The value.
			 */
			public function value( string $name ): ?string {
				$this->context->reads[] = $this->mode->value . '/' . $name;

				return $this->context->settings[ $this->mode->value ][ $name ] ?? null;
			}

			/**
			 * Returns a credential, counting the read.
			 *
			 * @throws CredentialUnavailable When it is not set.
			 *
			 * @param string $name The name.
			 * @return string The credential.
			 */
			public function secret( string $name ): string {
				$this->context->reads[]       = $this->mode->value . '/' . $name;
				$this->context->secretReads[] = $this->mode->value . '/' . $name;

				return $this->context->settings[ $this->mode->value ][ $name ] ?? throw new CredentialUnavailable( CredentialUnavailable::MISSING, $name );
			}
		};
	}

	/**
	 * Returns the credential values of both modes, which no log line may hold.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The values.
	 */
	public function credentials(): array {
		$values = array();

		foreach ( $this->settings as $settings ) {
			$values[] = $settings['client_secret'] ?? '';
		}

		return array_values( array_filter( $values ) );
	}
}
