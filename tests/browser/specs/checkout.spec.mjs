// The checkout's PayPal form in a browser: SEOCart's payment host with this plugin's island, PayPal's card fields and
// PayPal's approval page, on SEOCart's payment fixture page (its tests/E2E/support/payments-page.ts, in the SEOCart
// checkout beside this one), against a site whose PayPal gateway is in test mode, against PayPal's sandbox.
//
// The site is WP_BASE_URL, its administrator WP_USERNAME and WP_PASSWORD, who creates the one product the tests buy and
// trashes it afterwards. The page's configuration takes the gateway's public settings (the client id, the account's
// country and the two policies) from the sandbox settings file SEOCART_TAPE_SETTINGS names, read by PHP, which never
// answers the client secret. The site must trust the runner's own address as a proxy (SEOCART_TRUSTED_PROXIES): each
// test is then a client of its own, by a fresh address in X-Forwarded-For, since the store allows one client five
// placements and resumes an hour.
//
// Every test is a guest's, whose cart is built through the page's own request context; records every request the page
// sends; and holds the page to the host's contract: only the host's two writes reach the Store API, no secret or card
// number is in a Store API request or the site's addresses, and the tab keeps nothing secret. Only PayPal's published
// sandbox cards are typed, only into PayPal's own fields, key by key, each field's digit count read back while it has
// the focus (card-fields.mjs). No trace, video or screenshot is taken. A test watches PayPal's script, through the global
// the island loads it under, for the card fields it makes and what they answer, by the callback's name alone.
//
// The store's answer decides every payment: the island reports what PayPal's fields answered, and the order is the one
// the host hands over after the resume, read back with its key.
//
// Two groups, as the store's settings ask PayPal for the bank's check (bin/ci/paypal-sandbox.sh runs each with its
// settings): @when-required, where the sandbox checks no card, and @always, with the liability policy `require`.
//
// 1. @when-required: nothing is requested from PayPal until the card is chosen, then one script and three fields;
// 2. @when-required: a card with no challenge is approved: the order processing, its payment authorized;
// 5. @when-required: Place pressed twice at once places once;
// 7. @when-required: a new amount while the form is shown mounts it again, and the placement carries the amount;
// 8. @when-required: paying with PayPal: Place sends the page to PayPal's sandbox approval page for the store's order
//    and nothing else (the test answers that navigation itself, so PayPal's sign-in is never loaded); back at the
//    checkout without approving, the host rests waiting for the shopper; Place again is answered the same order and
//    leaves for the same page; nothing is authorized;
// 3. @always: the step-up card: PayPal's challenge appears on the page, its code is typed, and the resume approves;
// 4. @always: the failing step-up card: the resume declines it (no liability shift under `require`), the island shows
//    the decline, the cart is open again; another card is then approved as a new order;
// 6. @always: the page reloaded during the challenge: the host resumes on loading and waits; the card typed again is
//    placed under the same key, answered the same order, challenged again and approved.
//
// The sandbox's card fields now and then refuse a card before any challenge (INVALID_CVV with every digit typed). A case
// may press Place once more, only when the host rests waiting for the shopper with no challenge shown and every field
// verified; it says so in its output, and a second such rest fails the case.

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { expect, request, test } from '@playwright/test';

import {
	expectNoSecretInUrls,
	expectNothingSecretKept,
	expectOnlyHostWrites,
	expectRemountOn,
	forwardClientAddress,
	hostEvents,
	hostState,
	placedOrders,
	restUrlOf,
	servePaymentsPage,
	storeRequests,
	storeRouteOf,
	waitForHost,
} from '../../../../seocart/tests/E2E/support/payments-page.ts';
import { CHALLENGE_CODE, CHECKED_FIELDS, cardField, enterCode, typeChecked } from '../card-fields.mjs';

// PayPal's published sandbox cards (developer.paypal.com, card testing), typed with a future expiry and any security
// code: one the bank always challenges and passes, one it challenges and fails, and one it never challenges.
const CARDS = {
	stepUp: { number: '4868719166101368', expiry: '12/30', cvv: '123' },
	failing: { number: '4868719181895556', expiry: '12/30', cvv: '123' },
	frictionless: { number: '4012888888881881', expiry: '12/30', cvv: '123' },
};

// The island's script, as it ships.
const ISLAND = readFileSync(new URL('../../../assets/island.js', import.meta.url), 'utf8');

// The Store API's namespace.
const STORE = '/seocart/store/v1';

// The argument SEOCart's return addresses carry the payment's uuid in.
const RETURN_ARG = 'seocart_payment';

// The sandbox's approval page.
const APPROVAL = 'https://www.sandbox.paypal.com/checkoutnow';

// The product's price, in minor units.
const PRICE = 1999;

// How long PayPal and a bank's challenge may take to answer.
const PAYPAL_MS = 90_000;

// How long PayPal's fields may take to be shown, and to take a key.
const FIELDS_MS = 60_000;

