// Approves a PayPal order with a sandbox card typed into PayPal's card fields, for the PayPal sandbox workflow's live tests.
//
// Reads one JSON line on its standard input, never an argument a process list would show:
//   { "clientId": the sandbox REST app's client id, "orderId": the order to approve, "currency": its currency,
//     "card": { "number", "expiry" as MM/YY, "cvv", "name" } (a sandbox test card),
//     "control": "complete" or "fail", for a 3-D Secure challenge, "browser": a browser to launch, or "" for Playwright's own }
// Serves a page at http://127.0.0.1:<port> that loads PayPal's script with the client id and renders its card fields
// for the order, types the card, submits it, presses the challenge's control when the bank asks, and prints exactly one
// word: approved, declined, no-fields, no-control or timeout; any other error is reduced to its name, as a message of
// Playwright's or PayPal's can quote an address that holds a token. Nothing else is printed, and no trace, video or
// screenshot is taken.

import { createServer } from 'node:http';
import { pathToFileURL } from 'node:url';

// The controls of the sandbox's 3-D Secure challenge, by what the test asks for: a button's accessible name, looked for
// in every frame, as the challenge is a frame inside PayPal's. PayPal does not document the challenge's markup, so these
// are the one place to change when it changes.
export const CONTROLS = {
	complete: /^\s*(submit|continue|complete|authenticate|ok)\b/i,
	fail: /^\s*(cancel|fail|decline)\b/i,
};

// How long PayPal's script may take to render the fields, the challenge to appear, and PayPal to answer.
const FIELDS_MS = 60_000;
const CONTROL_MS = 20_000;
const ANSWER_MS = 60_000;

// How long a card without a challenge may take to be answered, before the script looks for a challenge's control.
const CHALLENGE_WAIT_MS = 10_000;

// Every word the script can print besides an error's name.
export const WORDS = ['approved', 'declined', 'no-fields', 'no-control', 'timeout'];

// The address of PayPal's script; the client id names the sandbox.
export const SDK = 'https://www.paypal.com/sdk/js';

// Reduces an error to one word: `timeout` for Playwright's timeouts, otherwise the error's name.
export function outcomeWord(error) {
	const name = error && typeof error.name === 'string' ? error.name : '';

	if (name === 'TimeoutError') {
		return 'timeout';
	}

	return /^[A-Za-z]{1,40}$/.test(name) ? name : 'Error';
}

// Prints an outcome, or an error reduced to its word, and nothing else.
export function report(outcome, write = (text) => process.stdout.write(text)) {
	write(`${typeof outcome === 'string' && WORDS.includes(outcome) ? outcome : outcomeWord(outcome)}\n`);
}

// Returns the address of PayPal's script for a client id and a currency: the card fields, for an order to authorize.
export function sdkUrl(clientId, currency) {
	const url = new URL(SDK);

	url.searchParams.set('client-id', clientId);
	url.searchParams.set('components', 'card-fields');
	url.searchParams.set('intent', 'authorize');
	url.searchParams.set('currency', currency);

	return url.toString();
}

