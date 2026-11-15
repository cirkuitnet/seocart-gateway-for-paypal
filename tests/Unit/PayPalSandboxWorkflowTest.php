<?php
/**
 * Tests the shape of the PayPal sandbox workflow, which holds the test account's key
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForPayPal\Tests\Support\Live\WorkflowFacts;

/**
 * The PayPal sandbox workflow starts only on its schedule or by hand, has no job named ci, reads the repository and nothing more, runs each job only in this repository, gives a secret only to a step's env, never traces the shell, prints the environment or uploads a file, runs one at a time uncancelled, limits every job's time, and has as its jobs and as the choices of its parts input the parts the verdict judges and no other, and, once a job's settings file is written, which holds the sandbox app's secret, runs only the script's own steps, which run only commands that cannot install, download or build; and its clean-up lists the checkout site's orders before it voids, and, when it cannot list them, voids what is listed, then fails and keeps what it needs to run again. Each fact is planted broken, in memory, and found.
 *
 * Pins, the PHP set-up, Composer and the Node.js version are SEOCart's check of workflows'
 * (`sh ../seocart/bin/ci/check-workflows.sh .`), which the required gates run.
 *
 * Planted violations, each shown red and removed:
 * - `push:` added to the workflow's `on:` in the file: the first test fails naming the triggers;
 * - a `cli` job, then a `cli` choice of the `parts` input, added back to the file, and the verdict's
 *   `needs` or a part's `if` changed in it: the first test fails naming the jobs, the choices, the
 *   condition or the needs;
 * - in WorkflowFacts::secrets(), only the lines holding `secrets.` read, and no comment's line: the
 *   bracket form, `toJSON(secrets)`, the capitals and the shell comment are not found;
 * - in WorkflowFacts::secrets(), an expression ended at its first `}}`, quoted or not: the secrets
 *   after a quoted `}}` are not found;
 * - in the file, after a job's settings step: the site's step; `npm i`; `pip install`; a set-up
 *   step by its anchor; WordPress's download; a third party's action; and in the script, in the
 *   functions those steps reach: `npm ci` and `npx --yes` in configure(), the site's step run
 *   through filtered(): the test of the lists fails naming each;
 * - in the file, the harvest left out of the script's cleanup(): the clean-up's test fails;
 * - in the file, the harvest's query piped into `tr` again, or the clean-up removing its folder
 *   whatever became of the harvest: the test of a failed harvest fails.
 *
 * @since 0.2.0
 */
final class PayPalSandboxWorkflowTest extends TestCase {

	/**
	 * The script's steps a job may run once its settings file is written, as a step's `run:` names them: the
	 * configuration of the site's gateway, the checkout's browser tests, the suite, a part's live tests and the clean-up.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const STEPS_AFTER_SETTINGS = array( 'configure', 'checkout', 'conformance', 'live three-d-secure', 'live webhooks', 'cleanup' );

	/**
	 * The keys such a step may have: none that runs an action, or gives a shell, a working directory or an environment.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const STEP_KEYS = array( 'name', 'id', 'if', 'run' );

	/**
	 * The only commands the functions those steps reach may run, as patterns of a simple command once its assignments,
	 * its `env` and its redirections are put aside. None can install, download or build anything:
	 *
	 * - the shell's own words and the POSIX tools for text and files, which run no code they are not given;
	 * - PHP running this checkout's own helpers, or the PHPUnit SEOCart's set-up installed before the settings step;
	 * - SEOCart's conformance suite, which runs that PHPUnit;
	 * - WP-CLI on the site, through site_wp(), for the gateway's configuration and the database's prefix and one read:
	 *   never `core download` nor `plugin install`;
	 * - jq, which rewrites the settings file;
	 * - the Playwright the browser step installed, running the tests: never npx, which fetches a package it lacks;
	 * - the site's file of its address and administrator, which the site step wrote, read into the shell;
	 * - a wrapper's own run of the command its caller gave it, which is checked at the call (WRAPPERS).
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const COMMANDS = array(
		'/^(\[|test|true|false|printf|echo|cat|grep|sed|sort|tail|tee|tr|cut|mkdir|touch|sleep|rm|mv|chmod|kill|date|umask|cd|set|export|shift|return|exit)( |$)/',
		'/^php "\$(step_php|ext\/tests\/Integration\/Live\/cleanup\.php|core\/vendor\/bin\/phpunit)"( |$)/',
		'/^sh "\$core\/bin\/ci\/extension\.sh" conformance( |$)/',
		'/^wp --path="\$site_dir" "\$@"$/',
		'/^site_wp (seocart gateway configure|db prefix|db query)( |$)/',
		'/^jq( |$)/',
		'/^node_modules\/\.bin\/playwright test( |$)/',
		'/^\. "\$site_env"$/',
	);

	/**
	 * The script's functions that run the command their caller gives them, by how many arguments of their own come first:
	 * a call of one is checked as the command those arguments are followed by. Any other function that runs `"$@"` is a
	 * violation, as its callers' commands would go unchecked.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, int>
	 */
	private const WRAPPERS = array( 'filtered' => 1 );

