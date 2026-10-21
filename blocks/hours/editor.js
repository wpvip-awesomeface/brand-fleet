/* global wp, brandFleetBlocks */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';
	var el = element.createElement;
	var __ = i18n.__;
	var choices = ( window.brandFleetBlocks && brandFleetBlocks.hours ) || [];

	blocks.registerBlockType( 'brand-fleet/hours', {
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
						{ title: __( 'Hours', 'brand-fleet' ) },
						el( components.SelectControl, {
							label: __( 'Variable', 'brand-fleet' ),
							value: a.variable,
							options: [ { label: __( 'First Opening hours variable', 'brand-fleet' ), value: '' } ].concat( choices.map( function ( c ) { return { label: c.label, value: c.key }; } ) ),
							onChange: function ( value ) { props.setAttributes( { variable: value } ); },
						} ),
						el( components.ToggleControl, {
							label: __( 'Show “Open now” or “Closed now”', 'brand-fleet' ),
							checked: a.showStatus,
							onChange: function ( value ) { props.setAttributes( { showStatus: value } ); },
						} ),
						el( components.ToggleControl, {
							label: __( 'Show holiday closures and special hours (next 3 weeks)', 'brand-fleet' ),
							checked: a.showSpecial,
							onChange: function ( value ) { props.setAttributes( { showSpecial: value } ); },
						} )
					)
				),
				el( serverSideRender, { block: 'brand-fleet/hours', attributes: a } )
			);
		},
		save: function () { return null; },
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.components, wp.serverSideRender, wp.i18n );
