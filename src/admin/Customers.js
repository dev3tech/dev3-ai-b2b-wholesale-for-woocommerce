import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, Drawer, ErrorNotice, Inline, PageHeader, ResponsiveTable, Select, Tabs, TextField, Textarea, useToast } from '../core3/ui';
import { ago, shortDate, useDebounced } from './util';

const SIGNAL_TONE = { good: 'success', neutral: 'neutral', warning: 'warning' };
const TAX_OPTIONS = () => [
	{ value: '', label: __( 'Use the role setting', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
	{ value: 'yes', label: __( 'Tax exempt', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
	{ value: 'no', label: __( 'Not exempt', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
];

/**
 * Application drawer: details, DEV3 AI screening, approve / decline.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.app     Application.
 * @param {Array}    props.roles   Roles.
 * @param {boolean}  props.aiReady AI connected.
 * @param {Function} props.onClose Close.
 * @param {Function} props.onDone  After a decision.
 * @param {Function} props.onUsage Usage refresh.
 * @return {Element} Drawer.
 */
export function ApplicationDrawer( { app, roles, aiReady, onClose, onDone, onUsage } ) {
	const toast = useToast();
	const [ role, setRole ] = useState( app.role );
	const [ tax, setTax ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ screen, setScreen ] = useState( app.ai );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ letter, setLetter ] = useState( null );
	const pending = app.status === 'pending';

	const run = ( path, data, ok ) => {
		setBusy( path );
		request( `applications/${ app.id }/${ path }`, { method: 'POST', data } )
			.then( () => {
				toast.success( ok );
				onDone();
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( '' ) );
	};
	const doScreen = () => {
		setBusy( 'screen' );
		setError( null );
		request( `applications/${ app.id }/screen`, { method: 'POST' } )
			.then( ( s ) => {
				setScreen( s );
				if ( s.suggested_role ) {
					setRole( s.suggested_role );
				}
			} )
			.catch( setError )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};
	const writeNote = ( kind ) => {
		setBusy( `letter-${ kind }` );
		setError( null );
		request( `applications/${ app.id }/ai-email`, { method: 'POST', data: { kind } } )
			.then( ( l ) => setLetter( { ...l, kind } ) )
			.catch( setError )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};

	const rows = [
		[ __( 'Company', 'dev3-ai-b2b-wholesale-for-woocommerce' ), app.company ],
		[ __( 'Contact', 'dev3-ai-b2b-wholesale-for-woocommerce' ), `${ app.first_name } ${ app.last_name }`.trim() ],
		[ __( 'Email', 'dev3-ai-b2b-wholesale-for-woocommerce' ), app.email ],
		[ __( 'Phone', 'dev3-ai-b2b-wholesale-for-woocommerce' ), app.phone ],
		[ __( 'Tax ID', 'dev3-ai-b2b-wholesale-for-woocommerce' ), app.tax_id ],
		[ __( 'Website', 'dev3-ai-b2b-wholesale-for-woocommerce' ), app.website ],
		[ __( 'Country', 'dev3-ai-b2b-wholesale-for-woocommerce' ), app.country ],
		[ __( 'Requested', 'dev3-ai-b2b-wholesale-for-woocommerce' ), app.role_name ],
		[ __( 'Applied', 'dev3-ai-b2b-wholesale-for-woocommerce' ), shortDate( app.created_at ) ],
	].filter( ( r ) => r[ 1 ] );

	return (
		<Drawer
			open
			onClose={ onClose }
			size="lg"
			title={ app.company }
			description={ sprintf( /* translators: %s: date */ __( 'Wholesale application · %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), ago( app.created_at ) ) }
			footer={
				pending && (
					<>
						<Button variant="danger" onClick={ () => run( 'reject', { reason: note }, __( 'Application declined. The applicant was emailed.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) } loading={ busy === 'reject' }>
							{ __( 'Decline', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						</Button>
						<Button variant="primary" icon="check" onClick={ () => run( 'approve', { role, tax_exempt: tax, note }, __( 'Approved. The customer now has wholesale prices.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) } loading={ busy === 'approve' }>
							{ __( 'Approve', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						</Button>
					</>
				)
			}
		>
			<dl className="dev3bq-deflist">
				{ rows.map( ( r ) => (
					<div key={ r[ 0 ] }>
						<dt>{ r[ 0 ] }</dt>
						<dd>{ r[ 1 ] }</dd>
					</div>
				) ) }
			</dl>
			{ app.message && (
				<blockquote className="dev3bq-quote">{ app.message }</blockquote>
			) }
			{ ! pending && (
				<p>
					<Badge tone={ app.status === 'approved' ? 'success' : 'danger' }>{ app.status === 'approved' ? __( 'Approved', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Declined', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</Badge> { app.reason }
				</p>
			) }
			<Card tone="ai" title={ __( 'DEV3 AI screening', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } description={ __( 'A second pair of eyes on the details. You decide.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
				{ screen ? (
					<>
						<p>{ screen.summary }</p>
						<ul className="dev3bq-signals">
							{ screen.signals.map( ( s, i ) => (
								<li key={ i }>
									<Badge tone={ SIGNAL_TONE[ s.tone ] } dot>
										{ s.tone === 'good' ? __( 'Looks fine', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : s.tone === 'warning' ? __( 'Check', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Note', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
									</Badge>
									<span>{ s.label }</span>
								</li>
							) ) }
						</ul>
						{ screen.questions.length > 0 && (
							<>
								<strong>{ __( 'Questions to ask', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</strong>
								<ul className="dev3bq-bullets">
									{ screen.questions.map( ( q, i ) => (
										<li key={ i }>{ q }</li>
									) ) }
								</ul>
							</>
						) }
					</>
				) : (
					<p className="dev3bq-muted">{ aiReady ? __( 'Not screened yet.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Connect DEV3 AI in API Config to screen applications.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</p>
				) }
				{ aiReady && (
					<AiButton onClick={ doScreen } loading={ busy === 'screen' } loadingText={ __( 'Screening…', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
						{ screen ? __( 'Screen again', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Screen with DEV3 AI', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</AiButton>
				) }
				<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
			</Card>
			{ pending && (
				<Card title={ __( 'Decision', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
					<Select label={ __( 'Approve as', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ role } onChange={ setRole } options={ roles.map( ( r ) => ( { value: r.key, label: r.name } ) ) } />
					<Select label={ __( 'Tax', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ tax } onChange={ setTax } options={ TAX_OPTIONS() } />
					<Textarea label={ __( 'Note to the applicant (in the email)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ note } onChange={ setNote } rows={ 4 } maxLength={ 500 } />
					{ aiReady && (
						<Inline gap={ 8 }>
							<AiButton size="sm" onClick={ () => writeNote( 'approved' ) } loading={ busy === 'letter-approved' }>
								{ __( 'Write welcome note', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
							</AiButton>
							<AiButton size="sm" onClick={ () => writeNote( 'declined' ) } loading={ busy === 'letter-declined' }>
								{ __( 'Write decline note', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
							</AiButton>
						</Inline>
					) }
					{ letter && (
						<AiDraftReview
							title={ __( 'DEV3 AI · note draft', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
							draft={ letter.body }
							original={ note }
							meta={ letter.meta }
							acceptLabel={ __( 'Use this note', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
							onAccept={ ( v ) => {
								setNote( String( v ).slice( 0, 500 ) );
								setLetter( null );
							} }
							onReject={ () => setLetter( null ) }
							onRegenerate={ () => writeNote( letter.kind ) }
							regenerating={ busy.startsWith( 'letter' ) }
						/>
					) }
				</Card>
			) }
		</Drawer>
	);
}

/**
 * Customer drawer.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.user    Customer row.
 * @param {Array}    props.roles   Roles.
 * @param {Function} props.onClose Close.
 * @param {Function} props.onSaved Saved.
 * @return {Element} Drawer.
 */
export function CustomerDrawer( { user, roles, onClose, onSaved } ) {
	const toast = useToast();
	const [ role, setRole ] = useState( user.role );
	const [ tax, setTax ] = useState( user.tax_exempt );
	const [ note, setNote ] = useState( user.note );
	const [ busy, setBusy ] = useState( false );
	const save = () => {
		setBusy( true );
		request( `customers/${ user.id }`, { method: 'POST', data: { role, tax_exempt: tax, note } } )
			.then( () => {
				toast.success( role ? __( 'Customer saved.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Wholesale access removed.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
				onSaved();
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Drawer
			open
			onClose={ onClose }
			title={ user.name }
			description={ [ user.email, user.company ].filter( Boolean ).join( ' · ' ) }
			footer={
				<>
					<Button variant="secondary" href={ user.edit_url }>
						{ __( 'Edit user', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
					<Button variant="primary" onClick={ save } loading={ busy }>
						{ __( 'Save', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
				</>
			}
		>
			<Select label={ __( 'Wholesale role', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ role } onChange={ setRole } options={ [ { value: '', label: __( 'None (retail customer)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, ...roles.map( ( r ) => ( { value: r.key, label: r.name } ) ) ] } />
			<Select label={ __( 'Tax', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ tax } onChange={ setTax } options={ TAX_OPTIONS() } help={ user.exempt_now ? __( 'Currently tax exempt.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Currently pays tax.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
			<Textarea label={ __( 'Internal note', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ note } onChange={ setNote } rows={ 4 } maxLength={ 1000 } />
		</Drawer>
	);
}

/**
 * Applications + wholesale customers.
 *
 * @param {Object}   props          Props.
 * @param {boolean}  props.aiReady  AI connected.
 * @param {Function} props.onUsage  Usage refresh.
 * @param {Function} props.onCounts Pending count callback.
 * @return {Element} Screen.
 */
export default function Customers( { aiReady, onUsage, onCounts } ) {
	const [ tab, setTab ] = useState( 'pending' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ roles, setRoles ] = useState( [] );
	const [ open, setOpen ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const term = useDebounced( search, 300 );
	const isCustomers = tab === 'customers';

	const load = () => {
		setError( null );
		setData( null );
		const params = new URLSearchParams( { page: String( page ) } );
		if ( term ) {
			params.set( 'search', term );
		}
		if ( ! isCustomers ) {
			params.set( 'status', tab );
		}
		request( `${ isCustomers ? 'customers' : 'applications' }?${ params.toString() }` )
			.then( ( d ) => {
				setData( d );
				if ( d.counts ) {
					onCounts( d.counts.pending );
				}
			} )
			.catch( setError );
	};
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( load, [ tab, term, page ] );
	useEffect( () => {
		request( 'roles' )
			.then( ( d ) => setRoles( d.roles ) )
			.catch( () => {} );
	}, [] );

	const counts = ( data && data.counts ) || {};
	const appColumns = [
		{
			key: 'company',
			label: __( 'Company', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			primary: true,
			render: ( r ) => (
				<span className="dev3bq-cell">
					<strong>{ r.company }</strong>
					<span className="dev3bq-muted">{ r.email }</span>
				</span>
			),
		},
		{ key: 'role', label: __( 'Requested', 'dev3-ai-b2b-wholesale-for-woocommerce' ), render: ( r ) => r.role_name },
		{ key: 'country', label: __( 'Country', 'dev3-ai-b2b-wholesale-for-woocommerce' ), hideOnMobile: true, render: ( r ) => r.country || '—' },
		{
			key: 'ai',
			label: __( 'DEV3 AI', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			hideOnMobile: true,
			render: ( r ) => ( r.ai ? <Badge tone={ r.ai.signals.some( ( s ) => s.tone === 'warning' ) ? 'warning' : 'success' }>{ __( 'Screened', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</Badge> : <span className="dev3bq-muted">—</span> ),
		},
		{ key: 'created_at', label: __( 'Applied', 'dev3-ai-b2b-wholesale-for-woocommerce' ), align: 'end', nowrap: true, render: ( r ) => ago( r.created_at ) },
	];
	const userColumns = [
		{
			key: 'name',
			label: __( 'Customer', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			primary: true,
			render: ( r ) => (
				<span className="dev3bq-cell">
					<strong>{ r.company || r.name }</strong>
					<span className="dev3bq-muted">{ r.email }</span>
				</span>
			),
		},
		{ key: 'role_name', label: __( 'Role', 'dev3-ai-b2b-wholesale-for-woocommerce' ), render: ( r ) => <Badge tone="brand">{ r.role_name }</Badge> },
		{ key: 'tax', label: __( 'Tax', 'dev3-ai-b2b-wholesale-for-woocommerce' ), render: ( r ) => ( r.exempt_now ? <Badge tone="info">{ __( 'Exempt', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</Badge> : __( 'Standard', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) },
		{ key: 'registered', label: __( 'Since', 'dev3-ai-b2b-wholesale-for-woocommerce' ), align: 'end', nowrap: true, hideOnMobile: true, render: ( r ) => shortDate( r.registered ) },
	];

	return (
		<>
			<PageHeader title={ __( 'Wholesale customers', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } description={ __( 'Review applications from the registration form and manage who gets which price list.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
			<Tabs
				label={ __( 'View', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				value={ tab }
				onChange={ ( v ) => {
					setTab( v );
					setPage( 1 );
					setSearch( '' );
				} }
				tabs={ [
					{ id: 'pending', label: __( 'Pending', 'dev3-ai-b2b-wholesale-for-woocommerce' ), badge: counts.pending ? String( counts.pending ) : undefined },
					{ id: 'approved', label: __( 'Approved', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
					{ id: 'rejected', label: __( 'Declined', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
					{ id: 'customers', label: __( 'Customers', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
				] }
			/>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			<Card flush>
				<div className="dev3bq-filters">
					<TextField label={ __( 'Search', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } hideLabel type="search" value={ search } onChange={ setSearch } placeholder={ isCustomers ? __( 'Name or email', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Company or email', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				</div>
				<ResponsiveTable
					columns={ isCustomers ? userColumns : appColumns }
					rows={ data ? data.rows : [] }
					loading={ ! data && ! error }
					onRowClick={ ( r ) => setOpen( r ) }
					pagination={ data && data.total > 20 ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
					empty={
						isCustomers
							? { icon: 'users', title: __( 'No wholesale customers yet', 'dev3-ai-b2b-wholesale-for-woocommerce' ), description: __( 'Approve an application or give a user a wholesale role here or in Users.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
							: { icon: 'inbox', title: tab === 'pending' ? __( 'No applications waiting', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Nothing here', 'dev3-ai-b2b-wholesale-for-woocommerce' ), description: __( 'Add the [dev3bq_wholesale_registration] shortcode or the Wholesale registration block to a page so businesses can apply.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					}
				/>
			</Card>
			{ open && ! isCustomers && (
				<ApplicationDrawer
					app={ open }
					roles={ roles }
					aiReady={ aiReady }
					onUsage={ onUsage }
					onClose={ () => setOpen( null ) }
					onDone={ () => {
						setOpen( null );
						load();
					} }
				/>
			) }
			{ open && isCustomers && (
				<CustomerDrawer
					user={ open }
					roles={ roles }
					onClose={ () => setOpen( null ) }
					onSaved={ () => {
						setOpen( null );
						load();
					} }
				/>
			) }
		</>
	);
}