// The billing address the checkout holds and the host gives the island at each call.
const BILLING = {
	country: 'US',
	first_name: 'Ada',
	last_name: 'Lovelace',
	line1: '1 Main Street',
	city: 'Springfield',
	region: 'IL',
	postcode: '62701',
	email: 'ada@example.com',
};

// The address the running test's requests to the site come from; set before each test.
let client = '';

// What the running test's page wrote to its console, printed when the test fails.
let consoleLines = [];

// How many times the running test pressed Place once more after the sandbox refused a card before any challenge.
let extraPresses = 0;

// The addresses of PayPal's script the running test's page itself requested: PayPal's own frames load it too.
let scriptLoads = [];

// The answers to the running test's placements: each payment's uuid and its PayPal order's id.
let placements = [];

// An id PayPal's script writes in the page's local storage, as measured: `uid_`, ten hexadecimal digits, `_`, and
// eleven lower-case letters and digits.
const PAYPAL_STORAGE_ID = /^uid_[0-9a-f]{10}_[a-z0-9]{11}$/;

// Returns a line with every address's query and every id of PayPal's shape replaced.
function redacted(line) {
	return line.replace(/(https?:\/\/[^\s?'"]+)\?[^\s'"]*/g, '$1?<query>').replace(/\b[A-Z0-9]{17}\b/g, '<id>');
}

// Returns the site's home URL, with its trailing slash.
function home() {
	return String(process.env.WP_BASE_URL ?? '').replace(/\/?$/, '/');
}

// Returns the URL of a Store API route.
function storeUrl(route) {
	return restUrlOf(home(), `${STORE}${route}`);
}

// Returns the headers of the running test's Store API requests.
function storeHeaders() {
	return { 'X-SEOCart-Store': '1', 'X-Forwarded-For': client };
}

// Returns the gateway's public settings from the settings file. PHP reads the file and answers these members only: the
// client secret never reaches this process.
function publicSettings() {
	const code =
		'$s = json_decode( (string) file_get_contents( $argv[1] ), true ); $p = array();' +
		'foreach ( array( "client_id", "account_country", "sca_policy", "liability_shift_policy", "webhook_id" ) as $n ) { $p[ $n ] = (string) ( $s[ $n ] ?? "" ); }' +
		'echo json_encode( $p );';

	return JSON.parse(execFileSync('php', ['-r', code, process.env.SEOCART_TAPE_SETTINGS ?? ''], { encoding: 'utf8' }));
}

// The PayPal method, as a checkout's payment configuration lists it.
function paypalMethod() {
	return {
		id: 'paypal',
		label: 'PayPal',
		mode: 'test',
		island: true,
		settings: publicSettings(),
		currencies: ['USD', 'GBP', 'EUR'],
		instructions: null,
		paysOnDelivery: false,
	};
}

// A method paid outside any provider, listed first in the first case.
const BANK = {
	id: 'bank_transfer',
	label: 'Bank transfer',
	mode: 'test',
	island: false,
	settings: {},
	currencies: ['USD', 'GBP', 'EUR'],
	instructions: 'Pay by transfer, quoting your order number.',
	paysOnDelivery: false,
};

// Signs the administrator in, in a request context of its own, and returns it with the headers of its REST requests.
async function administrator() {
	const admin = await request.newContext({ baseURL: home(), ignoreHTTPSErrors: true });

	await admin.get('wp-login.php');

	const signedIn = await admin.post('wp-login.php', {
		form: { log: process.env.WP_USERNAME ?? '', pwd: process.env.WP_PASSWORD ?? '', 'wp-submit': 'Log In', testcookie: '1' },
		maxRedirects: 0,
	});

	expect(signedIn.status(), 'The administrator signs in.').toBe(302);

	const nonce = (await (await admin.get('wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();

	return { admin, headers: { 'X-WP-Nonce': nonce } };
}

// Returns the guest's cart as the Store API answers it.
async function readCart(api) {
	return (await api.get(storeUrl('/cart'), { headers: storeHeaders() })).json();
}

// Adds units of the product to the cart.
async function addLine(api, variantId, quantity) {
	const cart = await readCart(api);
	const added = await api.post(storeUrl('/cart/lines'), {
		headers: storeHeaders(),
		data: { lines: [{ variant_id: variantId, quantity }], ...(cart.version > 0 ? { cart_version: cart.version } : {}) },
	});

	expect(added.status(), 'The line is added.').toBe(200);
}

// Builds a guest's cart: one unit of the product, and a checkout paid with PayPal.
async function readyCart(api, variantId) {
	await addLine(api, variantId, 1);

	const { email, ...address } = BILLING;
	const written = await api.put(storeUrl('/checkout'), {
		headers: storeHeaders(),
		data: { cart_version: (await readCart(api)).version, billing_address: { ...address, email }, shipping_address: address, payment_method_key: 'paypal' },
	});

	expect(written.status(), 'The checkout is written.').toBe(200);
}

// Watches PayPal's script on every page the test loads, on `window.__paypalSeen`: each set of card fields the island
// makes, each submission, and each answer of the fields by its callback's name, never what it carries. The island loads
// the script under a global of its own (`seocartPayPal<n>`), which PayPal's script fills once it is assigned; its
// CardFields, and each set's submit, are wrapped in place the first time the island reads the global.
function watchPayPal() {
	const seen = (window.__paypalSeen = { made: 0, submitted: 0, answers: [] });
	const wrapped = new WeakSet();
	const answered = (name, callback) => (...args) => {
		seen.answers.push(name);
		return callback?.(...args);
	};
	const wrap = (paypal) => {
		if (!paypal || wrapped.has(paypal) || typeof paypal.CardFields !== 'function') {
			return;
		}

		const make = paypal.CardFields;

		wrapped.add(paypal);
		paypal.CardFields = function (options) {
			const fields = make.call(this, { ...options, onApprove: answered('onApprove', options.onApprove), onError: answered('onError', options.onError), onCancel: answered('onCancel', options.onCancel) });
			const submit = fields.submit;

			seen.made++;
			fields.submit = function (...args) {
				seen.submitted++;
				return submit.apply(this, args);
			};

			return fields;
		};
	};

	for (let n = 1; n <= 9; n++) {
		let given;

		Object.defineProperty(window, `seocartPayPal${n}`, {
			configurable: true,
			get() {
				wrap(given);
				return given;
			},
			set(paypal) {
				given = paypal;
			},
		});
	}
}

// Returns what the page saw of PayPal's script.
function paypalSeen(page) {
	return page.evaluate(() => window.__paypalSeen);
}

// Hands the host the cart as the Store API has it now, with the billing address, as the checkout does. The cart is read
// first and handed over in an evaluation of its own that awaits nothing: handed over inside the evaluation that awaited
// the read, a change that mounts the island again, and so closes PayPal's frames, lost that evaluation's result
// (Playwright: "Resulting promise was garbage collected").
async function updateHost(page) {
	const cart = await page.evaluate(() => window.__seocartPayments.readCart());

	await page.evaluate(([read, billing]) => {
		window.__seocartPayments.host.update({ ...read, billingCountry: billing.country, billing });
	}, [cart, BILLING]);
}

// The fixture page's options: the island and the methods given.
function fixture(pluginBasePath, methods) {
	return {
		baseURL: home(),
		pluginBasePath,
		config: { contract: '0.1.0', restRoot: `${home()}?rest_route=/`, returnQueryArg: RETURN_ARG, locale: 'en_US', methods },
		islands: [{ source: ISLAND, at: 'before' }],
	};
}

// Serves the fixture page with the island and the methods given, opens it, and gives the host the billing address.
async function openCheckout(page, pluginBasePath, methods) {
	const address = await servePaymentsPage(page, fixture(pluginBasePath, methods));

	await page.goto(address);
	await waitForHost(page);
	await updateHost(page);

	return address;
}

// The island's element.
function island(page) {
	return page.locator('.seocart-island[data-gateway="paypal"]');
}

// Chooses a part of the PayPal form: `paypal` or `card`.
async function choosePart(page, part) {
	await island(page).locator(`input[type="radio"][value="${part}"]`).check();
}

// The selector of a card field's container in the island: number, expiry and security code, in that order.
function fieldContainer(field) {
	return `.seocart-island[data-gateway="paypal"] .seocart-island__frame > :nth-child(${CHECKED_FIELDS.indexOf(field) + 1})`;
}

// Opens the checkout with PayPal and chooses the card, which shows PayPal's three fields.
async function openCardForm(page, pluginBasePath) {
	await openCheckout(page, pluginBasePath, [paypalMethod()]);
	await choosePart(page, 'card');
	await expect(island(page).locator('iframe[title^="paypal_card_"]')).toHaveCount(3, { timeout: FIELDS_MS });
}

// Types a card into PayPal's fields, key by key, each field read back as the count of digits it holds while it has the
// focus, and typed again at most twice; fails at once, naming the field, when one never holds what was typed: nothing
// is placed for a card the fields did not take. Fields that hold an earlier card are cleared first.
async function typeCard(page, card, { clear = false } = {}) {
	for (const field of CHECKED_FIELDS) {
		const handle = cardField(page, fieldContainer(field), FIELDS_MS);

		if (clear) {
			await handle.ready();
			await handle.clear();
		}

		expect(await typeChecked(handle, card[field]), `card not typed: the ${field} field never held every digit typed.`).toBe(true);
	}
}

// Tells whether PayPal's challenge, the sandbox's authentication simulator, is on the page: its code's field in a frame.
async function challengeShown(page) {
	for (const frame of page.frames()) {
		if ((await frame.locator(CHALLENGE_CODE.field).count().catch(() => 0)) > 0) {
			return true;
		}
	}

	return false;
}

// Presses the page's Place button.
async function placeOrder(page) {
	await page.locator('#seocart-place').click();
}

// Returns how many calls of the host's callbacks the page has recorded so far.
async function eventCount(page) {
	return (await hostEvents(page)).length;
}

// Waits until, since the host's callback numbered `since`, the host has handed over an order or rested with an error,
// or PayPal's challenge is on the page; says which.
async function nextMoment(page, since) {
	let moment = null;

	await expect
		.poll(
			async () => {
				const events = (await hostEvents(page)).slice(since);
				const last = events.filter((event) => event.type === 'change').at(-1)?.change;

				if (events.some((event) => event.type === 'placed')) {
					moment = 'placed';
				} else if (await challengeShown(page)) {
					moment = 'challenge';
				} else {
					moment = last?.phase === 'idle' && last.error ? last.error.code : null;
				}

				return moment;
			},
			{ timeout: PAYPAL_MS, message: 'The host hands over an order, rests, or PayPal challenges the card.' }
		)
		.not.toBeNull();

	return moment;
}

// Places the order with the card typed and waits for what comes: the challenge or an answer. When the sandbox refused
// the card before any challenge (the host rests waiting for the shopper, no challenge shown, every field verified), the
// card is placed once more, said in the output; a second such rest fails.
async function placeCard(page) {
	const since = await eventCount(page);

	await placeOrder(page);

	let moment = await nextMoment(page, since);

	if (moment === 'action_incomplete' && !(await challengeShown(page))) {
		const again = await eventCount(page);

		extraPresses++;
		// eslint-disable-next-line no-console
		console.log(`extra press: the sandbox's fields refused the card before any challenge (PayPal's answers: ${JSON.stringify((await paypalSeen(page)).answers)}); Place pressed once more.`);
		await placeOrder(page);
		moment = await nextMoment(page, again);
		expect(moment, 'The card is refused before any challenge a second time.').not.toBe('action_incomplete');
	}

	return moment;
}

// Waits for PayPal's challenge, types its code and submits it; then waits until the challenge has closed.
async function passChallenge(page) {
	await expect.poll(() => challengeShown(page), { timeout: PAYPAL_MS, message: 'PayPal\'s challenge appears on the page.' }).toBe(true);
	expect(await enterCode(page, Date.now() + PAYPAL_MS, async () => false), 'The challenge\'s code is typed.').toBe('challenge');
	await expect.poll(() => challengeShown(page), { timeout: PAYPAL_MS, message: 'The challenge closes once answered.' }).toBe(false);
}

// Waits until the host has handed over an order, and returns it.
async function awaitPlaced(page) {
	await expect.poll(async () => (await placedOrders(page)).length, { timeout: PAYPAL_MS }).toBe(1);

	return (await placedOrders(page))[0];
}

// Waits until the host rests with an error, and returns its code.
async function awaitError(page, code) {
	await expect.poll(async () => (await hostState(page))?.error?.code ?? null, { timeout: PAYPAL_MS }).toBe(code);
}

// Reads an order's statuses as its customer does, with its key in the header.
async function orderStatus(api, placed) {
	const answer = await api.get(storeUrl(`/orders/${placed.orderUuid}`), {
		headers: { ...storeHeaders(), 'X-SEOCart-Order-Key': placed.orderKey },
	});

	expect(answer.status(), 'The order is read with its key.').toBe(200);

	const { status, payment_status: paymentStatus } = await answer.json();

	return { status, paymentStatus };
}

// Expects the order handed over to be approved: processing, its payment authorized for later capture.
async function expectApproved(page, placed) {
	expect(placed.outcome).toBe('approved');
	expect(await orderStatus(page.request, placed)).toEqual({ status: 'processing', paymentStatus: 'authorized' });
	expect((await hostState(page))?.phase).toBe('settled');
}

// Returns the page's placements and resumes, in order: the route, the idempotency key, the payment's uuid a resume
// names, and the answer's status.
function hostWrites(ledger) {
	return ledger.store
		.filter((entry) => entry.method === 'POST')
		.map((entry) => ({
			route: entry.route,
			key: entry.idempotencyKey,
			intent: entry.route.endsWith('/resume') ? JSON.parse(entry.body).intent_uuid : null,
			status: entry.status,
		}));
}

// Expects the routes of the host's writes: those given, after one more placement and resume for each extra press the
// case made, which came before them.
function expectWrites(ledger, routes) {
	const extra = Array.from({ length: extraPresses }, () => [`${STORE}/checkout`, `${STORE}/checkout/resume`]).flat();

	expect(hostWrites(ledger).map(({ route }) => route)).toEqual([...extra, ...routes]);
}

// Records the placements' answers the page receives, in order: the payment's uuid, the step's kind and order id, and
// the status. The placement is sent on by the test and its answer read before the page has it, as the page may leave
// for PayPal at once; it goes with the test's client address, as every request of the test's to the site does.
async function watchPlacements(page) {
	const answers = [];

	await page.route(
		(url) => storeRouteOf(url.href) === `${STORE}/checkout`,
		async (route) => {
			if (route.request().method() !== 'POST') {
				await route.fallback();
				return;
			}

			const response = await route.fetch({ headers: { ...(await route.request().allHeaders()), 'x-forwarded-for': client } });
			const body = await response.json().catch(() => null);

			answers.push({ intent: body?.intent_uuid ?? null, order: body?.next_action?.client_token ?? null, type: body?.next_action?.type ?? null, status: body?.status ?? null });
			await route.fulfill({ response });
		}
	);

	return answers;
}

// Expects a decline to be shown in the island, the cart read again for it, and the host at rest.
async function expectDeclineShown(page) {
	await awaitError(page, 'declined');
	await expect(island(page).locator('.seocart-notice.is-error')).not.toBeEmpty();
	expect((await hostEvents(page)).filter((event) => event.type === 'stale').map((event) => event.detail.reason)).toEqual(['declined']);
	expect(await placedOrders(page)).toEqual([]);
}

// PayPal's script keeps its own state in the page's local storage, under `__<its global>_storage__`: an id, a session
// (an id and the time it began, in milliseconds) and the funding it found, measured empty in every card payment. The
// time is a run of thirteen digits, which the fixture's card-number check takes for a card about one time in ten. Each
// such item is held to exactly that measured shape: those members and no other, both ids PayPal's pattern, the time an
// integer within ten minutes of now, the funding empty; and none of the run's own values (its orders' ids, its
// payments' uuids, an order's key) anywhere in it. Then it is removed, so that the fixture's check holds the rest of
// the tab's storage as it is.
async function expectPayPalStorageOnly(page, known) {
	const items = await page.evaluate(() =>
		Array.from({ length: window.localStorage.length }, (_, index) => window.localStorage.key(index))
			.filter((key) => /^__seocartPayPal\d+_storage__$/.test(key))
			.map((key) => [key, window.localStorage.getItem(key)])
	);

	for (const [key, text] of items) {
		const item = JSON.parse(text);

		expect(Object.keys(item).sort(), `PayPal's ${key} holds its id, its session and its funding, and nothing else.`).toEqual(['__session__', 'funding', 'id']);
		expect(Object.keys(item.__session__).sort(), `PayPal's ${key} session holds its id and its time.`).toEqual(['created', 'guid']);
		expect(item.id, `PayPal's ${key} id is PayPal's own.`).toMatch(PAYPAL_STORAGE_ID);
		expect(item.__session__.guid, `PayPal's ${key} session id is PayPal's own.`).toMatch(PAYPAL_STORAGE_ID);
		expect(Number.isInteger(item.__session__.created) && Math.abs(item.__session__.created - Date.now()) < 600_000, `PayPal's ${key} session's time is now.`).toBe(true);
		expect(item.funding, `PayPal's ${key} funding is empty.`).toEqual({});

		for (const value of known.filter(Boolean)) {
			expect(text.includes(value), `PayPal's ${key} holds a value of the run's.`).toBe(false);
		}
	}

	await page.evaluate((keys) => keys.forEach((key) => window.localStorage.removeItem(key)), items.map(([key]) => key));
}

// Holds the page to the host's contract: only its writes reached the Store API; no secret given or card number is in a
// Store API request or an address of the site; the tab keeps nothing secret. PayPal's own requests carry its ids to
// PayPal, and are left out; PayPal's own state in the page's storage is held to its shape first.
async function expectContractKept(page, ledger, secrets) {
	const origin = new URL(home()).origin;

	await expectOnlyHostWrites(page, ledger);
	expectNoSecretInUrls({ ...ledger, urls: ledger.urls.filter((url) => url.startsWith(`${origin}/`)) }, secrets);
	await expectPayPalStorageOnly(page, [...secrets, ...placements.flatMap(({ intent, order }) => [intent, order])]);
	await expectNothingSecretKept(page, secrets);
}

test.use({ trace: 'off', screenshot: 'off', video: 'off' });

test.describe('The PayPal form in the checkout, as a guest', () => {
	let product;
	let ledger;
	let pluginBasePath;

	test.beforeAll(async () => {
		const { admin, headers } = await administrator();
		const created = await admin.post(restUrlOf(home(), '/wp/v2/seocart-products'), {
			headers,
			data: { title: 'PayPal form spec: a product a guest pays for', status: 'publish', seocart: { sku: `PAYPAL-E2E-${Date.now()}`, price_minor: PRICE } },
		});

		expect(created.status(), 'The product is created.').toBe(201);

		const { id, seocart } = await created.json();
		const stocked = await admin.post(restUrlOf(home(), `/seocart/v1/stock-items/${seocart.variant_id}/adjustments`), {
			headers,
			data: { delta: 400, reason: 'received' },
		});

		expect(stocked.status(), 'The product is stocked.').toBeLessThan(300);

		product = { id, variantId: seocart.variant_id };
		pluginBasePath = `${new URL(home()).pathname}wp-content/plugins/seocart/`;
		await admin.dispose();
	});

	test.afterAll(async () => {
		if (!product) {
			return;
		}

		const { admin, headers } = await administrator();

		// The trash, not a delete: the orders' allocations keep the variant from being deleted.
		await admin.delete(restUrlOf(home(), `/wp/v2/seocart-products/${product.id}`), { headers });
		await admin.dispose();
	});

	test.beforeEach(async ({ page }) => {
		// A client of its own, for this test.
		client = await forwardClientAddress(page.context(), home());
		ledger = storeRequests(page);
		consoleLines = [];
		extraPresses = 0;
		scriptLoads = [];
		placements = await watchPlacements(page);
		page.on('request', (request) => {
			try {
				if (request.frame() === page.mainFrame() && request.url().startsWith('https://www.paypal.com/sdk/js')) {
					scriptLoads.push(request.url());
				}
			} catch {
				// A request of no frame, such as a worker's, is not the page's.
			}
		});
		page.on('console', (message) => consoleLines.push(`${message.type()}: ${redacted(message.text())}`));
		await page.addInitScript(watchPayPal);
	});

	test.afterEach(async ({ page }, testInfo) => {
		const seen = (await paypalSeen(page).catch(() => null)) ?? {};

		// What PayPal's fields answered, by callback, and how often Place was pressed once more.
		// eslint-disable-next-line no-console
		console.log(`PayPal's fields: ${seen.made ?? 0} made, ${seen.submitted ?? 0} submitted, answers ${JSON.stringify(seen.answers ?? [])}; extra presses: ${extraPresses}`);

		if (testInfo.status !== testInfo.expectedStatus) {
			const state = await hostState(page).catch(() => null);

			// eslint-disable-next-line no-console
			console.log([ledger.describe(), `The host: ${state?.phase ?? 'unknown'}, ${state?.error?.code ?? 'no error'} ${state?.error?.detail ?? ''}`, 'Console:', ...consoleLines.slice(-20)].join('\n'));
		}
	});

	test('(1) nothing is requested from PayPal until the card is chosen, then one script and three fields @when-required', async ({ page }) => {
		await readyCart(page.request, product.variantId);
		await openCheckout(page, pluginBasePath, [BANK, paypalMethod()]);

		const paypalRequests = () => ledger.urls.filter((url) => /^https:\/\/([a-z0-9-]+\.)*paypal(objects)?\.com\//.test(url));

		await expect(page.locator('.seocart-choice[data-method="bank_transfer"]')).toHaveClass(/is-selected/);
		await page.locator('.seocart-choice[data-method="paypal"] input[type="radio"]').check();
		await expect(island(page).locator('input[type="radio"][value="paypal"]')).toBeChecked();
		await page.waitForTimeout(1000);
		expect(paypalRequests(), 'Nothing is requested from PayPal while the PayPal part is chosen.').toEqual([]);
		// As soon as the form is shown: an island that writes to the store fails here, on the ledger.
		await expectOnlyHostWrites(page, ledger);

		await choosePart(page, 'card');
		await expect(island(page).locator('iframe[title^="paypal_card_"]')).toHaveCount(3, { timeout: FIELDS_MS });

		const [script] = scriptLoads;
		const url = new URL(script);

		expect(scriptLoads.length, 'One script.').toBe(1);
		expect(await page.locator('script[src^="https://www.paypal.com/sdk/js"]').count(), 'One script element.').toBe(1);
		expect([...url.searchParams.keys()], 'Exactly its four parameters.').toEqual(['client-id', 'components', 'intent', 'currency']);
		expect([url.searchParams.get('components'), url.searchParams.get('intent'), url.searchParams.get('currency')]).toEqual(['card-fields', 'authorize', (await readCart(page.request)).totals.currency]);
		expect((await paypalSeen(page)).made, 'One set of card fields.').toBe(1);
		await typeCard(page, CARDS.frictionless);
		expect((await paypalSeen(page)).submitted, 'Nothing is submitted before the order is placed.').toBe(0);
		await expectContractKept(page, ledger, []);
	});

	test('(2) a card with no challenge is approved: the order processing, its payment authorized @when-required', async ({ page }) => {
		await readyCart(page.request, product.variantId);
		await openCardForm(page, pluginBasePath);
		await typeCard(page, CARDS.frictionless);
		expect(await placeCard(page), 'No challenge: the host hands the order over.').toBe('placed');

		const placed = await awaitPlaced(page);

		await expectApproved(page, placed);
		expectWrites(ledger, [`${STORE}/checkout`, `${STORE}/checkout/resume`]);
		expect((await paypalSeen(page)).answers.at(-1)).toBe('onApprove');
		await expectContractKept(page, ledger, [placed.orderKey]);
	});

	test('(5) Place pressed twice at once places once @when-required', async ({ page }) => {
		await readyCart(page.request, product.variantId);
		await openCardForm(page, pluginBasePath);
		await typeCard(page, CARDS.frictionless);
		// The fixture's Place button calls the host on every click, and stays enabled: both clicks reach it, which the
		// page counts.
		await page.evaluate(() => {
			window.__placeClicks = 0;
			document.getElementById('seocart-place').addEventListener('click', () => window.__placeClicks++);
		});
		const since = await eventCount(page);

		await page.locator('#seocart-place').dblclick();

		if ((await nextMoment(page, since)) === 'action_incomplete') {
			const again = await eventCount(page);

			extraPresses++;
			// eslint-disable-next-line no-console
			console.log('extra press: the sandbox\'s fields refused the card before any challenge; Place pressed once more.');
			await placeOrder(page);
			expect(await nextMoment(page, again), 'The card is refused before any challenge a second time.').not.toBe('action_incomplete');
		}

		const placed = await awaitPlaced(page);

		expect(await page.evaluate(() => window.__placeClicks), 'Both clicks reached Place.').toBeGreaterThanOrEqual(2);
		await expectApproved(page, placed);
		expectWrites(ledger, [`${STORE}/checkout`, `${STORE}/checkout/resume`]);
		expect((await paypalSeen(page)).submitted, 'One submission of the card per placement.').toBe(1 + extraPresses);
		await expectContractKept(page, ledger, [placed.orderKey]);
	});

	test('(7) a new amount while the form is shown mounts it again, and the placement carries the amount @when-required', async ({ page }) => {
		await readyCart(page.request, product.variantId);
		await openCardForm(page, pluginBasePath);

		const first = (await readCart(page.request)).totals.summary.grand_minor;

		await expectRemountOn(page, 'paypal', async () => {
			await addLine(page.request, product.variantId, 1);
			await updateHost(page);
		});

		const total = (await readCart(page.request)).totals.summary.grand_minor;

		expect(total).toBeGreaterThan(first);
		// The card part is remembered: the new mount shows the card fields again, from the script loaded once.
		await expect(island(page).locator('input[type="radio"][value="card"]')).toBeChecked();
		await expect(island(page).locator('iframe[title^="paypal_card_"]')).toHaveCount(3, { timeout: FIELDS_MS });
		expect(scriptLoads.length, 'PayPal\'s script is loaded once.').toBe(1);
		expect((await paypalSeen(page)).made, 'A second set of card fields, for the new mount.').toBe(2);

		await typeCard(page, CARDS.frictionless);
		expect(await placeCard(page)).toBe('placed');

		const placed = await awaitPlaced(page);
		const [placement] = ledger.store.filter((entry) => entry.method === 'POST' && entry.route === `${STORE}/checkout`);

		await expectApproved(page, placed);
		expect(JSON.parse(placement.body).grand_total_minor, 'The placement is for the new amount.').toBe(total);
		await expectContractKept(page, ledger, [placed.orderKey]);
	});

	test('(8) paying with PayPal: Place leaves for PayPal\'s page for the store\'s order; back without approving, Place again is the same order @when-required', async ({ page }) => {
		const answers = placements;
		const approvals = [];

		// PayPal's approval page is answered here: the test never loads PayPal's sign-in.
		await page.route(
			(url) => url.href.startsWith(APPROVAL),
			async (route) => {
				approvals.push(route.request().url());
				await route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>PayPal</title><p>PayPal\'s approval page, answered by the test.</p>' });
			}
		);

		await readyCart(page.request, product.variantId);

		const address = await openCheckout(page, pluginBasePath, [paypalMethod()]);

		await expect(island(page).locator('input[type="radio"][value="paypal"]')).toBeChecked();
		await Promise.all([page.waitForURL((url) => url.href.startsWith(APPROVAL), { timeout: PAYPAL_MS }), placeOrder(page)]);

		expect(answers.length, 'One placement.').toBe(1);

		const [{ intent, order, type }] = answers;
		const left = new URL(approvals[0]);

		expect(type).toBe('redirect');
		expect(order).toMatch(/^[A-Z0-9]{17}$/);
		expect(`${left.origin}${left.pathname}`).toBe(APPROVAL);
		expect([...left.searchParams], 'The order\'s id, and nothing else of the store\'s.').toEqual([['token', order]]);
		expect((await paypalSeen(page)).made, 'PayPal\'s script was never loaded.').toBe(0);

		// Back at the checkout without approving: the return address carries the payment's uuid.
		await page.goto(`${address}?${RETURN_ARG}=${intent}`);
		await waitForHost(page);
		await updateHost(page);
		await awaitError(page, 'action_incomplete');
		expect(page.url(), 'The host removed its return argument.').not.toContain(RETURN_ARG);

		await Promise.all([page.waitForURL((url) => url.href.startsWith(APPROVAL), { timeout: PAYPAL_MS }), placeOrder(page)]);

		const writes = hostWrites(ledger);

		expect(answers.map((answer) => answer.order), 'The same order, both times.').toEqual([order, order]);
		expect(approvals.map((url) => new URL(url).searchParams.get('token'))).toEqual([order, order]);
		expect(writes.map(({ route }) => route)).toEqual([`${STORE}/checkout`, `${STORE}/checkout/resume`, `${STORE}/checkout`]);
		expect(writes[2].key, 'Placed again under the kept key.').toBe(writes[0].key);
		expect(writes[1].intent, 'The payment resumed is the placement\'s.').toBe(intent);
		expect(answers.every((answer) => answer.status === 'pending_payment'), 'Nothing is authorized.').toBe(true);

		// The ledger's checks, at the checkout's own origin, where the tab's storage is.
		await page.goto(address);
		await waitForHost(page);
		await expectContractKept(page, ledger, []);
	});

	test('(3) the step-up card: PayPal\'s challenge appears, its code is typed, and the resume approves @always', async ({ page }) => {
		await readyCart(page.request, product.variantId);
		await openCardForm(page, pluginBasePath);
		await typeCard(page, CARDS.stepUp);
		expect(await placeCard(page), 'The bank challenges the card.').toBe('challenge');
		await passChallenge(page);

		const placed = await awaitPlaced(page);

		await expectApproved(page, placed);
		expectWrites(ledger, [`${STORE}/checkout`, `${STORE}/checkout/resume`]);
		expect((await paypalSeen(page)).answers.at(-1)).toBe('onApprove');
		await expectContractKept(page, ledger, [placed.orderKey]);
	});

	test('(4) the failing step-up card is declined at the resume and shown in the form; another card is then approved as a new order @always', async ({ page }) => {
		await readyCart(page.request, product.variantId);
		await openCardForm(page, pluginBasePath);
		await typeCard(page, CARDS.failing);
		expect(await placeCard(page), 'The bank challenges the card.').toBe('challenge');
		await passChallenge(page);
		await expectDeclineShown(page);

		const reopened = await readCart(page.request);
		const declined = hostWrites(ledger);

		expect(reopened.lines.length, 'The cart is open again, with its line.').toBe(1);
		expect(declined.at(-1).route).toBe(`${STORE}/checkout/resume`);

		// The decline leaves the card typed: the fields are cleared for the next card.
		await typeCard(page, CARDS.stepUp, { clear: true });
		expect(await placeCard(page)).toBe('challenge');
		await passChallenge(page);

		const placed = await awaitPlaced(page);
		const writes = hostWrites(ledger);

		await expectApproved(page, placed);
		expect(writes.slice(-2).map(({ route }) => route)).toEqual([`${STORE}/checkout`, `${STORE}/checkout/resume`]);
		expect(writes.at(-2).key, 'The second attempt has a key of its own.').not.toBe(declined.at(-2).key);
		expect(writes.at(-1).intent, 'Each attempt is a payment of its own.').not.toBe(declined.at(-1).intent);
		await expectContractKept(page, ledger, [placed.orderKey]);
	});

	test('(6) the page reloaded during the challenge: one payment, approved once the card is typed again @always', async ({ page }) => {
		const answers = placements;

		await readyCart(page.request, product.variantId);
		await openCardForm(page, pluginBasePath);
		await typeCard(page, CARDS.stepUp);
		expect(await placeCard(page)).toBe('challenge');

		await page.reload();
		await waitForHost(page);
		await updateHost(page);
		// The host resumes the kept payment on loading, and waits for the shopper: nothing acts by itself.
		await awaitError(page, 'action_incomplete');
		expect(await challengeShown(page), 'No challenge opens by itself.').toBe(false);

		await choosePart(page, 'card');
		await expect(island(page).locator('iframe[title^="paypal_card_"]')).toHaveCount(3, { timeout: FIELDS_MS });
		await typeCard(page, CARDS.stepUp);
		expect(await placeCard(page)).toBe('challenge');
		await passChallenge(page);

		const placed = await awaitPlaced(page);
		const writes = hostWrites(ledger);

		await expectApproved(page, placed);
		expect(writes.filter(({ route }) => route === `${STORE}/checkout`).length, 'Placed before and after the reload.').toBeGreaterThanOrEqual(2);
		expect(writes.filter(({ route }) => route === `${STORE}/checkout`).every(({ key }) => key === writes[0].key), 'Placed again under the kept key.').toBe(true);
		expect(new Set(writes.filter(({ intent }) => intent).map(({ intent }) => intent)).size, 'One payment.').toBe(1);
		expect(new Set(answers.map(({ order }) => order)).size, 'One PayPal order on every answer.').toBe(1);
		await expectContractKept(page, ledger, [placed.orderKey]);
	});
});
