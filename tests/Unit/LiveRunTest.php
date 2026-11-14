<?php
/**
 * Tests the files a run against PayPal's sandbox writes
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForPayPal\Tests\Support\Live\LiveRun;

/**
 * A report keeps one line per fact and reads back the last value of each, readable by its owner
 * alone; a run list keeps one order per line with the store uuid it was made for, and reads back
 * only lines of that shape; a uuid the live tests make is a version 4 uuid.
 *
 * @since 0.2.0
 */
final class LiveRunTest extends TestCase {

	/**
	 * Tests the report.
	 *
	 * @since 0.2.0
	 */
	public function test_a_report_is_written_and_read_back(): void {
		$file = tempnam( sys_get_temp_dir(), 'live-report-' );

		$this->assertIsString( $file );
		putenv( LiveRun::REPORT . '=' . $file );

		try {
			LiveRun::report( 'events', 1 );
			LiveRun::report( 'liability-shift', "step-up POSSIBLE\nmore" );
			LiveRun::report( 'events', 2 );

			$this->assertSame( 0600, fileperms( $file ) & 0777 );
			$this->assertSame(
				array(
					'events'          => '2',
					'liability-shift' => 'step-up POSSIBLE more',
				),
				LiveRun::facts( (string) file_get_contents( $file ) )
			);
		} finally {
			putenv( LiveRun::REPORT );
			unlink( $file );
		}
	}

	/**
	 * Tests the run list: each order with its uuid, written whole even when its digits read as a card number, and nothing of another shape written or read back.
	 *
	 * @since 0.2.0
	 */
	public function test_a_run_list_is_written_and_read_back(): void {
		$file = tempnam( sys_get_temp_dir(), 'live-run-list-' );

		$this->assertIsString( $file );
		putenv( LiveRun::RUN_LIST . '=' . $file );

		try {
			LiveRun::created( '5O190127TN364715T', '0199c0de-0000-7000-8000-00000000a001' );
			file_put_contents( $file, "not an order\n4111111111111111 0199c0de-0000-7000-8000-00000000a001\n", FILE_APPEND );
			LiveRun::created( '21P35652VJ9135157', '0199c0de-0000-7000-8000-00000000a002' );
			// An id of PayPal's whose digits pass a card number's checksum, as the sandbox gave one: written whole, so the cleanup finds it.
			LiveRun::created( '0TP3831016068574K', '0199c0de-0000-7000-8000-00000000a003' );

			$this->assertSame(
				array(
					'5O190127TN364715T' => '0199c0de-0000-7000-8000-00000000a001',
					'21P35652VJ9135157' => '0199c0de-0000-7000-8000-00000000a002',
					'0TP3831016068574K' => '0199c0de-0000-7000-8000-00000000a003',
				),
				LiveRun::orders( (string) file_get_contents( $file ) )
			);

			foreach ( array( array( '41111111111111111', '0199c0de-0000-7000-8000-00000000a004' ), array( '5O190127TN364715T', 'not-a-uuid' ) ) as list( $orderId, $uuid ) ) {
				try {
					LiveRun::created( $orderId, $uuid );
					$this->fail( 'The run list took what is not an order\'s id and a store uuid.' );
				} catch ( \LogicException $refused ) {
					$this->assertStringContainsString( 'nothing else', $refused->getMessage() );
				}
			}
		} finally {
			putenv( LiveRun::RUN_LIST );
			unlink( $file );
		}
	}

	/**
	 * Tests the uuids the live tests make.
	 *
	 * @since 0.2.0
	 */
	public function test_a_uuid_is_a_version_4_uuid(): void {
		$uuid = LiveRun::uuid();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid );
		$this->assertNotSame( $uuid, LiveRun::uuid() );
	}
}
