// Tests that the card script prints one word and never what an error quotes, and builds its page as PayPal's script needs.
//
// Planted violation, shown red and removed: report() printing an error's message instead of its word: the address with
// the made-up token is printed.

import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { test } from 'node:test';

import { CHALLENGE_CODE, CONTROLS, KEY_DELAYS_MS, mayTryAgain, outcomeWord, page, reasonOf, report, sdkUrl, typeChecked, wordFor, WORDS } from './approve-card.mjs';

// A card field as typeChecked() handles it: keeps what it accepts, drops the first key of a value some number of times,
// or accepts nothing; records each try's key delay.
function fakeField({ dropsFirstKey = 0, accepts = true } = {}) {
	let value = '';
	let drops = dropsFirstKey;

	return {
		delays: [],
		readied: 0,
		ready: async function () {
			this.readied++;
		},
		clear: async () => {
			value = '';
		},
		type: async function (text, delay) {
			this.delays.push(delay);

			if (!accepts) {
				return;
			}

			value += drops > 0 ? text.slice(1) : text;
			drops = Math.max(0, drops - 1);
		},
		digits: async () => value.replace(/\D/g, '').length,
	};
}

// Prints through report() into a string.
function printed(outcome, reason = '', attempt = 2) {
	let text = '';

	report(
		outcome,
		reason,
		(chunk) => {
			text += chunk;
		},
		attempt
	);

	return text;
}

test('an error quoting an address with a token prints its name only', () => {
	const token = `A21AA${randomBytes(24).toString('hex')}`;
	const error = new Error(`page.goto: net::ERR_FAILED at https://www.sandbox.paypal.com/webapps/helios?token=${token}`);

	assert.equal(printed(error), 'Error\n');

	const named = new TypeError(`Cannot read the frame of https://www.paypal.com/smart/card-fields?token=${token}`);

	assert.equal(printed(named), 'TypeError\n');
	assert.ok(!printed(named).includes(token));
});

test('a timeout prints timeout, and an error without a plain name prints Error', () => {
	const timeout = new Error('Timeout 60000ms exceeded.');

	timeout.name = 'TimeoutError';
	assert.equal(outcomeWord(timeout), 'timeout');

	const odd = new Error('odd');

	odd.name = 'Name with https://example.com/?q=1';
	assert.equal(printed(odd), 'Error\n');
	assert.equal(printed(undefined), 'Error\n');
});

test('each outcome prints as itself, and nothing else does', () => {
	for (const word of ['approved', 'refused', 'challenge-passed', 'challenge-failed', 'no-fields', 'no-control', 'timeout']) {
		assert.ok(WORDS.includes(word));
		assert.equal(printed(word), `${word}\n`);
	}

	assert.equal(printed('declined'), 'Error\n', 'The fields\' own word is never printed: whether a challenge came first is.');
	assert.equal(printed('4868719196829038'), 'Error\n');
	assert.equal(printed('https://example.com/?token=x'), 'Error\n');
});

test('a field that drops a key is typed again, more slowly, and a field that keeps it once is typed once', async () => {
	const steady = fakeField();
	const dropping = fakeField({ dropsFirstKey: 1 });

	assert.equal(await typeChecked(steady, '123'), true);
	assert.deepEqual(steady.delays, [KEY_DELAYS_MS[0]]);
	assert.equal(await typeChecked(dropping, '12/30'), true, 'The expiry\'s dropped first key is typed again.');
	assert.deepEqual(dropping.delays, [KEY_DELAYS_MS[0], KEY_DELAYS_MS[1]]);
	assert.equal(dropping.readied, 1, 'The field is waited for before the first key.');
});

test('a field that never takes the value is given up after two more tries, and prints its name, never a value', async () => {
	const deaf = fakeField({ accepts: false });
	const stubborn = fakeField({ dropsFirstKey: 5 });

	assert.equal(await typeChecked(deaf, '123'), false);
	assert.deepEqual(deaf.delays, KEY_DELAYS_MS, 'Three tries, the last two slower.');
	assert.equal(await typeChecked(stubborn, '4868719166101368'), false);
	assert.ok(WORDS.includes('not-typed'));
	assert.equal(printed('not-typed', 'cvv'), 'not-typed cvv\n');
	assert.equal(printed('not-typed', '4868719166101368'), 'not-typed\n', 'Only a field\'s name follows the word.');
	assert.equal(printed('refused', 'cvv'), 'refused\n', 'A field\'s name follows no other word.');
});

