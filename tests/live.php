<?php
/**
 * The PayPal sandbox workflow, as SEOCart's live steps read it: its parts, what each proves, the conformance suite's
 * run against the sandbox, the shape of PayPal's credentials, the orders a recorded run created, and the webhooks
 * part that waits for the owner's webhook id
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\GatewayForPayPal\Tests\Support\Live\CleanupSelection;
use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;

// SEOCart reads this file by path, where no autoloader may be: what it needs of the plugin's tests is required by path too.
require_once __DIR__ . '/Support/Live/CleanupSelection.php';

return new class() extends LiveDeclaration {

	/**
	 * Returns the live workflow's file name.
	 *
	 * @since 0.2.0
	 *
	 * @return string The file name.
	 */
	public function workflow(): string {
		return 'paypal-sandbox.yml';
	}

	/**
	 * Returns the jobs, each with its choice of the `parts` input.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each choice, by job.
	 */
	public function jobs(): array {
		return array(
			'live-conformance' => 'conformance',
			'three-d-secure'   => 'three-d-secure',
			'webhooks'         => 'webhooks',
			'checkout'         => 'checkout',
		);
	}

	/**
	 * Returns the live parts' tests: a run of fewer proved less than the part claims.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{filter: string, tests: int}> Each part's filter and count.
	 */
	public function liveParts(): array {
		return array(
			'three-d-secure' => array(
				'filter' => '/Live\\\\ThreeDSecureTest::/',
				'tests'  => 4,
			),
			'webhooks'       => array(
				'filter' => '/Live\\\\WebhooksTest::/',
				'tests'  => 3,
			),
		);
	}

	/**
	 * Returns what a run proves, each with its job.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each job, by what it proves.
	 */
	public function proofs(): array {
		return array(
			'The conformance suite, every request sent to PayPal\'s sandbox' => 'live-conformance',
			'3-D Secure completed in the browser, then resumed: the order authorized' => 'three-d-secure',
			'3-D Secure failed: declined under `require`, authorized under `accept`' => 'three-d-secure',
			'A shopper who never acts: voided at the window\'s end with nothing sent to PayPal' => 'three-d-secure',
			'A vaulted card\'s authorization voided: `VOIDED` at PayPal' => 'three-d-secure',
			'The delivery fixtures verified with a certificate fetched from PayPal' => 'webhooks',
			'The owner\'s webhook and the run\'s events listed by PayPal' => 'webhooks',
			'The checkout\'s PayPal form in a browser: PayPal\'s script only for a card, a card with no challenge, two presses, a new amount, and PayPal\'s page left for and come back from' => 'checkout',
			'The checkout\'s PayPal form with the bank\'s check always asked: a challenge passed, one failed and declined, and a reload during one' => 'checkout',
		);
	}

	/**
	 * Returns the facts a job reports beyond its counts and nonce: the liability results, the events counted, whether the webhook id is set, and the browser tests' extra presses.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The facts.
	 */
	public function facts(): array {
		return array( 'liability-shift', 'events', 'webhook-id', 'extra-presses' );
	}

	/**
	 * Returns how many tests the conformance test holds.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	public function suiteTests(): int {
		return 17;
	}

	/**
	 * Returns the tests the suite skips against PayPal's sandbox, by the scenario each cannot produce.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each scenario, by test.
	 */
	public function suiteSkips(): array {
		return array(
			'test_rule_09_expiry'                    => 'expires',
			'test_rule_17_void_answers_the_approval' => 'requires_action_completed',
		);
	}

	/**
	 * Returns the shape of PayPal's credentials: a client id, a secret or an access token is a run of 48 letters, digits, `_` and `-` or more, unless the whole run is a test's name.
	 *
	 * PayPal's ids are shorter (an event's, the longest, has 38), and so is a commit's 40-character
	 * hash, which a verdict names. A test's name is printed, as PHPUnit names a failing test with it
	 * and the live tests' names are longer: a run that is `test_` and then lower-case letters,
	 * digits and `_` alone, to its end. The run is read from its first character, so one that only
	 * starts as a test's name does and goes on with an upper-case letter or a `-`, or that holds a
	 * test's name after other characters, is hidden whole.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The pattern, by what it matches.
	 */
	public function shapes(): array {
		return array( 'a credential' => '/(?<![A-Za-z0-9_\-])(?!test_[a-z0-9_]*+(?![A-Za-z0-9_\-]))[A-Za-z0-9_\-]{48,}/' );
	}

	/**
	 * Returns the orders recorded exchanges created at PayPal.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<mixed>> $exchanges The exchanges.
	 * @return array<string, string> Each store uuid, by the order's id.
	 */
	public function createdBy( array $exchanges ): array {
		return CleanupSelection::createdBy( $exchanges );
	}

	/**
	 * Returns the webhooks part, which runs nothing while the repository has no webhook id.
	 *
	 * @since 0.2.0
	 *
	 * @return array{job: string, secret: string, output: string, reason: string} The part.
	 */
	public function optionalPart(): array {
		return array(
			'job'    => 'webhooks',
			'secret' => 'PAYPAL_WEBHOOK_ID',
			'output' => 'webhook-id',
			'reason' => 'the repository has no PAYPAL_WEBHOOK_ID secret yet, the id of the sandbox webhook PayPal signs real deliveries for',
		);
	}
};
