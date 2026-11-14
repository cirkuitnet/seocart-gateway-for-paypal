<?php
/**
 * LiveRun: the files one run against PayPal's sandbox writes, and the browser it approves cards in
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Support\Live;

/**
 * Names the files one run against PayPal's sandbox writes, and runs the browser script that approves an order in PayPal's card fields.
 *
 * The workflow's script, bin/ci/paypal-sandbox.sh, creates the files in the runner's temporary
 * directory and names them in the environment; the live tests append to them; the cleanup and the
 * verdict read them. The run list holds, per line, the id of each order the run created and the
 * store uuid it was created for, and nothing else, so the cleanup voids what the run left
 * authorized. The report holds `key=value` lines of what the run's summary shows: counts and
 * PayPal's words; never a body, a secret or an address with a query.
 *
 * @since 0.2.0
 */
final class LiveRun {

	/**
	 * The environment variable naming the run list.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RUN_LIST = 'SEOCART_LIVE_RUN_LIST';

	/**
	 * The environment variable naming the report.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REPORT = 'SEOCART_LIVE_REPORT';

	/**
	 * The environment variable naming the browser the card script launches, for a machine Playwright downloads none for; empty for Playwright's own.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const BROWSER = 'SEOCART_LIVE_BROWSER';

	/**
	 * The words the card script prints.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const CARD_WORDS = array( 'approved', 'declined', 'no-fields', 'no-control', 'timeout' );

	/**
	 * Returns a version 4 uuid, for a store reference the live tests make themselves.
	 *
	 * @since 0.2.0
	 *
	 * @return string The uuid.
	 */
	public static function uuid(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ord( $bytes[6] ) & 0x0f | 0x40 );
		$bytes[8] = chr( ord( $bytes[8] ) & 0x3f | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}

	/**
	 * Approves an order in PayPal's card fields with a sandbox test card, through tests/browser/approve-card.mjs.
	 *
	 * The card, the client id and the order reach the script on its standard input, never in a
	 * process list; its error output is dropped unread, as a browser's message can quote an
	 * address that holds a token.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $clientId The sandbox app's client id.
	 * @param string                $orderId  The order.
	 * @param string                $currency The order's currency.
	 * @param array<string, string> $card     The card: number, expiry (MM/YY), cvv, name.
	 * @param string                $control  `complete` or `fail`, for a 3-D Secure challenge.
	 * @return string One of CARD_WORDS, or the name of the error the script met.
	 */
	public static function approveCard( #[\SensitiveParameter] string $clientId, string $orderId, string $currency, #[\SensitiveParameter] array $card, string $control ): string {
		$script  = dirname( __DIR__, 2 ) . '/browser/approve-card.mjs';
		$process = proc_open(
			array( 'node', $script ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'file', '/dev/null', 'w' ),
			),
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			return 'Error';
		}

		fwrite(
			$pipes[0],
			(string) json_encode(
				array(
					'clientId' => $clientId,
					'orderId'  => $orderId,
					'currency' => $currency,
					'card'     => $card,
					'control'  => $control,
					'browser'  => (string) getenv( self::BROWSER ),
				)
			) . "\n"
		);
		fclose( $pipes[0] );

		$word = trim( (string) stream_get_contents( $pipes[1] ) );
		fclose( $pipes[1] );
		proc_close( $process );

		return in_array( $word, self::CARD_WORDS, true ) || 1 === preg_match( '/^[A-Za-z]{1,40}$/D', $word ) ? $word : 'Error';
	}

	/**
	 * Returns the file an environment variable names.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When the variable names no file that can be written.
	 *
	 * @param string $variable The variable.
	 * @return string The file.
	 */
	public static function file( string $variable ): string {
		$file = (string) getenv( $variable );

		if ( '' === $file || ! is_writable( file_exists( $file ) ? $file : dirname( $file ) ) ) {
			throw new \RuntimeException( sprintf( 'The live tests write to the file %s names: bin/ci/paypal-sandbox.sh sets it.', $variable ) );
		}

		return $file;
	}

	/**
	 * Appends a line to the file an environment variable names, readable by its owner alone.
	 *
	 * @since 0.2.0
	 *
	 * @param string $variable The variable.
	 * @param string $line     The line, with its newline.
	 */
	public static function append( string $variable, string $line ): void {
		$file = self::file( $variable );

		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
		chmod( $file, 0600 );
	}

	/**
	 * Adds an order the run created to the run list, its id written whole.
	 *
	 * The run list is never printed: the cleanup reads it, and names an id in what it prints only as
	 * MessageGuard shows it. So an id is written as PayPal gave it, even one whose digits pass a
	 * card number's checksum, as PayPal's ids may; the cleanup must find every order the run made.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the id is not an order's (17 capitals and digits, a letter among them), or the uuid not a store uuid.
	 *
	 * @param string $orderId    The order's id.
	 * @param string $intentUuid The store uuid it was created for.
	 */
	public static function created( string $orderId, string $intentUuid ): void {
		if ( 1 !== preg_match( '/^(?=[0-9]*[A-Z])[A-Z0-9]{17}$/D', $orderId ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $intentUuid ) ) {
			throw new \LogicException( 'The run list holds an order\'s id and a store uuid, and nothing else.' );
		}

		self::append( self::RUN_LIST, CleanupSelection::line( $orderId, $intentUuid ) );
	}

	/**
	 * Adds a fact to the run's report, its value made safe to print.
	 *
	 * @since 0.2.0
	 *
	 * @param string     $key   The fact, such as `liability-shift`: letters, digits and `-`.
	 * @param string|int $value Its value, on one line.
	 */
	public static function report( string $key, string|int $value ): void {
		self::append( self::REPORT, MessageGuard::say( "%s=%s\n", $key, str_replace( array( "\r", "\n" ), ' ', (string) $value ) ) );
	}

	/**
	 * Reads a report: its facts, the last value of each.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The report's text.
	 * @return array<string, string> The facts, by key.
	 */
	public static function facts( string $text ): array {
		$facts = array();

		foreach ( explode( "\n", $text ) as $line ) {
			if ( 1 === preg_match( '/^([a-z][a-z0-9\-]*)=(.*)$/D', $line, $match ) ) {
				$facts[ $match[1] ] = $match[2];
			}
		}

		return $facts;
	}

	/**
	 * Reads a run list: the store uuid of each order, by the order's id.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The run list's text.
	 * @return array<string, string> The uuids, by order id; a line of any other shape is left out.
	 */
	public static function orders( string $text ): array {
		$orders = array();

		foreach ( explode( "\n", $text ) as $line ) {
			if ( 1 === preg_match( '/^([A-Z0-9]{17}) ([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/D', trim( $line ), $match ) ) {
				$orders[ $match[1] ] = $match[2];
			}
		}

		return $orders;
	}
}
