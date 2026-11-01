import { __, _n, sprintf } from '@wordpress/i18n';
import { api, cfg } from './api';
import { field, h, id, render, showErrors } from './dom';

/**
 * Request-a-quote page: quote list + contact form + free-text request (with the list builder).
 *
 * @param {HTMLElement} root Mount.
 */
export function mountRequestQuote( root ) {
	const user = cfg().user || {};
	let basket = { lines: [], count: 0 };
	const listEl = h( 'div', { class: 'dev3bq-shop__basket', 'aria-live': 'polite' } );
	const suggestEl = h( 'div', { class: 'dev3bq-shop__suggest', 'aria-live': 'polite' } );

	const drawList = () => {
		if ( ! basket.lines.length ) {
			render( listEl, h( 'p', { class: 'dev3bq-shop__empty' }, __( 'Your quote list is empty. Add products with the “Add to quote” button on any product page, or describe what you need below.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) );
			return;
		}
		render(
			listEl,
			h(
				'ul',
				{ class: 'dev3bq-shop__lines' },
				basket.lines.map( ( l ) => {
					const qid = id( 'dev3bq-bq' );
					const qty = h( 'input', { id: qid, type: 'number', min: 1, step: 1, inputmode: 'numeric', class: 'dev3bq-shop__qty', value: l.qty } );
					qty.addEventListener( 'change', () => update( { [ l.key ]: Math.max( 1, parseInt( qty.value, 10 ) || 1 ) } ) );
					return h(
						'li',
						{},
						l.image ? h( 'img', { src: l.image, alt: '', width: 56, height: 56, loading: 'lazy' } ) : h( 'span', { class: 'dev3bq-shop__noimg', 'aria-hidden': 'true' } ),
						h( 'span', { class: 'dev3bq-shop__prodtext' }, h( 'a', { href: l.url }, l.name ), h( 'span', { class: 'dev3bq-shop__muted' }, [ l.sku, l.price ? sprintf( /* translators: %s: price */ __( '%s each', 'dev3-ai-b2b-wholesale-for-woocommerce' ), l.price ) : '' ].filter( Boolean ).join( ' · ' ) ) ),
						h( 'span', { class: 'dev3bq-shop__lineqty' }, h( 'label', { for: qid, class: 'dev3bq-sr' }, sprintf( /* translators: %s: product */ __( 'Quantity of %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), l.name ) ), qty ),
						h( 'button', { type: 'button', class: 'dev3bq-shop__icon', 'aria-label': sprintf( /* translators: %s: product */ __( 'Remove %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), l.name ), onclick: () => update( { [ l.key ]: 0 } ) }, '×' )
					);
				} )
			)
		);
	};
	const update = ( qtys ) =>
		api( 'public/basket', { data: { action: 'update', qtys } } )
			.then( ( b ) => {
				basket = b;
				drawList();
			} )
			.catch( () => {} );

	const form = h( 'form', { class: 'dev3bq-shop__form', novalidate: true } );
	const text = field( { label: __( 'Describe what you need', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'request', type: 'textarea', rows: 5, wide: true, help: __( 'Quantities, sizes, delivery date, anything that helps us price it.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), placeholder: __( 'e.g. 120 kraft mugs and 30 A5 notebooks, delivery to Berlin by March', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } );
	const findBtn = h( 'button', { type: 'button', class: 'dev3bq-shop__btn dev3bq-shop__btn--ghost' }, __( 'Find these products', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
	const submit = h( 'button', { type: 'submit', class: 'dev3bq-shop__btn' }, __( 'Request my quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
	const formMsg = h( 'p', { class: 'dev3bq-shop__note', role: 'status' } );
	const hp = h( 'div', { class: 'dev3bq-hp', 'aria-hidden': 'true' }, h( 'label', {}, 'Website', h( 'input', { type: 'text', name: 'hp', tabindex: '-1', autocomplete: 'off' } ) ) );

	findBtn.addEventListener( 'click', () => {
		const value = text.querySelector( 'textarea' ).value.trim();
		if ( ! value ) {
			text.querySelector( 'textarea' ).focus();
			return;
		}
		findBtn.disabled = true;
		render( suggestEl, h( 'p', { class: 'dev3bq-shop__muted' }, __( 'Looking for matching products…', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) );
		api( 'public/parse', { data: { text: value } } )
			.then( ( res ) => {
				if ( ! res.lines.length ) {
					render( suggestEl, h( 'p', { class: 'dev3bq-shop__note' }, __( 'We could not match products automatically. No problem: send your request and we will do it for you.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) );
					return;
				}
				const checks = res.lines.map( ( r ) => ( { r, box: h( 'input', { type: 'checkbox', checked: true } ) } ) );
				const addBtn = h( 'button', { type: 'button', class: 'dev3bq-shop__btn' }, __( 'Add selected to my list', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
				addBtn.addEventListener( 'click', () => {
					const lines = checks.filter( ( c ) => c.box.checked ).map( ( c ) => ( { product_id: c.r.product_id, variation_id: c.r.variation_id, qty: c.r.qty } ) );
					api( 'public/basket', { data: { action: 'add', lines } } )
						.then( ( b ) => {
							basket = b;
							drawList();
							render( suggestEl, h( 'p', { class: 'dev3bq-shop__note is-ok' }, __( 'Added to your list. Adjust quantities above if needed.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) );
						} )
						.catch( ( err ) => render( suggestEl, h( 'p', { class: 'dev3bq-shop__error', role: 'alert' }, err.message ) ) );
				} );
				render(
					suggestEl,
					h( 'p', { class: 'dev3bq-shop__subtitle' }, __( 'Did you mean:', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ),
					h(
						'ul',
						{ class: 'dev3bq-shop__checks' },
						checks.map( ( c ) => h( 'li', {}, h( 'label', {}, c.box, h( 'span', {}, h( 'strong', {}, `${ c.r.qty } × ` ), c.r.name, c.r.sku ? ` (${ c.r.sku })` : '', c.r.price ? h( 'span', { class: 'dev3bq-shop__muted' }, ` · ${ c.r.price }` ) : null ) ) ) )
					),
					res.unmatched.length ? h( 'p', { class: 'dev3bq-shop__muted' }, __( 'Not matched (we will check these by hand):', 'dev3-ai-b2b-wholesale-for-woocommerce' ), ' ', res.unmatched.join( ', ' ) ) : null,
					addBtn
				);
			} )
			.catch( ( err ) => render( suggestEl, h( 'p', { class: 'dev3bq-shop__error', role: 'alert' }, err.message ) ) )
			.finally( () => {
				findBtn.disabled = false;
			} );
	} );

	const contact = cfg().loggedIn
		? [ h( 'p', { class: 'dev3bq-shop__muted is-wide' }, sprintf( /* translators: 1: name, 2: email */ __( 'Sending as %1$s (%2$s).', 'dev3-ai-b2b-wholesale-for-woocommerce' ), user.name || '', user.email || '' ) ), field( { label: __( 'Company', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'company', value: user.company, autocomplete: 'organization' } ), field( { label: __( 'Phone', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'phone', type: 'tel', value: user.phone, autocomplete: 'tel' } ) ]
		: [
				field( { label: __( 'Name', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'name', required: true, autocomplete: 'name' } ),
				field( { label: __( 'Email', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'email', type: 'email', required: true, autocomplete: 'email' } ),
				field( { label: __( 'Company', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'company', autocomplete: 'organization' } ),
				field( { label: __( 'Phone', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'phone', type: 'tel', autocomplete: 'tel' } ),
		  ];

	render(
		form,
		h( 'div', { class: 'dev3bq-shop__grid' }, contact, text ),
		h( 'div', { class: 'dev3bq-shop__row' }, findBtn, h( 'span', { class: 'dev3bq-shop__muted' }, __( 'Optional: turn your text into list items.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) ),
		suggestEl,
		hp,
		h( 'div', { class: 'dev3bq-shop__actions' }, submit ),
		formMsg
	);

	form.addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		const data = Object.fromEntries( new window.FormData( form ).entries() );
		if ( ! basket.lines.length && ! ( data.request || '' ).trim() ) {
			formMsg.className = 'dev3bq-shop__note is-error';
			formMsg.textContent = __( 'Add a product to your list or describe what you need.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
			text.querySelector( 'textarea' ).focus();
			return;
		}
		submit.disabled = true;
		submit.setAttribute( 'aria-busy', 'true' );
		showErrors( form, {} );
		api( 'public/quote', { data } )
			.then( ( res ) => {
				render(
					root,
					h(
						'div',
						{ class: 'dev3bq-shop__card dev3bq-shop__done', tabindex: '-1' },
						h( 'span', { class: 'dev3bq-shop__check', 'aria-hidden': 'true' }, '✓' ),
						h( 'h2', { class: 'dev3bq-shop__title' }, __( 'Request sent', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ),
						h( 'p', {}, res.message ),
						res.view_url ? h( 'p', {}, h( 'a', { class: 'dev3bq-shop__btn dev3bq-shop__btn--ghost', href: res.view_url }, __( 'View your request', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) ) : null
					)
				);
				root.firstChild.focus();
			} )
			.catch( ( err ) => {
				showErrors( form, err.fields );
				formMsg.className = 'dev3bq-shop__note is-error';
				formMsg.textContent = err.message;
			} )
			.finally( () => {
				submit.disabled = false;
				submit.removeAttribute( 'aria-busy' );
			} );
	} );

	render(
		root,
		h( 'div', { class: 'dev3bq-shop__card' }, h( 'h2', { class: 'dev3bq-shop__title' }, __( 'Your quote list', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), listEl ),
		h( 'div', { class: 'dev3bq-shop__card' }, h( 'h2', { class: 'dev3bq-shop__title' }, __( 'Your details', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), form )
	);
	render( listEl, h( 'div', { class: 'dev3bq-shop__loading', role: 'status' }, h( 'span', { class: 'dev3bq-shop__spinner', 'aria-hidden': 'true' } ), __( 'Loading…', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) );
	api( 'public/basket' )
		.then( ( b ) => {
			basket = b;
			drawList();
		} )
		.catch( () => drawList() );
}

/**
 * One quote for the customer (guest link or My Account).
 *
 * @param {HTMLElement} root Mount.
 */
export function mountQuoteView( root ) {
	const number = root.getAttribute( 'data-number' );
	const key = root.getAttribute( 'data-key' ) || '';
	const path = ( suffix = '' ) => `public/quotes/${ encodeURIComponent( number ) }${ suffix }?key=${ encodeURIComponent( key ) }`;

	const draw = ( q ) => {
		const notice = q.notice ? h( 'p', { class: 'dev3bq-shop__note is-ok', role: 'status' }, q.notice ) : null;
		const act = ( which, data, btn ) => {
			btn.disabled = true;
			api( path( `/${ which }` ), { data: { key, ...data } } )
				.then( draw )
				.catch( ( err ) => {
					btn.disabled = false;
					window.alert( err.message ); // eslint-disable-line no-alert
				} );
		};
		const accept = q.can_accept ? h( 'button', { type: 'button', class: 'dev3bq-shop__btn' }, __( 'Accept quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) : null;
		if ( accept ) {
			accept.addEventListener( 'click', () => act( 'accept', {}, accept ) );
		}
		let declineBox = null;
		if ( q.can_decline ) {
			const rid = id( 'dev3bq-reason' );
			const reason = h( 'textarea', { id: rid, class: 'dev3bq-shop__input', rows: 2, maxlength: 1000 } );
			const btn = h( 'button', { type: 'button', class: 'dev3bq-shop__btn dev3bq-shop__btn--danger' }, __( 'Decline quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
			btn.addEventListener( 'click', () => act( 'decline', { reason: reason.value }, btn ) );
			declineBox = h( 'details', { class: 'dev3bq-shop__decline' }, h( 'summary', {}, __( 'Decline this quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), h( 'div', { class: 'dev3bq-shop__field' }, h( 'label', { for: rid }, __( 'Reason (optional)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), reason ), btn );
		}
		let msgBox = null;
		if ( q.can_message ) {
			const mid = id( 'dev3bq-msg' );
			const msg = h( 'textarea', { id: mid, class: 'dev3bq-shop__input', rows: 3, maxlength: 4000 } );
			const btn = h( 'button', { type: 'button', class: 'dev3bq-shop__btn dev3bq-shop__btn--ghost' }, __( 'Send message', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
			btn.addEventListener( 'click', () => {
				if ( msg.value.trim() ) {
					act( 'message', { message: msg.value }, btn );
				}
			} );
			msgBox = h( 'div', { class: 'dev3bq-shop__field' }, h( 'label', { for: mid }, __( 'Questions or changes? Send us a message', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), msg, btn );
		}
		const priced = q.totals.length > 0;
		render(
			root,
			h(
				'div',
				{ class: 'dev3bq-shop__card' },
				h( 'div', { class: 'dev3bq-shop__head' }, h( 'h2', { class: 'dev3bq-shop__title' }, sprintf( /* translators: %s: quote number */ __( 'Quote %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), q.number ) ), h( 'span', { class: `dev3bq-shop__badge is-${ q.status }` }, q.status_label ) ),
				h( 'p', { class: 'dev3bq-shop__muted' }, [ sprintf( /* translators: %s: date */ __( 'Requested %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), q.created ), q.valid_until ? sprintf( /* translators: %s: date */ __( 'valid until %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), q.valid_until ) : '' ].filter( Boolean ).join( ' · ' ) ),
				notice,
				! priced && q.status !== 'cancelled' ? h( 'p', { class: 'dev3bq-shop__note' }, __( 'We are preparing your quote and will email you when it is ready.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) : null,
				q.message ? h( 'blockquote', { class: 'dev3bq-shop__msg' }, q.message ) : null,
				q.items.length
					? h(
							'table',
							{ class: 'dev3bq-shop__table dev3bq-shop__table--stack' },
							h( 'thead', {}, h( 'tr', {}, h( 'th', { scope: 'col' }, __( 'Item', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), h( 'th', { scope: 'col' }, __( 'Qty', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), priced ? h( 'th', { scope: 'col' }, __( 'Unit price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) : null, priced ? h( 'th', { scope: 'col' }, __( 'Total', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) : null ) ),
							h(
								'tbody',
								{},
								q.items.map( ( i ) =>
									h(
										'tr',
										{},
										h( 'td', { 'data-label': __( 'Item', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, h( 'strong', {}, i.name ), i.sku || i.note ? h( 'span', { class: 'dev3bq-shop__muted dev3bq-shop__block' }, [ i.sku, i.note ].filter( Boolean ).join( ' · ' ) ) : null ),
										h( 'td', { 'data-label': __( 'Qty', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, String( i.qty ) ),
										priced ? h( 'td', { 'data-label': __( 'Unit price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, i.unit ) : null,
										priced ? h( 'td', { 'data-label': __( 'Total', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, i.total ) : null
									)
								)
							)
					  )
					: q.request
					? h( 'blockquote', { class: 'dev3bq-shop__msg' }, q.request )
					: null,
				priced ? h( 'dl', { class: 'dev3bq-shop__totals' }, q.totals.map( ( t, i ) => h( 'div', { class: i === q.totals.length - 1 ? 'is-total' : '' }, h( 'dt', {}, t[ 0 ] ), h( 'dd', {}, t[ 1 ] ) ) ) ) : null,
				q.terms ? h( 'p', { class: 'dev3bq-shop__muted dev3bq-shop__terms' }, q.terms ) : null,
				h(
					'div',
					{ class: 'dev3bq-shop__actions' },
					q.pay_url ? h( 'a', { class: 'dev3bq-shop__btn', href: q.pay_url }, sprintf( /* translators: %s: order number */ __( 'Pay for order #%s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), q.order_number ) ) : null,
					accept,
					q.pdf_url ? h( 'a', { class: 'dev3bq-shop__btn dev3bq-shop__btn--ghost', href: q.pdf_url, target: '_blank', rel: 'noopener' }, __( 'Download PDF', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) : null
				),
				declineBox
			),
			q.events.length || msgBox
				? h(
						'div',
						{ class: 'dev3bq-shop__card' },
						h( 'h3', { class: 'dev3bq-shop__subtitle' }, __( 'Messages and updates', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ),
						h( 'ol', { class: 'dev3bq-shop__thread' }, q.events.map( ( e ) => h( 'li', { class: `is-${ e.actor } is-${ e.type }` }, h( 'span', { class: 'dev3bq-shop__muted' }, `${ e.actor === 'customer' ? __( 'You', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Store', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } · ${ new Date( e.created_at ).toLocaleDateString() }` ), h( 'span', {}, e.message ) ) ) ),
						msgBox
				  )
				: null
		);
	};

	api( path() )
		.then( draw )
		.catch( ( err ) => render( root, h( 'div', { class: 'dev3bq-shop__card' }, h( 'p', { class: 'dev3bq-shop__error', role: 'alert' }, err.status === 404 ? __( 'We could not find this quote. Please use the link from your email.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : err.message ) ) ) );
}

/**
 * Pluralised count text (exported for tests).
 *
 * @param {number} n Count.
 * @return {string} Text.
 */
export const itemsText = ( n ) => sprintf( /* translators: %d: items */ _n( '%d item', '%d items', n, 'dev3-ai-b2b-wholesale-for-woocommerce' ), n );
