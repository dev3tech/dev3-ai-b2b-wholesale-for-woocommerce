import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, FormGrid, IconButton, Modal, NumberField, PageHeader, ResponsiveTable, Select, TextField, Toggle, useToast } from '../core3/ui';
import { ProductPicker, discountText, money, whoLabel } from './util';
import { CheckGroup } from './Roles';

const BLANK = { name: '', role: 'all_wholesale', scope: 'all', targets: [], type: 'percent', amount: 10, breaks: [], priority: 10, active: true, starts_at: '', ends_at: '' };

const typeOptions = () => [
	{ value: 'percent', label: __( 'Percentage off', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
	{ value: 'fixed', label: __( 'Amount off each', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
	{ value: 'price', label: __( 'Fixed unit price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
];

/**
 * Quantity breaks editor.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.value    Breaks.
 * @param {Function} props.onChange Change.
 * @return {Element} Editor.
 */
export function BreaksEditor( { value, onChange } ) {
	const set = ( i, patch ) => onChange( value.map( ( b, j ) => ( j === i ? { ...b, ...patch } : b ) ) );
	return (
		<fieldset className="dev3bq-breaks-ed">
			<legend>{ __( 'Quantity breaks', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</legend>
			<p className="dev3bq-muted">{ __( 'Bigger orders get a better price. The highest matching tier wins.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</p>
			{ value.map( ( b, i ) => (
				<div className="dev3bq-breaks-ed__row" key={ i }>
					<NumberField label={ __( 'From quantity', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ b.min } min={ 2 } step={ 1 } onChange={ ( v ) => set( i, { min: v } ) } />
					<Select label={ __( 'Discount type', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ b.type } onChange={ ( v ) => set( i, { type: v } ) } options={ typeOptions() } />
					<NumberField label={ b.type === 'percent' ? __( 'Percent', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Amount', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ b.amount } min={ 0 } step={ 0.01 } onChange={ ( v ) => set( i, { amount: v } ) } />
					<IconButton icon="trash" label={ sprintf( /* translators: %d: row */ __( 'Remove tier %d', 'dev3-ai-b2b-wholesale-for-woocommerce' ), i + 1 ) } onClick={ () => onChange( value.filter( ( x, j ) => j !== i ) ) } />
				</div>
			) ) }
			<Button size="sm" icon="plus" onClick={ () => onChange( [ ...value, { min: value.length ? Number( value[ value.length - 1 ].min || 0 ) * 2 || 50 : 50, type: 'percent', amount: 15 } ] ) }>
				{ __( 'Add tier', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
			</Button>
		</fieldset>
	);
}

/**
 * Rule modal.
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.rule       Rule.
 * @param {Array}    props.roles      Roles.
 * @param {Array}    props.categories Categories.
 * @param {Object}   props.names      Target names.
 * @param {Function} props.onClose    Close.
 * @param {Function} props.onSaved    Saved.
 * @return {Element} Modal.
 */
export function RuleModal( { rule, roles, categories, names, onClose, onSaved } ) {
	const toast = useToast();
	const [ r, setR ] = useState( { ...BLANK, ...rule, starts_at: ( rule.starts_at || '' ).slice( 0, 10 ), ends_at: ( rule.ends_at || '' ).slice( 0, 10 ) } );
	const [ picked, setPicked ] = useState( () => ( rule.scope === 'products' ? rule.targets.map( ( id ) => ( { id, name: names[ `p${ id }` ] || `#${ id }` } ) ) : [] ) );
	const [ busy, setBusy ] = useState( false );
	const set = ( k ) => ( v ) => setR( { ...r, [ k ]: v } );
	const save = () => {
		setBusy( true );
		const targets = r.scope === 'products' ? picked.map( ( p ) => p.id ) : r.scope === 'categories' ? r.targets : [];
		request( rule.id ? `rules/${ rule.id }` : 'rules', { method: 'POST', data: { ...r, targets } } )
			.then( ( d ) => {
				toast.success( __( 'Rule saved. Prices update right away.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
				onSaved( d );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	const who = [ { value: 'everyone', label: __( 'Everyone (incl. retail)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, { value: 'all_wholesale', label: __( 'All wholesale roles', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, ...roles.map( ( x ) => ( { value: x.key, label: x.name } ) ) ];
	return (
		<Modal
			open
			onClose={ onClose }
			size="lg"
			title={ rule.id ? __( 'Edit pricing rule', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'New pricing rule', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
			footer={
				<>
					<Button variant="secondary" onClick={ onClose }>
						{ __( 'Cancel', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
					<Button variant="primary" onClick={ save } loading={ busy }>
						{ __( 'Save rule', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
				</>
			}
		>
			<FormGrid>
				<TextField label={ __( 'Rule name', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ r.name } onChange={ set( 'name' ) } placeholder={ __( 'e.g. Distributor mugs', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } data-autofocus />
				<Select label={ __( 'Who', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ r.role } onChange={ set( 'role' ) } options={ who } />
				<Select
					label={ __( 'Products', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					value={ r.scope }
					onChange={ set( 'scope' ) }
					options={ [
						{ value: 'all', label: __( 'All products', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
						{ value: 'categories', label: __( 'Categories', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
						{ value: 'products', label: __( 'Specific products', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
					] }
				/>
				<NumberField label={ __( 'Priority', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ r.priority } onChange={ set( 'priority' ) } min={ 0 } max={ 999 } help={ __( 'Higher wins when several rules match.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				<Select label={ __( 'Base discount', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ r.type } onChange={ set( 'type' ) } options={ typeOptions() } />
				<NumberField label={ r.type === 'percent' ? __( 'Percent', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Amount', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ r.amount } onChange={ set( 'amount' ) } min={ 0 } step={ 0.01 } help={ r.type === 'percent' ? __( 'Use 0 if only the quantity breaks should apply.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : '' } />
				<TextField label={ __( 'Starts (optional)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } type="date" value={ r.starts_at } onChange={ set( 'starts_at' ) } />
				<TextField label={ __( 'Ends (optional)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } type="date" value={ r.ends_at } onChange={ set( 'ends_at' ) } />
			</FormGrid>
			{ r.scope === 'categories' && (
				<CheckGroup legend={ __( 'Categories', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } help={ __( 'Sub-categories are included.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } options={ categories.map( ( c ) => ( { value: c.id, label: c.name } ) ) } value={ r.targets } onChange={ set( 'targets' ) } />
			) }
			{ r.scope === 'products' && (
				<div className="dev3bq-targets">
					<ProductPicker label={ __( 'Add products', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } mode="parents" onPick={ ( p ) => ! picked.find( ( x ) => x.id === p.id ) && setPicked( [ ...picked, { id: p.id, name: p.name } ] ) } />
					<ul className="dev3bq-chips" aria-label={ __( 'Selected products', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
						{ picked.map( ( p ) => (
							<li key={ p.id }>
								{ p.name }
								<IconButton icon="x" size="sm" label={ sprintf( /* translators: %s: product */ __( 'Remove %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), p.name ) } onClick={ () => setPicked( picked.filter( ( x ) => x.id !== p.id ) ) } />
							</li>
						) ) }
					</ul>
				</div>
			) }
			<BreaksEditor value={ r.breaks } onChange={ set( 'breaks' ) } />
			<Toggle label={ __( 'Rule is active', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } checked={ r.active } onChange={ set( 'active' ) } />
		</Modal>
	);
}

/**
 * Price preview tool.
 *
 * @param {Object} props       Props.
 * @param {Array}  props.roles Roles.
 * @return {Element} Card.
 */
export function PricePreview( { roles } ) {
	const [ product, setProduct ] = useState( null );
	const [ role, setRole ] = useState( roles[ 0 ] ? roles[ 0 ].key : '' );
	const [ qty, setQty ] = useState( 1 );
	const [ res, setRes ] = useState( null );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		if ( ! product ) {
			return;
		}
		setError( null );
		request( 'price-preview', { method: 'POST', data: { product_id: product.id, role, qty: qty || 1 } } )
			.then( setRes )
			.catch( setError );
	}, [ product, role, qty ] );
	const source = ( s ) => ( { product: __( 'Product price for this role', 'dev3-ai-b2b-wholesale-for-woocommerce' ), rule: __( 'Pricing rule', 'dev3-ai-b2b-wholesale-for-woocommerce' ), role: __( 'Role default discount', 'dev3-ai-b2b-wholesale-for-woocommerce' ), none: __( 'Regular price (no rule)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } )[ s ];
	return (
		<Card title={ __( 'Price preview', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } description={ __( 'Check what a customer pays before you publish a rule.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
			<FormGrid>
				<ProductPicker label={ __( 'Product', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } onPick={ setProduct } />
				<Select label={ __( 'Customer role', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ role } onChange={ setRole } options={ [ { value: '', label: __( 'Retail / guest', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, ...roles.map( ( r ) => ( { value: r.key, label: r.name } ) ) ] } />
				<NumberField label={ __( 'Quantity', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ qty } onChange={ setQty } min={ 1 } />
			</FormGrid>
			<ErrorNotice error={ error } />
			{ product && res && (
				<div className="dev3bq-preview" aria-live="polite">
					<p className="dev3bq-preview__name">{ res.name }</p>
					<p className="dev3bq-preview__price">
						{ res.regular > res.price && <s>{ money( res.regular ) }</s> } <strong>{ money( res.price ) }</strong> <span className="dev3bq-muted">{ __( 'each', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</span>
					</p>
					<p>
						{ sprintf( /* translators: 1: qty, 2: total */ __( '%1$d × = %2$s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), res.qty, money( res.total ) ) }
					</p>
					<p className="dev3bq-muted">
						{ source( res.source ) }
						{ res.rule_name ? `: ${ res.rule_name }` : '' }
						{ res.break ? sprintf( /* translators: %d: qty */ __( ', tier from %d', 'dev3-ai-b2b-wholesale-for-woocommerce' ), res.break ) : '' }
						{ res.min_qty > 1 ? sprintf( /* translators: %d: qty */ __( ' · minimum %d', 'dev3-ai-b2b-wholesale-for-woocommerce' ), res.min_qty ) : '' }
						{ res.step > 1 ? sprintf( /* translators: %d: qty */ __( ' · packs of %d', 'dev3-ai-b2b-wholesale-for-woocommerce' ), res.step ) : '' }
					</p>
					{ ! res.visible && <Badge tone="warning">{ __( 'Hidden from this role', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</Badge> }
				</div>
			) }
		</Card>
	);
}

/**
 * Rules screen.
 *
 * @return {Element} Screen.
 */
export default function Rules() {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ roles, setRoles ] = useState( [] );
	const [ categories, setCategories ] = useState( [] );
	const [ error, setError ] = useState( null );
	const [ edit, setEdit ] = useState( null );
	const load = () => {
		setError( null );
		request( 'rules' ).then( setData ).catch( setError );
	};
	useEffect( () => {
		load();
		request( 'roles' )
			.then( ( d ) => setRoles( d.roles ) )
			.catch( () => {} );
		request( 'categories' )
			.then( ( d ) => setCategories( d.categories ) )
			.catch( () => {} );
	}, [] );

	const names = ( data && data.names ) || {};
	const remove = ( rule ) =>
		request( `rules/${ rule.id }`, { method: 'DELETE' } )
			.then( ( d ) => {
				setData( d );
				toast.success( __( 'Rule deleted.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) );
	const toggle = ( rule ) =>
		request( `rules/${ rule.id }`, { method: 'POST', data: { ...rule, active: ! rule.active } } )
			.then( setData )
			.catch( ( err ) => toast.error( err ) );

	const columns = [
		{
			key: 'name',
			label: __( 'Rule', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			primary: true,
			render: ( r ) => (
				<span className="dev3bq-cell">
					<strong>{ r.name }</strong>
					<span className="dev3bq-muted">{ whoLabel( r.role, roles ) }</span>
				</span>
			),
		},
		{
			key: 'scope',
			label: __( 'Applies to', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			render: ( r ) => {
				if ( r.scope === 'all' ) {
					return __( 'All products', 'dev3-ai-b2b-wholesale-for-woocommerce' );
				}
				const list = r.targets.map( ( t ) => names[ `${ r.scope === 'products' ? 'p' : 'c' }${ t }` ] || `#${ t }` );
				return list.length > 2 ? `${ list.slice( 0, 2 ).join( ', ' ) } +${ list.length - 2 }` : list.join( ', ' );
			},
		},
		{ key: 'amount', label: __( 'Price', 'dev3-ai-b2b-wholesale-for-woocommerce' ), render: ( r ) => ( r.amount > 0 || r.type !== 'percent' ? discountText( r.type, r.amount ) : __( 'Tiers only', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) },
		{
			key: 'breaks',
			label: __( 'Quantity breaks', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			hideOnMobile: true,
			render: ( r ) => ( r.breaks.length ? r.breaks.map( ( b ) => `${ b.min }+: ${ discountText( b.type, b.amount ) }` ).join( ' · ' ) : <span className="dev3bq-muted">—</span> ),
		},
		{ key: 'priority', label: __( 'Priority', 'dev3-ai-b2b-wholesale-for-woocommerce' ), align: 'end', hideOnMobile: true },
		{ key: 'active', label: __( 'Status', 'dev3-ai-b2b-wholesale-for-woocommerce' ), render: ( r ) => <Badge tone={ r.active ? 'success' : 'neutral' } dot>{ r.active ? __( 'Active', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Paused', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</Badge> },
	];

	return (
		<>
			<PageHeader
				title={ __( 'Pricing rules', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				description={ __( 'Tier prices and quantity breaks. Product-level prices on the product’s Wholesale tab win over rules.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				actions={
					<Button variant="primary" icon="plus" onClick={ () => setEdit( BLANK ) }>
						{ __( 'New rule', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
				}
			/>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			<Card flush>
				<ResponsiveTable
					columns={ columns }
					rows={ data ? data.rules : [] }
					loading={ ! data && ! error }
					onRowClick={ ( r ) => setEdit( r ) }
					rowActions={ ( r ) => (
						// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
						<span className="dev3bq-rowactions" onClick={ ( e ) => e.stopPropagation() }>
							<Button size="sm" variant="ghost" icon={ r.active ? 'pause' : 'check' } onClick={ () => toggle( r ) }>
								{ r.active ? __( 'Pause', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Activate', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
							</Button>
							<IconButton icon="trash" label={ sprintf( /* translators: %s: rule */ __( 'Delete %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), r.name ) } onClick={ () => remove( r ) } />
						</span>
					) }
					empty={ {
						icon: 'bolt',
						title: __( 'No pricing rules yet', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
						description: __( 'Without rules, wholesale customers get their role’s default discount.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
						action: <Button onClick={ () => setEdit( BLANK ) }>{ __( 'Create a rule', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</Button>,
					} }
				/>
			</Card>
			<PricePreview roles={ roles } />
			{ edit && (
				<RuleModal
					rule={ edit }
					roles={ roles }
					categories={ categories }
					names={ names }
					onClose={ () => setEdit( null ) }
					onSaved={ ( d ) => {
						setData( d );
						setEdit( null );
					} }
				/>
			) }
		</>
	);
}