	/**
	 * The words that open a compound command or join its parts, put aside before a command is read.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const KEYWORDS = array( 'if', 'then', 'else', 'elif', 'fi', 'do', 'done', 'while', 'until', 'esac', '!', '{', '}' );

	/**
	 * Tests that the workflow as committed keeps every fact.
	 *
	 * @since 0.2.0
	 */
	public function test_the_workflow_keeps_every_fact(): void {
		$this->assertSame( array(), WorkflowFacts::violations( self::lines() ) );
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
		$found = implode( "\n", WorkflowFacts::violations( $plant( self::lines() ) ) );

		$this->assertStringContainsString( $expected, $found );
	}

	/**
	 * Tests that, once a job's settings file is written, the job runs only the script's own steps, and those steps only the commands on the list.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_listed_steps_and_commands_run_once_the_settings_file_is_written(): void {
		$this->assertSame( array(), self::afterSettings( self::lines(), self::script() ) );
	}

	/**
	 * Tests that a step, an action or a command off the lists after a job's settings step is found, in the workflow or in the script.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider afterSettingsPlanted
	 *
	 * @param string   $expected Text of the violation expected.
	 * @param \Closure $plant    The plant: the workflow's and the script's lines in, both broken out.
	 */
	public function test_a_step_or_a_command_off_the_lists_after_the_settings_file_is_found( string $expected, \Closure $plant ): void {
		[ $lines, $script ] = $plant( self::lines(), self::script() );

		$this->assertStringContainsString( $expected, implode( "\n", self::afterSettings( $lines, $script ) ) );
	}

