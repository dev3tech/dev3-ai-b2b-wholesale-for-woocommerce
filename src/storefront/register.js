import { __ } from '@wordpress/i18n';
import { api, cfg } from './api';
import { field, h, render, showErrors } from './dom';

/**
 * Wholesale registration form.
 *
 * @param {HTMLElement} root Mount.
 */
export function mountRegistration( root ) {
	const c = cfg();
	const user = c.user || {};
	const countries = Object.keys( c.countries || {} ).map( ( k ) => ( { value: k, label: c.countries[ k ] } ) );
	const roles = c.signupRoles || [];
	const form = h( 'form', { class: 'dev3bq-shop__form', novalidate: true } );
	const msg = h( 'p', { class: 'dev3bq-shop__note', role: 'status' } );
	const submit = h( 'button', { type: 'submit', class: 'dev3bq-shop__btn' }, __( 'Apply for a wholesale account', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
	const fields = [
		c.loggedIn ? null : field( { label: __( 'First name', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'first_name', required: true, autocomplete: 'given-name' } ),
		c.loggedIn ? null : field( { label: __( 'Last name', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'last_name', autocomplete: 'family-name' } ),
		c.loggedIn ? null : field( { label: __( 'Business email', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'email', type: 'email', required: true, autocomplete: 'email' } ),
		field( { label: __( 'Company', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'company', required: true, value: user.company, autocomplete: 'organization' } ),
		field( { label: __( 'Phone', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'phone', type: 'tel', value: user.phone, autocomplete: 'tel' } ),
		field( { label: __( 'VAT / tax ID', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'tax_id', help: __( 'Needed for tax-exempt purchasing.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } ),
		field( { label: __( 'Website', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'website', type: 'url', autocomplete: 'url', placeholder: 'https://' } ),
		countries.length ? field( { label: __( 'Country', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'country', type: 'select', value: c.baseCountry, options: [ { value: '', label: __( 'Choose…', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, ...countries ], autocomplete: 'country' } ) : null,
		roles.length > 1 ? field( { label: __( 'Account type', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'role', type: 'select', options: roles } ) : null,
		field( { label: __( 'Tell us about your business', 'dev3-ai-b2b-wholesale-for-woocommerce' ), name: 'message', type: 'textarea', rows: 4, wide: true, help: __( 'What you sell, where, and roughly how much you expect to order.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } ),
	];
	const hp = h( 'div', { class: 'dev3bq-hp', 'aria-hidden': 'true' }, h( 'label', {}, 'Website URL', h( 'input', { type: 'text', name: 'hp', tabindex: '-1', autocomplete: 'off' } ) ) );
	render( form, c.loggedIn ? h( 'p', { class: 'dev3bq-shop__muted' }, __( 'You are logged in; we will upgrade this account when we approve it.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) : null, h( 'div', { class: 'dev3bq-shop__grid' }, fields ), hp, h( 'div', { class: 'dev3bq-shop__actions' }, submit ), msg );

	form.addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		const data = Object.fromEntries( new window.FormData( form ).entries() );
		submit.disabled = true;
		submit.setAttribute( 'aria-busy', 'true' );
		showErrors( form, {} );
		msg.textContent = '';
		api( 'public/register', { data } )
			.then( ( res ) => {
				render( root, h( 'div', { class: 'dev3bq-shop__card dev3bq-shop__done', tabindex: '-1' }, h( 'span', { class: 'dev3bq-shop__check', 'aria-hidden': 'true' }, '✓' ), h( 'h2', { class: 'dev3bq-shop__title' }, res.status === 'approved' ? __( 'Your account is ready', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Application received', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), h( 'p', {}, res.message ) ) );
				root.firstChild.focus();
			} )
			.catch( ( err ) => {
				showErrors( form, err.fields );
				msg.className = 'dev3bq-shop__note is-error';
				msg.textContent = err.message;
			} )
			.finally( () => {
				submit.disabled = false;
				submit.removeAttribute( 'aria-busy' );
			} );
	} );

	render( root, h( 'div', { class: 'dev3bq-shop__card' }, h( 'h2', { class: 'dev3bq-shop__title' }, __( 'Open a wholesale account', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), h( 'p', { class: 'dev3bq-shop__muted' }, __( 'Get trade prices, quantity discounts, quick ordering and quotes. We review every application by hand.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ), form ) );
}
