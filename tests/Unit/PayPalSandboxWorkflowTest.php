<?php
/**
 * Tests the shape of the PayPal sandbox workflow, which holds the test account's key, as SEOCart's checks of a live workflow read it
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;
use SEOCart\Tests\Conformance\Payment\Live\SettingsLast;
use SEOCart\Tests\Conformance\Payment\Live\WorkflowFacts;

/**
 * The PayPal sandbox workflow keeps every fact SEOCart's WorkflowFacts reads with the plugin's declaration (tests/live.php): it starts only on its schedule or by hand, has no job named ci, reads the repository and nothing more, runs each job only in this repository, gives a secret only to the env of a step that needs it, never traces the shell, prints the environment or uploads a file, runs one at a time uncancelled, limits every job's time, has as its jobs and as the choices of its parts input the parts the declaration states, and checks SEOCart out before its verdict. Once a job's settings file is written, which holds the sandbox app's secret, the job runs only what SettingsLast's closed lists allow: SEOCart's suite and live steps, and PayPal's configure, checkout and cleanup, which run only commands that cannot install, download or build. Its clean-up lists the checkout site's orders before it voids, and, when it cannot list them, voids what is listed, then fails and keeps what it needs to run again.
 *
 * Pins, the PHP set-up, Composer, the Node.js version and the reference to SEOCart's action are
 * SEOCart's check of workflows' (`sh ../seocart/bin/ci/check-workflows.sh .`), which the required
 * gates run. SEOCart's own tests hold the closed lists to every other way of running code after a
 * settings step, on a fixture extension.
 *
 * Planted violations, each shown red and removed:
 * - `push:` added to the workflow's `on:` in the file: the first test fails naming the triggers;
 * - the three-d-secure job's settings step moved before its set-up action, in the file: the closed
 *   list fails naming the action;
 * - in the file, the harvest left out of the script's cleanup(): the clean-up's test fails;
 * - in the file, the harvest's query piped into `tr` again, or the clean-up removing its folder
 *   whatever became of the harvest: the test of a failed harvest fails.
 *
 * @since 0.2.0
 */
final class PayPalSandboxWorkflowTest extends TestCase {

	/**
	 * The script's steps a job may run after its settings step.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const STEPS = array( 'configure', 'checkout', 'cleanup' );

	/**
	 * The calls of the script that name PayPal, allowed only as written: the gateway's configuration, and the site's orders read for the run list.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const EXACT = array(
		'site_wp seocart gateway configure paypal --mode=test --from-file="$settings" --no-webhooks --user=admin',
		'site_wp db query "SELECT provider_intent_id, uuid FROM ${prefix}seocart_payment_intents WHERE gateway_id = \'paypal\' AND provider_intent_id REGEXP \'^[A-Z0-9]{17}\$\'" --skip-column-names',
	);

	/**
	 * Tests that the workflow as committed keeps every fact.
	 *
	 * @since 0.2.0
	 */
	public function test_the_workflow_keeps_every_fact(): void {
		$this->assertSame( array(), WorkflowFacts::violations( self::declaration(), self::lines() ) );
	}

	/**
	 * Tests that each fact, planted broken, is found.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider plants
	 *
	 * @param string   $expected Text of the violation expected.
	 * @param \Closure $plant    The plant: the lines in, the lines broken out.
	 */
	public function test_each_fact_planted_broken_is_found( string $expected, \Closure $plant ): void {
		$found = implode( "\n", WorkflowFacts::violations( self::declaration(), $plant( self::lines() ) ) );

		$this->assertStringContainsString( $expected, $found );
	}

	/**
	 * Tests that, once a job's settings file is written, the job runs only SEOCart's suite and live steps and the script's steps the lists allow, and those steps only the commands on the lists.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_listed_steps_and_commands_run_once_the_settings_file_is_written(): void {
		$this->assertSame( array(), self::rule()->violations( SettingsLast::sources( self::declaration() ) ) );
	}

	/**
	 * Tests that a step, an action or a command off the lists after a job's settings step is found, in the workflow or in the script.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider afterSettingsPlanted
	 *
	 * @param string   $expected Text of the violation expected.
	 * @param \Closure $plant    The plant: the four texts the rule reads in, broken out.
	 */
	public function test_a_step_or_a_command_off_the_lists_after_the_settings_file_is_found( string $expected, \Closure $plant ): void {
		$found = implode( "\n", self::rule()->violations( $plant( SettingsLast::sources( self::declaration() ) ) ) );

		$this->assertStringContainsString( $expected, $found );
	}

