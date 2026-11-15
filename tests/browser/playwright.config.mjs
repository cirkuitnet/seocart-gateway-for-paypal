// The checkout's PayPal form in a browser: specs/checkout.spec.mjs, against a WordPress site with SEOCart and this
// plugin active and the PayPal gateway in test mode, which WP_BASE_URL names. bin/ci/paypal-sandbox.sh installs that
// site on the runner and runs this (`site`, `configure`, `checkout`), the tests tagged @when-required with the sandbox
// settings asking for a bank's check when required, then those tagged @always with them asking for it always.
//
// No trace, video or screenshot is taken, and nothing is uploaded: PayPal's frames carry the order's id and the REST
// app's client id in their addresses. One worker runs the tests in order: they share the site's product and the sandbox.

import { defineConfig } from '@playwright/test';

export default defineConfig({
	testDir: './specs',
	// SEOCart's payment fixture is TypeScript in the SEOCart checkout beside this one, and imports @playwright/test from
	// there; this maps that import to this package's copy, the one running the tests, as Playwright refuses to load two.
	tsconfig: './tsconfig.json',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	forbidOnly: Boolean(process.env.CI),
	// PayPal's script, a bank's challenge and the store's answers each take seconds; a test pays up to two cards.
	timeout: 240_000,
	expect: { timeout: 30_000 },
	reporter: [['list']],
	outputDir: 'test-results',
	use: {
		baseURL: process.env.WP_BASE_URL,
		// An action on a page, such as typing into a field that never comes, fails in half a minute rather than at the
		// test's own limit.
		actionTimeout: 30_000,
		trace: 'off',
		video: 'off',
		screenshot: 'off',
	},
	projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
