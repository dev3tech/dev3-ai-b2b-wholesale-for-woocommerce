/**
 * Storefront API client: same-origin fetch to dev3bq/v1/public/*; logged-in shoppers send their REST nonce.
 */
export const cfg = () => window.dev3bqShop || {};

/**
 * URL for a route (handles plain permalinks: ?rest_route=).
 *
 * @param {string} path Route under dev3bq/v1.
 * @param {string} base REST base.
 * @return {string} URL.
 */
export function url( path, base = cfg().restUrl || '/wp-json/dev3bq/v1/' ) {
	const [ route, query ] = String( path ).replace( /^\//, '' ).split( /\?(.*)/s );
	const u = base + route;
	return query ? u + ( u.includes( '?' ) ? '&' : '?' ) + query : u;
}

/**
 * Calls the API.
 *
 * @param {string} path Route.
 * @param {Object} opts { method, data }.
 * @return {Promise<Object>} JSON (rejects with { message, fields }).
 */
export async function api( path, opts = {} ) {
	const headers = { Accept: 'application/json' };
	if ( opts.data ) {
		headers[ 'Content-Type' ] = 'application/json';
	}
	if ( cfg().nonce ) {
		headers[ 'X-WP-Nonce' ] = cfg().nonce;
	}
	let res;
	try {
		res = await window.fetch( url( path ), { method: opts.method || ( opts.data ? 'POST' : 'GET' ), headers, credentials: 'same-origin', body: opts.data ? JSON.stringify( opts.data ) : undefined } );
	} catch ( e ) {
		throw { message: 'network', network: true };
	}
	let body = {};
	try {
		body = await res.json();
	} catch ( e ) {
		body = {};
	}
	if ( ! res.ok ) {
		throw { message: body.message || `HTTP ${ res.status }`, code: body.code || '', fields: body.fields || {}, status: res.status };
	}
	return body;
}

/**
 * Asks block carts / mini-carts to refresh after the cart changed outside them.
 */
export function refreshCarts() {
	try {
		document.body.dispatchEvent( new window.CustomEvent( 'wc-blocks_added_to_cart', { bubbles: true } ) );
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'wc_fragment_refresh' ).trigger( 'added_to_cart' );
		}
		if ( window.wp && window.wp.data && window.wp.data.dispatch ) {
			const store = window.wp.data.dispatch( 'wc/store/cart' );
			if ( store && store.invalidateResolutionForStore ) {
				store.invalidateResolutionForStore();
			}
		}
	} catch ( e ) {
		// Nothing to refresh.
	}
}