	/**
	 * Returns one plant per way something off the lists could run after a settings file is written.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, \Closure}> Each violation expected and its plant, by name.
	 */
	public static function afterSettingsPlanted(): array {
		$script = static fn( string $after, string $line ): \Closure => static fn( array $lines, array $script ): array => array( $lines, self::after( $script, $after, $line ) );
		$step   = static fn( string $after, string $line ): \Closure => static fn( array $lines, array $script ): array => array( self::after( $lines, $after, $line ), $script );

		return array(
			'the settings before the site is built' => array( 'The job checkout runs, after its settings step, a command that is not one of the script\'s steps: `run: sh ext/bin/ci/paypal-sandbox.sh site`.', $step( '/^            - \*browser-unit$/', '            - *settings' ) ),
			'composer install after them'           => array( 'The job webhooks runs, after its settings step, a command that is not one of the script\'s steps: `run: composer install --working-dir=seocart`.', $step( '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh live webhooks$/', '            - run: composer install --working-dir=seocart' ) ),
			'npm i under its alias'                 => array( 'The job live-conformance runs, after its settings step, a command that is not one of the script\'s steps: `run: npm i --prefix ext/tests/browser`.', $step( '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh conformance$/', '            - run: npm i --prefix ext/tests/browser' ) ),
			'pip install'                           => array( 'The job three-d-secure runs, after its settings step, a command that is not one of the script\'s steps: `run: pip install requests`.', $step( '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh live three-d-secure$/', '            - run: pip install requests' ) ),
			'a set-up step by its anchor'           => array( 'The job webhooks runs `- *setup-node` after its settings step: only the script\'s own steps may follow it.', $step( '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh live webhooks$/', '            - *setup-node' ) ),
			'WordPress downloaded'                  => array( 'The job checkout runs, after its settings step, a command that is not one of the script\'s steps: `run: sh seocart/bin/ci/prepare-integration.sh "$WORDPRESS_VERSION"`.', $step( '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh checkout$/', '            - run: sh seocart/bin/ci/prepare-integration.sh "$WORDPRESS_VERSION"' ) ),
			'an action of a third party'            => array( 'The job checkout uses an action after its settings step: `uses: some-org/some-action@0123456789abcdef0123456789abcdef01234567`.', $step( '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh checkout$/', '            - uses: some-org/some-action@0123456789abcdef0123456789abcdef01234567' ) ),
			'npm ci in configure()'                 => array( 'The step configure, which the job checkout runs after its settings step, runs in configure() a command not on the list: `npm ci`.', $script( '/^configure\(\) \{$/', "\t(cd \"\$core\" && npm ci)" ) ),
			'npx fetching a package in configure()' => array( 'The step configure, which the job checkout runs after its settings step, runs in configure() a command not on the list: `npx --yes cowsay hello`.', $script( '/^configure\(\) \{$/', "\tnpx --yes cowsay hello" ) ),
			'the zip built in harvest_site()'       => array( 'The step cleanup, which the job checkout runs after its settings step, runs in harvest_site() a command not on the list: `php "$core/bin/build-zip.php" --plugin="$ext"`.', $script( '/^harvest_site\(\) \{$/', "\tphp \"\$core/bin/build-zip.php\" --plugin=\"\$ext\"" ) ),
			'site() run through filtered()'         => array( 'The step checkout, which the job checkout runs after its settings step, runs in site() a command not on the list: `npm ci`.', $script( '/^checkout\(\) \{$/', "\tfiltered \"\$work/x.out\" site" ) ),
			'a pass-through of its own'             => array( 'The step checkout, which the job checkout runs after its settings step, runs in checkout() a command not on the list: `heads npm ci`.', static fn( array $lines, array $script ): array => array( $lines, self::after( self::after( $script, '/^checkout\(\) \{$/', "\theads npm ci" ), '/^heads\(\) \{$/', "\t\"\$@\"" ) ) ),
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
		$work   = $root . '/paypal-sandbox';
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
	 * its own reads the workflow with both settings from its first use of the pattern.
	 *
	 * @since 0.2.0
	 */
	public function test_expressions_that_cannot_be_read_are_a_violation(): void {
		$support = __DIR__ . '/../Support/Live/';
		$code    = sprintf(
			'require %1$s; require %2$s; require %3$s; echo implode( "\n", \%4$s::violations( file( %5$s, FILE_IGNORE_NEW_LINES ) ) );',
			var_export( $support . 'MessageGuard.php', true ),
			var_export( $support . 'Verdict.php', true ),
			var_export( $support . 'WorkflowFacts.php', true ),
			WorkflowFacts::class,
			var_export( dirname( __DIR__, 2 ) . '/.github/workflows/paypal-sandbox.yml', true )
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
		return array(
			'a push trigger'                => array( 'The workflow starts on push, schedule, workflow_dispatch', static fn( array $lines ): array => self::after( $lines, '/^on:$/', '    push:' ) ),
			'a pull request trigger'        => array( 'The workflow starts on pull_request, schedule, workflow_dispatch', static fn( array $lines ): array => self::after( $lines, '/^on:$/', '    pull_request:' ) ),
			'a job named ci'                => array( 'a job is named ci', static fn( array $lines ): array => self::after( $lines, '/^jobs:$/', '    ci:' ) ),
			'a wider permission'            => array( 'the permissions are not exactly `contents: read`', static fn( array $lines ): array => self::after( $lines, '/^    contents: read$/', '    issues: write' ) ),
			'a job writing everything'      => array( 'the permissions are not exactly `contents: read`', static fn( array $lines ): array => self::after( $lines, '/^    verdict:$/', '        permissions: write-all' ) ),
			'a job without the guard'       => array( 'the job three-d-secure does not run only in', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*three-d-secure/', "        if: contains(fromJSON('[\"all\", \"three-d-secure\"]'), inputs.parts || 'all')" ) ),
			'a secret in a run'             => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh heads$/', '              run: echo "${{ secrets.PAYPAL_SECRET_KEY }}"' ) ),
			'a secret in a job\'s env'      => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( self::after( $lines, '/^        timeout-minutes: 30$/', '        env:' ), '/^        env:$/', '            PAYPAL_KEY: ${{ secrets.PAYPAL_SECRET_KEY }}' ) ),
			'a secret in an action\'s with' => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( $lines, '/^                  persist-credentials: false$/', '                  token: ${{ secrets.PAYPAL_SECRET_KEY }}' ) ),
			'a secret in brackets in a run' => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh heads$/', '              run: echo "${{ secrets[\'PAYPAL_SECRET_KEY\'] }}"' ) ),
			'a secret in brackets in env'   => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^                  PAYPAL_SECRET_KEY: /', '                  PAYPAL_SECRET_KEY: ${{ secrets[\'PAYPAL_SECRET_KEY\'] }}' ) ),
			'every secret as JSON'          => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^                  PAYPAL_SANDBOX_NEEDS: /', '                  PAYPAL_SANDBOX_NEEDS: ${{ toJSON(secrets) }}' ) ),
			'a secret in capitals'          => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh heads$/', '              run: echo "${{ SECRETS.PAYPAL_SECRET_KEY }}"' ) ),
			'a secret in a shell comment'   => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( $lines, '/^                  if \[ -n "\$CORE_REF" \]; then$/', '                  # ${{ secrets.PAYPAL_SECRET_KEY }}' ) ),
			'a secret over two lines'       => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh heads$/', '              run: echo "${{' ), '/^              run: echo "\$\{\{$/', '                  secrets.PAYPAL_SECRET_KEY }}"' ) ),
			'a secret after a quoted }}'    => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh heads$/', '              run: echo "${{ format(\'}}{0}\', secrets.PAYPAL_SECRET_KEY) }}"' ) ),
			'a quoted }} past an escape'    => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh heads$/', '              run: echo "${{ format(\'it\'\'s }}{0}\', secrets.PAYPAL_SECRET_KEY) }}"' ) ),
			'a traced shell'                => array( 'holds `set -x`', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh heads$/', '              run: set -x; sh ext/bin/ci/paypal-sandbox.sh heads' ) ),
			'the environment printed'       => array( 'holds `printenv`', static fn( array $lines ): array => self::replaced( $lines, '/^              run: sh ext\/bin\/ci\/paypal-sandbox.sh heads$/', '              run: printenv' ) ),
			'an upload'                     => array( 'holds `upload-artifact`', static fn( array $lines ): array => self::after( $lines, '/^            - \*checkout-extension$/', '            - uses: actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02 # v4.6.2' ) ),
			'a run cancelled by the next'   => array( 'with cancel-in-progress: false', static fn( array $lines ): array => self::replaced( $lines, '/^    cancel-in-progress: false$/', '    cancel-in-progress: true' ) ),
			'another concurrency group'     => array( 'is not the group paypal-sandbox', static fn( array $lines ): array => self::replaced( $lines, '/^    group: paypal-sandbox$/', '    group: ${{ github.workflow }}-${{ github.ref }}' ) ),
			'a job without a time limit'    => array( 'the job verdict has no timeout-minutes', static fn( array $lines ): array => self::without( $lines, '/^        timeout-minutes: 5$/' ) ),
			'a cli job'                     => array( 'The jobs are checkout, cli, live-conformance, three-d-secure, verdict, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^jobs:$/', '    cli:' ) ),
			'a job removed'                 => array( 'The jobs are checkout, live-conformance, three-d-secure, verdict, not exactly', static fn( array $lines ): array => self::without( $lines, '/^    webhooks:$/' ) ),
			'a cli choice'                  => array( 'The parts input offers all, checkout, cli, conformance, three-d-secure, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli' ) ),
			'a part without a choice'       => array( 'The parts input offers all, checkout, conformance, three-d-secure, not exactly', static fn( array $lines ): array => self::without( $lines, '/^                    - webhooks$/' ) ),
			'a verdict missing a need'      => array( 'the verdict needs live-conformance, three-d-secure, not exactly checkout, live-conformance, three-d-secure, webhooks.', static fn( array $lines ): array => self::replaced( $lines, '/^        needs: /', '        needs: [live-conformance, three-d-secure]' ) ),
			'a condition without all'       => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && contains(fromJSON('[\"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a condition on another part'   => array( 'the job three-d-secure has no condition fromJSON(\'["all", "three-d-secure"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"three-d-secure"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && contains(fromJSON('[\"all\", \"cli\"]'), inputs.parts || 'all')" ) ),
			'a condition on no part'        => array( 'the job live-conformance has no condition fromJSON(\'["all", "conformance"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"conformance"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal'" ) ),
			'a verdict job removed'         => array( 'The jobs are checkout, live-conformance, three-d-secure, webhooks, not exactly checkout, live-conformance, three-d-secure, verdict, webhooks.', static fn( array $lines ): array => self::without( $lines, '/^    verdict:$/' ) ),
			'a verdict without always'      => array( 'its if must hold always()', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal'" ) ),
			'a cli choice with a comment'   => array( 'The parts input offers all, checkout, cli, conformance, three-d-secure, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli # back' ) ),
			'a condition with no fallback'  => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts)" ) ),
			'a condition and false'         => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all') && false" ) ),
			'a condition false first'       => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: false && github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a negated condition'           => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && !contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a condition in a comment'      => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' # contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a continued condition'         => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::after( $lines, '/^        if: .*"webhooks"/', '            && false' ) ),
			'a verdict that never runs'     => array( 'its if must hold always(), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: always() && github.repository == 'cirkuitnet/seocart-gateway-for-paypal' && false" ) ),
			'always only in a comment'      => array( 'its if must hold always(), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: github.repository == 'cirkuitnet/seocart-gateway-for-paypal' # always()" ) ),
			'a choice with a space'         => array( 'The parts input offers - cli session, all, checkout, conformance, three-d-secure, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli session' ) ),
			'a choice with a hash'          => array( 'The parts input offers all, checkout, conformance, three-d-secure, webhooks#2, not exactly', static fn( array $lines ): array => self::replaced( $lines, '/^                    - webhooks$/', '                    - webhooks#2' ) ),
		);
	}

	/**
	 * Returns every way a job runs, once its settings file is written, anything but the script's own steps, and every command those steps reach in bin/ci/paypal-sandbox.sh that is not on the list.
	 *
	 * The settings file holds the sandbox app's secret, so it is written last: a package's install
	 * script, a build, a downloaded browser or an action would run with the file readable otherwise.
	 * The lists are closed: after the settings step a job may run only the script's steps
	 * (STEPS_AFTER_SETTINGS) and the clean-up's anchor, with no action at all; and the functions
	 * those steps reach, followed through every call and every function named as an argument, may
	 * run only the commands COMMANDS allows, a wrapper's command checked at its call (WRAPPERS).
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines  The workflow's lines.
	 * @param string[] $script The lines of bin/ci/paypal-sandbox.sh.
	 * @return list<string> The violations; none when nothing but the allowed runs then.
	 * @phpstan-param list<string> $lines
	 * @phpstan-param list<string> $script
	 */
	private static function afterSettings( array $lines, array $script ): array {
		$violations = array();
		$steps      = array();

		foreach ( self::jobSteps( $lines ) as $job => $items ) {
			$after = false;

			foreach ( $items as $item ) {
				if ( in_array( $item['alias'], array( '*settings', '&settings' ), true ) ) {
					$after = true;
					continue;
				}

				if ( ! $after ) {
					continue;
				}

				$step = self::scriptStep( $job, $item, $violations );

				if ( null !== $step ) {
					$steps[ $step ][ $job ] = true;
				}
			}
		}

		$functions = self::functions( $script );
		$dispatch  = array();

		foreach ( $script as $line ) {
			if ( 1 === preg_match( '/^\t([a-z-]+)\) ([a-z_]+)\b/', $line, $match ) ) {
				$dispatch[ $match[1] ] = $match[2];
			}
		}

		foreach ( $steps as $step => $jobs ) {
			foreach ( self::reached( $dispatch[ (string) strtok( $step, ' ' ) ] ?? '', $functions ) as $function ) {
				foreach ( self::commands( $functions[ $function ] ) as $words ) {
					$problem = self::refusal( $words, $function, $functions );

					foreach ( null === $problem ? array() : array_keys( $jobs ) as $job ) {
						$violations[] = sprintf( 'The step %1$s, which the job %2$s runs after its settings step, runs in %3$s() %4$s.', $step, $job, $function, $problem );
					}
				}
			}
		}

		return array_values( array_unique( $violations ) );
	}

	/**
	 * Returns the script's step a job's step after its settings step runs, or null after noting why it is not one.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $job        The job.
	 * @param array    $item       The step: its alias or anchor, its keys and their first lines, its keys whose value goes on.
	 * @param string[] $violations The violations, noted into.
	 * @return string|null The script's step, such as `configure` or `live webhooks`.
	 * @phpstan-param array{alias: ?string, keys: array<string, string>, deep: array<string, bool>} $item
	 * @phpstan-param list<string> $violations
	 */
	private static function scriptStep( string $job, array $item, array &$violations ): ?string {
		if ( null !== $item['alias'] && str_starts_with( $item['alias'], '*' ) ) {
			if ( '*cleanup' === $item['alias'] ) {
				return 'cleanup';
			}

			$violations[] = sprintf( 'The job %1$s runs `- %2$s` after its settings step: only the script\'s own steps may follow it.', $job, $item['alias'] );

			return null;
		}

		if ( isset( $item['keys']['uses'] ) ) {
			$violations[] = sprintf( 'The job %1$s uses an action after its settings step: `uses: %2$s`.', $job, $item['keys']['uses'] );

			return null;
		}

		foreach ( array_diff( array_keys( $item['keys'] ), self::STEP_KEYS ) as $key ) {
			$violations[] = sprintf( 'The job %1$s gives a step after its settings step the key %2$s: only %3$s.', $job, $key, implode( ', ', self::STEP_KEYS ) );
		}

		$run = $item['keys']['run'] ?? '';

		if ( isset( $item['deep']['run'] ) || 1 !== preg_match( '/^sh ext\/bin\/ci\/paypal-sandbox\.sh (.+)$/D', $run, $match ) || ! in_array( $match[1], self::STEPS_AFTER_SETTINGS, true ) ) {
			$violations[] = sprintf( 'The job %1$s runs, after its settings step, a command that is not one of the script\'s steps: `run: %2$s`.', $job, $run );

			return null;
		}

		return $match[1];
	}

	/**
	 * Returns why a command of a function the steps reach is not allowed, or null when it is.
	 *
	 * A call of a wrapper is the command it is given (WRAPPERS); a call of another function of the
	 * script that runs `"$@"`, such as site_wp(), must itself be on the list; a call of any other
	 * function is allowed, as reached() follows it and its own commands are read.
	 *
	 * @since 0.2.0
	 *
	 * @param string[]                    $words     The command's words.
	 * @param string                      $within    The function it is in.
	 * @param array<string, list<string>> $functions The script's functions.
	 * @return string|null Why not; null when allowed.
	 * @phpstan-param list<string> $words
	 */
	private static function refusal( array $words, string $within, array $functions ): ?string {
		$words = self::bare( $words );

		if ( array() === $words ) {
			return null;
		}

		$name    = $words[0];
		$command = implode( ' ', $words );

		if ( isset( self::WRAPPERS[ $name ] ) ) {
			return self::refusal( array_slice( $words, 1 + self::WRAPPERS[ $name ] ), $within, $functions );
		}

		if ( '"$@"' === $name ) {
			return isset( self::WRAPPERS[ $within ] ) ? null : sprintf( 'the command its caller gives it (`%s`), though it is no wrapper whose calls are checked', $command );
		}

		if ( isset( $functions[ $name ] ) && ! self::passesThrough( $functions[ $name ] ) ) {
			return null;
		}

		foreach ( self::COMMANDS as $pattern ) {
			if ( 1 === preg_match( $pattern, $command ) ) {
				return null;
			}
		}

		return sprintf( 'a command not on the list: `%s`', $command );
	}

	/**
	 * Tells whether a function runs the command its caller gives it, `"$@"`.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The function's lines.
	 * @return bool True when it does.
	 * @phpstan-param list<string> $lines
	 */
	private static function passesThrough( array $lines ): bool {
		foreach ( self::commands( $lines ) as $words ) {
			if ( in_array( '"$@"', $words, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns a command's words without what does not name the command: the words that open or join a compound command, assignments, `env`, and redirections.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $words The words.
	 * @return list<string> The words from the command's name on.
	 * @phpstan-param list<string> $words
	 */
	private static function bare( array $words ): array {
		$words = array_values( array_filter( $words, static fn( string $word ): bool => 1 !== preg_match( '/^\d*[<>]/', $word ) ) );

		while ( array() !== $words && ( in_array( $words[0], self::KEYWORDS, true ) || 'env' === $words[0] || 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]*=/', $words[0] ) ) ) {
			array_shift( $words );
		}

		return $words;
	}

	/**
	 * Returns the simple commands of a function's lines, each as its words, quotes kept: a command
	 * substitution `$( … )` is a command of its own, an arithmetic expansion a number, a case's
	 * patterns and the heads of `case` and `for` no command.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @return list<list<string>> The commands.
	 * @phpstan-param list<string> $lines
	 */
	private static function commands( array $lines ): array {
		$text = (string) preg_replace( '/\$\(\([^()]*\)\)/', '0', (string) preg_replace( '/\\\\\n/', ' ', implode( "\n", $lines ) ) );

		return self::lex( $text );
	}

	/**
	 * Splits shell text into simple commands: words are split on blanks, commands on newlines, `;`, `&`, `|`, `(` and `)`, outside quotes.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text.
	 * @return list<list<string>> The commands.
	 */
	private static function lex( string $text ): array {
		$commands = array();
		$words    = array();
		$word     = null;
		$cases    = 0;
		$length   = strlen( $text );

		for ( $at = 0; $at <= $length; $at++ ) {
			$char = $text[ $at ] ?? "\n";
			$two  = substr( $text, $at, 2 );

			if ( '$(' === $two ) {
				$close    = self::closing( $text, $at + 2 );
				$commands = array_merge( $commands, self::lex( substr( $text, $at + 2, $close - $at - 2 ) ) );
				$word    .= '$(…)';
				$at       = $close;
			} elseif ( "'" === $char ) {
				$close = strpos( $text, "'", $at + 1 );
				$close = false === $close ? $length : $close;
				$word .= substr( $text, $at, $close - $at + 1 );
				$at    = $close;
			} elseif ( '"' === $char ) {
				[ $quoted, $inner, $at ] = self::doubleQuoted( $text, $at );
				$word                   .= $quoted;
				$commands                = array_merge( $commands, $inner );
			} elseif ( '\\' === $char || '${' === $two ) {
				$close = '\\' === $char ? $at + 1 : (int) strpos( $text, '}', $at );
				$word .= substr( $text, $at, $close - $at + 1 );
				$at    = $close;
			} elseif ( '#' === $char && null === $word ) {
				$close = strpos( $text, "\n", $at );
				$at    = ( false === $close ? $length : $close ) - 1;
			} elseif ( '>' === $char || '<' === $char ) {
				$word .= $char . ( '&' === ( $text[ $at + 1 ] ?? '' ) ? $text[ ++$at ] : '' );
			} elseif ( ' ' === $char || "\t" === $char ) {
				self::endWord( $word, $words );
			} elseif ( in_array( $char, array( "\n", ';', '&', '|', '(', ')' ), true ) ) {
				self::endWord( $word, $words );
				self::endCommand( $words, $commands, $cases, ')' === $char );
			} else {
				$word .= $char;
			}
		}

		return $commands;
	}

	/**
	 * Reads a double-quoted text from its opening quote: the text, the commands of the substitutions in it, and where it ends.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text.
	 * @param int    $at   Where its opening quote is.
	 * @return array{string, list<list<string>>, int} The quoted text, its commands, its closing quote's place.
	 */
	private static function doubleQuoted( string $text, int $at ): array {
		$quoted   = '"';
		$commands = array();
		$length   = strlen( $text );

		for ( ++$at; $at < $length && '"' !== $text[ $at ]; $at++ ) {
			if ( '\\' === $text[ $at ] ) {
				$quoted .= substr( $text, $at++, 2 );
			} elseif ( '$(' === substr( $text, $at, 2 ) ) {
				$close    = self::closing( $text, $at + 2 );
				$commands = array_merge( $commands, self::lex( substr( $text, $at + 2, $close - $at - 2 ) ) );
				$quoted  .= '$(…)';
				$at       = $close;
			} else {
				$quoted .= $text[ $at ];
			}
		}

		return array( $quoted . '"', $commands, $at );
	}

	/**
	 * Returns where the `)` that closes a command substitution is, from the text just inside it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text.
	 * @param int    $at   Where the substitution's text begins.
	 * @return int Its closing parenthesis's place; the text's end when it has none.
	 */
	private static function closing( string $text, int $at ): int {
		$length = strlen( $text );

		for ( $depth = 1; $at < $length; $at++ ) {
			$char = $text[ $at ];

			if ( "'" === $char ) {
				$close = strpos( $text, "'", $at + 1 );
				$at    = false === $close ? $length : $close;
			} elseif ( '\\' === $char ) {
				++$at;
			} elseif ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char && 0 === --$depth ) {
				return $at;
			}
		}

		return $length;
	}

	/**
	 * Ends the word being read, when there is one.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $word  The word; null between words.
	 * @param string[]    $words The command's words, added to.
	 * @phpstan-param list<string> $words
	 * @phpstan-param-out null $word
	 */
	private static function endWord( ?string &$word, array &$words ): void {
		if ( null !== $word ) {
			$words[] = $word;
			$word    = null;
		}
	}

	/**
	 * Ends the command being read: a case's pattern (one word before `)` inside a `case`) and the heads of `case` and `for` are dropped.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $words    The command's words, emptied.
	 * @param array    $commands The commands, added to.
	 * @param int      $cases    How many `case` commands are open.
	 * @param bool     $closing  Whether a `)` ends it.
	 * @phpstan-param list<string> $words
	 * @phpstan-param list<list<string>> $commands
	 */
	private static function endCommand( array &$words, array &$commands, int &$cases, bool $closing ): void {
		$head = self::bare( $words )[0] ?? null;

		if ( 'case' === $head ) {
			++$cases;
		} elseif ( 'esac' === ( $words[0] ?? null ) ) {
			--$cases;
		} elseif ( 'for' !== $head && ! ( $closing && $cases > 0 && 1 === count( $words ) ) && array() !== $words ) {
			$commands[] = $words;
		}

		$words = array();
	}

	/**
	 * Returns the script's functions: each one's lines, by its name.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $script The script's lines.
	 * @return array<string, list<string>> The functions.
	 * @phpstan-param list<string> $script
	 */
	private static function functions( array $script ): array {
		$functions = array();
		$name      = null;

		foreach ( $script as $line ) {
			if ( 1 === preg_match( '/^([a-z_]+)\(\) \{$/', $line, $match ) ) {
				$name               = $match[1];
				$functions[ $name ] = array();
			} elseif ( '}' === $line ) {
				$name = null;
			} elseif ( null !== $name ) {
				$functions[ $name ][] = $line;
			}
		}

		return $functions;
	}

	/**
	 * Returns a function of the script and every function of the script it reaches, at any depth: one
	 * it calls, and one it names as an unquoted word anywhere in a command, as a wrapper's argument
	 * or otherwise.
	 *
	 * @since 0.2.0
	 *
	 * @param string                      $first     The function's name.
	 * @param array<string, list<string>> $functions The script's functions.
	 * @return list<string> The names.
	 */
	private static function reached( string $first, array $functions ): array {
		$reached = array();
		$queue   = isset( $functions[ $first ] ) ? array( $first ) : array();

		while ( array() !== $queue ) {
			$name      = array_shift( $queue );
			$reached[] = $name;

			foreach ( self::commands( $functions[ $name ] ) as $words ) {
				foreach ( $words as $word ) {
					if ( isset( $functions[ $word ] ) && ! in_array( $word, $reached, true ) && ! in_array( $word, $queue, true ) ) {
						$queue[] = $word;
					}
				}
			}
		}

		return $reached;
	}

	/**
	 * Returns a job's steps, each as its alias or anchor, its keys and their first lines, and the keys whose value goes on to deeper lines; by job.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The workflow's lines.
	 * @return array<string, list<array{alias: ?string, keys: array<string, string>, deep: array<string, bool>}>> The steps.
	 * @phpstan-param list<string> $lines
	 */
	private static function jobSteps( array $lines ): array {
		$jobs = array();
		$job  = null;
		$key  = null;

		foreach ( $lines as $line ) {
			if ( 1 === preg_match( '/^    ([a-z][a-z0-9-]*):\s*$/', $line, $match ) ) {
				$job          = $match[1];
				$jobs[ $job ] = array();
				continue;
			}

			if ( null === $job || '' === trim( $line ) || str_starts_with( ltrim( $line ), '#' ) ) {
				continue;
			}

			if ( 1 === preg_match( '/^            - (?:([*&][a-z0-9-]+)\s*)?(.*)$/', $line, $match ) ) {
				$jobs[ $job ][] = array(
					'alias' => '' === $match[1] ? null : $match[1],
					'keys'  => array(),
					'deep'  => array(),
				);
				$key            = '' === $match[2] ? null : self::stepKey( $jobs[ $job ][ count( $jobs[ $job ] ) - 1 ], $match[2] );
				continue;
			}

			if ( array() === $jobs[ $job ] ) {
				continue;
			}

			$at = count( $jobs[ $job ] ) - 1;

			if ( 1 === preg_match( '/^              ([^ ].*)$/', $line, $match ) ) {
				$key = self::stepKey( $jobs[ $job ][ $at ], $match[1] );
			} elseif ( null !== $key && str_starts_with( $line, '               ' ) ) {
				$jobs[ $job ][ $at ]['deep'][ $key ] = true;
			}
		}

		return $jobs;
	}

	/**
	 * Reads one `key: value` line of a step into it, and returns the key.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $item The step, read into.
	 * @param string $text The line, without its indentation.
	 * @return string The key; `?` for a line that is no key.
	 * @phpstan-param array{alias: ?string, keys: array<string, string>, deep: array<string, bool>} $item
	 */
	private static function stepKey( array &$item, string $text ): string {
		if ( 1 !== preg_match( '/^([A-Za-z0-9_-]+):\s*(.*)$/', $text, $match ) ) {
			$item['keys']['?'] = $text;

			return '?';
		}

		$item['keys'][ $match[1] ] = $match[2];

		return $match[1];
	}

	/**
	 * Says why the script's cleanup() could void before the site's orders are on the run list, or null when it cannot.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $script The script's lines.
	 * @return string|null Why; null when the harvest comes first.
	 * @phpstan-param list<string> $script
	 */
	private static function cleanupHarvestsFirst( array $script ): ?string {
		$heads = array_map( static fn( array $words ): string => implode( ' ', self::bare( $words ) ), self::commands( self::functions( $script )['cleanup'] ?? array() ) );
		$void  = array_keys( preg_grep( '/^php "\$ext\/tests\/Integration\/Live\/cleanup\.php"/', $heads ) );
		$reap  = array_keys( preg_grep( '/^harvest_site$/', $heads ) );

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
	 * Returns the lines of bin/ci/paypal-sandbox.sh.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The lines.
	 */
	private static function script(): array {
		$lines = file( dirname( __DIR__, 2 ) . '/bin/ci/paypal-sandbox.sh', FILE_IGNORE_NEW_LINES );

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
		$lines = file( dirname( __DIR__, 2 ) . '/.github/workflows/paypal-sandbox.yml', FILE_IGNORE_NEW_LINES );

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
		$at = self::find( $lines, $pattern );

		array_splice( $lines, $at + 1, 0, array( $line ) );

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
