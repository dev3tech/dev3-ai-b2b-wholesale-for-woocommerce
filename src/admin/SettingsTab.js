import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, FormGrid, NumberField, PageHeader, Select, Textarea, TextField, Toggle, useToast } from '../core3/ui';
import { CheckGroup } from './Roles';

export const KEYS = [
	'guest_prices',
	'guest_price_text',
	'guest_quote_instead',
	'show_retail_price',
	'show_break_table',
	'break_count',
	'retail_hidden_gateways',
	'retail_hidden_shipping',
	'quote_button',
	'cart_to_quote',
	'quote_page_id',
	'quick_order_page_id',
	'registration_page_id',
	'quote_valid_days',
	'quote_terms',
	'quote_prefix',
	'followup_days',
	'expiry_email',
	'account_timing',
	'registration_open',
	'store_address',
	'ai_tone',
	'public_parse',
	'delete_on_uninstall',
];

const PAGE_FIELDS = () => [
	{ key: 'quote_page_id', kind: 'quote', label: __( 'Quote request page', 'dev3-ai-b2b-wholesale-for-woocommerce' ), code: '[dev3bq_request_quote]' },
	{ key: 'quick_order_page_id', kind: 'quick_order', label: __( 'Quick order page', 'dev3-ai-b2b-wholesale-for-woocommerce' ), code: '[dev3bq_quick_order]' },
	{ key: 'registration_page_id', kind: 'registration', label: __( 'Wholesale registration page', 'dev3-ai-b2b-wholesale-for-woocommerce' ), code: '[dev3bq_wholesale_registration]' },
];

/**
 * Settings screen.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @return {Element} Settings.
 */
