<?php
/**
 * The PayPal sandbox workflow's steps that read what the tests printed or recorded
 *
 * Usage: php workflow-step.php redact                            Copies its standard input with credential-shaped text blanked out
 *        php workflow-step.php suite <output>                    Checks the conformance suite's run, and gives its counts
 *        php workflow-step.php harvest <tapes> <mark> <run list> Adds the orders the suite created to the run list
 *        php workflow-step.php live <part> <output> <report>     Checks a part's live tests, and gives its counts and facts
 *        php workflow-step.php webhook-id                        Says whether PAYPAL_WEBHOOK_ID is set, never its value
 *        php workflow-step.php verdict                           Judges the run from the jobs in PAYPAL_SANDBOX_NEEDS, the parts in PAYPAL_SANDBOX_PARTS
 *
 * Outputs go to the file GITHUB_OUTPUT names, and the verdict's table to the one
 * GITHUB_STEP_SUMMARY names; to the standard output where neither is set. Needs no WordPress and
 * no SEOCart: the verdict's job checks out the extension alone.
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\GatewayForPayPal\Tests\Support\Live\WorkflowStep;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

foreach ( array( 'MessageGuard', 'LiveRun', 'CleanupSelection', 'SuiteOutput', 'Verdict', 'WorkflowStep' ) as $seocart_gateway_for_paypal_class ) {
	require_once dirname( __DIR__, 2 ) . '/Support/Live/' . $seocart_gateway_for_paypal_class . '.php';
}

$seocart_gateway_for_paypal_outputs = fopen( '' === (string) getenv( 'GITHUB_OUTPUT' ) ? 'php://stdout' : (string) getenv( 'GITHUB_OUTPUT' ), 'ab' );
$seocart_gateway_for_paypal_summary = fopen( '' === (string) getenv( 'GITHUB_STEP_SUMMARY' ) ? 'php://stdout' : (string) getenv( 'GITHUB_STEP_SUMMARY' ), 'ab' );
$seocart_gateway_for_paypal_read    = static fn( int $position ): string => is_readable( $argv[ $position ] ?? '' ) ? (string) file_get_contents( $argv[ $position ] ) : '';

if ( false === $seocart_gateway_for_paypal_outputs || false === $seocart_gateway_for_paypal_summary ) {
	fwrite( STDERR, "workflow-step: the outputs or the summary cannot be written.\n" );
	exit( 1 );
}

if ( ! in_array( $argv[1] ?? '', array( 'redact', 'suite', 'harvest', 'live', 'webhook-id', 'verdict' ), true ) ) {
	fwrite( STDERR, "usage: php workflow-step.php redact | suite <output> | harvest <tapes> <mark> <run list> | live <part> <output> <report> | webhook-id | verdict\n" );
	exit( 2 );
}

exit(
	match ( $argv[1] ) {
		'redact'     => WorkflowStep::redact( STDIN, STDOUT ),
		'suite'      => WorkflowStep::suite( $seocart_gateway_for_paypal_read( 2 ), $seocart_gateway_for_paypal_outputs, STDOUT ),
		'harvest'    => WorkflowStep::harvest( $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', STDOUT ),
		'live'       => WorkflowStep::live( $argv[2] ?? '', $seocart_gateway_for_paypal_read( 3 ), $seocart_gateway_for_paypal_read( 4 ), $seocart_gateway_for_paypal_outputs, STDOUT ),
		'webhook-id' => WorkflowStep::webhookId( (string) getenv( 'PAYPAL_WEBHOOK_ID' ), $seocart_gateway_for_paypal_outputs, STDOUT ),
		'verdict'    => WorkflowStep::verdict( (string) getenv( 'PAYPAL_SANDBOX_NEEDS' ), (string) getenv( 'PAYPAL_SANDBOX_PARTS' ), $seocart_gateway_for_paypal_summary, STDOUT ),
	}
);
