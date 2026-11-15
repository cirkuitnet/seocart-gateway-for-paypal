/**
 * SEOCart Gateway for PayPal: the payment form in SEOCart's checkout, a payment island as SEOCart's
 * docs/payment-islands.md describes one. It offers two ways to pay: on PayPal's site, or by a card
 * typed into PayPal's card fields.
 *
 * - The store creates the PayPal order when the order is placed; nothing here creates or changes
 *   one. confirm() names the way chosen (payment token `paypal` or `card`), and the store answers
 *   the step that order needs.
 * - PayPal: action() sends the browser to PayPal's page for the order (a `redirect` step, followed
 *   only to the mode's PayPal origin) and answers `left`; PayPal sends the shopper back to the
 *   store, which resumes the payment.
 * - Card: PayPal's script is loaded, from PayPal, once the card is first chosen. action() submits
 *   the card fields for the order (an `sdk` step), the bank's check running in PayPal's frame, and
 *   answers `completed` at PayPal's approval, `failed` otherwise, `left` once unmounted. The
 *   store's resume decides either way.
 *
 * Shipped as written (ES2020, no build, no dependency). It sends no request of its own, keeps
 * nothing in the browser's storage and opens no window: the card exists only in PayPal's frames.
 */
( function () {
	'use strict';

	// PayPal's script, loaded from PayPal alone, and the origin of PayPal's approval page by mode.
	const SDK = 'https://www.paypal.com/sdk/js';
	const APPROVAL_ORIGINS = { test: 'https://www.sandbox.paypal.com', live: 'https://www.paypal.com' };

	// What canMakePayment() mirrors of the gateway's isAvailable(); tests/Unit/IslandTest.php holds
	// each to Gateway::CURRENCIES, Gateway::MINIMUM_MINOR and Gateway::MAXIMUM_MINOR.
	const CURRENCIES = [ 'USD', 'GBP', 'EUR' ];
	const MINIMUM_MINOR = 1;
	const MAXIMUM_MINOR = 9999999;

	// How long PayPal's card fields may take to be ready, in milliseconds, before the card is shown
	// unavailable.
	const READY_MS = 30000;

	// PayPal's ids by shape: an order's (`token` on the way back) and a payer's (`PayerID`).
	const ORDER_ID = /^[A-Z0-9]{17}$/;
	const PAYER_ID = /^[A-Z0-9]{13}$/;

	// The checkout's billing address, by its names, and PayPal's names for each.
	const ADDRESS = { line1: 'addressLine1', line2: 'addressLine2', region: 'adminArea1', city: 'adminArea2', postcode: 'postalCode', country: 'countryCode' };

	// The words the shopper reads, translated through WordPress's wp.i18n when the page has it.
	const i18n = window.wp && window.wp.i18n;
	const __ = ( text, domain ) => ( i18n && 'function' === typeof i18n.__ ? i18n.__( text, domain ) : text );
	const WORDS = {
		paypal: __( 'PayPal', 'seocart-gateway-for-paypal' ),
		card: __( 'Debit or credit card', 'seocart-gateway-for-paypal' ),
		away: __( 'You finish paying on PayPal\'s site, and come back here.', 'seocart-gateway-for-paypal' ),
		loading: __( 'Loading the card form…', 'seocart-gateway-for-paypal' ),
		unavailable: __( 'Paying by card is not available right now. You can pay with PayPal.', 'seocart-gateway-for-paypal' ),
		incomplete: __( 'Enter the card\'s number, expiry date and security code.', 'seocart-gateway-for-paypal' ),
		refused: __( 'PayPal could not take this card; check it and place the order again.', 'seocart-gateway-for-paypal' ),
		declined: __( 'The payment was declined.', 'seocart-gateway-for-paypal' ),
		other: __( 'Finish the payment you started, or try again later.', 'seocart-gateway-for-paypal' ),
	};

	// The part of the form the shopper chose last, kept across mounts; how many mounts and loads there were.
	let chosen = 'paypal';
	let mounts = 0;
	let loads = 0;

	// PayPal's script, loaded once per client id and currency, each under a global of its own; and
	// the orders PayPal's card fields approved on this page, which are never submitted again.
	const scripts = new Map();
	const approved = new Set();

	// An error of the island's, named for the console, which logs its name alone.
	function islandError( code, message = '' ) {
		const error = new Error( message );

		error.name = 'PayPalIslandError';
		error.code = code;

		return error;
	}

	// Whether PayPal takes the payment, as the gateway's isAvailable() says: a client id, a currency
	// the method and the gateway take, an amount within the gateway's bounds.
	function canMakePayment( ctx ) {
		const clientId = ctx.settings.client_id;

		return (
			'string' === typeof clientId &&
			'' !== clientId &&
			ctx.currencies.includes( ctx.currency ) &&
			CURRENCIES.includes( ctx.currency ) &&
			Number.isInteger( ctx.amountMinor ) &&
			ctx.amountMinor >= MINIMUM_MINOR &&
			ctx.amountMinor <= MAXIMUM_MINOR
		);
	}

	// Loads PayPal's script once per client id (public) and currency, and resolves with its global.
	// Its address carries those two, the card fields and the intent to authorize, nothing else. A
	// load that failed, or never answered (abandon()), is forgotten and its element removed: the next
	// choice of the card loads again, under a global of its own.
	function loadPayPal( clientId, currency ) {
		const key = `${ clientId } ${ currency }`;

		if ( ! scripts.has( key ) ) {
			const url = new URL( SDK );
			const script = document.createElement( 'script' );
			const global = `seocartPayPal${ ++loads }`;
			const load = { key, script, answered: false };

			url.searchParams.set( 'client-id', clientId );
			url.searchParams.set( 'components', 'card-fields' );
			url.searchParams.set( 'intent', 'authorize' );
			url.searchParams.set( 'currency', currency );
			script.src = url.href;
			script.async = true;
			script.setAttribute( 'data-namespace', global );
			load.promise = new Promise( ( resolve, reject ) => {
				const fail = () => {
					forget( load );
					reject( islandError( 'paypal_script_failed' ) );
				};

				script.addEventListener( 'load', () => {
					load.answered = true;
					return window[ global ] ? resolve( window[ global ] ) : fail();
				} );
				script.addEventListener( 'error', fail );
			} );
			scripts.set( key, load );
			document.head.append( script );
		}

		return scripts.get( key ).promise;
	}

	// Forgets a load and removes its element, leaving alone a newer load of the same key.
	function forget( load ) {
		if ( scripts.get( load.key ) === load ) {
			scripts.delete( load.key );
		}

		load.script.remove();
	}

	// Gives up on the load of a client id and currency while it has not answered.
	function abandon( clientId, currency ) {
		const load = scripts.get( `${ clientId } ${ currency }` );

		if ( load && ! load.answered ) {
			forget( load );
		}
	}

	// The checkout's billing address in PayPal's names, a member left out where the checkout has none.
	function billingAddress( billing ) {
		const address = {};

		for ( const [ ours, theirs ] of Object.entries( ADDRESS ) ) {
			const value = billing && 'string' === typeof billing[ ours ] ? billing[ ours ].trim() : '';

			if ( value ) {
				address[ theirs ] = value;
			}
		}

		return address;
	}

	// Removes from the page's address the order's and the payer's ids PayPal adds when it sends the
	// shopper back, and nothing else, so neither stays in the history or in a shared link.
	function cleanAddress() {
		const pairs = window.location.search.slice( 1 ).split( '&' );
		const kept = pairs.filter( ( pair ) => {
			const [ name, value = '' ] = pair.split( '=' );

			return ! ( ( 'token' === name && ORDER_ID.test( value ) ) || ( 'PayerID' === name && PAYER_ID.test( value ) ) );
		} );

		if ( kept.length !== pairs.length ) {
			window.history.replaceState( window.history.state, '', `${ window.location.pathname }${ kept.length ? `?${ kept.join( '&' ) }` : '' }${ window.location.hash }` );
		}
	}

	// Creates an element.
	function node( tag, className = '', text = '' ) {
		const made = document.createElement( tag );

		made.className = className;
		made.textContent = text;

		return made;
	}

	/**
	 * Shows the form: the choice between PayPal and a card, and the part chosen last. Resolves at
	 * once: PayPal's script is loaded only when the card is chosen, and a card form that cannot be
	 * shown leaves the PayPal part working.
	 *
	 * @param {HTMLElement} el  The element to show it in, fresh for this mount.
	 * @param {Object}      ctx The payment context.
	 * @return {Object} The handle: confirm(), action(), showError(), unmount().
	 */
	function mount( el, ctx ) {
		const name = `seocart-paypal-part-${ ++mounts }`;
		const choices = node( 'div', 'seocart-island__choices' );
		const panels = { paypal: node( 'p', 'seocart-island__note', WORDS.away ), card: node( 'div', 'seocart-island__frame' ) };
		const slots = [ node( 'div' ), node( 'div' ), node( 'div' ) ];
		const status = node( 'p', 'seocart-island__note' );
		const radios = [];
		let live = true;
		let part = chosen;
		// The card fields, once PayPal rendered them, and the three fields, to be closed.
		let fields = null;
		let parts = [];
		// Which try at showing the card fields is the current one; null while none is.
		let attempt = 0;
		let showing = null;
		// The order the card fields may submit, set only while action() submits them; the action in flight.
		let step = null;
		let pending = null;
		let notice = null;

		const clearNotice = () => {
			notice?.remove();
			notice = null;
		};

		const show = ( message ) => {
			clearNotice();
			notice = node( 'div', 'seocart-notice is-error', message );
			notice.setAttribute( 'role', 'alert' );
			el.prepend( notice );
		};

		const closeFields = () => {
			for ( const field of parts ) {
				try {
					Promise.resolve( field.close() ).catch( () => {} );
				} catch {
					// A field PayPal already removed.
				}
			}

			parts = [];
			fields = null;
		};

		// Ends the action in flight, once: a later answer of PayPal's finds none and is dropped.
		const settle = ( result ) => {
			const ending = pending;

			pending = null;
			step = null;
			ending?.resolve( result );
		};

		// PayPal's card fields answered: an approval counts for the pending submission's order alone; a
		// refusal names no submission, and fails the pending one.
		const answer = ( outcome, order ) => {
			if ( ! pending ) {
				return;
			}

			if ( 'approved' === outcome ) {
				if ( order === pending.order ) {
					approved.add( order );
					settle( 'completed' );
				}

				return;
			}

			show( WORDS.refused );
			settle( 'failed' );
		};

		const giveUp = ( mine ) => {
			if ( live && mine === showing ) {
				showing = null;
				abandon( ctx.settings.client_id, ctx.currency );
				closeFields();
				status.textContent = WORDS.unavailable;
			}
		};

		// Loads PayPal's script and renders its three card fields, within READY_MS.
		const showCard = () => {
			if ( fields || null !== showing ) {
				return;
			}

			const mine = ++attempt;
			const limit = window.setTimeout( () => giveUp( mine ), READY_MS );

			showing = mine;
			status.textContent = WORDS.loading;
			loadPayPal( ctx.settings.client_id, ctx.currency )
				.then( ( paypal ) => {
					if ( ! live || mine !== showing ) {
						throw islandError( 'not_shown' );
					}

					const made = paypal.CardFields( {
						// PayPal's script asks for the order only inside the submission action() makes.
						createOrder: () => {
							if ( ! step ) {
								throw islandError( 'no_step' );
							}

							return step;
						},
						onApprove: ( data ) => answer( 'approved', data?.orderID ),
						onError: () => answer( 'refused' ),
						onCancel: () => answer( 'refused' ),
					} );

					if ( ! made.isEligible() ) {
						throw islandError( 'not_eligible' );
					}

					parts = [ made.NumberField(), made.ExpiryField(), made.CVVField() ];

					return Promise.all( parts.map( ( field, index ) => field.render( slots[ index ] ) ) ).then( () => made );
				} )
				.then( ( made ) => {
					if ( live && mine === showing ) {
						window.clearTimeout( limit );
						showing = null;
						fields = made;
						status.textContent = '';
					}
				} )
				.catch( () => {
					window.clearTimeout( limit );
					giveUp( mine );
				} );
		};

		const choose = ( next ) => {
			part = chosen = next;

			for ( const radio of radios ) {
				radio.checked = radio.value === next;
			}

			panels.paypal.hidden = 'paypal' !== next;
			panels.card.hidden = 'card' !== next;

			if ( 'card' === next ) {
				showCard();
			}
		};

		// A step for the part the shopper did not choose, such as one kept from an earlier placement.
		const otherPart = ( target ) => {
			choose( target );
			show( WORDS.other );

			return 'failed';
		};

		const redirect = ( next ) => {
			if ( 'paypal' !== part ) {
				return otherPart( 'paypal' );
			}

			let url = null;

			try {
				url = new URL( next.url );
			} catch {
				return 'failed';
			}

			if ( url.origin !== APPROVAL_ORIGINS[ ctx.mode ] || ! ORDER_ID.test( next.client_token || '' ) || url.searchParams.get( 'token' ) !== next.client_token ) {
				return 'failed';
			}

			window.location.assign( url.href );

			return 'left';
		};

		const submit = ( order, billing ) => {
			if ( 'card' !== part ) {
				return Promise.resolve( otherPart( 'card' ) );
			}

			// Approved already, on this page: PayPal is not asked again; the store's resume decides.
			if ( approved.has( order ) ) {
				return Promise.resolve( 'completed' );
			}

			if ( ! fields ) {
				return Promise.resolve( 'failed' );
			}

			const address = billingAddress( billing );
			let resolve;
			const promise = new Promise( ( done ) => {
				resolve = done;
			} );
			const mine = { order, resolve, promise };

			pending = mine;
			step = order;

			// PayPal answers through onApprove before the submission resolves; a refusal through onError
			// or a rejection, a closed challenge through onCancel; the first answer counts. The
			// submission's own promise speaks for it alone: resolved unanswered, it fails.
			try {
				fields.submit( Object.keys( address ).length ? { billingAddress: address } : undefined ).then(
					() => pending === mine && settle( 'failed' ),
					() => pending === mine && answer( 'refused' )
				);
			} catch {
				answer( 'refused' );
			}

			return promise;
		};

		for ( const value of [ 'paypal', 'card' ] ) {
			const label = node( 'label', 'seocart-island__choice' );
			const radio = node( 'input' );

			radio.type = 'radio';
			radio.name = name;
			radio.value = value;
			radios.push( radio );
			label.append( radio, ` ${ WORDS[ value ] }` );
			choices.append( label );
		}

		status.setAttribute( 'aria-live', 'polite' );
		panels.card.append( ...slots, status );
		choices.addEventListener( 'change', ( event ) => choose( event.target.value ) );
		el.append( choices, panels.paypal, panels.card );
		choose( part );

		return {
			/**
			 * Names the way the shopper chose; refuses a card form that is not ready or not complete.
			 *
			 * @return {Promise<Object>} `{ payment_data: { payment_token } }`.
			 */
			async confirm() {
				clearNotice();

				if ( 'paypal' === part ) {
					return { payment_data: { payment_token: 'paypal' } };
				}

				if ( ! fields ) {
					throw islandError( 'card_unavailable', null === showing ? WORDS.unavailable : WORDS.loading );
				}

				// PayPal's fields say only whether the form is valid; an empty form rejects.
				const state = await fields.getState().catch( () => null );

				if ( ! live ) {
					throw islandError( 'unmounted' );
				}

				if ( true !== state?.isFormValid ) {
					throw islandError( 'incomplete_card', WORDS.incomplete );
				}

				return { payment_data: { payment_token: 'card' } };
			},

			/**
			 * Takes the step the store answered: PayPal's page for a `redirect`, the card fields for an
			 * `sdk` step. A second call while one is in flight is answered the same.
			 *
			 * @param {Object} next   The step: type, url, client_token (the order's id).
			 * @param {Object} resume The payment's uuid, the address to come back to, the billing address.
			 * @return {Promise<string>} `completed`, `failed` or `left`.
			 */
			action( next, resume ) {
				if ( ! live ) {
					return Promise.resolve( 'left' );
				}

				if ( pending ) {
					return pending.promise;
				}

				if ( 'redirect' === next?.type ) {
					return Promise.resolve( redirect( next ) );
				}

				if ( 'sdk' === next?.type && ORDER_ID.test( next.client_token || '' ) ) {
					return submit( next.client_token, resume?.billing ?? null );
				}

				return Promise.resolve( 'failed' );
			},

			/**
			 * Shows a decline, by the store's message or the island's, or the island's own refusal; never
			 * a word of PayPal's.
			 *
			 * @param {Object} shown The error: code, message.
			 */
			showError( shown ) {
				const message = String( shown?.message || '' );

				if ( ! live ) {
					return;
				}

				if ( 'declined' === shown?.code ) {
					show( message || WORDS.declined );
				} else if ( Object.values( WORDS ).includes( message ) ) {
					show( message );
				}
			},

			/**
			 * Closes the card fields, empties the form, and abandons the action in flight, which answers `left`.
			 */
			unmount() {
				live = false;
				showing = null;
				settle( 'left' );
				closeFields();
				el.replaceChildren();
			},
		};
	}

	cleanAddress();

	( ( window.seocart = window.seocart || {} ).payments = window.seocart
		.payments || {
		queue: [],
		register( island ) {
			this.queue.push( island );
		},
	} ).register( {
		id: 'paypal',
		contractVersion: '0.1.0',
		supports: { action: true },
		canMakePayment,
		mount,
	} );
} )();
