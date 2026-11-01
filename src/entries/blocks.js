import { registerBlockType } from '@wordpress/blocks';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, RangeControl, TextControl } from '@wordpress/components';

const placeholder = ( title, text ) =>
	function Edit() {
		const props = useBlockProps( { className: 'dev3bq-block-placeholder' } );
		return createElement( 'div', { ...props, style: { border: '1px dashed #c3c4c7', borderRadius: 8, padding: 24, textAlign: 'center' } }, createElement( 'strong', null, title ), createElement( 'p', { style: { margin: '8px 0 0' } }, text ) );
	};

registerBlockType( 'dev3bq/request-quote', {
	apiVersion: 2,
	title: __( 'Request a quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
	description: __( 'Quote list, contact form and free-text request. Also shows a quote when opened from the email link.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
	category: 'woocommerce',
	icon: 'clipboard',
	supports: { html: false, align: [ 'wide', 'full' ] },
	edit: placeholder( __( 'Request a quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ), __( 'Shoppers review their quote list and send a request. Preview the page to see it.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ),
	save: () => null,
} );

registerBlockType( 'dev3bq/registration', {
	apiVersion: 2,
	title: __( 'Wholesale registration', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
	description: __( 'Application form for wholesale accounts.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
	category: 'woocommerce',
	icon: 'id',
	supports: { html: false, align: [ 'wide', 'full' ] },
	edit: placeholder( __( 'Wholesale registration', 'dev3-ai-b2b-wholesale-for-woocommerce' ), __( 'Businesses apply here; you approve them in the B2B desk.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ),
	save: () => null,
} );

registerBlockType( 'dev3bq/quick-order', {
	apiVersion: 2,
	title: __( 'Quick order table', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
	description: __( 'Searchable product table for fast bulk ordering with each customer’s own prices.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
	category: 'woocommerce',
	icon: 'list-view',
	attributes: { category: { type: 'string', default: '' }, perPage: { type: 'number', default: 20 } },
	supports: { html: false, align: [ 'wide', 'full' ] },
	edit: function Edit( { attributes, setAttributes } ) {
		const props = useBlockProps( { className: 'dev3bq-block-placeholder' } );
		return createElement(
			'div',
			{ ...props, style: { border: '1px dashed #c3c4c7', borderRadius: 8, padding: 24, textAlign: 'center' } },
			createElement(
				InspectorControls,
				null,
				createElement(
					PanelBody,
					{ title: __( 'Table', 'dev3-ai-b2b-wholesale-for-woocommerce' ) },
					createElement( TextControl, { label: __( 'Category slug (optional)', 'dev3-ai-b2b-wholesale-for-woocommerce' ), value: attributes.category, onChange: ( v ) => setAttributes( { category: v } ) } ),
					createElement( RangeControl, { label: __( 'Products per page', 'dev3-ai-b2b-wholesale-for-woocommerce' ), min: 5, max: 50, value: attributes.perPage, onChange: ( v ) => setAttributes( { perPage: v } ) } )
				)
			),
			createElement( 'strong', null, __( 'Quick order table', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ),
			createElement( 'p', { style: { margin: '8px 0 0' } }, __( 'Customers search, set quantities and add many products at once. Preview the page to see it.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) )
		);
	},
	save: () => null,
} );
