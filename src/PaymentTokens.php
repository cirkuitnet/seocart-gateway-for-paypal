<?php
/**
 * PaymentTokens: what a payment token asks the gateway to do
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\Payment\Mode;

/**
 * The grammar of the payment token an authorization carries, read before any request is built.
 *
 * Owns one fact: the three ways a payment is made through PayPal. No token, or `paypal`: the
 * shopper approves the order on PayPal's page or with PayPal's buttons. `card`: the shopper types
 * the card into PayPal's card fields, in their browser, so the card never reaches the site.
 * `vault:<id>`: a card PayPal saved earlier, by the id PayPal gave it; in test mode
 * `;mock=<ERROR_NAME>` may follow, which asks PayPal's sandbox to answer with that error (its
 * negative testing). Anything else, a mock in live mode, or a vault id that reads as a card number,
 * is no token the gateway accepts, and nothing is sent.
 *
 * @since 0.2.0
 */
final readonly class PaymentTokens {

	/**
	 * The kind of a payment the shopper approves at PayPal.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PAYPAL = 'paypal';

	/**
	 * The kind of a payment by a card the shopper types into PayPal's card fields.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CARD = 'card';

	/**
	 * The kind of a payment by a card PayPal vaulted.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VAULT = 'vault';

	/**
	 * The shape of a vault token: `vault:`, the id, and in test mode an optional `;mock=` and an error's name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const VAULT_TOKEN = '/^vault:([^;]{1,64})(?:;mock=([A-Z][A-Z0-9_]{2,63}))?\z/';

	/**
	 * Records what the token asks for.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $kind    PAYPAL, CARD or VAULT.
	 * @param string|null $vaultId The vaulted card's id, for VAULT.
	 * @param string|null $mock    The error PayPal's sandbox is asked to answer with; test mode only.
	 */
	private function __construct(
		public string $kind,
		public ?string $vaultId = null,
		public ?string $mock = null
	) {
	}

	/**
	 * Reads a payment token.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $token The authorization's payment token; null for none.
	 * @param Mode        $mode  The payment's mode: a mock is read in test mode only.
	 * @return self|null What the token asks for; null when it is no token the gateway accepts.
	 */
	public static function read( ?string $token, Mode $mode ): ?self {
		if ( null === $token || self::PAYPAL === $token ) {
			return new self( self::PAYPAL );
		}

		if ( self::CARD === $token ) {
			return new self( self::CARD );
		}

		if ( 1 !== preg_match( self::VAULT_TOKEN, $token, $parts ) ) {
			return null;
		}

		$vaultId = PayPalCodes::vaultId( $parts[1] );
		$mock    = $parts[2] ?? null;

		if ( null === $vaultId || ( null !== $mock && Mode::Test !== $mode ) ) {
			return null;
		}

		return new self( self::VAULT, $vaultId, $mock );
	}
}
