/* global wp, brandFleetBlocks */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';
	var el = element.createElement;
	var __ = i18n.__;
	var choices = ( window.brandFleetBlocks && brandFleetBlocks.list ) || [];

	blocks.registerBlockType( 'brand-fleet/list', {
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				blockEditor.useBlockProps(),
				el(
					blockEditor.InspectorControls,
					{},
					el(
						components.PanelBody,
						{ title: __( 'List', 'brand-fleet' ) },
						el( components.SelectControl, {
							label: __( 'Variable', 'brand-fleet' ),
							value: a.variable,
							options: [ { label: __( 'First List variable', 'brand-fleet' ), value: '' } ].concat( choices.map( function ( c ) { return { label: c.label, value: c.key }; } ) ),
							onChange: function ( value ) { props.setAttributes( { variable: value } ); },
						} ),
						el( components.ToggleControl, {
							label: __( 'Numbered list', 'brand-fleet' ),
							checked: a.ordered,
							onChange: function ( value ) { props.setAttributes( { ordered: value } ); },
						} )
					)
				),
				el( serverSideRender, { block: 'brand-fleet/list', attributes: a } )
			);
		},
		save: function () { return null; },
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.components, wp.serverSideRender, wp.i18n );
