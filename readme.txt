=== SEOCart Gateway for PayPal ===
Contributors: cirkuitnet
Tags: seocart, payments
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

SEOCart Gateway for PayPal connects the SEOCart store plugin to PayPal.

== Description ==

SEOCart Gateway for PayPal is a free extension of SEOCart, the free, open-source store plugin for WordPress. It needs SEOCart, installed and active.

It takes payments by card and by PayPal through PayPal Complete Payments, with your own PayPal REST app. A payment is authorized when the shopper has approved it, at PayPal or in PayPal's card fields, and captured later, in full or in part, or voided; a captured payment can be refunded in full or in part. When the card's bank asks the shopper to confirm a payment (3-D Secure), PayPal asks them; you choose whether PayPal asks only when the bank or the law requires it, or always, and whether a card payment whose check leaves the liability for fraud with your store is declined (the default) or accepted. Card details never reach your site: the shopper types them into PayPal's own fields.

PayPal reports what happens to each payment through a webhook. Create it yourself in your REST app on PayPal's developer dashboard (Apps & Credentials, your app, Webhooks, Add Webhook), pointing at your site's address `https://<your site>/wp-json/seocart/v1/webhooks/paypal/live` (or `/test` for the sandbox app), with the events PAYMENT.AUTHORIZATION.CREATED, PAYMENT.AUTHORIZATION.VOIDED, PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DECLINED, PAYMENT.CAPTURE.DENIED, PAYMENT.CAPTURE.PENDING, PAYMENT.CAPTURE.REFUNDED, PAYMENT.REFUND.PENDING, PAYMENT.REFUND.FAILED, PAYMENT.CAPTURE.REVERSED, CHECKOUT.ORDER.APPROVED, CHECKOUT.PAYMENT-APPROVAL.REVERSED, CUSTOMER.DISPUTE.CREATED, CUSTOMER.DISPUTE.UPDATED and CUSTOMER.DISPUTE.RESOLVED, then paste the webhook's ID into the settings. Each delivery is checked against PayPal's signature on your site, with the public certificate PayPal names; the extension keeps that certificate for a day, in one transient, which is the only thing it stores and which is removed when the extension is deleted.

It has a test mode, for PayPal's sandbox, and a live mode, each with its own client ID, secret and webhook ID. It takes payments for PayPal business accounts in the United States, in US dollars, pounds sterling and euros.

To try it in test mode, use the card numbers PayPal's sandbox card generator gives you (https://developer.paypal.com/tools/sandbox/card-testing/), with any future expiry date and any three-digit security code. For 3-D Secure, PayPal lists a card for each outcome (https://developer.paypal.com/docs/checkout/advanced/customize/3d-secure/test/): for example, 4868 7191 6610 1368 completes the bank's check and 4868 7191 8189 5556 fails it.

== Installation ==

1. Install and activate SEOCart.
2. In your site's dashboard, go to **Store > Extensions**, find SEOCart Gateway for PayPal and select **Install**, then **Activate**.
3. Or install it from WordPress.org: go to **Plugins > Add Plugin**, search for "SEOCart Gateway for PayPal", then select **Install Now** and **Activate**.

== External services ==

SEOCart connects to the external services listed below, each only for the purpose stated and only under the condition stated. It contacts no other server.

= PayPal =

PayPal is a payment processing service. SEOCart Gateway for PayPal uses its REST API to create the store's PayPal orders and to authorize, capture, void and refund their payments, and to look up a payment whose answer was lost.

* Endpoint: `https://api-m.paypal.com/*`
* Data sent: The REST app's client id and secret (for an access token), the order's amount and currency, its order number and SEOCart's reference for the payment, the address PayPal returns the shopper to, the id of a card PayPal saved, and refund amounts and references. No card number, name, address or email address.
* When: When a customer places an order paid with PayPal; when SEOCart checks a payment the customer approved at PayPal or whose answer it did not receive; and when an order's payment is captured, voided or refunded. Nothing is sent before PayPal is configured.
* Terms of use: https://www.paypal.com/legalhub/paypal/useragreement-full
* Privacy policy: https://www.paypal.com/myaccount/privacy/privacyhub

= PayPal (sandbox) =

PayPal is a payment processing service. SEOCart Gateway for PayPal uses its REST API to create the store's PayPal orders and to authorize, capture, void and refund their payments, and to look up a payment whose answer was lost.

