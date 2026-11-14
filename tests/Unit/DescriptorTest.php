<?php
/**
 * Tests describe(): the gateway's one declaration
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use SEOCart\Contracts\OutboundHost;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\ProvisionsWebhooks;
use SEOCart\GatewayForPayPal\Gateway;
use SEOCart\GatewayForPayPal\Tests\Support\GatewayTestCase;
use SEOCart\Support\Schema\Privacy;

/**
 * The descriptor declares the id, two modes, the six settings of a mode (the client secret the only
 * credential, no webhook signing secret, every name free of a card field's), the matrix of the
 * certified account country in USD, GBP and EUR, PayPal's 45-day request ids, searchable, and the
 * four hosts, each with every field the readme discloses. It is built from constants: asked twice,
 * it reads nothing and sends nothing.
 *
 * @since 0.2.0
 */
final class DescriptorTest extends GatewayTestCase {

	/**
	 * Tests the id, the label, the type, the contract and the modes.
	 *
	 * @since 0.2.0
	 */
	public function test_the_gateway_declares_its_id_label_type_contract_and_modes(): void {
		$descriptor = $this->gateway->describe();

		$this->assertSame( 'paypal', $descriptor->id );
		$this->assertSame( 'PayPal', $descriptor->label() );
		$this->assertSame( GatewayDescriptor::TYPE_PAYMENTS, $descriptor->type );
		$this->assertSame( PaymentGateway::CONTRACT_VERSION, $descriptor->contract, 'The gateway is written against the contract SEOCart implements.' );
		$this->assertSame( array( Mode::Test, Mode::Live ), $descriptor->modes );
		$this->assertNotContains( ProvisionsWebhooks::class, (array) class_implements( Gateway::class ), 'The merchant creates the webhook and pastes its id; the gateway sets none up.' );
	}

	/**
	 * Tests the settings: their names, which is a credential, what each allows and defaults to.
	 *
	 * @since 0.2.0
	 */
	public function test_each_mode_keeps_a_client_id_a_secret_a_webhook_id_the_country_and_two_policies(): void {
		$settings = array();

		foreach ( $this->gateway->describe()->settings as $field ) {
			$settings[ $field->name() ] = array( $field->privacy(), $field->allowedValues(), $field->defaultValue() );
		}

		$this->assertSame(
			array(
				'client_id'              => array( Privacy::Public, array(), null ),
				'client_secret'          => array( Privacy::Secret, array(), null ),
				'webhook_id'             => array( Privacy::Public, array(), null ),
				'account_country'        => array( Privacy::Public, array( 'US' ), null ),
				'sca_policy'             => array( Privacy::Public, array( 'SCA_WHEN_REQUIRED', 'SCA_ALWAYS' ), 'SCA_WHEN_REQUIRED' ),
				'liability_shift_policy' => array( Privacy::Public, array( 'require', 'accept' ), 'require' ),
			),
			$settings
		);
		$this->assertArrayNotHasKey( GatewayDescriptor::WEBHOOK_SECRET, $settings, 'PayPal signs with its own key: there is no signing secret to keep.' );
	}

	/**
	 * Tests the matrix: every operation, in USD, GBP and EUR, for an account in the United States.
	 *
	 * @since 0.2.0
	 */
	public function test_the_matrix_is_the_certified_country_in_three_currencies_with_every_declared_operation(): void {
		$rows = array();

		foreach ( $this->gateway->describe()->matrix->rows as $row ) {
			$rows[] = array( $row->currency->code(), $row->accountCountry, $row->operations );
		}

		$operations = array(
			Operations::AUTHORIZE,
			Operations::CAPTURE,
			Operations::PARTIAL_CAPTURE,
			Operations::VOID,
			Operations::REFUND,
			Operations::PARTIAL_REFUND,
			Operations::SCA,
			Operations::QUERY,
			Operations::QUERY_REFUND,
			Operations::WEBHOOKS,
		);

		$this->assertSame(
			array(
				array( 'USD', 'US', $operations ),
				array( 'GBP', 'US', $operations ),
				array( 'EUR', 'US', $operations ),
			),
			$rows
		);
	}

	/**
	 * Tests the idempotency profile: request ids kept 45 days, and an order's refunds listed at once.
	 *
	 * @since 0.2.0
	 */
	public function test_request_ids_are_kept_45_days_and_refunds_are_found_at_once(): void {
		$profile = $this->gateway->describe()->idempotency;

		$this->assertSame( array( 3888000, true, 0 ), array( $profile->keyRetentionSeconds, $profile->searchable, $profile->searchDelaySeconds ) );
	}

	/**
	 * Tests the four hosts: the REST API and the webhook certificates, each live and in the sandbox, each disclosed in full.
	 *
	 * @since 0.2.0
	 */
	public function test_the_four_hosts_are_declared_with_every_field_the_readme_discloses(): void {
		$hosts = array();

		foreach ( $this->gateway->describe()->hosts as $host ) {
			$hosts[ $host->id ] = array( $host->host, $host->endpoint, $host->timeoutSeconds );

			foreach ( array( 'service', 'purpose', 'dataSent', 'sentWhen', 'termsUrl', 'privacyUrl' ) as $field ) {
				$this->assertNotSame( '', trim( (string) $host->{$field} ), "The host {$host->id} declares its {$field}." );
			}

			$this->assertInstanceOf( OutboundHost::class, $host );
		}

		$this->assertSame(
			array(
				'paypal-api-live'      => array( 'api-m.paypal.com', 'https://api-m.paypal.com/*', 30 ),
				'paypal-api-sandbox'   => array( 'api-m.sandbox.paypal.com', 'https://api-m.sandbox.paypal.com/*', 30 ),
				'paypal-certs-live'    => array( 'api.paypal.com', 'https://api.paypal.com/v1/notifications/certs/*', 30 ),
				'paypal-certs-sandbox' => array( 'api.sandbox.paypal.com', 'https://api.sandbox.paypal.com/v1/notifications/certs/*', 30 ),
			),
			$hosts
		);
	}

	/**
	 * Tests that the declaration is built from constants: asking for it reads no setting and sends nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_describing_reads_nothing_and_sends_nothing(): void {
		$this->gateway->describe();
		$this->gateway->describe();

		$this->assertSame( array(), $this->context->reads );
		$this->assertSame( array(), $this->context->paypal->requests );
		$this->assertSame( array(), $this->context->log->lines );
	}

	/**
	 * Tests that every mode's host is the one its requests name: the sandbox for test mode, PayPal's own for live mode.
	 *
	 * @since 0.2.0
	 */
	public function test_each_mode_names_its_own_hosts(): void {
		$this->assertSame( array( 'paypal-api-sandbox', 'https://api-m.sandbox.paypal.com/' ), array( Gateway::apiHostId( Mode::Test ), Gateway::apiBase( Mode::Test ) ) );
		$this->assertSame( array( 'paypal-api-live', 'https://api-m.paypal.com/' ), array( Gateway::apiHostId( Mode::Live ), Gateway::apiBase( Mode::Live ) ) );
		$this->assertSame( array( 'paypal-certs-sandbox', 'paypal-certs-live' ), array( Gateway::CERT_HOSTS['test'], Gateway::CERT_HOSTS['live'] ) );
	}
}
