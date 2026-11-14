// Tests that the card script prints one word and never what an error quotes, and builds its page as PayPal's script needs.
//
// Planted violation, shown red and removed: report() printing an error's message instead of its word: the address with
// the made-up token is printed.

import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { test } from 'node:test';

import { CHALLENGE_CODE, CONTROLS, outcomeWord, page, reasonOf, report, sdkUrl, wordFor, WORDS } from './approve-card.mjs';

// Prints through report() into a string.
function printed(outcome, reason = '') {
	let text = '';

	report(outcome, reason, (chunk) => {
		text += chunk;
	});

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
