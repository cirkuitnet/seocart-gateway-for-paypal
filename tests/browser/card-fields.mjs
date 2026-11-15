// Typing a card into PayPal's card fields, and answering the bank's 3-D Secure challenge, in a browser: shared by the
// card script of the live tests (approve-card.mjs) and the checkout's browser tests (specs/checkout.spec.mjs).
//
// Each card field is PayPal's own frame inside a container of the page. What is typed is read back only as the count
// of digits the field holds, never its value, and while the field still has the focus: PayPal shows the number as its
// last four digits once it loses it. A field that drops a key is typed again, more slowly, at most twice.

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

// The field's own frame in its container: PayPal's script adds a frame of its own beside it, which watches for a close.
export const FIELD_FRAME = 'iframe[title^="paypal_card_"]';

// The card's fields whose typing is checked, by their name: each holds digits only once PayPal formats it.
export const CHECKED_FIELDS = ['number', 'expiry', 'cvv'];

// How long to wait between keys on each try of a field: a field that dropped a key is typed again, more slowly.
export const KEY_DELAYS_MS = [40, 90, 150];

// How long a control of the challenge may take to take a click or a key.
const CONTROL_MS = 20_000;

// Waits a moment.
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

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

// Returns the handle of the input of PayPal's frame inside a container of a page: waited for, up to readyMs, until
// attached, visible and editable; typed key by key, as a shopper types, since PayPal's fields format and check what
// they are given as it is typed; and read back as the count of digits it holds, the only thing of its value that
// leaves the frame.
export function cardField(page, container, readyMs) {
	const input = page.frameLocator(`${container} ${FIELD_FRAME}`).locator('input');

	return {
		ready: async () => {
			await input.waitFor({ state: 'visible', timeout: readyMs });

			for (const until = Date.now() + readyMs; !(await input.isEditable().catch(() => false)); ) {
				if (Date.now() > until) {
					throw Object.assign(new Error('A card field never became editable.'), { name: 'TimeoutError' });
				}

				await pause(250);
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

// Presses the first button of a name in any frame of the page: `challenge` once it did, `answered` when the card
// fields answered first (answered() says so), null when neither came before the time given.
export async function pressControl(page, name, until, answered) {
	while (Date.now() < until) {
		for (const frame of page.frames()) {
			const button = frame.getByRole('button', { name });

			if ((await button.count().catch(() => 0)) > 0) {
				await button.first().click({ timeout: CONTROL_MS });
				return 'challenge';
			}
		}

		if (await answered()) {
			return 'answered';
		}

		await pause(500);
	}

	return null;
}

// Types the challenge's code where a frame asks for one, and submits it with the Enter key: `challenge` once it did,
// `answered` when the card fields answered first (answered() says so), null when neither came before the time given.
export async function enterCode(page, until, answered) {
	while (Date.now() < until) {
		for (const frame of page.frames()) {
			const field = frame.locator(CHALLENGE_CODE.field);

			if ((await field.count().catch(() => 0)) > 0) {
				await field.first().fill(CHALLENGE_CODE.code, { timeout: CONTROL_MS });
				await field.first().press('Enter', { timeout: CONTROL_MS });
				return 'challenge';
			}
		}

		if (await answered()) {
			return 'answered';
		}

		await pause(500);
	}

	return null;
}
