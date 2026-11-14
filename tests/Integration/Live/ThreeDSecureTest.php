<?php
/**
 * Tests 3-D Secure in PayPal's card fields, the resume that authorizes, and the window that ends a payment nobody completed
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Integration\Live;

use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\GatewayForPayPal\Gateway;
use SEOCart\GatewayForPayPal\PayPalApi;
use SEOCart\GatewayForPayPal\Tests\Support\Live\LiveRun;
use SEOCart\Tests\Conformance\Payment\Exchange;
use SEOCart\Tests\Support\Payment\GatewayKernel;

require_once __DIR__ . '/LivePayPalCase.php';

/**
 * Against PayPal's sandbox, with SEOCart's own placement, status query and reconciliation job:
 * with the mode set to ask the card's bank every time, a card payment whose bank asks for 3-D
 * Secure is approved in PayPal's card fields in a browser, and the store's status query then
 * authorizes it; a card whose check fails is declined under the
 * policy that requires the liability to move, and authorized under the policy that accepts it; a
 * shopper who never acts has the payment voided when the window ends, with nothing sent to PayPal,
 * as nothing is held; and a vaulted card's authorization, voided by the store, reads `VOIDED` at
 * PayPal.
 *
 * @group paypal-live
 *
 * @since 0.2.0
 */
final class ThreeDSecureTest extends LivePayPalCase {

	/**
	 * Tests the step-up card: approved in the browser, then the store's query authorizes it, the liability moved.
	 *
	 * @since 0.2.0
	 */
	public function test_3ds_completed_in_the_browser_then_resumed_is_authorized(): void {
		$this->begin( 'live-3ds-completed' );
		self::alwaysAskTheBank();

		$placed = $this->placeLive( 'card' );
		$this->assertSame( 'requires_action', $placed['outcome'] );
		$this->assertSame( 'approved', $this->approveInBrowser( $placed, self::STEP_UP_SUCCEEDS ), 'The card fields did not approve the order with the step-up card.' );

		$answer = $this->payments()->queryGateway( $this->ref( $placed['intent_uuid'] ) );
		$this->assertNotNull( $answer, 'The resume of an approved order answered nothing.' );
		$this->assertSame( array( 'authorize', 'approved' ), array( $answer->operation->value, $answer->outcome->value ), 'The resume did not authorize the approved order.' );

		$shift = (string) ( $this->paypalOrder( (string) $placed['paypal_order'] )['payment_source']['card']['authentication_result']['liability_shift'] ?? 'none' );
		LiveRun::report( 'liability-shift', 'step-up ' . self::shown( $shift ) );
		$this->assertContains( $shift, array( 'POSSIBLE', 'YES' ) );

		$this->end();
	}

	/**
	 * Tests the failed step-up card: declined under `require`, with nothing authorized; authorized under `accept`.
	 *
	 * @since 0.2.0
	 */
	public function test_3ds_failed_is_declined_under_require_and_authorized_under_accept(): void {
		$this->begin( 'live-3ds-failed' );
		self::alwaysAskTheBank();

		$required = $this->placeLive( 'card' );
		$this->assertSame( 'approved', $this->approveInBrowser( $required, self::STEP_UP_FAILS ), 'The card fields did not hand the failed check back to the store.' );

		$declined = $this->payments()->queryGateway( $this->ref( $required['intent_uuid'] ) );
		$this->assertSame( array( 'declined', 'liability_shift_not_possible' ), array( $declined?->outcome->value, $declined?->errorCode ), 'Under require, a card whose liability stayed with the store is declined.' );
		$this->assertSame( 'APPROVED', $this->paypalOrder( (string) $required['paypal_order'] )['status'] ?? null, 'Under require, nothing was authorized.' );

		GatewayKernel::alterStored( Gateway::ID, Mode::Test, Gateway::LIABILITY_POLICY, static fn(): string => Gateway::ACCEPT_ANY );

		$accepted = $this->placeLive( 'card' );
		$this->assertSame( 'approved', $this->approveInBrowser( $accepted, self::STEP_UP_FAILS ) );

		$authorized = $this->payments()->queryGateway( $this->ref( $accepted['intent_uuid'] ) );
		$this->assertSame( 'approved', $authorized?->outcome->value, 'Under accept, the card is authorized whatever its check said.' );
		LiveRun::report( 'liability-shift', 'failed step-up ' . self::shown( (string) ( $this->paypalOrder( (string) $accepted['paypal_order'] )['payment_source']['card']['authentication_result']['liability_shift'] ?? 'none' ) ) );

		$this->end();
	}

	/**
	 * Tests a shopper who never acts: the window's end voids the payment, and nothing is sent to PayPal but the read.
	 *
	 * @since 0.2.0
	 */
	public function test_a_shopper_who_never_acts_is_voided_at_the_windows_end_with_nothing_sent(): void {
		$this->begin( 'live-never-acts' );

		$placed = $this->placeLive( 'card' );
		$this->assertSame( 'requires_action', $placed['outcome'] );

		$before = count( $this->transport->exchanges() );
		$this->endWait( $placed['intent_uuid'], ReconcileStalePlacements::STALE_SECONDS + 1 );
		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );
		$moving = array_filter( array_slice( $this->transport->exchanges(), $before ), static fn( Exchange $exchange ): bool => 'GET' !== $exchange->method && ! str_ends_with( $exchange->path(), PayPalApi::TOKEN_PATH ) );

		$this->assertSame( 'voided', $this->intentRow( $placed['intent_uuid'] )['status'], 'The window\'s end did not void the payment.' );
		$this->assertSame( array(), array_values( $moving ), 'The void of an order nobody approved sent PayPal a request that moves money.' );
		$this->assertSame( 'CREATED', $this->paypalOrder( (string) $placed['paypal_order'] )['status'] ?? null, 'The order still waits at PayPal, holding nothing, and lapses there.' );

		$this->end();
	}

	/**
	 * Tests a vaulted card's authorization voided by the store: `VOIDED` at PayPal.
	 *
	 * @since 0.2.0
	 */
	public function test_a_vaulted_cards_authorization_voided_by_the_store_is_voided_at_paypal(): void {
		$this->begin( 'live-vaulted-void' );

		$placed = $this->placeLive( 'vault:' . self::VAULTED_CARD );
		$this->assertSame( 'approved', $placed['outcome'] );

		$this->void( $placed['intent_uuid'] );

		$this->assertSame( 'voided', $this->intentRow( $placed['intent_uuid'] )['status'] );
		$this->assertSame( 'VOIDED', $this->paypalOrder( (string) $placed['paypal_order'] )['purchase_units'][0]['payments']['authorizations'][0]['status'] ?? null );

		$this->end();
	}

	/**
	 * Sets the test mode to ask the card's bank to confirm every card payment (`SCA_ALWAYS`), as a merchant may.
	 *
	 * The run's settings keep PayPal's default, `SCA_WHEN_REQUIRED`, under which PayPal asks the bank
	 * only when a rule requires it: for this US account it asked for nothing, so the step-up cards
	 * were authorized with no 3-D Secure result at all. The step-up tests need the check made.
	 *
	 * @since 0.2.0
	 */
	private static function alwaysAskTheBank(): void {
		GatewayKernel::alterStored( Gateway::ID, Mode::Test, Gateway::SCA_POLICY, static fn(): string => 'SCA_ALWAYS' );
	}
}
