import apiFetch from '@wordpress/api-fetch';
import { ToastProvider } from '../../src/core3/ui';
import { discountText, statusLabel, whoLabel } from '../../src/admin/util';
import Quotes from '../../src/admin/Quotes';
import QuoteEditor, { totals } from '../../src/admin/QuoteEditor';
import Customers from '../../src/admin/Customers';
import Rules, { BreaksEditor } from '../../src/admin/Rules';
import Roles from '../../src/admin/Roles';
import Dashboard from '../../src/admin/Dashboard';
import { change, click, render } from './render';

const wrap = ( el ) => <ToastProvider>{ el }</ToastProvider>;
const route = ( map ) =>
	apiFetch.mockImplementation( ( opts ) => {
		const url = opts.url.replace( /^.*dev3bq\/v1\//, '' );
		const method = opts.method || 'GET';
		for ( const [ pattern, reply ] of map ) {
			if ( pattern.test( `${ method } ${ url }` ) ) {
				return Promise.resolve( typeof reply === 'function' ? reply( opts ) : reply );
			}
		}
		return new Promise( () => {} );
	} );
const calls = ( pattern ) => apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).filter( ( o ) => pattern.test( `${ o.method || 'GET' } ${ o.url.replace( /^.*dev3bq\/v1\//, '' ) }` ) );

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

const ROLES = { roles: [ { key: 'wholesale', name: 'Wholesale', discount: 10, min_value: 0, min_qty: 0, gateways: [], shipping: [], tax_exempt: false, signup: true, auto_approve: false, free_shipping: 0, users: 2 }, { key: 'distributor', name: 'Distributor', discount: 20, min_value: 250, min_qty: 0, gateways: [ 'bacs' ], shipping: [], tax_exempt: true, signup: true, auto_approve: false, free_shipping: 0, users: 1 } ], gateways: [ { value: 'bacs', label: 'Bank transfer' }, { value: 'cod', label: 'Cash' } ], shipping: [ { value: 'flat_rate', label: 'Flat rate' } ] };

const QUOTE = {
	id: 7,
	number: 'Q-01007',
	status: 'requested',
	source: 'text',
	name: 'Anna Buyer',
	company: 'Anna GmbH',
	email: 'anna@buyer.test',
	phone: '',
	role: 'distributor',
	role_name: 'Distributor',
	request: '120 kraft mugs and 30 notebooks',
	message: '',
	admin_note: '',
	subtotal: 0,
	discount: 0,
	shipping: 0,
	tax: 0,
	total: 0,
	tax_mode: 'store',
	valid_until: '2026-11-24',
	terms: '',
	terms_hint: 'Default terms',
	currency: 'USD',
	items: [],
	events: [ { id: 1, type: 'status', actor: 'customer', user: '', message: 'Quote requested.', created_at: new Date().toISOString() } ],
	pdf_url: '/pdf',
	customer_url: '/view',
	order: null,
	user: null,
	editable: true,
	created_at: new Date().toISOString(),
	updated_at: new Date().toISOString(),
};

const DRAFT = {
	lines: [
		{ product_id: 11, variation_id: 0, name: 'Kraft mug', sku: 'MUG', qty: 120, unit_price: 6, line_total: 720, regular_price: 10, note: '', confidence: 'high' },
		{ product_id: 12, variation_id: 0, name: 'A5 notebook', sku: 'NB', qty: 30, unit_price: 3.2, line_total: 96, regular_price: 4, note: '', confidence: 'low' },
	],
	unmatched: [ { text: '1 suggestion', reason: 'Removed because the product is not in your catalogue.' } ],
	questions: [ 'Which delivery address?' ],
	message: 'Thanks Anna!',
	dropped: 1,
	meta: { model: 'gemini-2.5-flash', tokens: 200, cached: false },
};

describe( 'helpers', () => {
	it( 'labels statuses, discounts and rule targets', () => {
		expect( statusLabel( 'requested' ) ).toBe( 'New request' );
		expect( discountText( 'percent', 20 ) ).toBe( '20% off' );
		expect( discountText( 'price', 6 ) ).toContain( '6.00' );
		expect( whoLabel( 'all_wholesale' ) ).toBe( 'All wholesale roles' );
		expect( whoLabel( 'distributor', ROLES.roles ) ).toBe( 'Distributor' );
	} );

	it( 'computes editor totals with discount, shipping and exempt tax', () => {
		const t = totals( [ { unit_price: 6, qty: 120 }, { unit_price: '3.2', qty: '30' } ], { discount: 16, shipping: 25, tax: 40, tax_mode: 'exempt' } );
		expect( t.subtotal ).toBeCloseTo( 816 );
		expect( t.tax ).toBe( 0 );
		expect( t.total ).toBeCloseTo( 825 );
	} );
} );

