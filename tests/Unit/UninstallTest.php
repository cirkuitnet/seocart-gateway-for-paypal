<?php
/**
 * Tests uninstall.php: the certificates the gateway kept are removed, and nothing else
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForPayPal\CertificateCache;

/**
 * The uninstall file deletes the transients CertificateCache keeps, their values and their timeouts,
 * by their prefix, and runs no other statement. The file is run against a recording stand-in for
 * WordPress's database object, with the constant WordPress defines before it runs the file.
 *
 * @since 0.2.0
 */
final class UninstallTest extends TestCase {

	/**
	 * Tests the statements uninstall.php runs.
	 *
	 * @since 0.2.0
	 */
	public function test_it_deletes_the_kept_certificates_and_nothing_else(): void {
		$before = $GLOBALS['wpdb'] ?? null;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a stand-in for WordPress's database object, put back after the test.
		$GLOBALS['wpdb'] = new class() {

			/**
			 * The options table.
			 *
			 * @var string
			 */
			public string $options = 'wp_options';

			/**
			 * Every statement run.
			 *
			 * @var list<string>
			 */
			public array $queries = array();

			/**
			 * Escapes LIKE's wildcards.
			 *
			 * @param string $text The text.
			 * @return string The text, escaped.
			 */
			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			/**
			 * Fills a statement's one placeholder.
			 *
			 * @param string $query The statement.
			 * @param string $value The value.
			 * @return string The statement.
			 */
			public function prepare( string $query, string $value ): string {
				return str_replace( '%s', "'" . $value . "'", $query );
			}

			/**
			 * Records a statement.
			 *
			 * @param string $query The statement.
			 * @return int The rows changed.
			 */
			public function query( string $query ): int {
				$this->queries[] = $query;

				return 0;
			}
		};

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant WordPress defines before it runs the file.
			define( 'WP_UNINSTALL_PLUGIN', 'seocart-gateway-for-paypal/seocart-gateway-for-paypal.php' );
		}

		try {
			require dirname( __DIR__, 2 ) . '/uninstall.php';

			$queries = $GLOBALS['wpdb']->queries;
		} finally {
			$GLOBALS['wpdb'] = $before; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress's database object, put back as it was.
		}

		$prefix = addcslashes( CertificateCache::PREFIX, '_%\\' );

		$this->assertSame(
			array(
				"DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_" . $prefix . "%'",
				"DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_timeout\\_" . $prefix . "%'",
			),
			$queries
		);
	}
}