export default function SettingsTab( { settings, save } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const [ pages, setPages ] = useState( [] );
	const [ options, setOptions ] = useState( { gateways: [], shipping: [] } );
	const [ creating, setCreating ] = useState( '' );
	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );
	const dirty = KEYS.some( ( k ) => JSON.stringify( form[ k ] ) !== JSON.stringify( settings[ k ] ) );

	useEffect( () => {
		request( 'roles' )
			.then( ( d ) => setOptions( { gateways: d.gateways, shipping: d.shipping } ) )
			.catch( () => {} );
		request( 'pages' )
			.then( ( d ) => setPages( d.pages ) )
			.catch( () => {} );
	}, [] );

	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		const values = {};
		KEYS.forEach( ( k ) => ( values[ k ] = form[ k ] === '' && [ 'quote_valid_days', 'followup_days' ].includes( k ) ? 0 : form[ k ] ) );
		save( values )
			.then( ( next ) => {
				setForm( next );
				toast.success( __( 'Settings saved.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	const createPage = ( f ) => {
		setCreating( f.kind );
		request( 'setup-page', { method: 'POST', data: { kind: f.kind } } )
			.then( ( d ) => {
				setPages( d.pages );
				setForm( { ...form, [ f.key ]: d.id } );
				toast.success( __( 'Page created and selected. Save the settings to keep it.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setCreating( '' ) );
	};
	const pageOptions = [ { value: 0, label: __( '— Not set —', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, ...pages.map( ( p ) => ( { value: p.id, label: p.title } ) ) ];

	return (
		<form onSubmit={ submit }>
			<PageHeader title={ __( 'Settings', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } description={ __( 'Storefront behaviour, quotes, registration and DEV3 AI options.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />

			<Card title={ __( 'Pages', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } description={ __( 'Pick existing pages or create them with one click. Each page holds a shortcode (or the matching block).', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
				{ PAGE_FIELDS().map( ( f ) => (
					<div className="dev3bq-pagefield" key={ f.key }>
						<Select label={ f.label } value={ Number( form[ f.key ] ) || 0 } onChange={ ( v ) => set( f.key )( Number( v ) ) } options={ pageOptions } help={ f.code } />
						{ ! Number( form[ f.key ] ) && (
							<Button size="sm" icon="plus" onClick={ () => createPage( f ) } loading={ creating === f.kind }>
								{ __( 'Create page', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
							</Button>
						) }
					</div>
				) ) }
			</Card>

			<Card title={ __( 'Prices and visibility', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
				<FormGrid>
					<Select
						label={ __( 'Prices for guests', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						value={ form.guest_prices }
						onChange={ set( 'guest_prices' ) }
						options={ [
							{ value: 'show', label: __( 'Show prices to everyone', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
							{ value: 'hide', label: __( 'Hide prices until customers log in', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
						] }
					/>
					<TextField label={ __( 'Text instead of the price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.guest_price_text } onChange={ set( 'guest_price_text' ) } placeholder={ __( 'Log in to see prices', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } maxLength={ 80 } />
					<Select
						label={ __( 'Quantity breaks count', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						value={ form.break_count }
						onChange={ set( 'break_count' ) }
						options={ [
							{ value: 'line', label: __( 'Per cart line', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
							{ value: 'parent', label: __( 'All variations of a product together', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
						] }
					/>
				</FormGrid>
				<div className="dev3bq-toggles">
					<Toggle label={ __( 'Offer “Request a quote” when prices are hidden', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } checked={ !! form.guest_quote_instead } onChange={ set( 'guest_quote_instead' ) } />
					<Toggle label={ __( 'Show the retail price struck through next to wholesale prices', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } checked={ !! form.show_retail_price } onChange={ set( 'show_retail_price' ) } />
					<Toggle label={ __( 'Show the “Buy more, save more” table on product pages', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } checked={ !! form.show_break_table } onChange={ set( 'show_break_table' ) } />
				</div>
			</Card>

			<Card title={ __( 'Retail customers', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } description={ __( 'Methods only wholesale buyers may use, such as invoice / net 30 or pickup at the warehouse.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
				<CheckGroup legend={ __( 'Hide these payment methods from retail customers', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } options={ options.gateways } value={ form.retail_hidden_gateways || [] } onChange={ set( 'retail_hidden_gateways' ) } />
				<CheckGroup legend={ __( 'Hide these shipping methods from retail customers', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } options={ options.shipping } value={ form.retail_hidden_shipping || [] } onChange={ set( 'retail_hidden_shipping' ) } />
			</Card>

			<Card title={ __( 'Quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
				<FormGrid>
					<Select
						label={ __( '“Add to quote” button', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						value={ form.quote_button }
						onChange={ set( 'quote_button' ) }
						options={ [
							{ value: 'everyone', label: __( 'Everyone', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
							{ value: 'guests', label: __( 'Guests only', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
							{ value: 'wholesale', label: __( 'Wholesale customers only', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
							{ value: 'hidden_price', label: __( 'Only when the price is hidden', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
							{ value: 'off', label: __( 'Off (quote page only)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
						] }
					/>
					<NumberField label={ __( 'Quotes are valid for', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.quote_valid_days } onChange={ set( 'quote_valid_days' ) } min={ 1 } max={ 365 } suffix={ __( 'days', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
					<NumberField label={ __( 'Send a reminder after', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.followup_days } onChange={ set( 'followup_days' ) } min={ 0 } max={ 60 } suffix={ __( 'days', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } help={ __( '0 = no reminder.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
					<TextField label={ __( 'Quote number prefix', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.quote_prefix } onChange={ set( 'quote_prefix' ) } maxLength={ 8 } />
				</FormGrid>
				<div className="dev3bq-toggles">
					<Toggle label={ __( 'Show “Request a quote for this cart” on the cart page', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } checked={ !! form.cart_to_quote } onChange={ set( 'cart_to_quote' ) } />
					<Toggle label={ __( 'Email the customer when a sent quote expires', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } checked={ !! form.expiry_email } onChange={ set( 'expiry_email' ) } />
				</div>
				<Textarea label={ __( 'Default terms', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.quote_terms } onChange={ set( 'quote_terms' ) } rows={ 3 } maxLength={ 1000 } placeholder={ __( 'Prices are valid until the date shown. Shipping and taxes as listed…', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
				<Textarea label={ __( 'Address on the PDF', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.store_address } onChange={ set( 'store_address' ) } rows={ 3 } maxLength={ 400 } help={ __( 'Leave empty to use the WooCommerce store address.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
			</Card>

			<Card title={ __( 'Wholesale registration', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
				<FormGrid>
					<Select
						label={ __( 'Create the account', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						value={ form.account_timing }
						onChange={ set( 'account_timing' ) }
						options={ [
							{ value: 'now', label: __( 'Right away (as a retail customer until approved)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
							{ value: 'on_approval', label: __( 'Only when the application is approved', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
						] }
					/>
				</FormGrid>
				<Toggle label={ __( 'Registration is open', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } checked={ !! form.registration_open } onChange={ set( 'registration_open' ) } />
			</Card>

			<Card tone="ai" title={ __( 'DEV3 AI', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
				<FormGrid>
					<Select
						label={ __( 'Email tone', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						value={ form.ai_tone }
						onChange={ set( 'ai_tone' ) }
						options={ [
							{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
							{ value: 'formal', label: __( 'Formal', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
							{ value: 'concise', label: __( 'Short and to the point', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
						] }
					/>
				</FormGrid>
				<Toggle
					label={ __( 'Let shoppers build a list from text on the quote and quick-order pages', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					checked={ !! form.public_parse }
					onChange={ set( 'public_parse' ) }
					help={ __( 'Rate-limited and cached; falls back to a simple matcher when AI is off or the budget is reached. Shoppers confirm every line.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				/>
			</Card>

			<Card
				title={ __( 'Advanced', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				footer={
					<Button type="submit" variant="primary" loading={ busy } disabled={ ! dirty }>
						{ __( 'Save settings', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
				}
			>
				<Toggle label={ __( 'Delete all data when the plugin is uninstalled', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } checked={ !! form.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } help={ __( 'Removes quotes, applications, rules and roles. Orders stay in WooCommerce.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
			</Card>
		</form>
	);
}