describe( 'quotes list', () => {
	it( 'shows status tabs and opens a quote', async () => {
		route( [ [ /GET quotes\?/, { rows: [ { ...QUOTE, status: 'requested', total: 0 } ], total: 1, counts: { open: 1, requested: 1, draft: 0, sent: 0, accepted: 0, declined: 0, expired: 0, cancelled: 0 } } ] ] );
		const navigate = jest.fn();
		const onCounts = jest.fn();
		const view = await render( wrap( <Quotes navigate={ navigate } onCounts={ onCounts } /> ) );
		await view.flush();
		expect( onCounts ).toHaveBeenCalledWith( 1 );
		expect( view.getByText( 'Q-01007' ) ).toBeTruthy();
		await click( view.getByText( 'Q-01007' ) );
		expect( navigate ).toHaveBeenCalledWith( 'quotes/7' );
		await click( view.getByText( 'Sent' ) );
		await view.flush();
		expect( calls( /status=sent/ ).length ).toBe( 1 );
		view.unmount();
	} );
} );

describe( 'quote editor', () => {
	it( 'drafts lines with DEV3 AI and saves only after accept', async () => {
		route( [
			[ /GET quotes\/7$/, QUOTE ],
			[ /GET roles/, ROLES ],
			[ /POST quotes\/7\/ai-draft/, DRAFT ],
			[ /POST quotes\/7$/, ( opts ) => ( { ...QUOTE, status: 'draft', message: opts.data.message || '', items: opts.data.items.map( ( l, i ) => ( { ...l, id: i + 1, unit_price: Number( l.unit_price ), line_total: Number( l.unit_price ) * l.qty, regular_price: 10 } ) ), total: 816, subtotal: 816 } ) ],
		] );
		const view = await render( wrap( <QuoteEditor id={ 7 } navigate={ jest.fn() } aiReady onUsage={ jest.fn() } /> ) );
		await view.flush();
		expect( view.getByText( 'Quote Q-01007' ) ).toBeTruthy();
		await click( view.getByText( 'Draft with DEV3 AI' ) );
		await view.flush();
		expect( view.getByText( 'Kraft mug' ) ).toBeTruthy();
		expect( view.getByText( 'Check this' ) ).toBeTruthy();
		expect( view.getByText( 'Which delivery address?' ) ).toBeTruthy();
		expect( calls( /POST quotes\/7$/ ).length ).toBe( 0 );
		await click( view.getByText( 'Accept lines' ) );
		await view.flush();
		const saved = calls( /POST quotes\/7$/ );
		expect( saved.length ).toBe( 1 );
		expect( saved[ 0 ].data.ai_accepted ).toBe( true );
		expect( saved[ 0 ].data.items ).toHaveLength( 2 );
		expect( saved[ 0 ].data.message ).toBe( 'Thanks Anna!' );
		expect( view.$$( '.dev3bq-lines__row' ) ).toHaveLength( 2 );
		view.unmount();
	} );

	it( 'hides AI buttons without a key and locks accepted quotes', async () => {
		route( [
			[ /GET quotes\/8$/, { ...QUOTE, id: 8, status: 'accepted', editable: false, order: { id: 50, number: '50', status_label: 'Pending payment', edit_url: '/o', pay_url: '/pay', total: 10 } } ],
			[ /GET roles/, ROLES ],
		] );
		const view = await render( wrap( <QuoteEditor id={ 8 } navigate={ jest.fn() } aiReady={ false } onUsage={ jest.fn() } /> ) );
		await view.flush();
		expect( view.queryByText( 'Draft with DEV3 AI' ) ).toBeNull();
		expect( view.getByText( 'This quote is accepted' ) ).toBeTruthy();
		expect( view.queryByText( 'Send quote' ) ).toBeNull();
		expect( view.$( 'input[readonly]' ).value ).toBe( '/pay' );
		view.unmount();
	} );
} );

describe( 'customers', () => {
	it( 'approves an application with the chosen role', async () => {
		const app = { id: 3, company: 'Berg Café', email: 'lena@berg.test', first_name: 'Lena', last_name: 'Berg', phone: '', tax_id: 'DE1', website: '', country: 'DE', role: 'distributor', role_name: 'Distributor', message: 'Three cafés', status: 'pending', reason: '', ai: null, created_at: new Date().toISOString() };
		route( [
			[ /GET applications\?/, { rows: [ app ], total: 1, counts: { pending: 1, approved: 0, rejected: 0 } } ],
			[ /GET roles/, ROLES ],
			[ /POST applications\/3\/approve/, { ...app, status: 'approved' } ],
		] );
		const onCounts = jest.fn();
		const view = await render( wrap( <Customers aiReady={ false } onUsage={ jest.fn() } onCounts={ onCounts } /> ) );
		await view.flush();
		expect( onCounts ).toHaveBeenCalledWith( 1 );
		await click( view.getByText( 'Berg Café' ) );
		await view.flush();
		await change( view.$( '.dev3bq-ui-drawer select' ), 'wholesale' );
		await click( view.getByText( 'Approve' ) );
		await view.flush();
		const sent = calls( /approve/ );
		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].data.role ).toBe( 'wholesale' );
		view.unmount();
	} );
} );

