import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, FormGrid, Modal, PageHeader, ResponsiveTable, Tabs, TextField, Textarea, useToast } from '../core3/ui';
import { StatusBadge, ago, money, sourceLabel, statusLabel, useDebounced } from './util';

const TABS = [ 'open', 'requested', 'draft', 'sent', 'accepted', 'declined', 'expired', 'cancelled', 'all' ];
const PER_PAGE = 20;

/**
 * "New quote" modal: pick a customer (or type a contact) and paste the request.
 *
 * @param {Object}   props         Props.
 * @param {boolean}  props.open    Open.
 * @param {Function} props.onClose Close.
 * @param {Function} props.onDone  Created quote callback.
 * @return {Element} Modal.
 */
export function NewQuoteModal( { open, onClose, onDone } ) {
	const toast = useToast();
	const [ term, setTerm ] = useState( '' );
	const [ users, setUsers ] = useState( [] );
	const [ user, setUser ] = useState( null );
	const [ form, setForm ] = useState( { email: '', name: '', company: '', phone: '', request: '' } );
	const [ busy, setBusy ] = useState( false );
	const q = useDebounced( term, 300 );
	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );

	useEffect( () => {
		if ( ! q || user ) {
			setUsers( [] );
			return;
		}
		request( `customers/search?term=${ encodeURIComponent( q ) }` )
			.then( ( d ) => setUsers( d.users ) )
			.catch( () => setUsers( [] ) );
	}, [ q, user ] );

	const create = () => {
		setBusy( true );
		request( 'quotes', { method: 'POST', data: { ...form, user_id: user ? user.id : 0 } } )
			.then( ( quote ) => {
				toast.success( __( 'Quote created.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
				onDone( quote );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Modal
			open={ open }
			onClose={ onClose }
			size="lg"
			title={ __( 'New quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
			description={ __( 'Pick an existing customer or type the contact details. Paste the customer’s request so DEV3 AI can draft the lines.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
			footer={
				<>
					<Button variant="secondary" onClick={ onClose }>
						{ __( 'Cancel', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
					<Button variant="primary" onClick={ create } loading={ busy } disabled={ ! user && ! form.email }>
						{ __( 'Create quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
				</>
			}
		>
			{ user ? (
				<div className="dev3bq-picked">
					<span>
						<strong>{ user.name }</strong>
						<span className="dev3bq-muted">{ [ user.email, user.company, user.role_name ].filter( Boolean ).join( ' · ' ) }</span>
					</span>
					<Button size="sm" variant="ghost" onClick={ () => setUser( null ) }>
						{ __( 'Change', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
				</div>
			) : (
				<>
					<TextField label={ __( 'Find a customer', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } type="search" value={ term } onChange={ setTerm } placeholder={ __( 'Name or email', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } data-autofocus />
					{ users.length > 0 && (
						<ul className="dev3bq-userlist" aria-label={ __( 'Matching customers', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
							{ users.map( ( u ) => (
								<li key={ u.id }>
									<button type="button" onClick={ () => setUser( u ) }>
										<strong>{ u.name }</strong>
										<span className="dev3bq-muted">{ [ u.email, u.company, u.role_name ].filter( Boolean ).join( ' · ' ) }</span>
									</button>
								</li>
							) ) }
						</ul>
					) }
					<p className="dev3bq-muted dev3bq-or">{ __( 'Or a new contact:', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</p>
					<FormGrid>
						<TextField label={ __( 'Email', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } type="email" value={ form.email } onChange={ set( 'email' ) } required />
						<TextField label={ __( 'Name', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.name } onChange={ set( 'name' ) } />
						<TextField label={ __( 'Company', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.company } onChange={ set( 'company' ) } />
						<TextField label={ __( 'Phone', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.phone } onChange={ set( 'phone' ) } />
					</FormGrid>
				</>
			) }
			<Textarea label={ __( 'Customer request (optional)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.request } onChange={ set( 'request' ) } rows={ 4 } maxLength={ 4000 } placeholder={ __( 'e.g. "Please quote 200 kraft mugs and 50 A5 notebooks, delivered to Berlin."', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
		</Modal>
	);
}

/**
 * Quotes list.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.navigate Router.
 * @param {Function} props.onCounts New-request count callback.
 * @return {Element} Screen.
 */
export default function Quotes( { navigate, onCounts } ) {
	const [ status, setStatus ] = useState( 'open' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ creating, setCreating ] = useState( false );
	const term = useDebounced( search, 300 );

	const load = () => {
		setError( null );
		const params = new URLSearchParams( { page: String( page ), per_page: String( PER_PAGE ) } );
		if ( status !== 'all' ) {
			params.set( 'status', status );
		}
		if ( term ) {
			params.set( 'search', term );
		}
		request( `quotes?${ params.toString() }` )
			.then( ( d ) => {
				setData( d );
				onCounts( d.counts.requested );
			} )
			.catch( setError );
	};
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( load, [ status, term, page ] );

	const counts = data ? data.counts : {};
	const columns = [
		{
			key: 'number',
			label: __( 'Quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			primary: true,
			render: ( r ) => (
				<span className="dev3bq-cell">
					<strong>{ r.number }</strong>
					<span className="dev3bq-muted">{ sourceLabel( r.source ) }</span>
				</span>
			),
		},
		{
			key: 'customer',
			label: __( 'Customer', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			render: ( r ) => (
				<span className="dev3bq-cell">
					<span>{ r.company || r.name || '—' }</span>
					<span className="dev3bq-muted">{ r.email }</span>
				</span>
			),
		},
		{ key: 'status', label: __( 'Status', 'dev3-ai-b2b-wholesale-for-woocommerce' ), render: ( r ) => <StatusBadge status={ r.status } /> },
		{ key: 'total', label: __( 'Total', 'dev3-ai-b2b-wholesale-for-woocommerce' ), align: 'end', nowrap: true, render: ( r ) => ( r.total > 0 ? money( r.total, r.currency ) : '—' ) },
		{
			key: 'order',
			label: __( 'Order', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			hideOnMobile: true,
			render: ( r ) => ( r.order_id ? <Badge tone="success">{ `#${ r.order_id }` }</Badge> : <span className="dev3bq-muted">—</span> ),
		},
		{ key: 'updated_at', label: __( 'Updated', 'dev3-ai-b2b-wholesale-for-woocommerce' ), align: 'end', nowrap: true, hideOnMobile: true, render: ( r ) => ago( r.updated_at ) },
	];

	return (
		<>
			<PageHeader
				title={ __( 'Quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				description={ __( 'Requests from the storefront and quotes you build. Open one to price it, let DEV3 AI draft it, send it as a PDF and turn it into an order.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				actions={
					<Button variant="primary" icon="plus" onClick={ () => setCreating( true ) }>
						{ __( 'New quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
				}
			/>
			<Tabs
				label={ __( 'Quote status', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				value={ status }
				onChange={ ( s ) => {
					setStatus( s );
					setPage( 1 );
				} }
				tabs={ TABS.map( ( s ) => ( { id: s, label: s === 'all' ? __( 'All', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : statusLabel( s ), badge: counts[ s ] ? String( counts[ s ] ) : undefined } ) ) }
			/>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			<Card flush>
				<div className="dev3bq-filters">
					<TextField label={ __( 'Search quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } hideLabel type="search" value={ search } onChange={ ( v ) => {
						setSearch( v );
						setPage( 1 );
					} } placeholder={ __( 'Number, email, name or company', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				</div>
				<ResponsiveTable
					columns={ columns }
					rows={ data ? data.rows : [] }
					loading={ ! data && ! error }
					onRowClick={ ( r ) => navigate( `quotes/${ r.id }` ) }
					pagination={ data && data.total > PER_PAGE ? { page, perPage: PER_PAGE, total: data.total, onPageChange: setPage } : undefined }
					empty={ {
						icon: 'file',
						title: term ? __( 'No quotes match your search', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'No quotes here yet', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
						description: __( 'Shoppers request quotes from product pages, the cart and the quote page. You can also create one yourself.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
						action: (
							<Button icon="plus" onClick={ () => setCreating( true ) }>
								{ __( 'New quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
							</Button>
						),
					} }
				/>
			</Card>
			{ creating && <NewQuoteModal open onClose={ () => setCreating( false ) } onDone={ ( quote ) => navigate( `quotes/${ quote.id }` ) } /> }
		</>
	);
}
