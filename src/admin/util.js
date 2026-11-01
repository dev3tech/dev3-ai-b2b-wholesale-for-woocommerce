import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { Badge, Icon, Spinner, formatMoney } from '../core3/ui';

export const money = ( n, currency ) => formatMoney( n, currency || config.currency || 'USD' );

export const STATUS_TONE = { requested: 'info', draft: 'warning', sent: 'brand', accepted: 'success', declined: 'danger', expired: 'neutral', cancelled: 'neutral' };

export const statusLabel = ( s ) =>
	( {
		requested: __( 'New request', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		draft: __( 'In progress', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		sent: __( 'Sent', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		accepted: __( 'Accepted', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		declined: __( 'Declined', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		expired: __( 'Expired', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		cancelled: __( 'Cancelled', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		open: __( 'Open', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
	} )[ s ] || s;

export const sourceLabel = ( s ) =>
	( {
		cart: __( 'From cart', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		form: __( 'Quote list', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		text: __( 'Free-text request', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		admin: __( 'Created by store', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		quick: __( 'Quick order', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
	} )[ s ] || s;

export const ruleTypeLabel = ( t ) =>
	( {
		percent: __( '% off', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		fixed: __( 'Amount off', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		price: __( 'Fixed price', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
	} )[ t ] || t;

/**
 * Discount text ("20% off", "$2.00 off", "$6.00").
 *
 * @param {string} type   percent|fixed|price.
 * @param {number} amount Amount.
 * @return {string} Text.
 */
export function discountText( type, amount ) {
	if ( type === 'percent' ) {
		/* translators: %s: percentage */
		return sprintf( __( '%s%% off', 'dev3-ai-b2b-wholesale-for-woocommerce' ), Number( amount ).toString() );
	}
	if ( type === 'fixed' ) {
		/* translators: %s: amount */
		return sprintf( __( '%s off', 'dev3-ai-b2b-wholesale-for-woocommerce' ), money( amount ) );
	}
	/* translators: %s: unit price */
	return sprintf( __( '%s each', 'dev3-ai-b2b-wholesale-for-woocommerce' ), money( amount ) );
}

/**
 * Who a rule applies to.
 *
 * @param {string} role  Rule role.
 * @param {Array}  roles Roles.
 * @return {string} Label.
 */
export function whoLabel( role, roles = [] ) {
	if ( role === 'everyone' ) {
		return __( 'Everyone', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}
	if ( role === 'all_wholesale' ) {
		return __( 'All wholesale roles', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}
	const r = roles.find( ( x ) => x.key === role );
	return r ? r.name : role;
}

/**
 * Relative time.
 *
 * @param {string} iso ISO date.
 * @return {string} Text.
 */
export function ago( iso ) {
	if ( ! iso ) {
		return '';
	}
	const diff = ( Date.now() - new Date( iso ).getTime() ) / 1000;
	if ( diff < 60 ) {
		return __( 'just now', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}
	if ( diff < 3600 ) {
		/* translators: %d: minutes */
		return sprintf( __( '%d min ago', 'dev3-ai-b2b-wholesale-for-woocommerce' ), Math.floor( diff / 60 ) );
	}
	if ( diff < 86400 ) {
		/* translators: %d: hours */
		return sprintf( __( '%d h ago', 'dev3-ai-b2b-wholesale-for-woocommerce' ), Math.floor( diff / 3600 ) );
	}
	/* translators: %d: days */
	return sprintf( __( '%d d ago', 'dev3-ai-b2b-wholesale-for-woocommerce' ), Math.floor( diff / 86400 ) );
}

/**
 * Short date.
 *
 * @param {string} value Date / ISO.
 * @return {string} Text.
 */
export const shortDate = ( value ) => ( value ? new Date( value.length === 10 ? `${ value }T12:00:00` : value ).toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } ) : '' );

/**
 * Status badge.
 *
 * @param {Object} props        Props.
 * @param {string} props.status Status.
 * @return {Element} Badge.
 */
export function StatusBadge( { status } ) {
	return (
		<Badge tone={ STATUS_TONE[ status ] || 'neutral' } dot>
			{ statusLabel( status ) }
		</Badge>
	);
}

/**
 * Debounced value.
 *
 * @param {*}      value Value.
 * @param {number} delay Delay ms.
 * @return {*} Debounced.
 */
export function useDebounced( value, delay = 250 ) {
	const [ v, setV ] = useState( value );
	useEffect( () => {
		const t = setTimeout( () => setV( value ), delay );
		return () => clearTimeout( t );
	}, [ value, delay ] );
	return v;
}

/**
 * Searchable product picker (combobox). Calls onPick(product).
 *
 * @param {Object}   props             Props.
 * @param {string}   props.label       Label.
 * @param {Function} props.onPick      Pick handler.
 * @param {string}   props.role        Role for prices.
 * @param {string}   props.mode        'parents' to list parent products.
 * @param {string}   props.placeholder Placeholder.
 * @return {Element} Picker.
 */
export function ProductPicker( { label, onPick, role = '', mode = '', placeholder } ) {
	const [ term, setTerm ] = useState( '' );
	const [ open, setOpen ] = useState( false );
	const [ items, setItems ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	const [ active, setActive ] = useState( 0 );
	const q = useDebounced( term, 250 );
	const box = useRef( null );
	const id = useRef( `dev3bq-picker-${ Math.random().toString( 36 ).slice( 2, 8 ) }` ).current;

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		setBusy( true );
		const params = new URLSearchParams( { search: q, role } );
		if ( mode ) {
			params.set( 'mode', mode );
		}
		request( `products?${ params.toString() }` )
			.then( ( d ) => {
				setItems( d.products || [] );
				setActive( 0 );
			} )
			.catch( () => setItems( [] ) )
			.finally( () => setBusy( false ) );
	}, [ q, open, role, mode ] );

	useEffect( () => {
		const close = ( e ) => box.current && ! box.current.contains( e.target ) && setOpen( false );
		document.addEventListener( 'mousedown', close );
		return () => document.removeEventListener( 'mousedown', close );
	}, [] );

	const pick = ( p ) => {
		onPick( p );
		setTerm( '' );
		setOpen( false );
	};
	const onKey = ( e ) => {
		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			setOpen( true );
			setActive( ( a ) => Math.min( items.length - 1, a + 1 ) );
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			setActive( ( a ) => Math.max( 0, a - 1 ) );
		} else if ( e.key === 'Enter' && open && items[ active ] ) {
			e.preventDefault();
			pick( items[ active ] );
		} else if ( e.key === 'Escape' ) {
			setOpen( false );
		}
	};

	return (
		<div className="dev3bq-picker" ref={ box }>
			<label className="dev3bq-picker__label" htmlFor={ id }>
				{ label }
			</label>
			<div className="dev3bq-picker__control">
				<Icon name="search" size={ 16 } />
				<input
					id={ id }
					type="search"
					role="combobox"
					aria-expanded={ open }
					aria-controls={ `${ id }-list` }
					aria-autocomplete="list"
					aria-activedescendant={ open && items[ active ] ? `${ id }-${ items[ active ].id }` : undefined }
					value={ term }
					placeholder={ placeholder || __( 'Search by name or SKU…', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					onChange={ ( e ) => {
						setTerm( e.target.value );
						setOpen( true );
					} }
					onFocus={ () => setOpen( true ) }
					onKeyDown={ onKey }
				/>
				{ busy && <Spinner size={ 14 } /> }
			</div>
			{ open && (
				<ul className="dev3bq-picker__list" id={ `${ id }-list` } role="listbox" aria-label={ label }>
					{ ! busy && items.length === 0 && <li className="dev3bq-picker__empty">{ __( 'No products found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</li> }
					{ items.map( ( p, i ) => (
						<li
							key={ p.id }
							id={ `${ id }-${ p.id }` }
							role="option"
							aria-selected={ i === active }
							className={ i === active ? 'is-active' : '' }
							onMouseDown={ ( e ) => {
								e.preventDefault();
								pick( p );
							} }
						>
							<span className="dev3bq-picker__name">{ p.name }</span>
							<span className="dev3bq-picker__meta">
								{ p.sku ? `${ p.sku } · ` : '' }
								{ p.type === 'variable' ? __( 'Variable product', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : money( p.price ) }
							</span>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