	/**
	 * Returns one plant per way something off the lists could run after a settings file is written, in this workflow's own steps.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, \Closure}> Each violation expected and its plant, by name.
	 */
	public static function afterSettingsPlanted(): array {
		$webhooks  = '/^              run: sh seocart\/bin\/ci\/extension-live.sh live ext webhooks$/';
		$checkout  = '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh checkout$/';
		$workflow  = static fn( \Closure $plant ): \Closure => static fn( array $sources ): array => array( 'workflow' => $plant( $sources['workflow'] ) ) + $sources;
		$script    = static fn( \Closure $plant ): \Closure => static fn( array $sources ): array => array( 'script' => $plant( $sources['script'] ) ) + $sources;
		$not_steps = 'runs, after its settings step, a command that is not one of the script\'s steps';

		return array(
			'the settings before the site is built' => array( 'The job checkout ' . $not_steps . ': `run: sh seocart/bin/ci/extension-live.sh site ext`.', $workflow( static fn( array $lines ): array => self::before( $lines, '/^            - name: Build SEOCart, and install a site/', '            - *settings' ) ) ),
			'composer install after them'           => array( 'The job webhooks ' . $not_steps . ': `run: composer install --working-dir=seocart`.', $workflow( static fn( array $lines ): array => self::after( $lines, $webhooks, '            - run: composer install --working-dir=seocart' ) ) ),
			'a set-up step by its anchor'           => array( 'The job webhooks uses an action after its settings step: `uses: ./seocart/.github/actions/extension-live-setup`.', $workflow( static fn( array $lines ): array => self::after( $lines, $webhooks, '            - *setup' ) ) ),
			'an action of a third party'            => array( 'The job checkout uses an action after its settings step: `uses: some-org/some-action@0123456789abcdef0123456789abcdef01234567`.', $workflow( static fn( array $lines ): array => self::after( $lines, $checkout, '            - uses: some-org/some-action@0123456789abcdef0123456789abcdef01234567' ) ) ),
			'npm ci in configure()'                 => array( 'The step paypal-sandbox.sh configure, which the job checkout runs after its settings step, runs in configure() a command not on the list: `npm ci`.', $script( static fn( array $lines ): array => self::after( $lines, '/^configure\(\) \{$/', "\t(cd \"\$core\" && npm ci)" ) ) ),
			'the zip built in harvest_site()'       => array( 'The step paypal-sandbox.sh cleanup, which the job checkout runs after its settings step, runs in harvest_site() a command not on the list: `php "$core/bin/build-zip.php" --plugin="$ext"`.', $script( static fn( array $lines ): array => self::after( $lines, '/^harvest_site\(\) \{$/', "\tphp \"\$core/bin/build-zip.php\" --plugin=\"\$ext\"" ) ) ),
		);
	}

	/**
	 * Tests that the clean-up puts the site's PayPal orders on the run list before it voids, so that a checkout step that failed or ran out of time before its own end leaves nothing held.
	 *
	 * @since 0.2.0
	 */
	public function test_the_cleanup_harvests_the_site_before_it_voids(): void {
		$this->assertNull( self::cleanupHarvestsFirst( self::script() ) );
	}

	/**
	 * Tests that a clean-up that voids without harvesting first is found.
	 *
	 * @since 0.2.0
	 */
	public function test_a_cleanup_that_does_not_harvest_first_is_found(): void {
		$this->assertSame( 'cleanup() voids what the run list names without putting the site\'s orders on it first.', self::cleanupHarvestsFirst( self::without( self::script(), '/^\t\t\tharvest_site \|\| /' ) ) );
	}