// Returns the page that renders PayPal's card fields for the order; the values are JSON-encoded where the script reads them.
export function page(clientId, orderId, currency) {
	const sdk = sdkUrl(clientId, currency).replaceAll('&', '&amp;').replaceAll('"', '&quot;');

	return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Card fields</title><script src="${sdk}"></script></head>
<body>
<div id="name"></div><div id="number"></div><div id="expiry"></div><div id="cvv"></div>
<script>
window.outcome = null;
const fields = paypal.CardFields({
	createOrder: () => ${JSON.stringify(orderId)},
	onApprove: () => { window.outcome = 'approved'; },
	onError: () => { window.outcome = 'declined'; },
});
if (!fields.isEligible()) {
	window.outcome = 'no-fields';
} else {
	Promise.all([
		fields.NameField().render('#name'),
		fields.NumberField().render('#number'),
		fields.ExpiryField().render('#expiry'),
		fields.CVVField().render('#cvv'),
	]).then(() => { window.rendered = true; });
	window.submitCard = () => fields.submit().catch(() => { window.outcome = window.outcome || 'declined'; });
}
</script>
</body></html>`;
}

// The field's own frame in its container: PayPal's script adds a frame of its own beside it, which watches for a close.
export const FIELD_FRAME = 'iframe[title^="paypal_card_"]';

// Types a value into the input of the PayPal frame inside a container.
async function type(browserPage, container, value) {
	const input = browserPage.frameLocator(`${container} ${FIELD_FRAME}`).locator('input');

	await input.waitFor({ timeout: FIELDS_MS });
	await input.click();
	// Typed key by key, as a shopper types: PayPal's fields format and check what they are given as it is typed.
	await input.pressSequentially(value, { delay: 40 });
}

// Presses the first button of a name in any frame of the page; false when none appeared in time.
async function press(browserPage, name, until) {
	while (Date.now() < until) {
		for (const frame of browserPage.frames()) {
			const button = frame.getByRole('button', { name });

			if ((await button.count().catch(() => 0)) > 0) {
				await button.first().click({ timeout: CONTROL_MS });
				return true;
			}
		}

		if ((await browserPage.evaluate(() => window.outcome).catch(() => null)) !== null) {
			return true;
		}

		await new Promise((resolve) => setTimeout(resolve, 500));
	}

	return false;
}

// Waits for PayPal's answer to the submission for a while; null when none came in time.
async function settled(browserPage, ms) {
	for (const until = Date.now() + ms; Date.now() < until; ) {
		const outcome = await browserPage.evaluate(() => window.outcome);

		if (outcome !== null) {
			return outcome;
		}

		await new Promise((resolve) => setTimeout(resolve, 500));
	}

	return null;
}

// Serves the page once on a free port of 127.0.0.1, and returns its address and a way to stop serving.
async function serve(html) {
	const server = createServer((request, response) => {
		response.writeHead(200, { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-store' });
		response.end(html);
	});

	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

	return { url: `http://127.0.0.1:${server.address().port}/`, close: () => new Promise((resolve) => server.close(resolve)) };
}

// Opens the page, types the card, submits it, takes the bank's challenge when one comes, and waits for PayPal's answer.
async function approve({ clientId, orderId, currency, card, control, browser }) {
	const { chromium } = await import('playwright');
	const served = await serve(page(clientId, orderId, currency));
	const launched = await chromium.launch({ headless: true, ...(browser ? { executablePath: browser } : {}) });

	try {
		const browserPage = await (await launched.newContext()).newPage();

		await browserPage.goto(served.url, { waitUntil: 'load', timeout: FIELDS_MS });
		await browserPage.waitForFunction(() => window.rendered === true || window.outcome !== null, null, { timeout: FIELDS_MS });

		if ((await browserPage.evaluate(() => window.outcome)) === 'no-fields') {
			return 'no-fields';
		}

		await type(browserPage, '#name', card.name);
		await type(browserPage, '#number', card.number);
		await type(browserPage, '#expiry', card.expiry);
		await type(browserPage, '#cvv', card.cvv);

		// Not awaited in the page: the submission settles only once a challenge, if the bank asks for one, is done.
		await browserPage.evaluate(() => {
			window.submitCard();
		});

		const answered = await settled(browserPage, CHALLENGE_WAIT_MS);

		if (answered !== null) {
			return answered;
		}

		if (!(await press(browserPage, CONTROLS[control], Date.now() + CONTROL_MS))) {
			return 'no-control';
		}

		return (await settled(browserPage, ANSWER_MS)) ?? 'timeout';
	} finally {
		await launched.close();
		await served.close();
	}
}

// Reads the one JSON line of the standard input.
async function readInput() {
	let text = '';

	for await (const chunk of process.stdin) {
		text += chunk;
	}

	const input = JSON.parse(text.split('\n')[0]);
	const card = input.card ?? {};

	if (
		typeof input.clientId !== 'string' ||
		!/^[A-Z0-9]{17}$/.test(input.orderId ?? '') ||
		!/^[A-Z]{3}$/.test(input.currency ?? '') ||
		!['number', 'expiry', 'cvv', 'name'].every((field) => typeof card[field] === 'string') ||
		!((input.control ?? 'complete') in CONTROLS)
	) {
		throw new TypeError('The input names no client id, order, currency, card or known control.');
	}

	return { control: 'complete', browser: '', ...input };
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
	try {
		report(await approve(await readInput()));
	} catch (error) {
		report(error);
	}
}
