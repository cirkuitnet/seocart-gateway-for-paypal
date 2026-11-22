<?php
/**
 * Tests what the cleanup of a run against PayPal's sandbox may void
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForPayPal\Tests\Support\Fixtures;
use SEOCart\GatewayForPayPal\Tests\Support\Live\CleanupSelection;
use SEOCart\Tests\Conformance\Payment\Live\LiveRun;

/**
 * The orders a recording created are read from its tapes: each create the sandbox answered with an
 * order and the store's uuid. The cleanup voids an authorization still `CREATED` of an order on the
 * run list whose own uuid, as PayPal holds it, is the one the list names, and nothing else: no
 * order of another integration, no authorization captured, voided or expired, and no order nobody
 * approved, which holds nothing.
 *
 * Planted violation, shown red and removed: the uuid check dropped: the stray order's authorization
 * is chosen, and the test of a stray id fails.
 *
 * @since 0.2.0
 */
final class LiveCleanupSelectionTest extends TestCase {

	/**
	 * Tests the orders read from a recording's tapes.
	 *
	 * @since 0.2.0
	 */
	public function test_the_orders_a_recording_created_are_read_from_its_tapes(): void {
		$created = array(
			'request'  => array(
				'method' => 'POST',
				'path'   => '/v2/checkout/orders',
			),
			'response' => array(
				'status' => 201,
				'body'   => Fixtures::text( 'order-authorized' ),
			),
		);
		$refused = array( 'response' => array( 'status' => 422 ) + $created['response'] ) + $created;
		$read    = array( 'request' => array( 'method' => 'GET' ) + $created['request'] ) + $created;

		$this->assertSame( array( Fixtures::ORDER_ID => Fixtures::INTENT_UUID ), CleanupSelection::createdBy( array( $created, $refused, $read, array() ) ) );
		$this->assertSame( Fixtures::ORDER_ID . ' ' . Fixtures::INTENT_UUID . "\n", LiveRun::line( Fixtures::ORDER_ID, Fixtures::INTENT_UUID ) );
	}

	/**
	 * Tests the authorizations voided: the run's, still holding money, and no other.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_runs_authorizations_still_holding_money_are_voided(): void {
		$runList = array( Fixtures::ORDER_ID => Fixtures::INTENT_UUID );
		$held    = Fixtures::object( 'order-authorized' );

		$this->assertSame( array( Fixtures::ORDER_ID => Fixtures::AUTHORIZATION_ID ), CleanupSelection::authorizationsToVoid( $runList, array( $held ) ) );

		foreach ( array( 'CAPTURED', 'VOIDED', 'EXPIRED', 'DENIED' ) as $status ) {
			$order = $held;
			$order['purchase_units'][0]['payments']['authorizations'][0]['status'] = $status;

			$this->assertSame( array(), CleanupSelection::authorizationsToVoid( $runList, array( $order ) ), "An authorization {$status} holds nothing to void." );
		}

		$this->assertSame( array(), CleanupSelection::authorizationsToVoid( $runList, array( Fixtures::object( 'order-approved' ) ) ), 'An approved order holds nothing.' );
	}

	/**
	 * Tests the clean-up of past runs: an authorization still holding money, made that day, for a store uuid, and no other.
	 *
	 * @since 0.2.0
	 */
	public function test_a_past_runs_authorization_is_held_only_when_created_that_day_for_a_store_uuid(): void {
		$held = array(
			'id'          => Fixtures::AUTHORIZATION_ID,
			'status'      => 'CREATED',
			'custom_id'   => Fixtures::INTENT_UUID,
			'create_time' => '2026-11-14T11:59:59Z',
		);

		$this->assertSame( Fixtures::AUTHORIZATION_ID, CleanupSelection::heldOn( $held, '2026-11-14' ) );
		$this->assertNull( CleanupSelection::heldOn( $held, '2026-11-13' ), 'Another day\'s is not the run\'s.' );
		$this->assertNull( CleanupSelection::heldOn( array( 'status' => 'VOIDED' ) + $held, '2026-11-14' ) );
		$this->assertNull( CleanupSelection::heldOn( array( 'custom_id' => 'order-1001' ) + $held, '2026-11-14' ), 'Not a store uuid: not the gateway\'s.' );
		$this->assertNull( CleanupSelection::heldOn( array( 'id' => '4111111111111111' ) + $held, '2026-11-14' ) );
	}

	/**
	 * Tests the transactions a search lists: those for a store uuid, each once.
	 *
	 * @since 0.2.0
	 */
	public function test_the_transactions_searched_are_those_for_a_store_uuid(): void {
		$page = array(
			'transaction_details' => array(
				array(
					'transaction_info' => array(
						'transaction_id' => Fixtures::AUTHORIZATION_ID,
						'custom_field'   => Fixtures::INTENT_UUID,
					),
				),
				array(
					'transaction_info' => array(
						'transaction_id' => Fixtures::AUTHORIZATION_ID,
						'custom_field'   => Fixtures::INTENT_UUID,
					),
				),
				array(
					'transaction_info' => array(
						'transaction_id' => Fixtures::CAPTURE_ID,
						'custom_field'   => 'order-1001',
					),
				),
				array( 'transaction_info' => array( 'transaction_id' => Fixtures::REFUND_ID ) ),
				'not a transaction',
			),
		);

		$this->assertSame( array( Fixtures::AUTHORIZATION_ID ), CleanupSelection::searched( $page ) );
		$this->assertSame( array(), CleanupSelection::searched( array() ) );
	}

	/**
	 * Tests a stray id: an order whose uuid at PayPal is not the run's is never touched.
	 *
	 * @since 0.2.0
	 */
	public function test_an_order_whose_uuid_is_not_the_runs_is_never_touched(): void {
		$order = Fixtures::object( 'order-authorized' );

		$this->assertSame( array(), CleanupSelection::authorizationsToVoid( array( Fixtures::ORDER_ID => '0199c0de-0000-7000-8000-00000000ffff' ), array( $order ) ) );
		$this->assertSame( array(), CleanupSelection::authorizationsToVoid( array(), array( $order ) ) );
	}
}
