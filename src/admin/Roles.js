import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Checkbox, Columns, EmptyState, ErrorNotice, FormGrid, Modal, NumberField, PageHeader, Select, SkeletonCard, TextField, Toggle, useToast } from '../core3/ui';
import { money } from './util';

const BLANK = { key: '', name: '', discount: 10, min_value: 0, min_qty: 0, free_shipping: 0, tax_exempt: false, gateways: [], shipping: [], label: '', show_retail: true, quote: 'inherit', signup: true, auto_approve: false };

/**
 * Checkbox group.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.legend   Legend.
 * @param {string}   props.help     Help.
 * @param {Array}    props.options  [{value,label}].
 * @param {Array}    props.value    Selected.
 * @param {Function} props.onChange Change.
 * @return {Element} Fieldset.
 */
export function CheckGroup( { legend, help, options, value, onChange } ) {
	return (
		<fieldset className="dev3bq-checks">
			<legend>{ legend }</legend>
			{ help && <p className="dev3bq-muted">{ help }</p> }
			<div className="dev3bq-checks__grid">
				{ options.map( ( o ) => (
					<Checkbox key={ o.value } label={ o.label } checked={ value.includes( o.value ) } onChange={ ( on ) => onChange( on ? [ ...value, o.value ] : value.filter( ( v ) => v !== o.value ) ) } />
				) ) }
			</div>
		</fieldset>
	);
}

/**
 * Role editor modal.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.role     Role (BLANK for new).
 * @param {Array}    props.gateways Gateways.
 * @param {Array}    props.shipping Shipping methods.
 * @param {Function} props.onClose  Close.
 * @param {Function} props.onSaved  Saved (roles payload).
 * @return {Element} Modal.
 */
