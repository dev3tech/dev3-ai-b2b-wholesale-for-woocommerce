import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, ErrorNotice, Icon, Notice, PageHeader, ResponsiveTable, SkeletonCard, Stat, StatGrid } from '../core3/ui';
import { StatusBadge, ago, money } from './util';

/**
 * Setup checklist item.
 *
 * @param {Object}  props       Props.
 * @param {boolean} props.done  Done.
 * @param {string}  props.title Title.
 * @param {string}  props.text  Text.
 * @param {string}  props.href  Link.
 * @param {string}  props.cta   Link text.
 * @return {Element} Item.
 */
function Step( { done, title, text, href, cta } ) {
	return (
		<li className={ `dev3bq-step${ done ? ' is-done' : '' }` }>
			<span className="dev3bq-step__icon" aria-hidden="true">
				<Icon name={ done ? 'check' : 'clock' } size={ 14 } />
			</span>
			<span className="dev3bq-step__body">
				<strong>{ title }</strong>
				<span className="dev3bq-muted">{ text }</span>
			</span>
			{ ! done && href && (
				<Button size="sm" href={ href }>
					{ cta }
				</Button>
			) }
			{ done && <span className="dev3bq-ui-sr-only">{ __( 'Done', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</span> }
		</li>
	);
}

/**
 * Dashboard.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.navigate Router.
 * @param {Function} props.onCounts Nav badge counts.
 * @param {boolean}  props.aiReady  AI connected.
 * @return {Element} Screen.
 */
export default function Dashboard( { navigate, onCounts, aiReady } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = () => {
		setError( null );
		request( 'dashboard' )
			.then( ( d ) => {
				setData( d );
				onCounts( { quotes: d.quotes.new, applications: d.applications } );
			} )
			.catch( setError );
	};
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( load, [] );

	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! data ) {
		return (
			<>
				<PageHeader title={ __( 'Dashboard', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				<SkeletonCard lines={ 4 } />
			</>
		);
	}
	const q = data.quotes;
	const pages = data.setup.pages;
	const columns = [
		{
			key: 'number',
			label: __( 'Quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			primary: true,
			render: ( r ) => (
				<span className="dev3bq-cell">
					<strong>{ r.number }</strong>
					<span className="dev3bq-muted">{ r.company || r.name || r.email }</span>
				</span>
			),
		},
		{ key: 'status', label: __( 'Status', 'dev3-ai-b2b-wholesale-for-woocommerce' ), render: ( r ) => <StatusBadge status={ r.status } /> },
		{ key: 'total', label: __( 'Total', 'dev3-ai-b2b-wholesale-for-woocommerce' ), align: 'end', nowrap: true, render: ( r ) => ( r.total > 0 ? money( r.total, r.currency ) : '—' ) },
		{ key: 'updated_at', label: __( 'Updated', 'dev3-ai-b2b-wholesale-for-woocommerce' ), align: 'end', nowrap: true, hideOnMobile: true, render: ( r ) => ago( r.updated_at ) },
	];

	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				description={ __( 'Quotes waiting for you, wholesale applications and how B2B sales are doing.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				actions={
					<Button variant="primary" icon="file" href="#/quotes">
						{ __( 'Open quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
				}
			/>
			{ ! aiReady && (
				<Notice
					tone="ai"
					title={ __( 'Connect DEV3 AI', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					actions={
						<Button size="sm" variant="primary" href="#/api">
							{ __( 'Add your API key', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						</Button>
					}
				>
					{ __( 'With a Google Gemini key, DEV3 AI turns free-text requests into priced draft quotes, writes follow-up emails and pre-screens applications. Pricing, quotes and the storefront work without it.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				</Notice>
			) }
			<StatGrid>
				<Stat label={ __( 'New quote requests', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ String( q.new ) } icon="inbox" tone={ q.new ? 'warning' : 'neutral' } hint={ __( 'Waiting for a price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				<Stat label={ __( 'Open quote value', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ money( q.open_value ) } icon="file" tone="brand" hint={ sprintf( /* translators: %d: quotes */ __( '%d open quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' ), q.open ) } />
				<Stat label={ __( 'Acceptance rate', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ q.accept_rate === null ? '—' : `${ q.accept_rate }%` } icon="check" tone="success" hint={ sprintf( /* translators: %s: amount */ __( '%s won in 30 days', 'dev3-ai-b2b-wholesale-for-woocommerce' ), money( q.won_value ) ) } />
				<Stat label={ __( 'Pending applications', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ String( data.applications ) } icon="users" tone={ data.applications ? 'warning' : 'neutral' } hint={ sprintf( /* translators: %d: customers */ __( '%d wholesale customers', 'dev3-ai-b2b-wholesale-for-woocommerce' ), data.customers ) } />
				<Stat label={ __( 'Wholesale revenue', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ money( data.revenue.value ) } icon="chart" hint={ sprintf( /* translators: %d: orders */ __( '%d orders in 30 days', 'dev3-ai-b2b-wholesale-for-woocommerce' ), data.revenue.orders ) } />
			</StatGrid>
			<Columns min={ 320 }>
				<Card
					title={ __( 'Latest quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					flush
					actions={
						<Button size="sm" variant="ghost" iconRight="chevronRight" href="#/quotes">
							{ __( 'All quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						</Button>
					}
				>
					<ResponsiveTable
						columns={ columns }
						rows={ data.recent }
						onRowClick={ ( r ) => navigate( `quotes/${ r.id }` ) }
						empty={ { icon: 'file', title: __( 'No quotes yet', 'dev3-ai-b2b-wholesale-for-woocommerce' ), description: __( 'Requests from the quote page, the cart and the quick-order form show up here.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } }
					/>
				</Card>
				<Card title={ __( 'Setup checklist', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } description={ __( 'Everything a B2B store needs, in order.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
					<ul className="dev3bq-steps">
						<Step done={ data.setup.roles > 0 } title={ __( 'Wholesale roles', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } text={ sprintf( /* translators: %d: roles */ __( '%d roles with discounts, minimums, tax and payment rules.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), data.setup.roles ) } href="#/roles" cta={ __( 'Add roles', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
						<Step done={ data.setup.rules > 0 } title={ __( 'Pricing rules', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } text={ __( 'Tier prices and quantity breaks by category or product.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } href="#/rules" cta={ __( 'Create a rule', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
						<Step done={ !! pages.quote_page_id } title={ __( 'Quote request page', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } text={ __( 'A page with the [dev3bq_request_quote] shortcode or the Request a quote block.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } href="#/settings" cta={ __( 'Choose page', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
						<Step done={ !! pages.quick_order_page_id } title={ __( 'Quick order page', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } text={ __( 'A page with [dev3bq_quick_order] for fast bulk buying.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } href="#/settings" cta={ __( 'Choose page', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
						<Step done={ !! pages.registration_page_id } title={ __( 'Wholesale registration', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } text={ __( 'A page with [dev3bq_wholesale_registration] so businesses can apply.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } href="#/settings" cta={ __( 'Choose page', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
						<Step done={ !! data.setup.ai.key_set } title={ __( 'DEV3 AI', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } text={ __( 'Drafts quotes from request text and writes the emails.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } href="#/api" cta={ __( 'Connect', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
					</ul>
					{ data.applications > 0 && (
						<p className="dev3bq-callout">
							<Badge tone="warning">{ String( data.applications ) }</Badge> { __( 'applications are waiting for review.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }{ ' ' }
							<Button variant="link" href="#/customers">
								{ __( 'Review now', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
							</Button>
						</p>
					) }
				</Card>
			</Columns>
		</>
	);
}
