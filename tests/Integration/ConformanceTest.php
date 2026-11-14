<?php
/**
 * Tests the gateway against SEOCart's payment gateway conformance suite
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Integration;

use SEOCart\GatewayForPayPal\Gateway;
use SEOCart\Tests\Conformance\Payment\GatewayConformanceCase;

// SEOCart's integration bootstrap autoloads this plugin's src/ alone: the suite's declarations of PayPal are loaded by their path.
require_once __DIR__ . '/PayPalSuiteDeclarations.php';

/**
 * Holds the gateway this plugin registers to every rule of SEOCart's payment gateway conformance suite, replaying PayPal's answers from tests/tapes.
 *
 * `sh ../seocart/bin/ci/extension.sh conformance . payments` runs it; a plain run of the
 * integration suite leaves it out. The tapes are PayPal's sandbox, recorded with
 * SEOCART_TAPE=record, SEOCART_TAPE_SETTINGS naming a file of the test mode's settings readable by
 * its owner alone, and a fresh SEOCART_TAPE_NONCE each time, as PayPal answers a PayPal-Request-Id
 * it has seen in the last 45 days with its first answer. The webhook deliveries are the fixtures in
 * tests/fixtures/paypal/webhooks.
 *
 * What the suite is told of PayPal, the payment tokens and what of PayPal's answers is public, is
 * PayPalSuiteDeclarations, which the live tests use too.
 *
 * @since 0.1.0
 * @since 0.2.0 Pays with a vaulted sandbox card, and reads the delivery fixtures.
 */
final class ConformanceTest extends GatewayConformanceCase {

	use PayPalSuiteDeclarations;

	/**
	 * Returns the id the plugin registers the gateway under.
	 *
	 * @since 0.1.0
	 *
	 * @return string The gateway's id.
	 */
	protected function gatewayId(): string {
		return Gateway::ID;
	}

	/**
	 * Returns the directory of the provider's recorded answers.
	 *
	 * @since 0.1.0
	 *
	 * @return string The directory.
	 */
	protected function tapes(): string {
		return dirname( __DIR__ ) . '/tapes';
	}
}
