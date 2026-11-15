// Approves a PayPal order with a sandbox card typed into PayPal's card fields, for the PayPal sandbox workflow's live tests.
//
// Reads one JSON line on its standard input, never an argument a process list would show:
//   { "clientId": the sandbox REST app's client id, "orderId": the order to approve, "currency": its currency,
//     "card": { "number", "expiry" as MM/YY, "cvv", "name" } (a sandbox test card),
//     "control": "complete" or "fail", for a 3-D Secure challenge, "browser": a browser to launch, or "" for Playwright's own,
//     "attempt": 1 for the first try of the payment, 2 for the one more the test may make (mayTryAgain()) }
// Serves a page at http://127.0.0.1:<port> that loads PayPal's script with the client id and renders its card fields
// for the order, types the card, submits it, takes the bank's challenge when it asks (its code, or its control), and prints
// one line: a word of WORDS, saying what the fields answered and whether a challenge came first, followed, when the fields
// refused, by the issue PayPal named (capitals and underscores only, such as INSTRUMENT_DECLINED), or, when a field never
// held what was typed, by the field's name. Each field is read back as the count of digits it holds, never its value,
// and the card is submitted only when every count matches what was typed. Any other error is
// reduced to its name, as a message of Playwright's or PayPal's can quote an address that holds a token. Nothing else is
// printed, and no trace, video or screenshot is taken.

import { createServer } from 'node:http';
import { pathToFileURL } from 'node:url';

// The controls of the sandbox's 3-D Secure challenge, by what the test asks for: a button's accessible name, looked for
// in every frame, as the challenge is a frame inside PayPal's. PayPal does not document the challenge's markup, so these
// and CHALLENGE_CODE are the one place to change when it changes.
export const CONTROLS = {
	complete: /^\s*(submit|continue|complete|authenticate|ok)\b/i,
	fail: /^\s*(cancel|fail|decline)\b/i,
};

// The one-time code the sandbox's challenge asks for (PayPal's authentication simulator, a frame inside PayPal's), and
// the field it is typed into. The code is entered and submitted from the keyboard: PayPal's cookie notice can cover the
// challenge's button, and a key press needs no pointer.
export const CHALLENGE_CODE = { field: 'input[name="otp"]', code: '1234' };

// How long PayPal's script may take to render the fields, the challenge to appear, and PayPal to answer.
const FIELDS_MS = 60_000;
const CONTROL_MS = 20_000;
const ANSWER_MS = 60_000;

// How long a card without a challenge may take to be answered, before the script looks for a challenge's control.
const CHALLENGE_WAIT_MS = 10_000;

// Every word the script can print besides an error's name: the fields approved with no challenge (`approved`) or
// refused before any (`refused`); a challenge was shown, answered, and the fields then approved (`challenge-passed`) or
// refused (`challenge-failed`); a field never held what was typed, so nothing was submitted (`not-typed`, followed by the
// field's name); or the fields never rendered, no control of the challenge came, or no answer did.
export const WORDS = ['approved', 'refused', 'challenge-passed', 'challenge-failed', 'not-typed', 'no-fields', 'no-control', 'timeout'];

// Returns the issue PayPal named in what the fields threw when they refused, such as INSTRUMENT_DECLINED: the last run of
// capitals joined by underscores, which can hold no address, token or card; empty when there is none.
export function reasonOf(text) {
	const issues = typeof text === 'string' ? text.match(/\b[A-Z]+(?:_[A-Z]+)+\b/g) : null;
	const issue = issues ? issues[issues.length - 1] : '';

	return issue.length <= 64 ? issue : '';
}

// Tells whether the live test may try the payment once more, with a fresh order: only when the fields refused the card
// before any challenge (every field held what was typed, or nothing was submitted), and only after the first try. Never
// after a challenge was shown, and never a third time: a second refusal is the test's failure.
export function mayTryAgain(word, attempt) {
	return word === 'refused' && attempt === 1;
}

// Returns the word for the fields' answer, by whether a challenge was answered before it.
export function wordFor(answer, challenged) {
	if (answer === 'approved') {
		return challenged ? 'challenge-passed' : 'approved';
	}

	if (answer === 'declined') {
		return challenged ? 'challenge-failed' : 'refused';
	}

	return answer;
}

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

