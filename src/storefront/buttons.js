import { __ } from '@wordpress/i18n';
import { api, cfg } from './api';
import { h, render } from './dom';

/**
 * Status text with an optional link.
 *
 * @param {HTMLElement} el   Status element.
 * @param {string}      text Text.
 * @param {string}      tone ok|error.
 * @param {Object}      link { href, label }.
 */
export function status( el, text, tone = 'ok', link = null ) {
	if ( ! el ) {
		return;
	}
	el.className = `dev3bq-quote-btn__status is-${ tone }`;
	render( el, text, link ? [ ' ', h( 'a', { href: link.href }, link.label ) ] : null );
}

/**
 * Reads quantity + variation for an "Add to quote" wrapper.
 *
 * @param {HTMLElement} wrap Wrapper.
 * @return {Object} { product_id, variation_id, qty, missing }.
 */
export function readProduct( wrap ) {
	const form = wrap.closest( 'form.cart' );
	const own = wrap.querySelector( '.dev3bq-quote-qty' );
	const qtyEl = own || ( form && form.querySelector( 'input.qty, input[name="quantity"]' ) );
	const varEl = form && form.querySelector( 'input[name="variation_id"], .variation_id' );
	const variable = wrap.getAttribute( 'data-variable' ) === '1';
	const variation = varEl ? parseInt( varEl.value, 10 ) || 0 : 0;
	return {
		product_id: parseInt( wrap.getAttribute( 'data-product' ), 10 ) || 0,
		variation_id: variation,
		qty: Math.max( 1, parseInt( qtyEl ? qtyEl.value : '1', 10 ) || 1 ),
		missing: variable && ! variation,
	};
}

/**
 * Wires "Add to quote" and "Request a quote for this cart" buttons (event delegation, works for blocks too).
 *
 * @param {Document} doc Document.
 */
export function wireButtons( doc = document ) {
	doc.addEventListener( 'click', ( e ) => {
		const add = e.target.closest && e.target.closest( '.dev3bq-add-quote' );
		if ( add ) {
			e.preventDefault();
			const wrap = add.closest( '.dev3bq-quote-btn' );
			const out = wrap.querySelector( '.dev3bq-quote-btn__status' );
			const p = readProduct( wrap );
			if ( p.missing ) {
				status( out, __( 'Choose the product options first.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), 'error' );
				return;
			}
			add.disabled = true;
			add.setAttribute( 'aria-busy', 'true' );
			api( 'public/basket', { data: { action: 'add', product_id: p.product_id, variation_id: p.variation_id, qty: p.qty } } )
				.then( ( b ) => {
					status( out, __( 'Added to your quote list.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), 'ok', { href: b.quote_url || cfg().quoteUrl, label: `${ __( 'View list', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } (${ b.count })` } );
				} )
				.catch( ( err ) => status( out, err.message, 'error' ) )
				.finally( () => {
					add.disabled = false;
					add.removeAttribute( 'aria-busy' );
				} );
			return;
		}
		const cart = e.target.closest && e.target.closest( '.dev3bq-cart-to-quote' );
		if ( cart ) {
			e.preventDefault();
			const out = cart.parentNode.querySelector( '.dev3bq-quote-btn__status' );
			cart.disabled = true;
			api( 'public/basket/from-cart', { data: {} } )
				.then( ( b ) => {
					window.location.href = b.quote_url || cfg().quoteUrl;
				} )
				.catch( ( err ) => {
					status( out, err.message, 'error' );
					cart.disabled = false;
				} );
		}
	} );
}
