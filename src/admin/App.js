import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import Dashboard from './Dashboard';
import Quotes from './Quotes';
import QuoteEditor from './QuoteEditor';
import Customers from './Customers';
import Roles from './Roles';
import Rules from './Rules';
import SettingsTab from './SettingsTab';
import './admin.css';

export default function App() {
	const [ route, navigate ] = useHashRoute( [ 'dashboard', 'quotes', 'customers', 'roles', 'rules', 'settings', 'api', 'usage' ], 'dashboard' );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );
	const [ counts, setCounts ] = useState( { quotes: 0, applications: 0 } );

	const load = () => {
		setError( null );
		request( 'settings' ).then( setSettings ).catch( setError );
	};
	useEffect( load, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			return next;
		} );
	const onUsage = () => setMeter( ( n ) => n + 1 );
	const aiReady = !! ( settings && settings.api_key_set && settings.ai_enabled !== false );

	const NAV = [
		{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-b2b-wholesale-for-woocommerce' ), icon: 'dashboard' },
		{ id: 'quotes', label: __( 'Quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' ), icon: 'file', badge: counts.quotes ? String( counts.quotes ) : undefined },
		{ id: 'customers', label: __( 'Wholesale customers', 'dev3-ai-b2b-wholesale-for-woocommerce' ), icon: 'users', badge: counts.applications ? String( counts.applications ) : undefined },
		{ id: 'roles', label: __( 'Roles & tiers', 'dev3-ai-b2b-wholesale-for-woocommerce' ), icon: 'columns' },
		{ id: 'rules', label: __( 'Pricing rules', 'dev3-ai-b2b-wholesale-for-woocommerce' ), icon: 'bolt' },
		{ id: 'settings', label: __( 'Settings', 'dev3-ai-b2b-wholesale-for-woocommerce' ), icon: 'settings' },
		{ id: 'api', label: __( 'API Config', 'dev3-ai-b2b-wholesale-for-woocommerce' ), icon: 'key' },
		{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-b2b-wholesale-for-woocommerce' ), icon: 'chart' },
	];

	const quoteId = route.id === 'quotes' && route.params[ 0 ] ? parseInt( route.params[ 0 ], 10 ) : 0;

	const screens = {
		dashboard: () => <Dashboard navigate={ navigate } onCounts={ setCounts } aiReady={ aiReady } />,
		quotes: () => ( quoteId ? <QuoteEditor key={ quoteId } id={ quoteId } navigate={ navigate } aiReady={ aiReady } onUsage={ onUsage } /> : <Quotes navigate={ navigate } onCounts={ ( n ) => setCounts( ( c ) => ( { ...c, quotes: n } ) ) } /> ),
		customers: () => <Customers aiReady={ aiReady } onUsage={ onUsage } onCounts={ ( n ) => setCounts( ( c ) => ( { ...c, applications: n } ) ) } />,
		roles: () => <Roles />,
		rules: () => <Rules />,
		settings: () => <SettingsTab settings={ settings } save={ save } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } description={ __( 'Connect Google Gemini for quote drafts, email writing and application screening. Wholesale pricing and quotes work without it.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI B2B Wholesale & Quote Requests for WooCommerce' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ NAV }
			active={ route.id }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key. AI drafts are never saved or sent until you accept them.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
			{ settings && screens[ route.id ]() }
		</AppShell>
	);
}
