// Tests the checkout's PayPal form, assets/island.js, as it ships, in a context of its own with a stand-in window,
// document and PayPal script: when it says PayPal takes a payment; the two parts of the form; when and how it loads
// PayPal's script; what confirm() names; how each step the store answers is taken, and what each of PayPal's answers
// comes to; what the shopper is shown; and the address it cleans.
//
// Planted violations, each shown red and removed:
// - createOrder answering an id outside a step: the createOrder test fails;
// - confirm() answering `card` for a form PayPal says is not valid: the confirm test fails;
// - `completed` answered when submit() is called rather than at onApprove: the onApprove test fails;
// - a step abandoned by unmount() answering `completed`: the unmount-during-a-step test fails;
// - the remembered approval removed (a second submit): the remembered-approval test fails;
// - PayPal's script loaded at mount: the loading test fails;
// - a fifth parameter (the amount) in the script's address: the loading test fails;
// - the redirect followed to another origin: the redirect test fails;
// - an `sdk` step taken with the PayPal part selected by submitting anyway: the other-part test fails;
// - the order id written to sessionStorage: the storage test fails (and tests/Unit/IslandTest.php's scan);
// - in giveUp(), a load that never answered kept as it is: the never-answering load's tests fail;
// - in showCard(), the card fields made for a load the form gave up on: the late-arrival test fails.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../assets/island.js', import.meta.url), 'utf8');

// An order's id and a payer's, in PayPal's shapes; made up.
const ORDER = '5O190127TN364715T';
const OTHER_ORDER = '8AB45678CD9012345';
const PAYER = 'QYR5Z8XDVJNXQ';

// The step the store answers a card placement with, and one it answers a PayPal placement with.
const SDK_STEP = { type: 'sdk', url: null, client_token: ORDER };
const REDIRECT = { type: 'redirect', url: `https://www.sandbox.paypal.com/checkoutnow?token=${ORDER}`, client_token: ORDER };

