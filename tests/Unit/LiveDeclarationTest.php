<?php
/**
 * Tests what the plugin states of its live workflow, and what its own classes add to SEOCart's for a run
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOCart\GatewayForPayPal\Tests\Support\Live\PayPalRun;
use SEOCart\GatewayForPayPal\Tests\Support\TemporaryFolder;
use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;
use SEOCart\Tests\Conformance\Payment\Live\LiveRun;

/**
 * The guard built from tests/live.php refuses an argument shaped like a credential (48 letters, digits, `_` and `-` or more, as PayPal's client ids, secrets and access tokens are, unless the whole run is a test's name), an authorization header, an address with a query, or a card number, and blanks the same shapes out of any text; PayPal's ids, of 17 characters or of an event's 38, pass, as does a run of digits that is no card, and an id whose digits read as a card is withheld when shown. A test's name, `test_` and then lower-case letters, digits and `_` alone, is printed, so a red run of the live workflow says which test failed: every live test's and the longest of the plugin's, alone and on the line PHPUnit names a failure with, and in PHPUnit's own output for a failing live test passed through the workflow's filter, where an access token in the failure's message stays hidden. A run that only starts as a test's name does or holds one after other characters is hidden whole, as is one of lower-case letters and digits that does not start with `test_`. The run list keeps one order per line with the store uuid it was made for, and reads back only lines of that shape; the card script's line is read as its word and the issue PayPal named; and a uuid the live tests make is a version 4 uuid.
 *
 * Planted violations, each shown red and removed:
 * - the declaration's credential shape taken out: the credentials are printed, and the shapes' tests and the filter's test fail;
 * - the exemption of a test's name taken out: the names are hidden, and the names' test and the filter's test fail;
 * - the exemption widened to any run of lower-case letters, digits and `_`: the runs of lower case that do not start with `test_` are printed, and the hidden runs' test fails.
 *
 * @since 0.2.0
 */
final class LiveDeclarationTest extends TestCase {