export function RoleModal( { role, gateways, shipping, onClose, onSaved } ) {
	const toast = useToast();
	const isNew = ! role.key;
	const [ r, setR ] = useState( { ...BLANK, ...role } );
	const [ busy, setBusy ] = useState( false );
	const set = ( k ) => ( v ) => setR( { ...r, [ k ]: v } );
	const save = () => {
		setBusy( true );
		request( 'roles', { method: 'POST', data: { ...r, original_key: isNew ? '' : role.key } } )
			.then( ( d ) => {
				toast.success( __( 'Role saved.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
				onSaved( d );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Modal
			open
			onClose={ onClose }
			size="lg"
			title={ isNew ? __( 'New wholesale role', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : sprintf( /* translators: %s: role */ __( 'Edit %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), role.name ) }
			description={ __( 'A role is a price list plus buying rules. Customers get it when you approve their application.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
			footer={
				<>
					<Button variant="secondary" onClick={ onClose }>
						{ __( 'Cancel', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
					<Button variant="primary" onClick={ save } loading={ busy } disabled={ ! r.name.trim() }>
						{ __( 'Save role', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
				</>
			}
		>
			<FormGrid>
				<TextField label={ __( 'Name', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ r.name } onChange={ set( 'name' ) } required data-autofocus placeholder={ __( 'e.g. Gold distributor', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				<NumberField label={ __( 'Default discount', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ r.discount } onChange={ set( 'discount' ) } min={ 0 } max={ 100 } step={ 0.5 } suffix="%" help={ __( 'Used when no rule or product price matches.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				<NumberField label={ __( 'Minimum order value', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ r.min_value } onChange={ set( 'min_value' ) } min={ 0 } step={ 1 } help={ __( '0 = no minimum.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				<NumberField label={ __( 'Minimum items per order', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ r.min_qty } onChange={ set( 'min_qty' ) } min={ 0 } step={ 1 } />
				<NumberField label={ __( 'Free shipping from', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ r.free_shipping } onChange={ set( 'free_shipping' ) } min={ 0 } step={ 1 } help={ __( 'Adds a free shipping option above this cart value. 0 = off.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				<TextField label={ __( 'Price label', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ r.label } onChange={ set( 'label' ) } placeholder={ __( 'Wholesale price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				<Select
					label={ __( '“Add to quote” button', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					value={ r.quote }
					onChange={ set( 'quote' ) }
					options={ [
						{ value: 'inherit', label: __( 'Follow the store setting', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
						{ value: 'always', label: __( 'Always show', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
						{ value: 'never', label: __( 'Never show', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
					] }
				/>
			</FormGrid>
			<div className="dev3bq-toggles">
				<Toggle label={ __( 'Tax exempt', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } checked={ r.tax_exempt } onChange={ set( 'tax_exempt' ) } help={ __( 'No tax in cart, checkout and quotes. You can override it per customer.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				<Toggle label={ __( 'Show the retail price struck through', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } checked={ r.show_retail } onChange={ set( 'show_retail' ) } />
				<Toggle label={ __( 'Open for sign-up', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } checked={ r.signup } onChange={ set( 'signup' ) } help={ __( 'Applicants can choose this role on the registration form.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				<Toggle label={ __( 'Approve applications automatically', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } checked={ r.auto_approve } onChange={ set( 'auto_approve' ) } />
			</div>
			<CheckGroup legend={ __( 'Payment methods', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } help={ __( 'Leave all unticked to allow every enabled method.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } options={ gateways } value={ r.gateways } onChange={ set( 'gateways' ) } />
			<CheckGroup legend={ __( 'Shipping methods', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } help={ __( 'Leave all unticked to allow every method.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } options={ shipping } value={ r.shipping } onChange={ set( 'shipping' ) } />
		</Modal>
	);
}

/**
 * Roles screen.
 *
 * @return {Element} Screen.
 */
export default function Roles() {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ edit, setEdit ] = useState( null );
	const [ del, setDel ] = useState( null );
	const load = () => {
		setError( null );
		request( 'roles' ).then( setData ).catch( setError );
	};
	useEffect( load, [] );

	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! data ) {
		return <SkeletonCard lines={ 4 } />;
	}
	const gwName = ( id ) => ( data.gateways.find( ( g ) => g.value === id ) || { label: id } ).label;
	const shipName = ( id ) => ( data.shipping.find( ( g ) => g.value === id ) || { label: id } ).label;
	const remove = () => {
		request( `roles/${ del.key }`, { method: 'DELETE' } )
			.then( ( d ) => {
				setData( d );
				toast.success( __( 'Role deleted. Its customers are retail customers now.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setDel( null ) );
	};

	return (
		<>
			<PageHeader
				title={ __( 'Roles & tiers', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				description={ __( 'Each wholesale role has its own prices, minimums, tax, payment and shipping rules.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				actions={
					<Button variant="primary" icon="plus" onClick={ () => setEdit( BLANK ) }>
						{ __( 'Add role', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
				}
			/>
			{ data.roles.length === 0 ? (
				<Card>
					<EmptyState icon="users" title={ __( 'No wholesale roles', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } description={ __( 'Create a role such as “Wholesale” or “Distributor” to start.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } action={ <Button onClick={ () => setEdit( BLANK ) }>{ __( 'Add role', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</Button> } />
				</Card>
			) : (
				<Columns min={ 300 }>
					{ data.roles.map( ( r ) => (
						<Card
							key={ r.key }
							title={ r.name }
							description={ sprintf( /* translators: %d: customers */ _n( '%d customer', '%d customers', r.users, 'dev3-ai-b2b-wholesale-for-woocommerce' ), r.users ) }
							footer={
								<>
									<Button variant="ghost" icon="trash" onClick={ () => setDel( r ) }>
										{ __( 'Delete', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
									</Button>
									<Button icon="edit" onClick={ () => setEdit( r ) }>
										{ __( 'Edit', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
									</Button>
								</>
							}
						>
							<dl className="dev3bq-deflist dev3bq-deflist--compact">
								<div>
									<dt>{ __( 'Default discount', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</dt>
									<dd>{ r.discount ? `${ r.discount }%` : __( 'None', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</dd>
								</div>
								<div>
									<dt>{ __( 'Minimum order', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</dt>
									<dd>{ [ r.min_value ? money( r.min_value ) : '', r.min_qty ? sprintf( /* translators: %d: items */ __( '%d items', 'dev3-ai-b2b-wholesale-for-woocommerce' ), r.min_qty ) : '' ].filter( Boolean ).join( ' · ' ) || __( 'None', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</dd>
								</div>
								<div>
									<dt>{ __( 'Payment', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</dt>
									<dd>{ r.gateways.length ? r.gateways.map( gwName ).join( ', ' ) : __( 'All methods', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</dd>
								</div>
								<div>
									<dt>{ __( 'Shipping', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</dt>
									<dd>{ r.shipping.length ? r.shipping.map( shipName ).join( ', ' ) : __( 'All methods', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</dd>
								</div>
							</dl>
							<div className="dev3bq-badges">
								{ r.tax_exempt && <Badge tone="info">{ __( 'Tax exempt', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</Badge> }
								{ r.signup && <Badge tone="brand">{ __( 'Open for sign-up', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</Badge> }
								{ r.auto_approve && <Badge tone="warning">{ __( 'Auto-approve', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</Badge> }
								{ r.free_shipping > 0 && <Badge tone="success">{ sprintf( /* translators: %s: amount */ __( 'Free shipping from %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), money( r.free_shipping ) ) }</Badge> }
							</div>
						</Card>
					) ) }
				</Columns>
			) }
			{ edit && (
				<RoleModal
					role={ edit }
					gateways={ data.gateways }
					shipping={ data.shipping }
					onClose={ () => setEdit( null ) }
					onSaved={ ( d ) => {
						setData( d );
						setEdit( null );
					} }
				/>
			) }
			<Modal
				open={ !! del }
				onClose={ () => setDel( null ) }
				size="sm"
				title={ __( 'Delete this role?', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				description={ del ? sprintf( /* translators: %s: role */ __( 'Customers with “%s” go back to retail prices. Rules for this role stop applying.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), del.name ) : '' }
				footer={
					<>
						<Button variant="secondary" onClick={ () => setDel( null ) }>
							{ __( 'Keep it', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						</Button>
						<Button variant="danger" onClick={ remove }>
							{ __( 'Delete role', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						</Button>
					</>
				}
			/>
		</>
	);
}