	/**
	 * Tests that the clean-up voids what the run list names whatever became of the site's harvest, and that, when the site's orders could not be put on the list, it then fails, saying why, and keeps the settings file, the run list and the site.
	 *
	 * The script's cleanup step runs for real, its RUNNER_TEMP a folder of the test's own holding a settings file, a run
	 * list naming one order, the site's file and its folder, and WP-CLI and PHP stood in for at the head of its PATH:
	 * WP-CLI answers as the case says, and PHP, in the place of the voiding, keeps the run list it is given.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider harvests
	 *
	 * @param string $wp     The stand-in WP-CLI's script.
	 * @param bool   $failed Whether the harvest fails.
	 * @param string $voided The run list the voiding is given.
	 * @param string $said   Text the step prints.
	 */
	public function test_the_cleanup_voids_what_is_listed_and_fails_when_the_harvest_fails( string $wp, bool $failed, string $voided, string $said ): void {
		$root   = sys_get_temp_dir() . '/paypal-sandbox-cleanup-' . bin2hex( random_bytes( 8 ) );
		$work   = $root . '/seocart-live';
		$listed = "LISTED00000000001 0199c0de-0000-7000-8000-00000000a001\n";

		try {
			mkdir( $root . '/bin', 0700, true );
			mkdir( $work . '/site', 0700, true );
			file_put_contents( $work . '/settings.json', "{}\n" );
			file_put_contents( $work . '/site.env', "WP_BASE_URL=http://127.0.0.1:9\n" );
			file_put_contents( $work . '/run-list', $listed );
			file_put_contents( $root . '/bin/wp', "#!/bin/sh\n" . $wp );
			file_put_contents( $root . '/bin/php', "#!/bin/sh\ncat \"\$3\" >\"\$SEOCART_TEST_VOIDED\"\n" );
			chmod( $root . '/bin/wp', 0700 );
			chmod( $root . '/bin/php', 0700 );

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- the script's own step, with stand-ins of the test's own.
			exec( sprintf( 'env PATH=%1$s RUNNER_TEMP=%2$s SEOCART_TEST_VOIDED=%3$s sh %4$s cleanup 2>&1', escapeshellarg( $root . '/bin:' . getenv( 'PATH' ) ), escapeshellarg( $root ), escapeshellarg( $root . '/voided' ), escapeshellarg( dirname( __DIR__, 2 ) . '/bin/ci/paypal-sandbox.sh' ) ), $output, $status );
			$printed = implode( "\n", $output );

			$this->assertSame( $failed, 0 !== $status, 'The step fails when the harvest failed, and only then: ' . $printed );
			$this->assertStringContainsString( $said, $printed );
			$this->assertSame( $voided, is_file( $root . '/voided' ) ? file_get_contents( $root . '/voided' ) : 'nothing', 'The voiding is given the run list.' );
			$this->assertSame( $failed ? $listed : 'nothing', is_file( $work . '/settings.json' ) && is_dir( $work . '/site' ) ? file_get_contents( $work . '/run-list' ) : 'nothing', 'The settings file, the run list and the site are kept when the harvest failed, and only then.' );
		} finally {
			self::remove( $root );
		}
	}

	/**
	 * Returns the ways the clean-up's harvest can go: the site's folder missing, as when WP-CLI fails on every command; the database's prefix read and its query refused; and the harvest done.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, bool, string, string}> The stand-in WP-CLI's script, whether the harvest fails, the run list the voiding is given and text the step prints; by name.
	 */
	public static function harvests(): array {
		$refused = "echo 'Error: This does not seem to be a WordPress installation.' >&2\nexit 1\n";
		$prefix  = "[ \"\$2 \$3\" = 'db prefix' ] && echo wp_ && exit 0\n";
		$listed  = "LISTED00000000001 0199c0de-0000-7000-8000-00000000a001\n";
		$error   = '::error::The site\'s PayPal orders could not be put on the run list';

		return array(
			'the site\'s folder missing' => array( $refused, true, $listed, $error ),
			'its query refused'          => array( $prefix . $refused, true, $listed, $error ),
			'the harvest done'           => array( $prefix . "printf 'HARVESTED00000002\\t0199c0de-0000-7000-8000-00000000a002\\n'\n", false, "HARVESTED00000002 0199c0de-0000-7000-8000-00000000a002\n" . $listed, '2 PayPal orders on the run list.' ),
		);
	}