* Endpoint: `https://api-m.sandbox.paypal.com/*`
* Data sent: The REST app's client id and secret (for an access token), the order's amount and currency, its order number and SEOCart's reference for the payment, the address PayPal returns the shopper to, the id of a card PayPal saved, and refund amounts and references. No card number, name, address or email address.
* When: When a customer places an order paid with PayPal; when SEOCart checks a payment the customer approved at PayPal or whose answer it did not receive; and when an order's payment is captured, voided or refunded. Nothing is sent before PayPal is configured.
* Terms of use: https://www.paypal.com/legalhub/paypal/useragreement-full
* Privacy policy: https://www.paypal.com/myaccount/privacy/privacyhub

= PayPal webhook certificates =

PayPal signs each webhook delivery it sends the store. SEOCart Gateway for PayPal downloads the public certificate a delivery names, from PayPal, to check the signature, and keeps it for a day.

* Endpoint: `https://api.paypal.com/v1/notifications/certs/*`
* Data sent: The address of the certificate, as PayPal names it in the delivery. Nothing about the store or its customers.
* When: When PayPal delivers a webhook signed with a certificate the store has not downloaded in the last day.
* Terms of use: https://www.paypal.com/legalhub/paypal/useragreement-full
* Privacy policy: https://www.paypal.com/myaccount/privacy/privacyhub

= PayPal webhook certificates (sandbox) =

PayPal signs each webhook delivery it sends the store. SEOCart Gateway for PayPal downloads the public certificate a delivery names, from PayPal, to check the signature, and keeps it for a day.

* Endpoint: `https://api.sandbox.paypal.com/v1/notifications/certs/*`
* Data sent: The address of the certificate, as PayPal names it in the delivery. Nothing about the store or its customers.
* When: When PayPal delivers a webhook signed with a certificate the store has not downloaded in the last day.
* Terms of use: https://www.paypal.com/legalhub/paypal/useragreement-full
* Privacy policy: https://www.paypal.com/myaccount/privacy/privacyhub

= PayPal JavaScript SDK =

The PayPal JavaScript SDK is PayPal's script for taking card details in the browser. SEOCart Gateway for PayPal loads it on the checkout page to show PayPal's card fields, and to let the card's bank confirm a payment there (3D Secure).

* Endpoint: `https://www.paypal.com/sdk/js`
* Data sent: Sent to PayPal by the shopper's browser, never by your site: the REST app's client id and the payment's currency, in the script's address; the card number, expiry date and security code the shopper types into PayPal's fields; the billing address the checkout holds (postal address only); and the bank's check. PayPal's script also collects information about the browser and device, and sets its own cookies, to prevent fraud.
* When: On the checkout page, once the shopper chooses to pay by card with PayPal, and when they place the order with it. Nothing is loaded on any other page, before the card is chosen, or for a shopper who pays with PayPal on PayPal's site.
* Terms of use: https://www.paypal.com/legalhub/paypal/useragreement-full
* Privacy policy: https://www.paypal.com/myaccount/privacy/privacyhub

= PayPal approval page =

PayPal's page where the shopper signs in to PayPal and approves the payment. SEOCart Gateway for PayPal sends the shopper there to pay with PayPal, and PayPal sends them back to the store.

* Endpoint: `https://www.paypal.com/checkoutnow`
* Data sent: Sent to PayPal by the shopper's browser, never by your site: the id of the PayPal order the store created, in the page's address. What the shopper then gives PayPal on its page is between them and PayPal.
* When: When the shopper places an order to be paid with PayPal on PayPal's site, and again if they place it again without having approved it. In test mode the page is PayPal's sandbox.
* Terms of use: https://www.paypal.com/legalhub/paypal/useragreement-full
* Privacy policy: https://www.paypal.com/myaccount/privacy/privacyhub

== Source code and build steps ==

The complete, human-readable source code of this plugin is public at https://github.com/cirkuitnet/seocart-gateway-for-paypal. It is built with SEOCart's tools, from a SEOCart checkout beside it: `php ../seocart/bin/build-zip.php --plugin=.` assembles the installable zip in `dist/`. The checkout's PayPal form, `assets/island.js`, is hand-written JavaScript and ships as written: it has no build step.

== Changelog ==

= 0.1.0 =
* The extension's starting point. It does not do anything yet.