// The resume's details, as the host gives them.
const BILLING = { country: 'US', first_name: 'Ada', last_name: 'Lovelace', line1: '1 Main Street', line2: ' ', city: 'Springfield', region: 'IL', postcode: '62701', email: 'ada@example.com' };
const RESUME = { intentUuid: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6e', returnUrl: 'https://shop.example/checkout/?seocart_payment=0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6e', billing: BILLING };

// A stand-in for a DOM element: what the script does with one, recorded. Text appended is kept as a string.
function element(tag) {
	const listeners = {};

	return {
		tag,
		className: '',
		textContent: '',
		hidden: false,
		attributes: {},
		children: [],
		removed: false,
		setAttribute(name, value) {
			this.attributes[name] = value;
		},
		addEventListener(type, listener) {
			listeners[type] = listener;
		},
		dispatch(type, event = {}) {
			listeners[type]?.(event);
		},
		append(...nodes) {
			nodes.forEach((node) => typeof node === 'object' && (node.parent = this));
			this.children.push(...nodes);
		},
		prepend(...nodes) {
			nodes.forEach((node) => typeof node === 'object' && (node.parent = this));
			this.children.unshift(...nodes);
		},
		replaceChildren() {
			this.children = [];
		},
		remove() {
			this.removed = true;

			if (this.parent) {
				this.parent.children = this.parent.children.filter((child) => child !== this);
			}
		},
	};
}

// Every element under one, depth first.
function all(root) {
	return root.children.filter((child) => typeof child === 'object').flatMap((child) => [child, ...all(child)]);
}

// The text a shopper reads in an element: its own and its children's.
function text(root) {
	return [root.textContent, ...root.children.map((child) => (typeof child === 'string' ? child : text(child)))].join('').trim();
}

// A stand-in for PayPal's script's global: its card fields, and what each submission comes to, recorded. `eligible`
// is the account's answer; `renders` true for fields that render, false for ones that fail, 'never' for ones whose
// frames never answer; `state` what getState() resolves, or 'reject'; `submit` what a submission does: 'approve',
// 'error', 'cancel', 'reject', 'throw', 'resolve' (no callback) or 'hold' (the test answers through seen.last()).
function fakePayPal({ eligible = true, renders = true, state = { isFormValid: true }, submit = 'approve' } = {}) {
	const seen = { made: [], rendered: [], closed: 0, submissions: [], createOrder: [] };

	const field = () => ({
		render(container) {
			seen.rendered.push(container);

			if (renders === 'never') {
				return new Promise(() => {});
			}

			return renders ? Promise.resolve() : Promise.reject(new Error('render failed'));
		},
		close() {
			seen.closed++;
			return Promise.resolve();
		},
	});

	const paypal = {
		CardFields(options) {
			seen.made.push(options);

			return {
				isEligible: () => eligible,
				NumberField: field,
				ExpiryField: field,
				CVVField: field,
				getState: () => (state === 'reject' ? Promise.reject(new Error('no state')) : Promise.resolve(state)),
				submit(given) {
					seen.submissions.push(given);

					if (submit === 'throw') {
						throw new TypeError('The fields are gone.');
					}

					let order;

					try {
						order = options.createOrder();
					} catch (error) {
						seen.createOrder.push({ threw: error.code ?? error.name });
						options.onError?.(error);
						return Promise.reject(error);
					}

					seen.createOrder.push({ order });

					if (submit === 'approve') {
						options.onApprove({ orderID: order, liabilityShift: 'POSSIBLE' });
						return Promise.resolve();
					}

					if (submit === 'error') {
						options.onError(new Error(`UNPROCESSABLE_ENTITY ${order} https://www.sandbox.paypal.com/x`));
						return Promise.reject(new Error('INVALID_CVV'));
					}

					if (submit === 'cancel') {
						options.onCancel();
						return new Promise(() => {});
					}

					if (submit === 'reject') {
						return Promise.reject(new Error(`Card fields submission failed for ${order}`));
					}

					if (submit === 'resolve') {
						return Promise.resolve();
					}

					return new Promise(() => {});
				},
			};
		},
	};

	seen.last = () => seen.made[seen.made.length - 1];

	return { paypal, seen };
}

// Runs the script in a page whose address is given, and returns what it registered and the page. The window's timers
// are recorded and never run by themselves: a test runs one with timers[n].run(). Scripts appended to the head load by
// themselves, with the stand-in PayPal under their namespace, unless `load` is 'manual' (the test dispatches) or
// 'error'.
function load({ paypal = fakePayPal(), href = 'https://shop.example/checkout/', load: loading = 'auto', wp = null } = {}) {
	const registered = [];
	const head = element('head');
	const timers = [];
	const assigned = [];
	const replaced = [];
	const url = new URL(href);
	const storage = { writes: [], setItem: (...args) => storage.writes.push(args), getItem: () => null };
	const sandbox = {
		console,
		URL,
		document: { head, createElement: element },
		seocart: { payments: { register: (island) => registered.push(island) } },
		location: {
			href,
			search: url.search,
			pathname: url.pathname,
			hash: url.hash,
			assign: (to) => assigned.push(to),
		},
		history: { state: { kept: true }, replaceState: (state, unused, to) => replaced.push({ state, to }) },
		sessionStorage: storage,
		localStorage: storage,
		setTimeout: (run, ms) => timers.push({ run, ms, cleared: false }),
		clearTimeout: (id) => {
			if (timers[id - 1]) {
				timers[id - 1].cleared = true;
			}
		},
		...(wp ? { wp } : {}),
	};

	head.append = function (...nodes) {
		element('x').append.call(this, ...nodes);

		for (const script of nodes) {
			if (loading === 'auto') {
				queueMicrotask(() => {
					sandbox[script.attributes['data-namespace']] = paypal.paypal;
					script.dispatch('load');
				});
			} else if (loading === 'error') {
				queueMicrotask(() => script.dispatch('error'));
			}
		}
	};

	sandbox.window = sandbox;
	vm.runInContext(SOURCE, vm.createContext(sandbox), { filename: 'island.js' });
	assert.equal(registered.length, 1, 'The script registers one island.');

	return { island: registered[0], head, sandbox, seen: paypal.seen, paypal, timers, assigned, replaced, storage };
}

// Lets the script's pending promises run.
function settle() {
	return new Promise((resolve) => setImmediate(resolve));
}

// A payment context, as the host gives one.
function context(changes = {}) {
	return {
		gatewayId: 'paypal',
		mode: 'test',
		channel: 'storefront',
		amountMinor: 1999,
		currency: 'USD',
		billingCountry: 'US',
		cartVersion: 3,
		settings: { client_id: 'AExampleClientId', account_country: 'US', sca_policy: 'SCA_WHEN_REQUIRED', liability_shift_policy: 'require', webhook_id: '' },
		currencies: ['USD', 'GBP', 'EUR'],
		locale: 'en_US',
		...changes,
	};
}

// The form's two radios, PayPal's and the card's.
function radios(el) {
	return all(el).filter((node) => node.tag === 'input' && node.type === 'radio');
}

// Chooses a part of the form, as the shopper's click does.
function choose(el, value) {
	const radio = radios(el).find((node) => node.value === value);

	radio.checked = true;
	all(el)
		.find((node) => node.className === 'seocart-island__choices')
		.dispatch('change', { target: radio });
}

// The notices shown in the form.
function notices(el) {
	return el.children.filter((child) => typeof child === 'object' && child.className === 'seocart-notice is-error');
}

// Mounts the form with the card part chosen and its fields ready.
async function cardForm(options = {}, changes = {}) {
	const page = load(options);
	const el = element('div');
	const handle = page.island.mount(el, context(changes));

	choose(el, 'card');
	await settle();

	return { ...page, el, handle };
}

test('the island registers as paypal, against contract 0.1.0, taking the steps itself', () => {
	const { island } = load();

	assert.equal(island.id, 'paypal');
	assert.equal(island.contractVersion, '0.1.0');
	assert.equal(island.supports.action, true);
});

test('canMakePayment answers as the gateway would, row by row', () => {
	const { island } = load();
	const rows = [
		[{}, true, 'a payment of 19.99 USD in test mode'],
		[{ currency: 'GBP' }, true, 'in GBP'],
		[{ currency: 'EUR' }, true, 'in EUR'],
		[{ amountMinor: 1 }, true, 'the smallest amount'],
		[{ amountMinor: 0 }, false, 'nothing to pay'],
		[{ amountMinor: 9999999 }, true, 'the largest amount'],
		[{ amountMinor: 10000000 }, false, 'above the largest amount'],
		[{ amountMinor: 1000.5 }, false, 'not a whole number of minor units'],
		[{ currency: 'JPY', currencies: ['USD', 'GBP', 'EUR', 'JPY'] }, false, 'a currency the gateway does not take'],
		[{ currency: 'GBP', currencies: ['USD'] }, false, 'a currency the method does not take'],
		[{ settings: { client_id: '' } }, false, 'an empty client id'],
		[{ settings: { client_id: 7 } }, false, 'a client id that is not text'],
		[{ settings: {} }, false, 'no client id'],
		[{ mode: 'live' }, true, 'live mode, with its client id'],
	];

	for (const [changes, expected, row] of rows) {
		assert.equal(island.canMakePayment(context(changes)), expected, row);
	}
});

test('mount() resolves at once with the two parts, PayPal chosen, and loads nothing', () => {
	const { island, head } = load();
	const el = element('div');
	const handle = island.mount(el, context());

	assert.equal(typeof handle.confirm, 'function', 'The handle, not a promise of it.');
	assert.equal(head.children.length, 0, 'Nothing is loaded at registration or at mount.');
	assert.deepEqual(
		radios(el).map(({ value, checked }) => ({ value, checked })),
		[
			{ value: 'paypal', checked: true },
			{ value: 'card', checked: false },
		]
	);
	assert.equal(new Set(radios(el).map(({ name }) => name)).size, 1, 'One choice.');
	assert.match(text(el), /PayPal/);
	assert.match(text(el), /Debit or credit card/);

	const frame = all(el).find((node) => node.className === 'seocart-island__frame');

	assert.equal(frame.hidden, true, 'The card part is hidden while PayPal is chosen.');
});

test('PayPal\'s script is loaded when the card is first chosen, from PayPal, with exactly its four parameters, once per client id and currency', async () => {
	const { island, head, seen } = load();
	const el = element('div');

	island.mount(el, context());
	choose(el, 'card');
	choose(el, 'paypal');
	choose(el, 'card');
	await settle();

	assert.equal(head.children.length, 1, 'One script, however often the card is chosen.');

	const [script] = head.children;
	const url = new URL(script.src);

	assert.equal(url.origin + url.pathname, 'https://www.paypal.com/sdk/js');
	assert.deepEqual([...url.searchParams], [
		['client-id', 'AExampleClientId'],
		['components', 'card-fields'],
		['intent', 'authorize'],
		['currency', 'USD'],
	]);
	assert.match(script.attributes['data-namespace'], /^seocartPayPal\d+$/);
	assert.equal(seen.made.length, 1, 'One set of card fields for the mount.');
	assert.equal(seen.rendered.length, 3, 'Three fields: number, expiry, security code; no name field.');

	// A new mount for the same client id and currency loads nothing more; another currency loads a second script.
	const again = element('div');

	island.mount(again, context());
	await settle();
	assert.equal(radios(again).find(({ value }) => value === 'card').checked, true, 'The part chosen last is remembered across mounts.');
	assert.equal(head.children.length, 1);

	island.mount(element('div'), context({ currency: 'EUR' }));
	await settle();
	assert.equal(head.children.length, 2, 'A second currency, a second script.');
	assert.equal(new URL(head.children[1].src).searchParams.get('currency'), 'EUR');
	assert.notEqual(head.children[1].attributes['data-namespace'], script.attributes['data-namespace'], 'Each under a global of its own.');
});

test('a script that fails to load is removed, the card shown unavailable, and the next choice of the card tries again', async () => {
	const { island, head, timers } = load({ load: 'error' });
	const el = element('div');

	island.mount(el, context());
	choose(el, 'card');
	await settle();

	assert.equal(head.children.length, 0, 'The failed script is removed.');
	assert.match(text(el), /not available right now/);
	assert.equal(timers[0].cleared, true, 'The time limit is cleared.');

	choose(el, 'paypal');
	choose(el, 'card');
	assert.equal(head.children.length, 1, 'The next choice tries again.');
});

test('a script load that never answers is given up at 30 seconds, its element removed, and the next choice of the card loads it again, once', async () => {
	const { island, head, timers } = load({ load: 'manual' });
	const el = element('div');

	island.mount(el, context());
	choose(el, 'card');
	await settle();

	const [first] = head.children;

	timers[0].run();
	assert.match(text(el), /not available right now/);
	assert.deepEqual(head.children, [], 'The script that never answered is removed.');

	choose(el, 'paypal');
	choose(el, 'card');
	assert.equal(head.children.length, 1, 'One new request.');
	assert.notEqual(head.children[0].attributes['data-namespace'], first.attributes['data-namespace'], 'Under a global of its own.');
});

test('after a script load that never answered, a new mount with the card chosen loads it again', async () => {
	const { island, head, timers } = load({ load: 'manual' });
	const el = element('div');

	island.mount(el, context());
	choose(el, 'card');
	await settle();

	const [first] = head.children;

	timers[0].run();
	island.mount(element('div'), context());
	assert.equal(head.children.length, 1, 'One script on the page.');
	assert.notEqual(head.children[0].attributes['data-namespace'], first.attributes['data-namespace'], 'A new request, for the new mount.');
});

test('the abandoned script arriving late renders nothing and throws nothing, and the new load renders the fields', async () => {
	const unhandled = [];
	const note = (reason) => unhandled.push(reason);
	const { island, head, timers, sandbox, paypal, seen } = load({ load: 'manual' });
	const el = element('div');

	process.on('unhandledRejection', note);
	island.mount(el, context());
	choose(el, 'card');
	await settle();

	const [first] = head.children;

	timers[0].run();
	choose(el, 'paypal');
	choose(el, 'card');

	const [second] = head.children;

	sandbox[first.attributes['data-namespace']] = paypal.paypal;
	first.dispatch('load');
	await settle();
	assert.deepEqual([seen.made.length, seen.rendered.length], [0, 0], 'Nothing made or rendered for the late script.');

	sandbox[second.attributes['data-namespace']] = paypal.paypal;
	second.dispatch('load');
	await settle();
	process.off('unhandledRejection', note);
	assert.equal(seen.rendered.length, 3, 'The new load renders the three fields.');
	assert.doesNotMatch(text(el), /not available/);
	assert.deepEqual(unhandled, []);
});

test('card fields not ready within 30 seconds, or an account PayPal finds not eligible, leave the card unavailable and the PayPal part working', async () => {
	for (const [options, row] of [
		[{ renders: 'never' }, 'fields that never render'],
		[{ eligible: false }, 'an account not eligible'],
		[{ renders: false }, 'fields that fail to render'],
	]) {
		const { el, handle, timers, seen } = await cardForm({ paypal: fakePayPal(options) });

		assert.deepEqual(
			timers.map(({ ms }) => ms),
			[30000],
			`${row}: the wait is limited.`
		);

		if (options.renders === 'never') {
			assert.doesNotMatch(text(el), /not available/, 'Not before the limit.');
			timers[0].run();
		}

		assert.match(text(el), /not available right now/, row);
		assert.equal(seen.closed, options.eligible === false ? 0 : 3, `${row}: the fields made are closed.`);
		await assert.rejects(handle.confirm({ billing: null }), { name: 'PayPalIslandError', code: 'card_unavailable' }, row);

		choose(el, 'paypal');
		assert.deepEqual(JSON.parse(JSON.stringify(await handle.confirm({ billing: null }))), { payment_data: { payment_token: 'paypal' } }, `${row}: the PayPal part works.`);
	}
});

test('fields that become ready clear their time limit and show nothing but the fields', async () => {
	const { el, timers } = await cardForm();

	assert.deepEqual(
		timers.map(({ ms, cleared }) => ({ ms, cleared })),
		[{ ms: 30000, cleared: true }]
	);
	assert.doesNotMatch(text(el), /Loading|not available/);
});

test('confirm() names the part chosen, and refuses a card form PayPal does not call valid', async () => {
	const paypalPart = load().island.mount(element('div'), context());

	assert.deepEqual(JSON.parse(JSON.stringify(await paypalPart.confirm({ billing: BILLING }))), { payment_data: { payment_token: 'paypal' } });

	const { handle } = await cardForm();

	assert.deepEqual(JSON.parse(JSON.stringify(await handle.confirm({ billing: BILLING }))), { payment_data: { payment_token: 'card' } });

	for (const state of [{ isFormValid: false }, {}, 'reject']) {
		const { handle: invalid } = await cardForm({ paypal: fakePayPal({ state }) });

		await assert.rejects(invalid.confirm({ billing: BILLING }), { name: 'PayPalIslandError', code: 'incomplete_card' }, JSON.stringify(state));
	}
});

test('confirm() refuses the card while PayPal\'s script is still loading', async () => {
	const { island } = load({ load: 'manual' });
	const el = element('div');
	const handle = island.mount(el, context());

	choose(el, 'card');
	assert.match(text(el), /Loading the card form/);
	await assert.rejects(handle.confirm({ billing: null }), { code: 'card_unavailable', message: /Loading/ });
});

test('createOrder throws outside a step, and answers the step\'s order only inside the submission action() makes', async () => {
	const { handle, seen } = await cardForm();
	const { createOrder } = seen.last();

	assert.throws(() => createOrder(), { name: 'PayPalIslandError', code: 'no_step' });
	assert.equal(await handle.action(SDK_STEP, RESUME), 'completed');
	assert.deepEqual(JSON.parse(JSON.stringify(seen.createOrder)), [{ order: ORDER }]);
	assert.throws(() => createOrder(), { code: 'no_step' }, 'Once the step is answered, no order again.');
});

test('an sdk step submits the fields with the billing address in PayPal\'s names, and answers completed at onApprove and not before', async () => {
	const { handle, seen } = await cardForm({ paypal: fakePayPal({ submit: 'hold' }) });
	let answered = null;
	const acting = handle.action(SDK_STEP, RESUME).then((result) => (answered = result));

	await settle();
	assert.equal(answered, null, 'Not completed when submit() is called.');
	assert.deepEqual(JSON.parse(JSON.stringify(seen.submissions)), [
		{ billingAddress: { addressLine1: '1 Main Street', adminArea1: 'IL', adminArea2: 'Springfield', postalCode: '62701', countryCode: 'US' } },
	]);

	seen.last().onApprove({ orderID: ORDER });
	assert.equal(await acting, 'completed');
});

test('without a billing address the fields are submitted with none', async () => {
	const { handle, seen } = await cardForm();

	assert.equal(await handle.action(SDK_STEP, { ...RESUME, billing: null }), 'completed');
	assert.deepEqual(seen.submissions, [undefined]);
});

test('each of PayPal\'s other answers fails the step and shows the island\'s words, never PayPal\'s', async () => {
	for (const submit of ['error', 'cancel', 'reject', 'throw', 'resolve']) {
		const { el, handle } = await cardForm({ paypal: fakePayPal({ submit }) });

		assert.equal(await handle.action(SDK_STEP, RESUME), 'failed', submit);

		const shown = notices(el).map(text);

		if (submit === 'resolve') {
			assert.deepEqual(shown, [], 'A submission that resolves unanswered fails, saying nothing.');
		} else {
			assert.deepEqual(shown, ['PayPal could not take this card; check it and place the order again.'], submit);
			assert.equal(notices(el)[0].attributes.role, 'alert');
		}

		assert.doesNotMatch(text(el), /UNPROCESSABLE|INVALID_CVV|sandbox|5O190127TN364715T/, `${submit}: nothing of PayPal's is shown.`);
	}
});

test('an order approved on the page is never submitted again: the next action for it is completed without PayPal', async () => {
	const { handle, seen } = await cardForm();

	assert.equal(await handle.action(SDK_STEP, RESUME), 'completed');
	assert.equal(await handle.action(SDK_STEP, RESUME), 'completed');
	assert.equal(seen.submissions.length, 1, 'One submission.');

	assert.equal(await handle.action({ ...SDK_STEP, client_token: OTHER_ORDER }, RESUME), 'completed');
	assert.equal(seen.submissions.length, 2, 'Another order is submitted.');
});

test('a second action while one is in flight is answered the same, with one submission', async () => {
	const { handle, seen } = await cardForm({ paypal: fakePayPal({ submit: 'hold' }) });
	const first = handle.action(SDK_STEP, RESUME);
	const second = handle.action(SDK_STEP, RESUME);

	assert.equal(first, second);
	seen.last().onApprove({});
	assert.equal(await first, 'completed');
	assert.equal(seen.submissions.length, 1);
});

test('an answer after the step settled is dropped: the first answer counts', async () => {
	const { el, handle, seen } = await cardForm({ paypal: fakePayPal({ submit: 'hold' }) });
	const acting = handle.action(SDK_STEP, RESUME);

	seen.last().onCancel();
	seen.last().onApprove({});
	assert.equal(await acting, 'failed');
	assert.equal(notices(el).length, 1);

	// The late approval is not remembered: the next action submits the order again.
	handle.action(SDK_STEP, RESUME);
	await settle();
	assert.equal(seen.submissions.length, 2);
});

test('unmount() during a step abandons it: the action answers left, whatever PayPal answers later', async () => {
	for (const late of ['onApprove', 'onError', 'onCancel']) {
		const { el, handle, seen } = await cardForm({ paypal: fakePayPal({ submit: 'hold' }) });
		const acting = handle.action(SDK_STEP, RESUME);
		const { createOrder, [late]: answer } = seen.last();

		handle.unmount();
		answer(new Error('late'));
		assert.equal(await acting, 'left', `PayPal answered ${late} after the form was gone.`);
		assert.throws(() => createOrder(), { code: 'no_step' });
		assert.equal(seen.closed, 3, 'The fields are closed.');
		assert.equal(el.children.length, 0, 'The form is emptied.');
	}
});

test('an action asked of a form already unmounted calls PayPal not at all, and answers left; nothing is shown after', async () => {
	const { el, handle, seen, assigned } = await cardForm();

	handle.unmount();
	assert.equal(await handle.action(SDK_STEP, RESUME), 'left');
	assert.equal(await handle.action(REDIRECT, RESUME), 'left');
	assert.deepEqual([seen.submissions.length, assigned.length], [0, 0]);
	handle.showError({ code: 'declined', message: 'The payment was declined.' });
	assert.equal(el.children.length, 0);
});

test('a redirect to PayPal\'s page for the order is followed once, and the action answers left', async () => {
	const { island, assigned } = load();
	const handle = island.mount(element('div'), context());

	assert.equal(await handle.action(REDIRECT, RESUME), 'left');
	assert.deepEqual(assigned, [REDIRECT.url]);

	const live = load();
	const liveForm = live.island.mount(element('div'), context({ mode: 'live' }));
	const liveStep = { ...REDIRECT, url: `https://www.paypal.com/checkoutnow?token=${ORDER}` };

	assert.equal(await liveForm.action(liveStep, RESUME), 'left');
	assert.deepEqual(live.assigned, [liveStep.url]);
});

test('a redirect anywhere else, or for another order, is refused: nothing followed, the action fails', async () => {
	const rows = [
		[{ url: `https://evil.example/checkoutnow?token=${ORDER}` }, 'another origin'],
		[{ url: `https://www.paypal.com/checkoutnow?token=${ORDER}` }, 'the live page in test mode'],
		[{ url: `http://www.sandbox.paypal.com/checkoutnow?token=${ORDER}` }, 'plain HTTP'],
		[{ url: `https://www.sandbox.paypal.com.evil.example/checkoutnow?token=${ORDER}` }, 'a look-alike host'],
		[{ url: `https://www.sandbox.paypal.com/checkoutnow?token=${OTHER_ORDER}` }, 'another order\'s page'],
		[{ client_token: 'not-an-order' }, 'a handle that is no order id'],
		[{ url: 'javascript:alert(1)' }, 'a script address'],
		[{ url: 'not a url' }, 'no address'],
		[{ url: null }, 'none at all'],
	];

	for (const [changes, row] of rows) {
		const { island, assigned } = load();
		const handle = island.mount(element('div'), context());

		assert.equal(await handle.action({ ...REDIRECT, ...changes }, RESUME), 'failed', row);
		assert.deepEqual(assigned, [], `${row}: nothing followed.`);
	}
});

test('a step of the other part selects that part, says so, and fails: no link followed, no card submitted', async () => {
	// A card form answered a redirect, kept from an earlier placement with PayPal.
	const card = await cardForm();

	assert.equal(await card.handle.action(REDIRECT, RESUME), 'failed');
	assert.deepEqual(card.assigned, []);
	assert.equal(radios(card.el).find(({ value }) => value === 'paypal').checked, true, 'The PayPal part is selected.');
	assert.deepEqual(notices(card.el).map(text), ['Finish the payment you started, or try again later.']);

	// The PayPal part answered an sdk step, kept from an earlier placement by card: the card fields are ready, as the
	// shopper chose the card before choosing PayPal again.
	const { el, handle, seen } = await cardForm();

	choose(el, 'paypal');
	assert.equal(await handle.action(SDK_STEP, RESUME), 'failed');
	assert.equal(seen.submissions.length, 0, 'Nothing submitted, though the card fields are there.');
	assert.equal(radios(el).find(({ value }) => value === 'card').checked, true, 'The card part is selected.');
	assert.deepEqual(notices(el).map(text), ['Finish the payment you started, or try again later.']);
});

test('a step the island does not know, or an sdk step with no order id, fails without PayPal', async () => {
	const { handle, seen, assigned } = await cardForm();

	for (const step of [{ type: 'otp', url: null, client_token: ORDER }, { ...SDK_STEP, client_token: null }, { ...SDK_STEP, client_token: 'pi_secret_example' }, null]) {
		assert.equal(await handle.action(step, RESUME), 'failed', JSON.stringify(step));
	}

	assert.deepEqual([seen.submissions.length, assigned.length], [0, 0]);
});

test('showError() shows a decline by the store\'s message or the island\'s, and the island\'s own refusals; never any other text', async () => {
	const { el, handle } = await cardForm({ paypal: fakePayPal({ state: { isFormValid: false } }) });

	handle.showError({ code: 'declined', message: 'Your card was declined by its bank.' });
	assert.deepEqual(notices(el).map(text), ['Your card was declined by its bank.']);

	handle.showError({ code: 'declined', message: '' });
	assert.deepEqual(notices(el).map(text), ['The payment was declined.'], 'One notice at a time.');

	const refused = await handle.confirm({ billing: null }).catch((error) => error);

	handle.showError({ code: 'island_refused', detail: refused.code, message: refused.message });
	assert.deepEqual(notices(el).map(text), ['Enter the card\'s number, expiry date and security code.']);

	handle.showError({ code: 'island_refused', detail: 'x', message: 'INSTRUMENT_DECLINED https://www.sandbox.paypal.com/x' });
	handle.showError({ code: 'stale', message: 'not mounted' });
	assert.deepEqual(notices(el).map(text), ['Enter the card\'s number, expiry date and security code.'], 'Another text is never shown.');

	await handle.confirm({ billing: null }).catch(() => null);
	assert.deepEqual(notices(el), [], 'The next confirmation clears the notice.');
});

test('a decline leaves the card typed: the form is not emptied and its fields not closed', async () => {
	const { el, handle, seen } = await cardForm();

	handle.showError({ code: 'declined', message: '' });
	assert.equal(seen.closed, 0);
	assert.equal(radios(el).length, 2);
});

test('the words go through wp.i18n when the page has it, in the plugin\'s text domain', async () => {
	const asked = [];
	const wp = { i18n: { __: (words, domain) => (asked.push(domain), `«${words}»`) } };
	const { island } = load({ wp });
	const el = element('div');

	island.mount(el, context());
	assert.match(text(el), /«PayPal»/);
	assert.ok(asked.length >= 8);
	assert.deepEqual([...new Set(asked)], ['seocart-gateway-for-paypal']);
});

test('the page\'s address is cleaned of the order\'s and the payer\'s ids PayPal adds, and nothing else', () => {
	const rows = [
		[`/checkout/?seocart_payment=0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6e&token=${ORDER}&PayerID=${PAYER}`, '/checkout/?seocart_payment=0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6e'],
		[`/checkout/?token=${ORDER}#pay`, '/checkout/#pay'],
		[`/checkout/?a=1&PayerID=${PAYER}&b=%20x`, '/checkout/?a=1&b=%20x'],
	];

	for (const [path, cleaned] of rows) {
		const { replaced } = load({ href: `https://shop.example${path}` });

		assert.deepEqual(JSON.parse(JSON.stringify(replaced)), [{ state: { kept: true }, to: cleaned }], path);
	}

	for (const path of ['/checkout/', '/checkout/?token=abc', `/checkout/?token=${ORDER.toLowerCase()}`, '/checkout/?PayerID=SHORT', `/checkout/?mytoken=${ORDER}`]) {
		assert.deepEqual(load({ href: `https://shop.example${path}` }).replaced, [], `${path}: left alone.`);
	}
});

test('nothing is kept in the browser\'s storage, and no address but PayPal\'s is loaded or followed', async () => {
	const { handle, storage, head, assigned } = await cardForm();

	await handle.action(SDK_STEP, RESUME);
	assert.deepEqual(storage.writes, []);
	assert.deepEqual(
		head.children.map((script) => new URL(script.src).origin),
		['https://www.paypal.com']
	);
	assert.deepEqual(assigned, []);
});