	/**
	 * Tests that a workflow whose expressions cannot be read is a violation, not a pass.
	 *
	 * The regular expression gives up, and returns false, when PCRE's backtrack limit is lowered and
	 * its JIT is off. A pattern already compiled in this process keeps its JIT, so a PHP process of
	 * its own reads the workflow with both settings from its first use of the pattern, SEOCart's live
	 * classes and the declaration required by path.
	 *
	 * @since 0.2.0
	 */
	public function test_expressions_that_cannot_be_read_are_a_violation(): void {
		$code = sprintf(
			'foreach ( glob( %1$s ) as $class ) { require_once $class; } echo implode( "\n", \%2$s::violations( \%3$s::load( %4$s ), file( %5$s, FILE_IGNORE_NEW_LINES ) ) );',
			var_export( dirname( (string) ( new \ReflectionClass( WorkflowFacts::class ) )->getFileName() ) . '/[A-Z]*.php', true ),
			WorkflowFacts::class,
			LiveDeclaration::class,
			var_export( dirname( __DIR__, 2 ), true ),
			var_export( self::workflow(), true )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- a PHP process of its own, run with fixed settings.
		exec( escapeshellarg( PHP_BINARY ) . ' -d pcre.jit=0 -d pcre.backtrack_limit=10 -d max_execution_time=30 -r ' . escapeshellarg( $code ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );

		$this->assertStringContainsString( 'The expressions of the workflow could not be read', implode( "\n", $output ) );
	}

	/**
	 * Returns one plant per fact.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, \Closure}> Each violation expected and its plant, by name.
	 */
	public static function plants(): array {
		$heads = '/^              run: sh seocart\/bin\/ci\/extension-live.sh heads ext$/';

		return array(
			'a push trigger'                 => array( 'The workflow starts on push, schedule, workflow_dispatch', static fn( array $lines ): array => self::after( $lines, '/^on:$/', '    push:' ) ),
			'a pull request trigger'         => array( 'The workflow starts on pull_request, schedule, workflow_dispatch', static fn( array $lines ): array => self::after( $lines, '/^on:$/', '    pull_request:' ) ),
			'a job named ci'                 => array( 'a job is named ci', static fn( array $lines ): array => self::after( $lines, '/^jobs:$/', '    ci:' ) ),
			'a wider permission'             => array( 'the permissions are not exactly `contents: read`', static fn( array $lines ): array => self::after( $lines, '/^    contents: read$/', '    issues: write' ) ),
			'a job writing everything'       => array( 'the permissions are not exactly `contents: read`', static fn( array $lines ): array => self::after( $lines, '/^    verdict:$/', '        permissions: write-all' ) ),
			'a job without the guard'        => array( 'the job three-d-secure does not run only in', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*three-d-secure/', "        if: contains(fromJSON('[\"all\", \"three-d-secure\"]'), inputs.parts || 'all')" ) ),
			'a secret in a run'              => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ secrets.PAYPAL_SECRET_KEY }}"' ) ),
			'a secret in a job\'s env'       => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( self::after( $lines, '/^        timeout-minutes: 30$/', '        env:' ), '/^        env:$/', '            PAYPAL_KEY: ${{ secrets.PAYPAL_SECRET_KEY }}' ) ),
			'a secret in an action\'s with'  => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( $lines, '/^                  persist-credentials: false$/', '                  token: ${{ secrets.PAYPAL_SECRET_KEY }}' ) ),
			'a secret in brackets in a run'  => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ secrets[\'PAYPAL_SECRET_KEY\'] }}"' ) ),
			'a secret in brackets in env'    => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^                  PAYPAL_SECRET_KEY: /', '                  PAYPAL_SECRET_KEY: ${{ secrets[\'PAYPAL_SECRET_KEY\'] }}' ) ),
			'every secret as JSON'           => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^                  SEOCART_LIVE_NEEDS: /', '                  SEOCART_LIVE_NEEDS: ${{ toJSON(secrets) }}' ) ),
			'a secret in capitals'           => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ SECRETS.PAYPAL_SECRET_KEY }}"' ) ),
			'a secret in a shell comment'    => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( $lines, '/^                  if \[ -n "\$CORE_REF" \]; then$/', '                  # ${{ secrets.PAYPAL_SECRET_KEY }}' ) ),
			'a secret over two lines'        => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( self::replaced( $lines, $heads, '              run: echo "${{' ), '/^              run: echo "\$\{\{$/', '                  secrets.PAYPAL_SECRET_KEY }}"' ) ),
			'a secret after a quoted }}'     => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ format(\'}}{0}\', secrets.PAYPAL_SECRET_KEY) }}"' ) ),
			'a quoted }} past an escape'     => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ format(\'it\'\'s }}{0}\', secrets.PAYPAL_SECRET_KEY) }}"' ) ),
			'a traced shell'                 => array( 'holds `set -x`', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: set -x; sh seocart/bin/ci/extension-live.sh heads ext' ) ),
			'the environment printed'        => array( 'holds `printenv`', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: printenv' ) ),
			'an upload'                      => array( 'holds `upload-artifact`', static fn( array $lines ): array => self::after( $lines, '/^            - \*checkout-extension$/', '            - uses: actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02 # v4.6.2' ) ),
			'a run cancelled by the next'    => array( 'with cancel-in-progress: false', static fn( array $lines ): array => self::replaced( $lines, '/^    cancel-in-progress: false$/', '    cancel-in-progress: true' ) ),
			'another concurrency group'      => array( 'is not the group paypal-sandbox', static fn( array $lines ): array => self::replaced( $lines, '/^    group: paypal-sandbox$/', '    group: ${{ github.workflow }}-${{ github.ref }}' ) ),
			'a job without a time limit'     => array( 'the job verdict has no timeout-minutes', static fn( array $lines ): array => self::without( $lines, '/^        timeout-minutes: 5$/' ) ),
			'a cli job'                      => array( 'The jobs are checkout, cli, live-conformance, three-d-secure, verdict, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^jobs:$/', '    cli:' ) ),
			'a job removed'                  => array( 'The jobs are checkout, live-conformance, three-d-secure, verdict, not exactly', static fn( array $lines ): array => self::without( $lines, '/^    webhooks:$/' ) ),
			'a cli choice'                   => array( 'The parts input offers all, checkout, cli, conformance, three-d-secure, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli' ) ),
			'a part without a choice'        => array( 'The parts input offers all, checkout, conformance, three-d-secure, not exactly', static fn( array $lines ): array => self::without( $lines, '/^                    - webhooks$/' ) ),
			'a verdict missing a need'       => array( 'the verdict needs live-conformance, three-d-secure, not exactly checkout, live-conformance, three-d-secure, webhooks.', static fn( array $lines ): array => self::replaced( $lines, '/^        needs: /', '        needs: [live-conformance, three-d-secure]' ) ),
			'a condition without all'        => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && contains(fromJSON('[\"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a condition on another part'    => array( 'the job three-d-secure has no condition fromJSON(\'["all", "three-d-secure"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"three-d-secure"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && contains(fromJSON('[\"all\", \"cli\"]'), inputs.parts || 'all')" ) ),
			'a condition on no part'         => array( 'the job live-conformance has no condition fromJSON(\'["all", "conformance"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"conformance"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal'" ) ),
			'a verdict job removed'          => array( 'The jobs are checkout, live-conformance, three-d-secure, webhooks, not exactly checkout, live-conformance, three-d-secure, verdict, webhooks.', static fn( array $lines ): array => self::without( $lines, '/^    verdict:$/' ) ),
			'a verdict without always'       => array( 'its if must hold always()', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal'" ) ),
			'a cli choice with a comment'    => array( 'The parts input offers all, checkout, cli, conformance, three-d-secure, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli # back' ) ),
			'a condition with no fallback'   => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts)" ) ),
			'a condition and false'          => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all') && false" ) ),
			'a condition false first'        => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: false && github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a negated condition'            => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && !contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a condition in a comment'       => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' # contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a continued condition'          => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::after( $lines, '/^        if: .*"webhooks"/', '            && false' ) ),
			'a verdict that never runs'      => array( 'its if must hold always(), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: always() && github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && false" ) ),
			'always only in a comment'       => array( 'its if must hold always(), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' # always()" ) ),
			'a choice with a space'          => array( 'The parts input offers - cli session, all, checkout, conformance, three-d-secure, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli session' ) ),
			'a choice with a hash'           => array( 'The parts input offers all, checkout, conformance, three-d-secure, webhooks#2, not exactly', static fn( array $lines ): array => self::replaced( $lines, '/^                    - webhooks$/', '                    - webhooks#2' ) ),
			'another repository checked out' => array( 'the verdict does not check SEOCart (cirkuitnet/seocart) out before its step', static fn( array $lines ): array => self::replaced( $lines, '/^                  repository: cirkuitnet\/seocart$/', '                  repository: cirkuitnet/seocart-fork' ) ),
		);
	}

	/**
	 * Says why the script's cleanup() could void before the site's orders are on the run list, or null when it cannot.
	 *
	 * The function's lines are read from `cleanup() {` to the first `}` that starts a line: the line
	 * that runs harvest_site must come before the one that runs the voiding's PHP.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $script The script's lines.
	 * @return string|null Why; null when the harvest comes first.
	 * @phpstan-param list<string> $script
	 */
	private static function cleanupHarvestsFirst( array $script ): ?string {
		$start = array_search( 'cleanup() {', $script, true );
		$body  = array();

		if ( false !== $start ) {
			$body = array_slice( $script, $start + 1 );
			$body = array_slice( $body, 0, (int) array_search( '}', $body, true ) );
		}

		$void = array_keys( preg_grep( '/^\s*php "\$ext\/tests\/Integration\/Live\/cleanup\.php"/', $body ) );
		$reap = array_keys( preg_grep( '/^\s*harvest_site\b/', $body ) );

		return array() !== $void && array() !== $reap && $reap[0] < $void[0] ? null : 'cleanup() voids what the run list names without putting the site\'s orders on it first.';
	}

	/**
	 * Removes a file or a folder of the test's own, and everything in it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path The file or folder.
	 */
	private static function remove( string $path ): void {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $name ) {
				self::remove( $path . '/' . $name );
			}

			rmdir( $path );
		} elseif ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * Returns the plugin's declaration of its live workflow.
	 *
	 * @since 0.2.0
	 *
	 * @return LiveDeclaration The declaration.
	 */
	private static function declaration(): LiveDeclaration {
		return LiveDeclaration::load( dirname( __DIR__, 2 ) );
	}

	/**
	 * Returns SEOCart's rule of the settings file, with the script's steps and calls this workflow runs after it.
	 *
	 * @since 0.2.0
	 *
	 * @return SettingsLast The rule.
	 */
	private static function rule(): SettingsLast {
		return new SettingsLast( self::declaration(), self::STEPS, self::EXACT );
	}

	/**
	 * Returns the workflow's file.
	 *
	 * @since 0.2.0
	 *
	 * @return string The file.
	 */
	private static function workflow(): string {
		return dirname( __DIR__, 2 ) . '/.github/workflows/' . self::declaration()->workflow();
	}

	/**
	 * Returns the lines of the provider's script, bin/ci/paypal-sandbox.sh.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The lines.
	 */
	private static function script(): array {
		$lines = file( dirname( __DIR__, 2 ) . '/' . self::declaration()->script(), FILE_IGNORE_NEW_LINES );

		self::assertIsArray( $lines, 'The script cannot be read.' );

		return $lines;
	}

	/**
	 * Returns the workflow's lines.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The lines.
	 */
	private static function lines(): array {
		$lines = file( self::workflow(), FILE_IGNORE_NEW_LINES );

		self::assertIsArray( $lines, 'The workflow cannot be read.' );

		return $lines;
	}

	/**
	 * Inserts a line after the first that matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The line after which it goes.
	 * @param string   $line    The line.
	 * @return list<string> The lines, planted.
	 * @phpstan-param list<string> $lines
	 */
	private static function after( array $lines, string $pattern, string $line ): array {
		array_splice( $lines, self::find( $lines, $pattern ) + 1, 0, array( $line ) );

		return $lines;
	}

	/**
	 * Inserts a line before the first that matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The line before which it goes.
	 * @param string   $line    The line.
	 * @return list<string> The lines, planted.
	 * @phpstan-param list<string> $lines
	 */
	private static function before( array $lines, string $pattern, string $line ): array {
		array_splice( $lines, self::find( $lines, $pattern ), 0, array( $line ) );

		return $lines;
	}

	/**
	 * Replaces the first line that matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The line replaced.
	 * @param string   $line    The line put in its place.
	 * @return list<string> The lines, planted.
	 * @phpstan-param list<string> $lines
	 */
	private static function replaced( array $lines, string $pattern, string $line ): array {
		$lines[ self::find( $lines, $pattern ) ] = $line;

		return $lines;
	}

	/**
	 * Removes the first line that matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The line removed.
	 * @return list<string> The lines, planted.
	 * @phpstan-param list<string> $lines
	 */
	private static function without( array $lines, string $pattern ): array {
		array_splice( $lines, self::find( $lines, $pattern ), 1 );

		return $lines;
	}

	/**
	 * Finds the first line that matches, failing the test when none does: a plant must break the file.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The pattern.
	 * @return int The line's index.
	 * @phpstan-param list<string> $lines
	 */
	private static function find( array $lines, string $pattern ): int {
		foreach ( $lines as $at => $line ) {
			if ( 1 === preg_match( $pattern, $line ) ) {
				return $at;
			}
		}

		self::fail( sprintf( 'The plant found no line matching %s.', $pattern ) );
	}
}
