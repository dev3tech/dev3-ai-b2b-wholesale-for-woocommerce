import { wireButtons } from '../storefront/buttons';
import { mountQuickOrder } from '../storefront/quickorder';
import { mountQuoteView, mountRequestQuote } from '../storefront/quote';
import { mountRegistration } from '../storefront/register';
import '../storefront/storefront.css';

const MOUNTS = {
	'quick-order': mountQuickOrder,
	'request-quote': mountRequestQuote,
	'quote-view': mountQuoteView,
	registration: mountRegistration,
};

function boot() {
	document.querySelectorAll( '[data-dev3bq]' ).forEach( ( el ) => {
		const fn = MOUNTS[ el.getAttribute( 'data-dev3bq' ) ];
		if ( fn && ! el.dev3bqMounted ) {
			el.dev3bqMounted = true;
			fn( el );
		}
	} );
	wireButtons( document );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