// Prints an outcome and, for a refusal, the issue PayPal named, or for a field never typed, the field's name, then
// `again` when the test may try once more (mayTryAgain()); or an error reduced to its word; and nothing else.
export function report(outcome, reason = '', write = (text) => process.stdout.write(text), attempt = 1) {
	const word = typeof outcome === 'string' && WORDS.includes(outcome) ? outcome : outcomeWord(outcome);
	const named = word === 'not-typed' ? CHECKED_FIELDS.includes(reason) : reasonOf(reason) === reason;
	const issue = word !== outcome || !named ? '' : reason;
	const again = word === outcome && mayTryAgain(word, attempt);

	write(`${word}${issue ? ` ${issue}` : ''}${again ? ' again' : ''}\n`);
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
window.reason = '';
const refused = (error) => {
	window.reason = window.reason || String((error && (error.message || error)) || '');
	window.outcome = window.outcome || 'declined';
};
const fields = paypal.CardFields({
	createOrder: () => ${JSON.stringify(orderId)},
	onApprove: () => { window.outcome = 'approved'; },
	onError: refused,
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
	window.submitCard = () => fields.submit().catch(refused);
}
</script>
</body></html>`;
}

// The field's own frame in its container: PayPal's script adds a frame of its own beside it, which watches for a close.
export const FIELD_FRAME = 'iframe[title^="paypal_card_"]';

// The card's fields whose typing is checked, by their container's id: each holds digits only once PayPal formats it.
export const CHECKED_FIELDS = ['number', 'expiry', 'cvv'];

// How long to wait between keys on each try of a field: a field that dropped a key is typed again, more slowly.
export const KEY_DELAYS_MS = [40, 90, 150];

// Types a value into a field until the field holds as many digits as the value has: typed once, then, when the field
// holds fewer or more, cleared and typed again, at most twice, more slowly. The field is a handle with ready(),
// clear(), type(value, delay) and digits(), the count of digits it holds, never its value. True once the counts match.
export async function typeChecked(field, value, delays = KEY_DELAYS_MS) {
	const wanted = value.replace(/\D/g, '').length;

	await field.ready();

	for (const [attempt, delay] of delays.entries()) {
		if (attempt > 0) {
			await field.clear();
		}

		await field.type(value, delay);

		if ((await field.digits()) === wanted) {
			return true;
		}
	}

	return false;
}

// Returns the handle of the input of PayPal's frame inside a container: waited for until attached, visible and
// editable; typed key by key, as a shopper types, since PayPal's fields format and check what they are given as it is
// typed; and read back as the count of digits it holds, the only thing of its value that leaves the frame.
function cardField(browserPage, container) {
	const input = browserPage.frameLocator(`${container} ${FIELD_FRAME}`).locator('input');

	return {
		ready: async () => {
			await input.waitFor({ state: 'visible', timeout: FIELDS_MS });

			for (const until = Date.now() + FIELDS_MS; !(await input.isEditable().catch(() => false)); ) {
				if (Date.now() > until) {
					throw Object.assign(new Error('A card field never became editable.'), { name: 'TimeoutError' });
				}

				await new Promise((resolve) => setTimeout(resolve, 250));
			}
		},
		clear: async () => {
			await input.click();
			await input.press('ControlOrMeta+A');
			await input.press('Backspace');
		},
		type: async (value, delay) => {
			await input.click();
			await input.pressSequentially(value, { delay });
		},
		digits: () => input.evaluate((element) => element.value.replace(/\D/g, '').length),
	};
}

// Presses the first button of a name in any frame of the page: `challenge` once it did, `answered` when the fields
// answered first, null when neither came in time.
async function press(browserPage, name, until) {
	while (Date.now() < until) {
		for (const frame of browserPage.frames()) {
			const button = frame.getByRole('button', { name });

			if ((await button.count().catch(() => 0)) > 0) {
				await button.first().click({ timeout: CONTROL_MS });
				return 'challenge';
			}
		}

		if ((await browserPage.evaluate(() => window.outcome).catch(() => null)) !== null) {
			return 'answered';
		}

		await new Promise((resolve) => setTimeout(resolve, 500));
	}

	return null;
}

// Types the challenge's code where a frame asks for one, and submits it with the Enter key: `challenge` once it did,
// `answered` when the fields answered first, null when neither came in time.
async function enterCode(browserPage, until) {
	while (Date.now() < until) {
		for (const frame of browserPage.frames()) {
			const field = frame.locator(CHALLENGE_CODE.field);

			if ((await field.count().catch(() => 0)) > 0) {
				await field.first().fill(CHALLENGE_CODE.code, { timeout: CONTROL_MS });
				await field.first().press('Enter', { timeout: CONTROL_MS });
				return 'challenge';
			}
		}

		if ((await browserPage.evaluate(() => window.outcome).catch(() => null)) !== null) {
			return 'answered';
		}

		await new Promise((resolve) => setTimeout(resolve, 500));
	}

	return null;
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

// Opens the page, types the card, submits it, takes the bank's challenge when one comes, and waits for PayPal's answer:
// the word for it, and the issue PayPal named when the fields refused. Without a browser of its own it runs Playwright's
// Chromium as a whole browser in its headless mode, never the stripped headless shell, as a shopper's browser is whole.
async function approve({ clientId, orderId, currency, card, control, browser }) {
	const { chromium } = await import('playwright');
	const served = await serve(page(clientId, orderId, currency));
	const launched = await chromium.launch({ headless: true, ...(browser ? { executablePath: browser } : { channel: 'chromium' }) });

	try {
		const browserPage = await (await launched.newContext()).newPage();
		const answer = async (challenged) => {
			const outcome = await settled(browserPage, ANSWER_MS);

			return outcome === null ? { word: 'timeout' } : { word: wordFor(outcome, challenged), reason: reasonOf(await browserPage.evaluate(() => window.reason)) };
		};

		await browserPage.goto(served.url, { waitUntil: 'load', timeout: FIELDS_MS });
		await browserPage.waitForFunction(() => window.rendered === true || window.outcome !== null, null, { timeout: FIELDS_MS });

		if ((await browserPage.evaluate(() => window.outcome)) === 'no-fields') {
			return { word: 'no-fields' };
		}

		// The name is optional and holds no digits, so it is typed once; the number, the expiry and the security code are
		// checked, and the card is submitted only when each held every digit typed: a typing fault is never PayPal's refusal.
		// Each is read back while it still has the focus: PayPal shows the number as its last four digits once it loses it.
		const name = cardField(browserPage, '#name');

		await name.ready();
		await name.type(card.name, KEY_DELAYS_MS[0]);

		for (const field of CHECKED_FIELDS) {
			if (!(await typeChecked(cardField(browserPage, `#${field}`), card[field]))) {
				return { word: 'not-typed', reason: field };
			}
		}

		// Not awaited in the page: the submission settles only once a challenge, if the bank asks for one, is done.
		await browserPage.evaluate(() => {
			window.submitCard();
		});

		if ((await settled(browserPage, CHALLENGE_WAIT_MS)) !== null) {
			// Awaited here, so the browser is closed only once the answer is read.
			return await answer(false);
		}

		// To complete the challenge, its code when it asks for one, otherwise its button; to fail it, its button.
		const step = (control === 'complete' ? await enterCode(browserPage, Date.now() + CONTROL_MS) : null) ?? (await press(browserPage, CONTROLS[control], Date.now() + CONTROL_MS));

		return step === null ? { word: 'no-control' } : await answer(step === 'challenge');
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
		!((input.control ?? 'complete') in CONTROLS) ||
		![1, 2].includes(input.attempt ?? 1)
	) {
		throw new TypeError('The input names no client id, order, currency, card, known control or first or second try.');
	}

	return { control: 'complete', browser: '', attempt: 1, ...input };
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
	try {
		const input = await readInput();
		const { word, reason } = await approve(input);

		report(word, ['refused', 'challenge-failed', 'not-typed'].includes(word) ? (reason ?? '') : '', undefined, input.attempt);
	} catch (error) {
		report(error);
	}
}