describe( 'rules and roles', () => {
	it( 'edits quantity breaks', async () => {
		const onChange = jest.fn();
		const view = await render( <BreaksEditor value={ [ { min: 50, type: 'percent', amount: 25 } ] } onChange={ onChange } /> );
		await click( view.getByText( 'Add tier' ) );
		expect( onChange ).toHaveBeenCalledWith( [ { min: 50, type: 'percent', amount: 25 }, { min: 100, type: 'percent', amount: 15 } ] );
		view.unmount();
	} );

	it( 'lists rules and creates one', async () => {
		route( [
			[ /GET rules/, { rules: [ { id: 1, name: 'Distributor', role: 'distributor', scope: 'all', targets: [], type: 'percent', amount: 20, breaks: [ { min: 50, type: 'percent', amount: 25 } ], priority: 10, active: true } ], names: {} } ],
			[ /GET roles/, ROLES ],
			[ /GET categories/, { categories: [ { id: 5, name: 'Mugs', count: 3 } ] } ],
			[ /POST rules$/, { rules: [], names: {} } ],
		] );
		const view = await render( wrap( <Rules /> ) );
		await view.flush();
		expect( view.getByText( '20% off' ) ).toBeTruthy();
		expect( view.getByText( '50+: 25% off' ) ).toBeTruthy();
		await click( view.getByText( 'New rule' ) );
		await view.flush();
		await change( view.$( '.dev3bq-ui-modal input[type="text"]' ), 'Mugs 10%' );
		await click( view.getByText( 'Save rule' ) );
		await view.flush();
		expect( calls( /POST rules$/ )[ 0 ].data.name ).toBe( 'Mugs 10%' );
		view.unmount();
	} );

	it( 'renders category names with "&" once, not as entities', async () => {
		route( [
			[ /GET rules/, { rules: [ { id: 2, name: 'Mug & cup deal', role: 'all_wholesale', scope: 'categories', targets: [ 16 ], type: 'percent', amount: 10, breaks: [], priority: 10, active: true } ], names: { c16: 'Mugs & Drinkware' } } ],
			[ /GET roles/, ROLES ],
			[ /GET categories/, { categories: [ { id: 16, name: 'Mugs & Drinkware', count: 3 } ] } ],
		] );
		const view = await render( wrap( <Rules /> ) );
		await view.flush();
		expect( view.getByText( 'Mugs & Drinkware' ) ).toBeTruthy();
		expect( view.getByText( 'Mug & cup deal' ) ).toBeTruthy();
		expect( view.container.textContent ).not.toMatch( /&amp;/ );
		expect( view.container.innerHTML ).not.toMatch( /&amp;amp;/ );
		await click( view.getByText( 'Mug & cup deal' ) );
		await view.flush();
		expect( view.$( '.dev3bq-ui-modal' ).textContent ).toContain( 'Mugs & Drinkware' );
		expect( view.$( '.dev3bq-ui-modal' ).textContent ).not.toMatch( /&amp;/ );
		view.unmount();
	} );

	it( 'shows role cards with payment restrictions', async () => {
		route( [ [ /GET roles/, ROLES ] ] );
		const view = await render( wrap( <Roles /> ) );
		await view.flush();
		expect( view.getByText( 'Distributor' ) ).toBeTruthy();
		expect( view.getByText( 'Bank transfer' ) ).toBeTruthy();
		expect( view.getByText( 'Tax exempt' ) ).toBeTruthy();
		view.unmount();
	} );
} );

describe( 'dashboard', () => {
	it( 'shows KPIs and the setup checklist', async () => {
		route( [
			[
				/GET dashboard/,
				{ quotes: { open: 3, open_value: 1200, new: 2, requests: 4, accepted: 1, won_value: 500, accept_rate: 50 }, applications: 1, customers: 4, revenue: { value: 900, orders: 3 }, recent: [], counts: {}, setup: { roles: 2, rules: 0, pages: { quote_page_id: 0, quick_order_page_id: 9, registration_page_id: 0 }, ai: { key_set: false } } },
			],
		] );
		const onCounts = jest.fn();
		const view = await render( wrap( <Dashboard navigate={ jest.fn() } onCounts={ onCounts } aiReady={ false } /> ) );
		await view.flush();
		expect( onCounts ).toHaveBeenCalledWith( { quotes: 2, applications: 1 } );
		expect( view.getByText( '50%' ) ).toBeTruthy();
		expect( view.getByText( 'Connect DEV3 AI' ) ).toBeTruthy();
		expect( view.$$( '.dev3bq-step.is-done' ) ).toHaveLength( 2 );
		view.unmount();
	} );
} );
