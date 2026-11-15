<?php
/**
 * Island: the checkout's PayPal form, as a script the checkout loads
 *
 * @package SEOCart\GatewayForPayPal
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\GatewayForPayPal;

use SEOCart\Checkout\Interfaces\Storefront\PaymentIslands;
use SEOCart\Contracts\OutboundHost;
use SEOCart\Platform\Hooks\EnqueuePaymentIslandsAction;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the PayPal form's script, assets/island.js, to a page that shows the checkout's payment methods and offers PayPal.
 *
 * Owns one fact: the PayPal form's script, how the checkout loads it, and what the readme
 * discloses of the two PayPal services the shopper's browser reaches through it: PayPal's script,
 * which the form loads to take a card, and PayPal's approval page, which it sends the shopper to
 * (paypalScript(), approvalPage()). The script is SEOCart's kind of payment island (SEOCart's
 * docs/payment-islands.md), shipped as written: it registers with the checkout, offers PayPal or a
 * card, and takes the step the store answers a placement with.
 *
 * The main file calls listen() once the gateway is registered, which happens only when something
 * needs a payment gateway, so an idle request costs nothing here. SEOCart enqueues the script
 * deferred, in the footer, and only on a page that offers PayPal; its words are translated through
 * WordPress's script translations, so the page also loads wp-i18n. PayPal's script itself is loaded
 * by the form, from PayPal, once the shopper chooses to pay by card.
 *
 * @since 0.2.0
 */
final class Island {

	/**
	 * The script's handle.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const HANDLE = 'seocart-gateway-for-paypal-island';

	/**
	 * The script, relative to the plugin's directory.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SCRIPT = 'assets/island.js';

	/**
	 * Listens for a page that shows the checkout's payment methods. Adding the listener again changes nothing.
	 *
	 * @since 0.2.0
	 */
	public static function listen(): void {
		add_action( EnqueuePaymentIslandsAction::NAME, array( self::class, 'enqueue' ) );
	}

	/**
	 * Adds the script to the page, under the plugin's URL and version, with its words' translations; SEOCart enqueues it when the page offers PayPal.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentIslands $islands The page's payment islands.
	 */
	public static function enqueue( PaymentIslands $islands ): void {
		$main = self::mainFile();

		$islands->add( Gateway::ID, self::HANDLE, plugins_url( self::SCRIPT, $main ), (string) get_file_data( $main, array( 'version' => 'Version' ) )['version'] );

		// Only once SEOCart registered the script: on a page that does not offer PayPal, there is none to translate.
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_set_script_translations( self::HANDLE, 'seocart-gateway-for-paypal' );
		}
	}

	/**
	 * Declares PayPal's script, which the PayPal form loads in the shopper's browser to take a card, as the readme discloses it.
	 *
	 * The declaration is the disclosure alone: this site never calls the address, its shoppers'
	 * browsers do, so it is no host of the gateway's and the outbound HTTP client never allows it.
	 * tests/Unit/ExternalServicesTest.php renders it into readme.txt's External services section;
	 * tests/Unit/IslandTest.php holds the script's address to its endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @return OutboundHost The declaration.
	 */
	public static function paypalScript(): OutboundHost {
		return new OutboundHost(
			id: 'paypal-js-sdk',
			service: 'PayPal JavaScript SDK',
			purpose: 'The PayPal JavaScript SDK is PayPal\'s script for taking card details in the browser. SEOCart Gateway for PayPal loads it on the checkout page to show PayPal\'s card fields, and to let the card\'s bank confirm a payment there (3D Secure).',
			endpoint: 'https://www.paypal.com/sdk/js',
			dataSent: 'Sent to PayPal by the shopper\'s browser, never by your site: the REST app\'s client id and the payment\'s currency, in the script\'s address; the card number, expiry date and security code the shopper types into PayPal\'s fields; the billing address the checkout holds (postal address only); and the bank\'s check. PayPal\'s script also collects information about the browser and device, sets its own cookies, and keeps an id of its own and the time its session began in the browser\'s local storage.',
			sentWhen: 'On the checkout page, once the shopper chooses to pay by card with PayPal, and when they place the order with it. Nothing is loaded on any other page, before the card is chosen, or for a shopper who pays with PayPal on PayPal\'s site.',
			termsUrl: 'https://www.paypal.com/legalhub/paypal/useragreement-full',
			privacyUrl: 'https://www.paypal.com/myaccount/privacy/privacyhub'
		);
	}

	/**
	 * Declares PayPal's approval page, which the PayPal form sends the shopper's browser to, as the readme discloses it.
	 *
	 * As for paypalScript(), the declaration is the disclosure alone: the shopper's browser goes
	 * there, never the site. In test mode the page is the sandbox's, https://www.sandbox.paypal.com.
	 * tests/Unit/IslandTest.php holds the script's live approval origin to this endpoint's.
	 *
	 * @since 0.2.0
	 *
	 * @return OutboundHost The declaration.
	 */
	public static function approvalPage(): OutboundHost {
		return new OutboundHost(
			id: 'paypal-approval-page',
			service: 'PayPal approval page',
			purpose: 'PayPal\'s page where the shopper signs in to PayPal and approves the payment. SEOCart Gateway for PayPal sends the shopper there to pay with PayPal, and PayPal sends them back to the store.',
			endpoint: 'https://www.paypal.com/checkoutnow',
			dataSent: 'Sent to PayPal by the shopper\'s browser, never by your site: the id of the PayPal order the store created, in the page\'s address. What the shopper then gives PayPal on its page is between them and PayPal.',
			sentWhen: 'When the shopper places an order to be paid with PayPal on PayPal\'s site, and again if they place it again without having approved it. In test mode the page is PayPal\'s sandbox.',
			termsUrl: 'https://www.paypal.com/legalhub/paypal/useragreement-full',
			privacyUrl: 'https://www.paypal.com/myaccount/privacy/privacyhub'
		);
	}

	/**
	 * Returns the plugin's main file, whose header holds its version.
	 *
	 * @since 0.2.0
	 *
	 * @return string The path.
	 */
	private static function mainFile(): string {
		return dirname( __DIR__ ) . '/seocart-gateway-for-paypal.php';
	}
}
