<?php
/**
 * PayPalRun: what the live tests, their cleanup and their scripts share of one run against PayPal's sandbox, beside SEOCart's
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal\Tests\Support\Live;

use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;
use SEOCart\Tests\Conformance\Payment\Live\LiveRun;
use SEOCart\Tests\Conformance\Payment\Live\MessageGuard;

/**
 * Runs the browser script that approves an order in PayPal's card fields, keeps the run list to orders, and gives the live tests the plugin's declaration and its guard.
 *
 * The run's files, its report and the guard are SEOCart's (tests/Conformance/Payment/Live), built
 * from this plugin's tests/live.php. What is PayPal's alone is here: the card script and what it
 * answers, and the run list read and written as orders, each with the store uuid it was created
 * for, so the cleanup voids what the run left authorized.
 *
 * @since 0.2.0
 */
final class PayPalRun {

	/**
	 * The words the card script prints: the fields approved with no challenge or refused before any; a challenge was shown, answered, and the fields then approved or refused; a field never held what was typed, so nothing was submitted; or no fields, no control of the challenge, no answer.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const CARD_WORDS = array( 'approved', 'refused', 'challenge-passed', 'challenge-failed', 'not-typed', 'no-fields', 'no-control', 'timeout' );

	/**
	 * The card fields the card script checks what it typed into, by the name it prints after `not-typed`.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const CHECKED_FIELDS = array( 'number', 'expiry', 'cvv' );

	/**
	 * What the card script answered last: its word, and the issue PayPal named when the fields refused.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private static string $lastCard = '';

	/**
	 * Whether the card script's last line let the test try the payment once more.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private static bool $tryAgain = false;

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
	 * @param int                   $attempt  Optional. 1 for the payment's first try, 2 for the one more mayTryAgain() allows. Default 1.
	 * @return string One of CARD_WORDS, or the name of the error the script met.
	 */
	public static function approveCard( #[\SensitiveParameter] string $clientId, string $orderId, string $currency, #[\SensitiveParameter] array $card, string $control, int $attempt = 1 ): string {
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
					'browser'  => (string) getenv( LiveRun::BROWSER ),
					'attempt'  => $attempt,
				)
			) . "\n"
		);
		fclose( $pipes[0] );

		$line = trim( (string) stream_get_contents( $pipes[1] ) );
		fclose( $pipes[1] );
		proc_close( $process );

		return self::cardAnswer( $line );
	}

	/**
	 * Reads the card script's line: its word, which it returns, and the issue PayPal named or the field never typed, which it keeps with the word for lastCard().
	 *
	 * @since 0.2.0
	 *
	 * @param string $line The line the script printed.
	 * @return string One of CARD_WORDS, the name of the error the script met, or `Error` for anything else.
	 */
	public static function cardAnswer( string $line ): string {
		$tokens = explode( ' ', $line );
		$again  = count( $tokens ) > 1 && 'again' === $tokens[ count( $tokens ) - 1 ];

		list( $first, $rest ) = array_pad( array_slice( $tokens, 0, $again ? -1 : null ), 2, '' );

		$word   = in_array( $first, self::CARD_WORDS, true ) || 1 === preg_match( '/^[A-Za-z]{1,40}$/D', $first ) ? $first : 'Error';
		$named  = 'not-typed' === $word ? in_array( $rest, self::CHECKED_FIELDS, true ) : 1 === preg_match( '/^[A-Z]+(?:_[A-Z]+)+$/D', $rest ) && strlen( $rest ) <= 64;
		$reason = 'Error' !== $word && $named ? $rest : '';

		self::$lastCard = '' === $reason ? $word : $word . ' ' . $reason;
		// Only a refusal before any challenge is tried once more, whatever the line says.
		self::$tryAgain = $again && 'refused' === $word;

		return $word;
	}

	/**
	 * Tells whether the card script said the test may try the payment once more: its fields refused the card before any challenge, on the first try.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when it may.
	 */
	public static function mayTryAgain(): bool {
		return self::$tryAgain;
	}

	/**
	 * Returns what the card script answered last: its word, and the issue PayPal named when the fields refused.
	 *
	 * @since 0.2.0
	 *
	 * @return string The answer; empty before the script ran.
	 */
	public static function lastCard(): string {
		return self::$lastCard;
	}

	/**
	 * Adds an order the run created to the run list, its id written whole.
	 *
	 * The run list is never printed: the cleanup reads it, and names an id in what it prints only as
	 * the guard shows it. So an id is written as PayPal gave it, even one whose digits pass a card
	 * number's checksum, as PayPal's ids may; the cleanup must find every order the run made.
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

		LiveRun::append( LiveRun::RUN_LIST, LiveRun::line( $orderId, $intentUuid ) );
	}

	/**
	 * Reads a run list as orders: the store uuid of each order, by the order's id.
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

	/**
	 * Returns the plugin's declaration of its live workflow, tests/live.php.
	 *
	 * @since 0.2.0
	 *
	 * @return LiveDeclaration The declaration.
	 */
	public static function declaration(): LiveDeclaration {
		return LiveDeclaration::load( dirname( __DIR__, 3 ) );
	}

	/**
	 * Returns the guard every message of the live tests and their scripts is built through, with PayPal's shape.
	 *
	 * @since 0.2.0
	 *
	 * @return MessageGuard The guard.
	 */
	public static function guard(): MessageGuard {
		return self::declaration()->guard();
	}
}