	/**
	 * Tests the shapes refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_credential_a_header_an_address_with_a_query_and_a_card_number_are_refused(): void {
		$values = array(
			'a credential'            => 'AUnitTestClientIdLongerThanFortyCharactersForTheTestOnly',
			'an authorization header' => 'Bearer UnitTestAccessTokenOfTheFixturesOnly',
			'an address with a query' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T',
			'a card number'           => '4012 8888 8888 1881',
		);

		foreach ( $values as $shape => $value ) {
			$this->assertSame( $shape, PayPalRun::guard()->shapeOf( $value ) );

			try {
				PayPalRun::guard()->say( 'The value %s.', $value );
				$this->fail( "A value shaped like {$shape} was printed." );
			} catch ( \LogicException $refused ) {
				$this->assertStringNotContainsString( $value, $refused->getMessage() );
			}

			$this->assertSame( 'x [redacted: ' . $shape . '] y', PayPalRun::guard()->redact( 'x ' . $value . ' y' ) );
		}
	}

	/**
	 * Tests PayPal's ids and words: printed as they are.
	 *
	 * @since 0.2.0
	 */
	public function test_paypals_ids_and_words_are_printed(): void {
		foreach ( array( '5O190127TN364715T', '0C527818AF292391J', 'WH-2WR32451HC0233532-67976317FL4543714', '0199c0de-0000-7000-8000-00000000a001', '7390d37aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'PAYER_CANNOT_PAY', '1234567890123' ) as $value ) {
			$this->assertNull( PayPalRun::guard()->shapeOf( $value ), $value . ' is printed.' );
			$this->assertSame( 'The value ' . $value . '.', PayPalRun::guard()->say( 'The value %s.', $value ) );
		}
	}

	/**
	 * Tests an id whose digits read as a card: withheld when shown.
	 *
	 * @since 0.2.0
	 */
	public function test_an_id_whose_digits_read_as_a_card_is_withheld(): void {
		$this->assertSame( '(withheld: shaped like a card number)', PayPalRun::guard()->shown( '0VF52814937998046' ) );
		$this->assertSame( '5O190127TN364715T', PayPalRun::guard()->shown( '5O190127TN364715T' ) );
	}

	/**
	 * Tests PayPal's credentials, and runs that are no test's name whole: hidden, alone and on the line PHPUnit names a failure with.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider hidden
	 *
	 * @param string $value The value.
	 */
	public function test_paypals_credentials_and_runs_that_are_no_tests_name_whole_are_hidden( string $value ): void {
		$this->assertSame( 'a credential', PayPalRun::guard()->shapeOf( $value ) );
		$this->assertSame( 'x [redacted: a credential] y', PayPalRun::guard()->redact( 'x ' . $value . ' y' ) );
		$this->assertSame( "1) Live\\ThreeDSecureTest::[redacted: a credential]\n", PayPalRun::guard()->redact( '1) Live\\ThreeDSecureTest::' . $value . "\n" ) );
	}

	/**
	 * Returns the values hidden, built here so that the repository keeps no literal of a credential's shape: a client id and a secret of 80 characters and an access token of 97, beginning as PayPal's do; runs that start as a test's name does and go on with an upper-case letter or a `-`; a live test's name after a letter and before an upper-case one; and 48 lower-case letters and digits that do not start with `test_`.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string}> Each value, by what it is.
	 */
	public static function hidden(): array {
		$name = self::longest( array_keys( self::testsIn( 'tests/Integration/Live' ) ) );

		return array(
			'a client id'                                => array( self::credential( 'A', 80 ) ),
			'a secret'                                   => array( self::credential( 'E', 80 ) ),
			'an access token'                            => array( self::credential( 'A21AA', 97 ) ),
			'a test\'s start, then an upper-case letter' => array( 'test_' . str_repeat( 'live_', 9 ) . 'Token' ),
			'a test\'s start, then a hyphen'             => array( 'test_' . str_repeat( 'live-', 9 ) ),
			'a test\'s name after a letter'              => array( 'x' . $name ),
			'a test\'s name before an upper-case letter' => array( $name . 'Token' ),
			'lower-case letters and digits'              => array( str_repeat( '0123456789abcdef', 3 ) ),
		);
	}

	/**
	 * Tests a test's name: printed, alone and on the line PHPUnit names a failure with, so a red run says which test failed; every live test's, read from their files, and the longest of the plugin's tests.
	 *
	 * @since 0.2.0
	 */
	public function test_every_live_tests_name_and_the_longest_tests_name_are_printed(): void {
		$live  = self::testsIn( 'tests/Integration/Live' );
		$tests = self::testsIn( 'tests/Unit', 'tests/Integration' );
		$names = array_merge( array_keys( $live ), array( self::longest( array_keys( $tests ) ) ) );

		$this->assertCount( array_sum( array_column( PayPalRun::declaration()->liveParts(), 'tests' ) ), $live, 'The live tests read are those the live parts run.' );

		foreach ( $names as $name ) {
			$line = '1) ' . $tests[ $name ] . '::' . $name . "\n";

			$this->assertNull( PayPalRun::guard()->shapeOf( $name ), $name . ' is printed.' );
			$this->assertSame( $line, PayPalRun::guard()->redact( $line ) );
		}
	}

	/**
	 * Tests the live workflow's filter, as SEOCart's lib runs it with this plugin's declaration, on what PHPUnit prints for a failing live test whose message holds an access token: the test's name is printed, the token is not, and PHPUnit's status is the step's.
	 *
	 * The failing test has the class and the name of the live test whose name is the longest, and
	 * PHPUnit runs it in a folder of this test's own, which is the workflow's RUNNER_TEMP too and is
	 * removed whether this test passes or fails.
	 *
	 * @since 0.2.0
	 */
	public function test_a_red_live_run_names_its_failing_test_and_hides_a_credential_in_the_failure(): void {
		$live    = self::testsIn( 'tests/Integration/Live' );
		$name    = self::longest( array_keys( $live ) );
		$token   = self::credential( 'A21AA', 97 );
		$split   = (int) strrpos( $live[ $name ], '\\' );
		$root    = TemporaryFolder::make( 'paypal-live-filter' );
		$test    = $root . '/' . substr( $live[ $name ], $split + 1 ) . '.php';
		$core    = dirname( (string) ( new \ReflectionClass( LiveDeclaration::class ) )->getFileName(), 5 );
		$program = implode(
			"\n",
			array(
				'core=$1',
				'ext=$2',
				'. "$core/bin/ci/extension-live-lib.sh"',
				'mkdir -p "$work"',
				'filtered "$work/kept" php "$core/vendor/bin/phpunit" --no-configuration --do-not-cache-result --verbose "$3"',
				'printf "status=%s\n" "$?"',
			)
		);

		try {
			file_put_contents(
				$test,
				sprintf(
					"<?php\nnamespace %1\$s;\n\nfinal class %2\$s extends \\PHPUnit\\Framework\\TestCase {\n\tpublic function %3\$s(): void {\n\t\tself::fail( %4\$s );\n\t}\n}\n",
					substr( $live[ $name ], 0, $split ),
					substr( $live[ $name ], $split + 1 ),
					$name,
					var_export( 'PayPal answered with ' . $token . '.', true )
				)
			);

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- the workflow's own filter, on a PHPUnit run of the test's own.
			exec( sprintf( 'cd %1$s && env RUNNER_TEMP=%1$s sh -c %2$s sh %3$s %4$s %5$s 2>&1', escapeshellarg( $root ), escapeshellarg( $program ), escapeshellarg( $core ), escapeshellarg( dirname( __DIR__, 2 ) ), escapeshellarg( $test ) ), $output );

			$shown = implode( "\n", $output );

			$this->assertStringContainsString( "\n1) " . $live[ $name ] . '::' . $name . "\nPayPal answered with [redacted: a credential].\n", $shown );
			$this->assertStringNotContainsString( $token, $shown . (string) file_get_contents( $root . '/seocart-live/kept' ) );
			$this->assertStringEndsWith( "\nstatus=1", $shown );
		} finally {
			TemporaryFolder::remove( $root );
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
			PayPalRun::created( '5O190127TN364715T', '0199c0de-0000-7000-8000-00000000a001' );
			file_put_contents( $file, "not an order\n4111111111111111 0199c0de-0000-7000-8000-00000000a001\n", FILE_APPEND );
			PayPalRun::created( '21P35652VJ9135157', '0199c0de-0000-7000-8000-00000000a002' );
			// An id of PayPal's whose digits pass a card number's checksum, as the sandbox gave one: written whole, so the cleanup finds it.
			PayPalRun::created( '0TP3831016068574K', '0199c0de-0000-7000-8000-00000000a003' );

			$this->assertSame(
				array(
					'5O190127TN364715T' => '0199c0de-0000-7000-8000-00000000a001',
					'21P35652VJ9135157' => '0199c0de-0000-7000-8000-00000000a002',
					'0TP3831016068574K' => '0199c0de-0000-7000-8000-00000000a003',
				),
				PayPalRun::orders( (string) file_get_contents( $file ) )
			);

			foreach ( array( array( '41111111111111111', '0199c0de-0000-7000-8000-00000000a004' ), array( '5O190127TN364715T', 'not-a-uuid' ) ) as list( $orderId, $uuid ) ) {
				try {
					PayPalRun::created( $orderId, $uuid );
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
	 * Tests the card script's line: its word returned, and the issue PayPal named kept beside it, never anything else.
	 *
	 * @since 0.2.0
	 */
	public function test_the_card_scripts_line_is_read_as_its_word_and_the_issue_paypal_named(): void {
		$answers = array(
			array( 'refused INSTRUMENT_DECLINED', 'refused', 'refused INSTRUMENT_DECLINED' ),
			array( 'challenge-passed', 'challenge-passed', 'challenge-passed' ),
			array( 'refused token=abc', 'refused', 'refused' ),
			array( 'not-typed cvv', 'not-typed', 'not-typed cvv' ),
			array( 'not-typed 4868719166101368', 'not-typed', 'not-typed' ),
			array( 'refused cvv', 'refused', 'refused' ),
			array( '4868719196829038', 'Error', 'Error' ),
			array( 'TypeError', 'TypeError', 'TypeError' ),
		);

		foreach ( $answers as list( $line, $word, $kept ) ) {
			$this->assertSame( array( $word, $kept, false ), array( PayPalRun::cardAnswer( $line ), PayPalRun::lastCard(), PayPalRun::mayTryAgain() ), $line );
		}
	}

	/**
	 * Tests the one more try: only a refusal before any challenge that the card script lets be tried again, never anything after a challenge.
	 *
	 * @since 0.2.0
	 */
	public function test_only_a_refusal_before_any_challenge_may_be_tried_once_more(): void {
		$answers = array(
			array( 'refused INVALID_CVV again', 'refused', 'refused INVALID_CVV', true ),
			array( 'refused again', 'refused', 'refused', true ),
			array( 'refused INVALID_CVV', 'refused', 'refused INVALID_CVV', false ),
			array( 'challenge-failed INSTRUMENT_DECLINED again', 'challenge-failed', 'challenge-failed INSTRUMENT_DECLINED', false ),
			array( 'not-typed cvv again', 'not-typed', 'not-typed cvv', false ),
		);

		foreach ( $answers as list( $line, $word, $kept, $again ) ) {
			$this->assertSame( array( $word, $kept, $again ), array( PayPalRun::cardAnswer( $line ), PayPalRun::lastCard(), PayPalRun::mayTryAgain() ), $line );
		}
	}

	/**
	 * Tests the uuids the live tests make.
	 *
	 * @since 0.2.0
	 */
	public function test_a_uuid_is_a_version_4_uuid(): void {
		$uuid = PayPalRun::uuid();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid );
		$this->assertNotSame( $uuid, PayPalRun::uuid() );
	}

	/**
	 * Returns the test methods of the plugin's test classes under directories, each with its class, read from their files: a test added later is read too.
	 *
	 * @since 0.2.0
	 *
	 * @param string ...$directories The directories, from the plugin's root.
	 * @return array<string, string> Each test's class, by its method's name.
	 */
	private static function testsIn( string ...$directories ): array {
		$tests = array();

		foreach ( $directories as $directory ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/' . $directory, \FilesystemIterator::SKIP_DOTS ) );

			/**
			 * One file under the directory.
			 *
			 * @var \SplFileInfo $file
			 */
			foreach ( $files as $file ) {
				if ( ! str_ends_with( $file->getFilename(), 'Test.php' ) ) {
					continue;
				}

				$code = (string) file_get_contents( $file->getPathname() );

				preg_match( '/^namespace\s+([^;]+);/m', $code, $namespace );
				preg_match_all( '/\bfunction\s+(test_\w+)\s*\(/', $code, $methods );

				foreach ( $methods[1] as $method ) {
					$tests[ $method ] = ( $namespace[1] ?? '' ) . '\\' . $file->getBasename( '.php' );
				}
			}
		}

		return $tests;
	}

	/**
	 * Returns the longest of names.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $names The names.
	 * @return string The longest, the first of those as long.
	 */
	private static function longest( array $names ): string {
		return array_reduce( $names, static fn( string $longest, string $name ): string => strlen( $name ) > strlen( $longest ) ? $name : $longest, '' );
	}

	/**
	 * Returns a value of the letters, digits, `_` and `-` of PayPal's credentials, of a length, after a beginning: built when the test runs, so that the repository keeps no literal of a credential's shape.
	 *
	 * @since 0.2.0
	 *
	 * @param string $beginning The beginning.
	 * @param int    $length    The length.
	 * @return string The value.
	 */
	private static function credential( string $beginning, int $length ): string {
		return substr( $beginning . str_repeat( 'UnitTest-Canary_0', 8 ), 0, $length );
	}
}
