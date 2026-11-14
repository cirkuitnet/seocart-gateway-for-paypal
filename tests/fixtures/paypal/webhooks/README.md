# Webhook deliveries

The conformance suite's webhook rule reads the two deliveries here, through the gateway's own
check: the local RSA verification over `transmission id|transmission time|webhook id|CRC-32 of the
raw body`, with the certificate the delivery names, which the suite keeps in the gateway's
certificate cache before it reads them, as a fetch from PayPal would.

- `authorization-created.json`: a `PAYMENT.AUTHORIZATION.CREATED` event, which the gateway reads as
  an authorization's approval.
- `order-approved.json`: a `CHECKOUT.ORDER.APPROVED` event, which the gateway ignores until the
  status query authorizes the order.
- `cert.pem`: the certificate both were signed under.

Each delivery file holds the webhook id it was signed for (`webhook_id`), which a recording takes
as the test mode's webhook id, its mode, when it was sent (`signed_at`, its transmission time), its
headers and its raw body, byte for byte.

These two were signed by a key pair `tests/Support/write-test-deliveries.php` made for them. Its
private key was never written anywhere, and the certificate's organisation names it a test
fixture; the certificate's address names the sandbox's certificate host, as PayPal's do, and
nothing is ever fetched from it.

To read two deliveries PayPal signed instead, replace the three files with a real
`PAYMENT.AUTHORIZATION.CREATED` and `CHECKOUT.ORDER.APPROVED` delivery to the sandbox webhook, in
the same shape (the webhook's id, `"mode": "test"`, the transmission time as `signed_at`, the
`paypal-*` headers and `content-type` as PayPal sent them, the raw body), and the certificate their
`paypal-cert-url` names; then record the tapes again, so their settings carry that webhook's id. No
code changes.
