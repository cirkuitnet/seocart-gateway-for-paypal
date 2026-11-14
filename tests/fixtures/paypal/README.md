# PayPal fixtures

The JSON files here are PayPal's answers as the unit tests serve them, written by hand from the
shapes PayPal documents in its REST reference (Orders v2, Payments v2 and the OAuth token call).
None was recorded from PayPal, and none holds a real id, token or card: every id is made up in
PayPal's shape, the access token and the client secret are a placeholder's, and the card's last
digits are those of PayPal's published test cards.

- `token.json`: the answer to `POST /v1/oauth2/token`.
- `order-payer-action.json`: a new order the shopper approves at PayPal (`payment_source.paypal`).
- `order-created.json`: a new order awaiting PayPal's card fields.
- `order-approved.json`: an order the shopper approved with a card, after a 3-D Secure check.
- `order-authorized.json`: an order PayPal processed, holding its authorization.
- `capture.json`, `refund.json`, `authorization-voided.json`: the answers to a capture, a refund and a void.
- `error-400.json`, `error-401.json`, `error-401-token.json`, `error-404.json`, `error-422.json`,
  `error-500.json`: PayPal's error answers; a test changes the issue where it needs another.

`webhooks/` holds the two webhook deliveries the conformance suite reads, with the certificate
they verify under (`cert.pem`). They are signed by a key pair `tests/Support/write-test-deliveries.php`
generated once and kept nowhere: the private key was never written, and the certificate names
itself a test fixture. `webhooks/README.md` says how they are replaced by two deliveries PayPal
signed.

Two things wait for those real deliveries. The gateway accepts a certificate fetched from the
mode's own certificate address whose subject is any name under `.paypal.com`; requiring the
exact name (`messageverificationcerts.paypal.com`, or `messageverificationcerts.sandbox.paypal.com`
in the sandbox) waits until a real live certificate has been seen. And the sandbox certificate
fetched while the gateway was built had expired in 2021, so the first thing the follow-up with
real deliveries shows is whether a sandbox delivery verifies under the gateway's validity check.
