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

PayPal reports what happens to each payment through a webhook. Create it yourself in your REST app on PayPal's developer dashboard (Apps & Credentials, your app, Webhooks, Add Webhook), pointing at your site's address `https://<your site>/wp-json/seocart/v1/webhooks/paypal/live` (or `/test` for the sandbox app), with the events PAYMENT.AUTHORIZATION.CREATED, PAYMENT.AUTHORIZATION.VOIDED, PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DECLINED, PAYMENT.CAPTURE.PENDING, PAYMENT.CAPTURE.REFUNDED, PAYMENT.CAPTURE.REVERSED, CHECKOUT.ORDER.APPROVED, CHECKOUT.PAYMENT-APPROVAL.REVERSED, CUSTOMER.DISPUTE.CREATED, CUSTOMER.DISPUTE.UPDATED and CUSTOMER.DISPUTE.RESOLVED, then paste the webhook's ID into the settings. Each delivery is checked against PayPal's signature on your site, with the public certificate PayPal names; the extension keeps that certificate for a day, in one transient, which is the only thing it stores and which is removed when the extension is deleted.

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

== Source code and build steps ==

The complete, human-readable source code of this plugin is public at https://github.com/cirkuitnet/seocart-gateway-for-paypal. It is built with SEOCart's tools, from a SEOCart checkout beside it: `php ../seocart/bin/build-zip.php --plugin=.` assembles the installable zip in `dist/`.

== Changelog ==

= 0.1.0 =
* The extension's starting point. It does not do anything yet.
