import { __, _n, sprintf } from '@wordpress/i18n';
import { api, cfg, refreshCarts } from './api';
import { h, id, render } from './dom';

/**
 * Rounds a quantity up to the product's min/step rules (0 stays 0).
 *
 * @param {number} qty  Quantity.
 * @param {Object} row  Row with min/step.
 * @return {number} Quantity.
 */
export function fitQty( qty, row ) {
	let q = Math.max( 0, parseInt( qty, 10 ) || 0 );
	if ( q === 0 ) {
		return 0;
	}
	const step = Math.max( 1, row.step || 1 );
	q = Math.max( q, row.min || 1 );
	return Math.ceil( q / step ) * step;
}

/**
 * Quick-order table.
 *
 * @param {HTMLElement} root Mount.
 */
export function mountQuickOrder( root ) {
	const perPage = parseInt( root.getAttribute( 'data-per-page' ), 10 ) || 20;
	let categories = [];
	try {
		categories = JSON.parse( root.getAttribute( 'data-categories' ) || '[]' );
	} catch ( e ) {
		categories = [];
	}
	const state = { search: '', category: root.getAttribute( 'data-category' ) || '', page: 1, rows: [], pages: 1, total: 0, qty: {}, known: {}, busy: false };
	const searchId = id( 'dev3bq-qs' );
	const catId = id( 'dev3bq-qc' );
	const searchInput = h( 'input', { id: searchId, type: 'search', class: 'dev3bq-shop__input', placeholder: __( 'Search by name or SKU', 'dev3-ai-b2b-wholesale-for-woocommerce' ), autocomplete: 'off' } );
	const catSelect = h( 'select', { id: catId, class: 'dev3bq-shop__input' }, h( 'option', { value: '' }, __( 'All categories', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), categories.map( ( c ) => h( 'option', { value: c.value, selected: c.value === state.category ? 'selected' : false }, c.label ) ) );
	const tableWrap = h( 'div', { class: 'dev3bq-shop__qo', 'aria-live': 'polite' } );
	const pager = h( 'nav', { class: 'dev3bq-shop__pager', 'aria-label': __( 'Product pages', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } );
	const summary = h( 'span', { class: 'dev3bq-shop__sum' } );
	const note = h( 'p', { class: 'dev3bq-shop__note', role: 'status' } );
	const addCart = h( 'button', { type: 'button', class: 'dev3bq-shop__btn', disabled: true }, __( 'Add to cart', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
	const addQuote = h( 'button', { type: 'button', class: 'dev3bq-shop__btn dev3bq-shop__btn--ghost', disabled: true }, __( 'Add to quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
	const bar = h( 'div', { class: 'dev3bq-shop__bar' }, summary, h( 'div', { class: 'dev3bq-shop__barbtns' }, addQuote, cfg().pricesHidden ? null : addCart ) );

	// Bulk paste.
	const pasteId = id( 'dev3bq-paste' );
	const paste = h( 'textarea', { id: pasteId, class: 'dev3bq-shop__input', rows: 4, maxlength: 2000, placeholder: __( 'One item per line, e.g.\nMUG-KRAFT-12 x 120\nA5 notebook, 30', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } );
	const pasteOut = h( 'div', { class: 'dev3bq-shop__paste-out', 'aria-live': 'polite' } );
	const pasteBtn = h( 'button', { type: 'button', class: 'dev3bq-shop__btn dev3bq-shop__btn--ghost' }, __( 'Find products', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
	const pasteBox = h(
		'details',
		{ class: 'dev3bq-shop__paste' },
		h( 'summary', {}, __( 'Paste a list instead', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ),
		h( 'div', { class: 'dev3bq-shop__field' }, h( 'label', { for: pasteId }, __( 'Your list (SKU or product name and quantity)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), paste ),
		pasteBtn,
		pasteOut
	);

	const selected = () => Object.keys( state.qty ).filter( ( k ) => state.qty[ k ] > 0 );
	const updateBar = () => {
		const keys = selected();
		const items = keys.reduce( ( s, k ) => s + state.qty[ k ], 0 );
		summary.textContent = items
			? sprintf( /* translators: 1: items, 2: products */ _n( '%1$d item from %2$d product selected', '%1$d items from %2$d products selected', items, 'dev3-ai-b2b-wholesale-for-woocommerce' ), items, keys.length )
			: __( 'Set a quantity to select products.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		addCart.disabled = ! items || state.busy;
		addQuote.disabled = ! items || state.busy;
	};

	const rowEl = ( r ) => {
		const qid = id( 'dev3bq-qq' );
		const qty = h( 'input', { id: qid, type: 'number', min: 0, step: r.step || 1, inputmode: 'numeric', class: 'dev3bq-shop__qty', value: state.qty[ r.id ] || '', placeholder: '0' } );
		qty.addEventListener( 'change', () => {
			const v = fitQty( qty.value, r );
			qty.value = v || '';
			state.qty[ r.id ] = v;
			state.known[ r.id ] = r;
			updateBar();
		} );
		const breaks = r.breaks && r.breaks.length > 1 ? h( 'span', { class: 'dev3bq-shop__tiers' }, r.breaks.filter( ( b ) => b.min > 1 ).map( ( b ) => `${ b.min }+ ${ b.price }` ).join( ' · ' ) ) : null;
		return h(
			'tr',
			{},
			h(
				'td',
				{ class: 'dev3bq-shop__prod', 'data-label': __( 'Product', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
				r.image ? h( 'img', { src: r.image, alt: '', width: 48, height: 48, loading: 'lazy' } ) : h( 'span', { class: 'dev3bq-shop__noimg', 'aria-hidden': 'true' } ),
				h( 'span', { class: 'dev3bq-shop__prodtext' }, h( 'a', { href: r.url }, r.name ), r.sku ? h( 'span', { class: 'dev3bq-shop__muted' }, r.sku ) : null, r.min > 1 || r.step > 1 ? h( 'span', { class: 'dev3bq-shop__muted' }, [ r.min > 1 ? sprintf( /* translators: %d: qty */ __( 'Min. %d', 'dev3-ai-b2b-wholesale-for-woocommerce' ), r.min ) : '', r.step > 1 ? sprintf( /* translators: %d: pack */ __( 'Packs of %d', 'dev3-ai-b2b-wholesale-for-woocommerce' ), r.step ) : '' ].filter( Boolean ).join( ' · ' ) ) : null )
			),
			h( 'td', { 'data-label': __( 'Stock', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, r.in_stock ? h( 'span', { class: 'dev3bq-shop__stock is-in' }, r.stock !== null ? sprintf( /* translators: %d: stock */ __( '%d in stock', 'dev3-ai-b2b-wholesale-for-woocommerce' ), r.stock ) : __( 'In stock', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) : h( 'span', { class: 'dev3bq-shop__stock is-out' }, __( 'Out of stock', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) ),
			h( 'td', { class: 'dev3bq-shop__price', 'data-label': __( 'Price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, r.price ? [ r.regular ? h( 'del', {}, r.regular ) : null, ' ', h( 'strong', {}, r.price ) ] : h( 'span', { class: 'dev3bq-shop__muted' }, __( 'On request', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), breaks ),
			h( 'td', { class: 'dev3bq-shop__qtycell', 'data-label': __( 'Quantity', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, h( 'label', { for: qid, class: 'dev3bq-sr' }, sprintf( /* translators: %s: product */ __( 'Quantity of %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), r.name ) ), qty )
		);
	};

	const draw = () => {
		if ( ! state.rows.length ) {
			render( tableWrap, h( 'p', { class: 'dev3bq-shop__empty' }, state.search ? __( 'No products match your search.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'No products to show.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) );
		} else {
			render(
				tableWrap,
				h(
					'table',
					{ class: 'dev3bq-shop__table dev3bq-shop__table--stack' },
					h( 'caption', { class: 'dev3bq-sr' }, __( 'Products', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ),
					h( 'thead', {}, h( 'tr', {}, h( 'th', { scope: 'col' }, __( 'Product', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), h( 'th', { scope: 'col' }, __( 'Stock', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), h( 'th', { scope: 'col' }, __( 'Price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), h( 'th', { scope: 'col' }, __( 'Quantity', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) ) ),
					h( 'tbody', {}, state.rows.map( rowEl ) )
				)
			);
		}
		render(
			pager,
			state.pages > 1
				? [
						h( 'button', { type: 'button', class: 'dev3bq-shop__btn dev3bq-shop__btn--ghost', disabled: state.page <= 1, onclick: () => go( state.page - 1 ) }, __( 'Previous', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ),
						h( 'span', {}, sprintf( /* translators: 1: page, 2: pages */ __( 'Page %1$d of %2$d', 'dev3-ai-b2b-wholesale-for-woocommerce' ), state.page, state.pages ) ),
						h( 'button', { type: 'button', class: 'dev3bq-shop__btn dev3bq-shop__btn--ghost', disabled: state.page >= state.pages, onclick: () => go( state.page + 1 ) }, __( 'Next', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ),
				  ]
				: null
		);
		updateBar();
	};

	const load = () => {
		tableWrap.setAttribute( 'aria-busy', 'true' );
		const q = new URLSearchParams( { page: String( state.page ), per_page: String( perPage ) } );
		if ( state.search ) {
			q.set( 'search', state.search );
		}
		if ( state.category ) {
			q.set( 'category', state.category );
		}
		return api( `public/products?${ q.toString() }` )
			.then( ( d ) => {
				state.rows = d.rows;
				state.pages = d.pages;
				state.total = d.total;
				d.rows.forEach( ( r ) => ( state.known[ r.id ] = r ) );
				draw();
			} )
			.catch( ( err ) => render( tableWrap, h( 'p', { class: 'dev3bq-shop__error', role: 'alert' }, err.message ) ) )
			.finally( () => tableWrap.removeAttribute( 'aria-busy' ) );
	};
	const go = ( p ) => {
		state.page = p;
		load().then( () => root.scrollIntoView( { block: 'start', behavior: 'smooth' } ) );
	};

	let timer;
	searchInput.addEventListener( 'input', () => {
		clearTimeout( timer );
		timer = setTimeout( () => {
			state.search = searchInput.value.trim();
			state.page = 1;
			load();
		}, 300 );
	} );
	catSelect.addEventListener( 'change', () => {
		state.category = catSelect.value;
		state.page = 1;
		load();
	} );

	const lines = () =>
		selected().map( ( k ) => {
			const r = state.known[ k ];
			return { product_id: r.product_id, variation_id: r.variation_id, qty: state.qty[ k ] };
		} );
	const done = ( text, tone = 'ok', link = null ) => {
		note.className = `dev3bq-shop__note is-${ tone }`;
		render( note, text, link ? [ ' ', h( 'a', { href: link.href }, link.label ) ] : null );
	};
	const reset = () => {
		state.qty = {};
		draw();
	};
	addCart.addEventListener( 'click', () => {
		state.busy = true;
		updateBar();
		api( 'public/cart', { data: { lines: lines() } } )
			.then( ( r ) => {
				refreshCarts();
				if ( r.errors && r.errors.length ) {
					done( r.errors.join( ' ' ), 'error', { href: r.cart_url, label: __( 'View cart', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } );
				} else {
					done( sprintf( /* translators: %d: lines */ _n( '%d product added to your cart.', '%d products added to your cart.', r.added, 'dev3-ai-b2b-wholesale-for-woocommerce' ), r.added ), 'ok', { href: r.cart_url, label: __( 'View cart', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } );
				}
				reset();
			} )
			.catch( ( err ) => done( err.message, 'error' ) )
			.finally( () => {
				state.busy = false;
				updateBar();
			} );
	} );
	addQuote.addEventListener( 'click', () => {
		state.busy = true;
		updateBar();
		api( 'public/basket', { data: { action: 'add', lines: lines() } } )
			.then( ( b ) => {
				done( __( 'Added to your quote list.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), 'ok', { href: b.quote_url || cfg().quoteUrl, label: __( 'Request your quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } );
				reset();
			} )
			.catch( ( err ) => done( err.message, 'error' ) )
			.finally( () => {
				state.busy = false;
				updateBar();
			} );
	} );

	pasteBtn.addEventListener( 'click', () => {
		if ( ! paste.value.trim() ) {
			paste.focus();
			return;
		}
		pasteBtn.disabled = true;
		render( pasteOut, h( 'p', { class: 'dev3bq-shop__muted' }, __( 'Matching your list…', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) );
		api( 'public/parse', { data: { text: paste.value } } )
			.then( ( res ) => {
				res.lines.forEach( ( r ) => {
					state.known[ r.id ] = r;
					state.qty[ r.id ] = fitQty( r.qty, r );
				} );
				render(
					pasteOut,
					res.lines.length
						? h( 'p', { class: 'dev3bq-shop__note is-ok' }, sprintf( /* translators: %d: products */ _n( '%d product matched and selected. Check the quantities, then add them to the cart or quote.', '%d products matched and selected. Check the quantities, then add them to the cart or quote.', res.lines.length, 'dev3-ai-b2b-wholesale-for-woocommerce' ), res.lines.length ) )
						: null,
					res.lines.length ? h( 'ul', { class: 'dev3bq-shop__matches' }, res.lines.map( ( r ) => h( 'li', {}, h( 'strong', {}, `${ state.qty[ r.id ] } × ` ), r.name, r.sku ? ` (${ r.sku })` : '' ) ) ) : null,
					res.unmatched.length ? h( 'p', { class: 'dev3bq-shop__note is-error' }, __( 'Not found:', 'dev3-ai-b2b-wholesale-for-woocommerce' ), ' ', res.unmatched.join( ', ' ) ) : null
				);
				updateBar();
			} )
			.catch( ( err ) => render( pasteOut, h( 'p', { class: 'dev3bq-shop__error', role: 'alert' }, err.message ) ) )
			.finally( () => {
				pasteBtn.disabled = false;
			} );
	} );

	render(
		root,
		h(
			'div',
			{ class: 'dev3bq-shop__card' },
			cfg().pricesHidden ? h( 'p', { class: 'dev3bq-shop__note' }, __( 'Log in to see your prices. You can still build a quote list.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), ' ', h( 'a', { href: cfg().accountUrl }, __( 'Log in', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) ) : null,
			h( 'div', { class: 'dev3bq-shop__filters' }, h( 'div', { class: 'dev3bq-shop__field' }, h( 'label', { for: searchId }, __( 'Search', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), searchInput ), categories.length ? h( 'div', { class: 'dev3bq-shop__field' }, h( 'label', { for: catId }, __( 'Category', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), catSelect ) : null ),
			pasteBox,
			tableWrap,
			pager,
			note,
			bar
		)
	);
	render( tableWrap, h( 'div', { class: 'dev3bq-shop__loading', role: 'status' }, h( 'span', { class: 'dev3bq-shop__spinner', 'aria-hidden': 'true' } ), __( 'Loading products…', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) );
	load();
}
