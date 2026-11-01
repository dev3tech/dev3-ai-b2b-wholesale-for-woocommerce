import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { AiButton, AiDraftReview, Badge, Button, Card, ErrorNotice, FormGrid, IconButton, Inline, Modal, Notice, PageHeader, Select, SkeletonCard, TextField, Textarea, useToast } from '../core3/ui';
import { ProductPicker, StatusBadge, ago, money, shortDate, sourceLabel } from './util';

const CONF_TONE = { high: 'success', medium: 'warning', low: 'danger' };
const confLabel = ( c ) => ( { high: __( 'Sure match', 'dev3-ai-b2b-wholesale-for-woocommerce' ), medium: __( 'Likely match', 'dev3-ai-b2b-wholesale-for-woocommerce' ), low: __( 'Check this', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } )[ c ] || c;

let lineSeq = 0;
const withKey = ( l ) => ( { ...l, key: l.key || `l${ ++lineSeq }` } );

/**
 * Totals from the editor state (tax comes from the last save).
 *
 * @param {Array}  lines Lines.
 * @param {Object} q     Quote fields.
 * @return {Object} Totals.
 */
export function totals( lines, q ) {
	const subtotal = lines.reduce( ( s, l ) => s + ( Number( l.unit_price ) || 0 ) * ( Number( l.qty ) || 0 ), 0 );
	const discount = Math.min( subtotal, Math.max( 0, Number( q.discount ) || 0 ) );
	const shipping = Math.max( 0, Number( q.shipping ) || 0 );
	const tax = q.tax_mode === 'exempt' ? 0 : Number( q.tax ) || 0;
	return { subtotal, discount, shipping, tax, total: subtotal - discount + shipping + tax };
}

/**
 * Review panel for an AI-drafted set of lines.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.draft     Draft.
 * @param {string}   props.currency  Currency.
 * @param {boolean}  props.hasLines  Quote already has lines.
 * @param {Function} props.onAccept  (mode: replace|append).
 * @param {Function} props.onReject  Reject.
 * @param {Function} props.onRegenerate Regenerate.
 * @param {boolean}  props.busy      Busy.
 * @return {Element} Panel.
 */
export function AiQuoteDraft( { draft, currency, hasLines, onAccept, onReject, onRegenerate, busy } ) {
	const sum = draft.lines.reduce( ( s, l ) => s + l.line_total, 0 );
	return (
		<div className="dev3bq-aidraft" role="region" aria-label={ __( 'DEV3 AI draft quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
			<div className="dev3bq-aidraft__head">
				<strong>{ __( 'DEV3 AI draft', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</strong>
				<Badge tone="brand">{ __( 'Not saved yet', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</Badge>
				{ draft.meta && draft.meta.model && <span className="dev3bq-muted">{ `${ draft.meta.model } · ${ draft.meta.tokens } tokens${ draft.meta.cached ? ' · cached' : '' }` }</span> }
			</div>
			{ draft.lines.length > 0 ? (
				<ul className="dev3bq-aidraft__lines">
					{ draft.lines.map( ( l ) => (
						<li key={ `${ l.product_id }:${ l.variation_id }` }>
							<span className="dev3bq-aidraft__name">
								<strong>{ l.name }</strong>
								<span className="dev3bq-muted">{ [ l.sku, l.note ].filter( Boolean ).join( ' · ' ) }</span>
							</span>
							<Badge tone={ CONF_TONE[ l.confidence ] }>{ confLabel( l.confidence ) }</Badge>
							<span className="dev3bq-aidraft__qty">{ `× ${ l.qty }` }</span>
							<span className="dev3bq-aidraft__price">
								{ money( l.line_total, currency ) }
								<span className="dev3bq-muted">{ sprintf( /* translators: %s: unit price */ __( '%s each', 'dev3-ai-b2b-wholesale-for-woocommerce' ), money( l.unit_price, currency ) ) }</span>
							</span>
						</li>
					) ) }
					<li className="dev3bq-aidraft__sum">
						<span>{ __( 'Lines total (prices from your rules)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</span>
						<strong>{ money( sum, currency ) }</strong>
					</li>
				</ul>
			) : (
				<Notice tone="warning">{ __( 'DEV3 AI could not match any catalogue products to this request.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</Notice>
			) }
			{ draft.unmatched.length > 0 && (
				<div className="dev3bq-aidraft__block">
					<strong>{ __( 'Not matched', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</strong>
					<ul>
						{ draft.unmatched.map( ( u, i ) => (
							<li key={ i }>{ `${ u.text }: ${ u.reason }` }</li>
						) ) }
					</ul>
				</div>
			) }
			{ draft.questions.length > 0 && (
				<div className="dev3bq-aidraft__block">
					<strong>{ __( 'Questions to ask the customer', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</strong>
					<ul>
						{ draft.questions.map( ( q, i ) => (
							<li key={ i }>{ q }</li>
						) ) }
					</ul>
				</div>
			) }
			{ draft.message && (
				<div className="dev3bq-aidraft__block">
					<strong>{ __( 'Suggested note to the customer', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</strong>
					<p className="dev3bq-pre">{ draft.message }</p>
				</div>
			) }
			<Inline gap={ 8 } className="dev3bq-aidraft__actions">
				<Button variant="primary" icon="check" onClick={ () => onAccept( 'replace' ) } disabled={ ! draft.lines.length } loading={ busy }>
					{ hasLines ? __( 'Replace lines', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Accept lines', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				</Button>
				{ hasLines && (
					<Button onClick={ () => onAccept( 'append' ) } disabled={ ! draft.lines.length || busy }>
						{ __( 'Add to current lines', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
					</Button>
				) }
				<Button variant="ghost" icon="refresh" onClick={ onRegenerate } disabled={ busy }>
					{ __( 'Regenerate', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				</Button>
				<Button variant="ghost" icon="x" onClick={ onReject } disabled={ busy }>
					{ __( 'Reject', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				</Button>
			</Inline>
		</div>
	);
}

/**
 * Quote editor.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.id       Quote id.
 * @param {Function} props.navigate Router.
 * @param {boolean}  props.aiReady  AI connected.
 * @param {Function} props.onUsage  Usage refresh.
 * @return {Element} Screen.
 */
export default function QuoteEditor( { id, navigate, aiReady, onUsage } ) {
	const toast = useToast();
	const [ quote, setQuote ] = useState( null );
	const [ form, setForm ] = useState( null );
	const [ lines, setLines ] = useState( [] );
	const [ dirty, setDirty ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ roles, setRoles ] = useState( [] );
	const [ draftText, setDraftText ] = useState( '' );
	const [ draft, setDraft ] = useState( null );
	const [ aiError, setAiError ] = useState( null );
	const [ emailKind, setEmailKind ] = useState( 'cover' );
	const [ points, setPoints ] = useState( '' );
	const [ email, setEmail ] = useState( null );
	const [ emailError, setEmailError ] = useState( null );
	const [ reply, setReply ] = useState( '' );
	const [ confirm, setConfirm ] = useState( null );

	const apply = ( q ) => {
		setQuote( q );
		setForm( { name: q.name, company: q.company, email: q.email, phone: q.phone, role: q.role, request: q.request, message: q.message, admin_note: q.admin_note, shipping: q.shipping, discount: q.discount, tax_mode: q.tax_mode, tax: q.tax, valid_until: q.valid_until, terms: q.terms } );
		setLines( q.items.map( ( l ) => withKey( { ...l, auto: false } ) ) );
		setDraftText( ( t ) => t || q.request );
		setDirty( false );
	};
	const load = () => {
		setError( null );
		request( `quotes/${ id }` ).then( apply ).catch( setError );
	};
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( load, [ id ] );
	useEffect( () => {
		request( 'roles' )
			.then( ( d ) => setRoles( d.roles ) )
			.catch( () => {} );
	}, [] );

	const t = useMemo( () => ( form ? totals( lines, form ) : null ), [ lines, form ] );

	if ( error ) {
		return (
			<>
				<PageHeader title={ __( 'Quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } back={ { href: '#/quotes', label: __( 'Quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } } />
				<ErrorNotice error={ error } onRetry={ load } />
			</>
		);
	}
	if ( ! quote || ! form ) {
		return <SkeletonCard lines={ 8 } />;
	}

	const cur = quote.currency || config.currency;
	const editable = quote.editable;
	const set = ( k ) => ( v ) => {
		setForm( { ...form, [ k ]: v } );
		setDirty( true );
	};
	const setLine = ( key, patch ) => {
		setLines( lines.map( ( l ) => ( l.key === key ? { ...l, ...patch } : l ) ) );
		setDirty( true );
	};
	const addProduct = ( p ) => {
		const existing = lines.find( ( l ) => l.product_id === p.product_id && l.variation_id === p.variation_id && p.product_id );
		if ( existing ) {
			setLine( existing.key, { qty: Number( existing.qty ) + 1 } );
			return;
		}
		setLines( [ ...lines, withKey( { product_id: p.product_id, variation_id: p.variation_id, name: p.name, sku: p.sku, qty: 1, unit_price: p.price, regular_price: p.regular, note: '', auto: true } ) ] );
		setDirty( true );
	};
	const addCustom = () => {
		setLines( [ ...lines, withKey( { product_id: 0, variation_id: 0, name: '', sku: '', qty: 1, unit_price: 0, regular_price: 0, note: '', auto: false, custom: true } ) ] );
		setDirty( true );
	};
	const payloadLines = ( list ) =>
		list.map( ( l ) => ( {
			product_id: l.product_id,
			variation_id: l.variation_id,
			name: l.name,
			sku: l.sku,
			qty: Number( l.qty ) || 1,
			unit_price: l.auto ? '' : Number( l.unit_price ) || 0,
			note: l.note || '',
		} ) );

	const save = ( extra = {}, list = lines ) => {
		setBusy( 'save' );
		return request( `quotes/${ id }`, { method: 'POST', data: { ...form, items: payloadLines( list ), ...extra } } )
			.then( ( q ) => {
				apply( q );
				return q;
			} )
			.finally( () => setBusy( '' ) );
	};
	const saveClick = () =>
		save()
			.then( () => toast.success( __( 'Quote saved.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) )
			.catch( ( err ) => toast.error( err ) );

	const act = ( path, data = {}, ok ) => {
		setBusy( path );
		const run = () =>
			request( `quotes/${ id }/${ path }`, { method: 'POST', data } )
				.then( ( q ) => {
					if ( path === 'duplicate' ) {
						toast.success( ok );
						navigate( `quotes/${ q.id }` );
						return;
					}
					apply( q );
					if ( ok ) {
						toast.success( ok );
					}
				} )
				.catch( ( err ) => toast.error( err ) )
				.finally( () => setBusy( '' ) );
		// Save pending edits first so the action uses what is on screen.
		return dirty && editable && path !== 'duplicate' && path !== 'status' ? save().then( run, ( err ) => {
			toast.error( err );
			setBusy( '' );
		} ) : run();
	};

	const generate = () => {
		setBusy( 'ai' );
		setAiError( null );
		request( `quotes/${ id }/ai-draft`, { method: 'POST', data: { text: draftText } } )
			.then( setDraft )
			.catch( setAiError )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};
	const acceptDraft = ( mode ) => {
		const next = draft.lines.map( ( l ) => withKey( { ...l, auto: false } ) );
		let list = next;
		if ( mode === 'append' ) {
			list = [ ...lines ];
			next.forEach( ( n ) => {
				const hit = list.findIndex( ( l ) => l.product_id === n.product_id && l.variation_id === n.variation_id );
				if ( hit >= 0 ) {
					list[ hit ] = { ...list[ hit ], qty: Number( list[ hit ].qty ) + n.qty, auto: true };
				} else {
					list.push( n );
				}
			} );
		}
		const extra = { ai_accepted: true };
		if ( draft.message && ! form.message ) {
			extra.message = draft.message;
		}
		save( extra, list )
			.then( () => {
				setDraft( null );
				toast.success( __( 'DEV3 AI lines added to the quote.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) );
	};

	const writeEmail = () => {
		setBusy( 'email' );
		setEmailError( null );
		const go = () =>
			request( `quotes/${ id }/ai-email`, { method: 'POST', data: { kind: emailKind, points } } )
				.then( setEmail )
				.catch( setEmailError )
				.finally( () => {
					setBusy( '' );
					onUsage();
				} );
		return dirty && editable ? save().then( go, ( err ) => {
			setEmailError( err );
			setBusy( '' );
		} ) : go();
	};
	const acceptEmail = ( v ) => {
		if ( emailKind === 'cover' || emailKind === 'decline' ) {
			setForm( { ...form, message: v.body } );
			setDirty( true );
			toast.info( __( 'Draft placed in the message to the customer. Press “Send quote” when ready.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
		} else {
			setReply( v.body );
			toast.info( __( 'Draft placed in the reply box. Press “Send reply” when ready.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
		}
		setEmail( null );
	};

	const statusActions = [];
	if ( [ 'requested', 'draft', 'sent' ].includes( quote.status ) ) {
		statusActions.push( { id: 'order', label: __( 'Convert to order', 'dev3-ai-b2b-wholesale-for-woocommerce' ), icon: 'check' } );
		statusActions.push( { id: 'declined', label: __( 'Mark declined', 'dev3-ai-b2b-wholesale-for-woocommerce' ), icon: 'x' } );
	}
	if ( [ 'expired', 'declined', 'sent' ].includes( quote.status ) ) {
		statusActions.push( { id: 'draft', label: __( 'Reopen as draft', 'dev3-ai-b2b-wholesale-for-woocommerce' ), icon: 'refresh' } );
	}
	if ( ! [ 'accepted', 'cancelled' ].includes( quote.status ) ) {
		statusActions.push( { id: 'cancelled', label: __( 'Cancel quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ), icon: 'trash' } );
	}
	const runStatus = ( a ) => {
		setConfirm( null );
		if ( a === 'order' ) {
			act( 'order', {}, __( 'Order created from the quote.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
		} else {
			act( 'status', { status: a }, __( 'Quote updated.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
		}
	};

	const roleOptions = [ { value: '', label: __( 'Retail prices', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }, ...roles.map( ( r ) => ( { value: r.key, label: r.name } ) ) ];
	const kindOptions = [
		{ value: 'cover', label: __( 'Cover email for the quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
		{ value: 'followup', label: __( 'Follow-up reminder', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
		{ value: 'reply', label: __( 'Reply to the customer', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
		{ value: 'decline', label: __( 'Cannot supply / alternatives', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
		{ value: 'expired', label: __( 'Quote expired', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
	];
	const customerEvents = quote.events;

	return (
		<>
			<PageHeader
				eyebrow={ sourceLabel( quote.source ) }
				title={ sprintf( /* translators: %s: quote number */ __( 'Quote %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), quote.number ) }
				back={ { href: '#/quotes', label: __( 'Quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } }
				actions={
					<Inline gap={ 8 }>
						<StatusBadge status={ quote.status } />
						<Button icon="file" href={ quote.pdf_url } target="_blank" rel="noopener noreferrer">
							{ __( 'PDF', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						</Button>
						<Button icon="copy" variant="ghost" onClick={ () => act( 'duplicate', {}, __( 'Copy created as a new draft.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) } loading={ busy === 'duplicate' }>
							{ __( 'Duplicate', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						</Button>
					</Inline>
				}
			/>
			{ ! editable && (
				<Notice tone="info" title={ quote.status === 'accepted' ? __( 'This quote is accepted', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'This quote is closed', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
					{ quote.order
						? sprintf( /* translators: 1: order number, 2: status */ __( 'It became order #%1$s (%2$s). Duplicate it to make changes.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), quote.order.number, quote.order.status_label )
						: __( 'Duplicate it to make a new version.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
				</Notice>
			) }
			<div className="dev3bq-editor">
				<div className="dev3bq-editor__main">
					{ ( quote.request || editable ) && (
						<Card tone="ai" title={ __( 'Customer request', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } description={ __( 'What the customer asked for, in their words. DEV3 AI matches it to real products and prices it with your rules.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
							{ editable ? (
								<Textarea label={ __( 'Request text', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } hideLabel value={ draftText } onChange={ setDraftText } rows={ 4 } maxLength={ 4000 } placeholder={ __( 'Paste the customer’s email or list here…', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
							) : (
								<p className="dev3bq-pre">{ quote.request || '—' }</p>
							) }
							{ editable &&
								( aiReady ? (
									<Inline gap={ 8 }>
										<AiButton onClick={ generate } loading={ busy === 'ai' } disabled={ ! draftText.trim() } loadingText={ __( 'Matching products…', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
											{ __( 'Draft with DEV3 AI', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
										</AiButton>
										<span className="dev3bq-muted">{ __( 'Prices always come from your pricing rules, never from AI.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</span>
									</Inline>
								) : (
									<p className="dev3bq-muted">
										{ __( 'Connect DEV3 AI to draft lines from this text automatically.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }{ ' ' }
										<a href="#/api">{ __( 'Add your API key', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</a>
									</p>
								) ) }
							<ErrorNotice error={ aiError } onRetry={ generate } onDismiss={ () => setAiError( null ) } />
							{ draft && <AiQuoteDraft draft={ draft } currency={ cur } hasLines={ lines.length > 0 } busy={ busy === 'save' } onAccept={ acceptDraft } onReject={ () => setDraft( null ) } onRegenerate={ generate } /> }
						</Card>
					) }

					<Card
						title={ __( 'Lines', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						description={ __( 'New lines are priced by your rules for this customer’s role and quantity. Type a price to override it.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						actions={
							editable &&
							lines.some( ( l ) => l.product_id ) && (
								<Button size="sm" variant="ghost" icon="refresh" onClick={ () => act( 'reprice', {}, __( 'Prices updated from the rules.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) } loading={ busy === 'reprice' }>
									{ __( 'Re-price from rules', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
								</Button>
							)
						}
					>
						{ lines.length === 0 && <p className="dev3bq-muted">{ __( 'No lines yet. Search for a product below or let DEV3 AI draft them.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</p> }
						{ lines.length > 0 && (
							<div className="dev3bq-lines" role="table" aria-label={ __( 'Quote lines', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
								<div className="dev3bq-lines__head" role="row">
									<span role="columnheader">{ __( 'Product', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</span>
									<span role="columnheader">{ __( 'Qty', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</span>
									<span role="columnheader">{ __( 'Unit price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</span>
									<span role="columnheader">{ __( 'Total', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</span>
									<span role="columnheader" className="dev3bq-ui-sr-only">
										{ __( 'Remove', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
									</span>
								</div>
								{ lines.map( ( l ) => (
									<div className="dev3bq-lines__row" role="row" key={ l.key }>
										<div className="dev3bq-lines__product" role="cell">
											{ l.custom || ! l.product_id ? (
												<TextField label={ __( 'Item name', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } hideLabel value={ l.name } onChange={ ( v ) => setLine( l.key, { name: v } ) } placeholder={ __( 'Custom item', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } disabled={ ! editable } />
											) : (
												<span className="dev3bq-cell">
													<strong>{ l.name }</strong>
													<span className="dev3bq-muted">
														{ [ l.sku, l.regular_price > l.unit_price ? sprintf( /* translators: %s: list price */ __( 'List %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), money( l.regular_price, cur ) ) : '' ].filter( Boolean ).join( ' · ' ) }
													</span>
												</span>
											) }
											<TextField label={ __( 'Line note', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } hideLabel value={ l.note } onChange={ ( v ) => setLine( l.key, { note: v } ) } placeholder={ __( 'Note (optional)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } disabled={ ! editable } className="dev3bq-lines__note" />
										</div>
										<div role="cell" className="dev3bq-lines__qty">
											<TextField label={ __( 'Quantity', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } hideLabel type="number" min="1" step="1" inputMode="numeric" value={ String( l.qty ) } onChange={ ( v ) => setLine( l.key, { qty: v } ) } disabled={ ! editable } />
										</div>
										<div role="cell" className="dev3bq-lines__price">
											<TextField
												label={ __( 'Unit price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
												hideLabel
												type="number"
												min="0"
												step="0.01"
												inputMode="decimal"
												prefix={ config.currencySymbol || '$' }
												value={ l.auto ? '' : String( l.unit_price ) }
												placeholder={ l.auto ? __( 'Rule price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : '' }
												onChange={ ( v ) => setLine( l.key, { unit_price: v, auto: v === '' } ) }
												disabled={ ! editable }
											/>
										</div>
										<div role="cell" className="dev3bq-lines__total">{ l.auto ? <span className="dev3bq-muted">{ __( 'On save', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</span> : money( ( Number( l.unit_price ) || 0 ) * ( Number( l.qty ) || 0 ), cur ) }</div>
										<div role="cell" className="dev3bq-lines__remove">
											{ editable && (
												<IconButton
													icon="trash"
													label={ sprintf( /* translators: %s: item */ __( 'Remove %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), l.name || __( 'line', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) }
													onClick={ () => {
														setLines( lines.filter( ( x ) => x.key !== l.key ) );
														setDirty( true );
													} }
												/>
											) }
										</div>
									</div>
								) ) }
							</div>
						) }
						{ editable && (
							<div className="dev3bq-lines__add">
								<ProductPicker label={ __( 'Add a product', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } role={ form.role } onPick={ addProduct } />
								<Button variant="ghost" icon="plus" onClick={ addCustom }>
									{ __( 'Custom line', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
								</Button>
							</div>
						) }
					</Card>

					<Card title={ __( 'Totals, validity and terms', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
						<FormGrid>
							<TextField label={ __( 'Shipping', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } type="number" min="0" step="0.01" prefix={ config.currencySymbol || '$' } value={ String( form.shipping ) } onChange={ set( 'shipping' ) } disabled={ ! editable } />
							<TextField label={ __( 'Extra discount', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } type="number" min="0" step="0.01" prefix={ config.currencySymbol || '$' } value={ String( form.discount ) } onChange={ set( 'discount' ) } disabled={ ! editable } />
							<Select
								label={ __( 'Tax', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
								value={ form.tax_mode }
								onChange={ set( 'tax_mode' ) }
								options={ [
									{ value: 'store', label: __( 'Store tax rules', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
									{ value: 'exempt', label: __( 'Tax exempt', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
								] }
								disabled={ ! editable }
							/>
							<TextField label={ __( 'Valid until', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } type="date" value={ form.valid_until || '' } onChange={ set( 'valid_until' ) } disabled={ ! editable } />
						</FormGrid>
						<Textarea label={ __( 'Terms', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.terms } onChange={ set( 'terms' ) } rows={ 3 } maxLength={ 1000 } placeholder={ quote.terms_hint } help={ __( 'Leave empty to use the default terms from Settings.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } disabled={ ! editable } />
						<dl className="dev3bq-totals">
							<div>
								<dt>{ __( 'Subtotal', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</dt>
								<dd>{ money( t.subtotal, cur ) }</dd>
							</div>
							{ t.discount > 0 && (
								<div>
									<dt>{ __( 'Discount', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</dt>
									<dd>{ `−${ money( t.discount, cur ) }` }</dd>
								</div>
							) }
							{ t.shipping > 0 && (
								<div>
									<dt>{ __( 'Shipping', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</dt>
									<dd>{ money( t.shipping, cur ) }</dd>
								</div>
							) }
							<div>
								<dt>{ form.tax_mode === 'exempt' ? __( 'Tax (exempt)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Estimated tax', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</dt>
								<dd>{ money( t.tax, cur ) }</dd>
							</div>
							<div className="is-total">
								<dt>{ __( 'Total', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</dt>
								<dd>{ money( t.total, cur ) }</dd>
							</div>
						</dl>
						{ dirty && <p className="dev3bq-muted">{ __( 'Totals update fully (rule prices and tax) when you save.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</p> }
					</Card>

					<Card
						title={ __( 'Message to the customer', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						description={ __( 'Printed on the PDF and sent with the quote email.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						footer={
							editable && (
								<>
									<Button variant="secondary" onClick={ saveClick } loading={ busy === 'save' } disabled={ ! dirty }>
										{ __( 'Save', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
									</Button>
									<Button variant="primary" icon="external" onClick={ () => act( 'send', { message: form.message }, __( 'Quote sent to the customer.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) } loading={ busy === 'send' } disabled={ lines.length === 0 }>
										{ quote.status === 'sent' ? __( 'Send again', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Send quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
									</Button>
								</>
							)
						}
					>
						<Textarea label={ __( 'Message', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } hideLabel value={ form.message } onChange={ set( 'message' ) } rows={ 5 } maxLength={ 4000 } placeholder={ __( 'Hi Anna, thanks for your request. Here is our offer…', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } disabled={ ! editable } />
						{ aiReady && (
							<div className="dev3bq-writer">
								<FormGrid>
									<Select label={ __( 'Email to write', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ emailKind } onChange={ setEmailKind } options={ kindOptions } />
									<TextField label={ __( 'Points to mention (optional)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ points } onChange={ setPoints } placeholder={ __( 'e.g. delivery in 5 days, free samples', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } />
								</FormGrid>
								<AiButton onClick={ writeEmail } loading={ busy === 'email' } loadingText={ __( 'Writing…', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
									{ __( 'Write with DEV3 AI', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
								</AiButton>
								<ErrorNotice error={ emailError } onRetry={ writeEmail } onDismiss={ () => setEmailError( null ) } />
								{ email && (
									<AiDraftReview
										title={ __( 'DEV3 AI · email draft', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
										fields={ [
											{ key: 'subject', label: __( 'Subject', 'dev3-ai-b2b-wholesale-for-woocommerce' ), maxLength: 140 },
											{ key: 'body', label: __( 'Body', 'dev3-ai-b2b-wholesale-for-woocommerce' ), multiline: true, maxLength: 2000 },
										] }
										original={ { subject: '', body: emailKind === 'cover' ? form.message : '' } }
										draft={ { subject: email.subject, body: email.body } }
										meta={ email.meta }
										acceptLabel={ __( 'Use this text', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
										onAccept={ acceptEmail }
										onReject={ () => setEmail( null ) }
										onRegenerate={ writeEmail }
										regenerating={ busy === 'email' }
									/>
								) }
							</div>
						) }
					</Card>
				</div>

				<aside className="dev3bq-editor__side">
					<Card title={ __( 'Customer', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
						{ quote.user ? (
							<p className="dev3bq-cell">
								<a href={ quote.user.edit_url }>{ quote.user.name }</a>
								<span className="dev3bq-muted">{ __( 'Registered customer', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</span>
							</p>
						) : (
							<p className="dev3bq-muted">{ __( 'Guest. The customer uses the private link in the email.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }</p>
						) }
						<TextField label={ __( 'Name', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.name } onChange={ set( 'name' ) } disabled={ ! editable } />
						<TextField label={ __( 'Company', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.company } onChange={ set( 'company' ) } disabled={ ! editable } />
						<TextField label={ __( 'Email', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } type="email" value={ form.email } onChange={ set( 'email' ) } disabled={ ! editable } />
						<TextField label={ __( 'Phone', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.phone } onChange={ set( 'phone' ) } disabled={ ! editable } />
						<Select label={ __( 'Price list', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.role } onChange={ set( 'role' ) } options={ roleOptions } help={ __( 'New and re-priced lines use this role’s prices.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } disabled={ ! editable } />
						<Button size="sm" variant="link" href={ quote.customer_url } target="_blank" rel="noopener noreferrer" iconRight="external">
							{ __( 'Open customer view', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						</Button>
					</Card>

					{ quote.order && (
						<Card title={ __( 'Order', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
							<p>
								<a href={ quote.order.edit_url }>{ `#${ quote.order.number }` }</a> · { quote.order.status_label } · { money( quote.order.total, cur ) }
							</p>
							{ quote.order.pay_url && (
								<TextField label={ __( 'Payment link for the customer', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ quote.order.pay_url } readOnly onFocus={ ( e ) => e.target.select() } />
							) }
						</Card>
					) }

					{ statusActions.length > 0 && (
						<Card title={ __( 'Actions', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
							<div className="dev3bq-actions">
								{ statusActions.map( ( a ) => (
									<Button key={ a.id } block variant={ a.id === 'order' ? 'secondary' : 'ghost' } icon={ a.icon } onClick={ () => setConfirm( a ) } loading={ busy === a.id || ( a.id === 'order' && busy === 'order' ) }>
										{ a.label }
									</Button>
								) ) }
							</div>
						</Card>
					) }

					<Card title={ __( 'Timeline', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
						<ol className="dev3bq-timeline">
							{ customerEvents.map( ( e ) => (
								<li key={ e.id } className={ `is-${ e.type } is-${ e.actor }` }>
									<span className="dev3bq-timeline__meta">
										{ e.actor === 'customer' ? __( 'Customer', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : e.actor === 'system' ? __( 'System', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : e.user || __( 'Store', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } · { ago( e.created_at ) }
									</span>
									<span className={ e.type === 'message' ? 'dev3bq-timeline__msg' : '' }>{ e.message }</span>
								</li>
							) ) }
						</ol>
						{ [ 'requested', 'draft', 'sent' ].includes( quote.status ) && (
							<>
								<Textarea label={ __( 'Reply to the customer', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ reply } onChange={ setReply } rows={ 3 } maxLength={ 4000 } />
								<Button
									size="sm"
									onClick={ () =>
										act( 'message', { message: reply }, __( 'Reply sent.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ).then( () => setReply( '' ) )
									}
									disabled={ ! reply.trim() }
									loading={ busy === 'message' }
								>
									{ __( 'Send reply', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
								</Button>
							</>
						) }
					</Card>

					<Card title={ __( 'Internal note', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
						<Textarea label={ __( 'Only your team sees this', 'dev3-ai-b2b-wholesale-for-woocommerce' ) } value={ form.admin_note } onChange={ set( 'admin_note' ) } rows={ 3 } maxLength={ 4000 } disabled={ ! editable } />
						<p className="dev3bq-muted">
							{ sprintf( /* translators: 1: created date, 2: valid until */ __( 'Created %1$s · valid until %2$s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), shortDate( quote.created_at ), shortDate( quote.valid_until ) || '—' ) }
						</p>
					</Card>
				</aside>
			</div>

			{ dirty && editable && (
				<div className="dev3bq-savebar" role="region" aria-label={ __( 'Unsaved changes', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }>
					<span>{ sprintf( /* translators: %d: lines */ _n( 'Unsaved changes · %d line', 'Unsaved changes · %d lines', lines.length, 'dev3-ai-b2b-wholesale-for-woocommerce' ), lines.length ) }</span>
					<Inline gap={ 8 }>
						<Button variant="ghost" onClick={ load }>
							{ __( 'Discard', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						</Button>
						<Button variant="primary" onClick={ saveClick } loading={ busy === 'save' }>
							{ __( 'Save', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						</Button>
					</Inline>
				</div>
			) }

			<Modal
				open={ !! confirm }
				onClose={ () => setConfirm( null ) }
				size="sm"
				title={ confirm ? confirm.label : '' }
				description={
					confirm && confirm.id === 'order'
						? __( 'Creates a pending WooCommerce order with exactly these prices and marks the quote as accepted.', 'dev3-ai-b2b-wholesale-for-woocommerce' )
						: __( 'The customer will no longer be able to accept this quote.', 'dev3-ai-b2b-wholesale-for-woocommerce' )
				}
				footer={
					<>
						<Button variant="secondary" onClick={ () => setConfirm( null ) }>
							{ __( 'Keep it', 'dev3-ai-b2b-wholesale-for-woocommerce' ) }
						</Button>
						<Button variant={ confirm && confirm.id === 'order' ? 'primary' : 'danger' } onClick={ () => runStatus( confirm.id ) }>
							{ confirm ? confirm.label : '' }
						</Button>
					</>
				}
			/>
		</>
	);
}