test('a refusal before any challenge may be tried once more, and nothing else may', () => {
	assert.equal(mayTryAgain('refused', 1), true, 'A refusal before any challenge, on the first try: once more.');
	assert.equal(printed('refused', 'INVALID_CVV', 1), 'refused INVALID_CVV again\n');
	assert.equal(mayTryAgain('refused', 2), false, 'Two refusals: the test fails.');
	assert.equal(printed('refused', 'INVALID_CVV', 2), 'refused INVALID_CVV\n');

	for (const word of ['challenge-failed', 'challenge-passed', 'approved', 'not-typed', 'no-fields', 'no-control', 'timeout']) {
		assert.equal(mayTryAgain(word, 1), false, `${word} is never tried again.`);
		assert.ok(!printed(word, '', 1).includes('again'));
	}

	assert.equal(printed(new Error('refused'), '', 1), 'Error\n');
});

test('the fields\' answer is told apart by whether a challenge came before it', () => {
	assert.deepEqual(
		[wordFor('approved', false), wordFor('declined', false), wordFor('approved', true), wordFor('declined', true), wordFor('no-fields', false)],
		['approved', 'refused', 'challenge-passed', 'challenge-failed', 'no-fields']
	);
});

test('a refusal prints the issue PayPal named, and never anything else of what the fields threw', () => {
	const token = `A21AA${randomBytes(24).toString('hex')}`;
	const thrown = `Error: UNPROCESSABLE_ENTITY {"details":[{"issue":"INSTRUMENT_DECLINED"}],"links":["https://www.sandbox.paypal.com/x?token=${token}"],"card":"4868719196829038"}`;

	assert.equal(reasonOf(thrown), 'INSTRUMENT_DECLINED');
	assert.equal(printed('refused', reasonOf(thrown)), 'refused INSTRUMENT_DECLINED\n');
	assert.equal(reasonOf('Card fields submission failed'), '');
	assert.equal(printed('refused', ''), 'refused\n');
	assert.equal(printed('refused', `token=${token}`), 'refused\n', 'Only an issue\'s capitals and underscores are printed.');
	assert.equal(printed(new Error('x'), 'INSTRUMENT_DECLINED'), 'Error\n', 'An error prints its word alone.');
});

test('the page loads the card fields of PayPal\'s script to authorize, and quotes the order where the script reads it', () => {
	const url = new URL(sdkUrl('AClientId', 'EUR'));

	assert.equal(url.origin + url.pathname, 'https://www.paypal.com/sdk/js');
	assert.deepEqual(Object.fromEntries(url.searchParams), { 'client-id': 'AClientId', components: 'card-fields', intent: 'authorize', currency: 'EUR' });

	const html = page('AClientId', '5O190127TN364715T', 'USD');

	assert.match(html, /<script src="https:\/\/www\.paypal\.com\/sdk\/js\?client-id=AClientId&amp;components=card-fields&amp;intent=authorize&amp;currency=USD"><\/script>/);
	assert.match(html, /createOrder: \(\) => "5O190127TN364715T"/);
	assert.ok(!html.includes('4868'), 'The page holds no card: the card is typed into PayPal\'s fields.');
});

test('the controls match the names the sandbox challenge gives its buttons', () => {
	for (const name of ['Submit', 'SUBMIT', 'Continue', 'Complete authentication']) {
		assert.match(name, CONTROLS.complete);
		assert.doesNotMatch(name, CONTROLS.fail);
	}

	for (const name of ['Cancel', 'Fail authentication']) {
		assert.match(name, CONTROLS.fail);
		assert.doesNotMatch(name, CONTROLS.complete);
	}

	// The challenge's other button, and the cookie notice PayPal shows over it, are never pressed.
	for (const name of ['RESEND CODE', 'Yes, I accept', 'Close']) {
		assert.doesNotMatch(name, CONTROLS.complete);
		assert.doesNotMatch(name, CONTROLS.fail);
	}
});

test('the challenge\'s code is the sandbox simulator\'s, typed into its one-time code field', () => {
	assert.deepEqual(CHALLENGE_CODE, { field: 'input[name="otp"]', code: '1234' });
});
