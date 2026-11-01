/**
 * Tiny DOM builder: every string becomes a text node, so shopper and server data is never parsed as HTML.
 *
 *     h( 'button', { class: 'x', onclick: fn }, 'Label' )
 *
 * @param {string}       tag      Tag name.
 * @param {Object}       attrs    Attributes; on* keys are event listeners; `html` is never supported.
 * @param {...*}         children Children (strings, nodes, arrays, false/null skipped).
 * @return {HTMLElement} Element.
 */
export function h( tag, attrs = {}, ...children ) {
	const el = document.createElement( tag );
	Object.keys( attrs || {} ).forEach( ( key ) => {
		const value = attrs[ key ];
		if ( value === false || value === null || value === undefined ) {
			return;
		}
		if ( key.startsWith( 'on' ) && typeof value === 'function' ) {
			el.addEventListener( key.slice( 2 ), value );
		} else if ( key === 'class' ) {
			el.className = value;
		} else if ( key === 'value' ) {
			el.value = value;
		} else if ( key === 'checked' || key === 'disabled' || key === 'required' || key === 'hidden' ) {
			el[ key ] = !! value;
		} else {
			el.setAttribute( key, value === true ? '' : String( value ) );
		}
	} );
	append( el, children );
	return el;
}

/**
 * Appends children.
 *
 * @param {HTMLElement} el       Parent.
 * @param {Array}       children Children.
 * @return {HTMLElement} Parent.
 */
export function append( el, children ) {
	children.flat( Infinity ).forEach( ( c ) => {
		if ( c === null || c === undefined || c === false || c === '' ) {
			return;
		}
		el.appendChild( c instanceof window.Node ? c : document.createTextNode( String( c ) ) );
	} );
	return el;
}

/**
 * Replaces an element's content.
 *
 * @param {HTMLElement} el       Element.
 * @param {...*}        children Children.
 * @return {HTMLElement} Element.
 */
export function render( el, ...children ) {
	while ( el.firstChild ) {
		el.removeChild( el.firstChild );
	}
	return append( el, children );
}

let uid = 0;
/**
 * Unique id.
 *
 * @param {string} prefix Prefix.
 * @return {string} Id.
 */
export const id = ( prefix = 'dev3bq' ) => `${ prefix }-${ ++uid }`;

/**
 * Labelled form field.
 *
 * @param {Object} o              Options.
 * @param {string} o.label        Label.
 * @param {string} o.name         Name.
 * @param {string} o.type         Input type (or 'textarea' / 'select').
 * @param {string} o.value        Value.
 * @param {Array}  o.options      Select options [{value,label}].
 * @param {boolean} o.required    Required.
 * @param {string} o.autocomplete Autocomplete token.
 * @param {string} o.help         Help text.
 * @param {string} o.placeholder  Placeholder.
 * @return {HTMLElement} Field wrapper (input at .dev3bq-shop__input).
 */
export function field( o ) {
	const fid = id( 'dev3bq-f' );
	const help = o.help ? h( 'span', { class: 'dev3bq-shop__help', id: `${ fid }-help` }, o.help ) : null;
	const err = h( 'span', { class: 'dev3bq-shop__error', id: `${ fid }-err`, role: 'alert' } );
	const common = {
		id: fid,
		name: o.name,
		class: 'dev3bq-shop__input',
		required: o.required,
		autocomplete: o.autocomplete,
		placeholder: o.placeholder,
		'aria-describedby': [ help ? `${ fid }-help` : '', `${ fid }-err` ].filter( Boolean ).join( ' ' ),
	};
	let input;
	if ( o.type === 'textarea' ) {
		input = h( 'textarea', { ...common, rows: o.rows || 4, maxlength: o.maxlength || 4000 } );
		input.value = o.value || '';
	} else if ( o.type === 'select' ) {
		input = h( 'select', common, ( o.options || [] ).map( ( opt ) => h( 'option', { value: opt.value, selected: String( opt.value ) === String( o.value || '' ) ? 'selected' : false }, opt.label ) ) );
	} else {
		input = h( 'input', { ...common, type: o.type || 'text', value: o.value || '', inputmode: o.inputmode, maxlength: o.maxlength } );
	}
	return h( 'div', { class: `dev3bq-shop__field${ o.wide ? ' is-wide' : '' }` }, h( 'label', { for: fid }, o.label, o.required ? h( 'span', { class: 'dev3bq-shop__req', 'aria-hidden': 'true' }, ' *' ) : null ), input, help, err );
}

/**
 * Shows field errors returned by the API ({ fields: { name: message } }).
 *
 * @param {HTMLFormElement} form   Form.
 * @param {Object}          fields Errors.
 */
export function showErrors( form, fields = {} ) {
	form.querySelectorAll( '.dev3bq-shop__field' ).forEach( ( wrap ) => {
		const input = wrap.querySelector( '.dev3bq-shop__input' );
		const err = wrap.querySelector( '.dev3bq-shop__error' );
		const msg = input && fields[ input.name ];
		if ( input ) {
			input.setAttribute( 'aria-invalid', msg ? 'true' : 'false' );
		}
		if ( err ) {
			err.textContent = msg || '';
		}
	} );
	const first = form.querySelector( '[aria-invalid="true"]' );
	if ( first ) {
		first.focus();
	}
}
