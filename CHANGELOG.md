# Changelog

All notable changes to SEOCart Gateway for PayPal are documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/), and
the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before
version 1.0, a **minor** version may contain breaking changes and a **patch** version does
not.

## [Unreleased]

- The PayPal adapter: card and PayPal payments through PayPal Complete Payments with your own REST app, authorized
  once the shopper approves them at PayPal or in PayPal's card fields (with 3-D Secure, and your choice of whether a
  card whose check leaves the liability with your store is declined) and captured later in full or in part, voided,
  refunded in full or in part, and looked up when an answer was lost; PayPal's webhook deliveries are checked on your
  site against PayPal's signature. Test and live modes; PayPal business accounts in the United States, in USD, GBP
  and EUR.

## [0.1.0]

- The extension's starting point, generated from SEOCart's extension template. It does not do
  anything yet.
