/* global wp */
( function ( blocks, element, blockEditor, serverSideRender, components, i18n ) {
	'use strict';
	var el = element.createElement;
	var __ = i18n.__;
	blocks.registerBlockType( 'brand-fleet/brand-footer', {
		edit: function ( props ) {
			return el( element.Fragment, null,
				el( blockEditor.InspectorControls, null,
					el( components.PanelBody, { title: __( 'Footer settings', 'brand-fleet' ) },
						el( components.SelectControl, {
							label: __( 'Layout', 'brand-fleet' ), value: props.attributes.variant,
							options: [ { label: __( 'Default', 'brand-fleet' ), value: 'default' }, { label: __( 'Corporate', 'brand-fleet' ), value: 'corporate' } ],
							onChange: function ( value ) { props.setAttributes( { variant: value } ); },
						} ),
						props.attributes.variant === 'corporate' && el( components.TextareaControl, {
							label: __( 'Optional demo disclosure', 'brand-fleet' ), value: props.attributes.demoNotice,
							onChange: function ( value ) { props.setAttributes( { demoNotice: value } ); },
						} )
					)
				),
				el( 'div', blockEditor.useBlockProps(), el( serverSideRender, { block: 'brand-fleet/brand-footer', attributes: props.attributes } ) )
			);
		},
		save: function () { return null; },
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.serverSideRender, wp.components, wp.i18n );
