import { url } from '../../src/storefront/api';
import { h } from '../../src/storefront/dom';
import { fitQty, mountQuickOrder } from '../../src/storefront/quickorder';
import { readProduct } from '../../src/storefront/buttons';
import { mountQuoteView } from '../../src/storefront/quote';

const flush = () => new Promise( ( r ) => setTimeout( r, 0 ) );
const json = ( body, ok = true, status = 200 ) => Promise.resolve( { ok, status, json: () => Promise.resolve( body ) } );

beforeEach( () => {
	window.dev3bqShop = { restUrl: '/wp-json/dev3bq/v1/', nonce: '', quoteUrl: '/quote', pricesHidden: false };
	document.body.innerHTML = '';
} );

describe( 'storefront helpers', () => {
	it( 'builds REST URLs for pretty and plain permalinks', () => {
		expect( url( 'public/products?page=2' ) ).toBe( '/wp-json/dev3bq/v1/public/products?page=2' );
		expect( url( 'public/products?page=2', '/index.php?rest_route=/dev3bq/v1/' ) ).toBe( '/index.php?rest_route=/dev3bq/v1/public/products&page=2' );
	} );

	it( 'never parses strings as HTML', () => {
		const el = h( 'p', { class: 'x' }, '<img src=x onerror=alert(1)>' );
		expect( el.querySelector( 'img' ) ).toBeNull();
		expect( el.textContent ).toContain( '<img' );
	} );

	it( 'rounds quantities to the minimum and pack size', () => {
		expect( fitQty( 0, { min: 12, step: 6 } ) ).toBe( 0 );
		expect( fitQty( 3, { min: 12, step: 6 } ) ).toBe( 12 );
		expect( fitQty( 13, { min: 12, step: 6 } ) ).toBe( 18 );
		expect( fitQty( 5, { min: 1, step: 1 } ) ).toBe( 5 );
	} );

	it( 'reads quantity and variation from the add-to-cart form', () => {
		document.body.innerHTML = '<form class="cart"><input class="qty" value="7"><input name="variation_id" value="0"><div class="dev3bq-quote-btn" data-product="5" data-variable="1"><button class="dev3bq-add-quote"></button></div></form>';
		const wrap = document.querySelector( '.dev3bq-quote-btn' );
		expect( readProduct( wrap ) ).toEqual( { product_id: 5, variation_id: 0, qty: 7, missing: true } );
		document.querySelector( '[name="variation_id"]' ).value = '9';
		expect( readProduct( wrap ).missing ).toBe( false );
	} );
} );

describe( 'quick order', () => {
	it( 'renders rows and adds the selected quantities to the cart in one request', async () => {
		const rows = [
			{ id: 11, product_id: 11, variation_id: 0, name: 'Kraft mug', sku: 'MUG', url: '/mug', image: '', in_stock: true, stock: 40, price: '$8.00', regular: '$10.00', breaks: [ { min: 1, price: '$8.00' }, { min: 50, price: '$7.50' } ], min: 1, step: 1, purchasable: true },
			{ id: 12, product_id: 12, variation_id: 0, name: 'Notebook', sku: 'NB', url: '/nb', image: '', in_stock: true, stock: null, price: '$3.20', regular: '', breaks: [], min: 6, step: 6, purchasable: true },
		];
		window.fetch = jest.fn( ( u, opts ) => {
			if ( u.includes( 'public/products' ) ) {
				return json( { rows, total: 2, pages: 1, page: 1, pricesHidden: false } );
			}
			if ( u.includes( 'public/cart' ) ) {
				return json( { added: 2, errors: [], count: 8, cart_url: '/cart', lines: JSON.parse( opts.body ).lines } );
			}
			return json( {} );
		} );
		const root = document.createElement( 'div' );
		root.setAttribute( 'data-categories', '[]' );
		document.body.appendChild( root );
		mountQuickOrder( root );
		await flush();
		await flush();
		expect( root.textContent ).toContain( 'Kraft mug' );
		expect( root.textContent ).toContain( '50+ $7.50' );
		const inputs = root.querySelectorAll( '.dev3bq-shop__qty' );
		inputs[ 0 ].value = '2';
		inputs[ 0 ].dispatchEvent( new window.Event( 'change' ) );
		inputs[ 1 ].value = '4';
		inputs[ 1 ].dispatchEvent( new window.Event( 'change' ) );
		expect( inputs[ 1 ].value ).toBe( '6' );
		const btn = Array.from( root.querySelectorAll( 'button' ) ).find( ( b ) => b.textContent === 'Add to cart' );
		expect( btn.disabled ).toBe( false );
		btn.click();
		await flush();
		await flush();
		const call = window.fetch.mock.calls.find( ( c ) => c[ 0 ].includes( 'public/cart' ) );
		expect( JSON.parse( call[ 1 ].body ).lines ).toEqual( [
			{ product_id: 11, variation_id: 0, qty: 2 },
			{ product_id: 12, variation_id: 0, qty: 6 },
		] );
		expect( root.textContent ).toContain( '2 products added to your cart.' );
	} );
} );

describe( 'quote view', () => {
	it( 'shows a sent quote and accepts it', async () => {
		const quote = { number: 'Q-01001', status: 'sent', status_label: 'Sent', created: 'Oct 25', valid_until: 'Nov 24', items: [ { name: 'Kraft mug', sku: 'MUG', qty: 50, note: '', unit: '$7.25', total: '$362.50' } ], totals: [ [ 'Subtotal', '$362.50' ], [ 'Total', '$362.50' ] ], request: '', message: 'Hi Anna', terms: 'Terms', events: [], pdf_url: '/pdf', pay_url: '', order_number: '', can_accept: true, can_decline: true, can_message: true };
		window.fetch = jest.fn( ( u ) => ( u.includes( '/accept' ) ? json( { ...quote, status: 'accepted', status_label: 'Accepted', can_accept: false, can_decline: false, can_message: false, pay_url: '/pay', order_number: '77', notice: 'Thank you!' } ) : json( quote ) ) );
		const root = document.createElement( 'div' );
		root.setAttribute( 'data-number', 'Q-01001' );
		root.setAttribute( 'data-key', 'abc' );
		document.body.appendChild( root );
		mountQuoteView( root );
		await flush();
		await flush();
		expect( root.textContent ).toContain( '$362.50' );
		expect( window.fetch.mock.calls[ 0 ][ 0 ] ).toContain( 'key=abc' );
		Array.from( root.querySelectorAll( 'button' ) )
			.find( ( b ) => b.textContent === 'Accept quote' )
			.click();
		await flush();
		await flush();
		expect( root.textContent ).toContain( 'Pay for order #77' );
		expect( root.textContent ).toContain( 'Thank you!' );
	} );

	it( 'shows a friendly message for a wrong link', async () => {
		window.fetch = jest.fn( () => json( { code: 'dev3bq_no_quote', message: 'Quote not found.' }, false, 404 ) );
		const root = document.createElement( 'div' );
		root.setAttribute( 'data-number', 'Q-1' );
		document.body.appendChild( root );
		mountQuoteView( root );
		await flush();
		await flush();
		expect( root.textContent ).toContain( 'use the link from your email' );
	} );
} );
