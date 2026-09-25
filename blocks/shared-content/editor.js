/* global wp */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';
	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'brand-fleet/shared-content', {
		edit: function ( props ) {
			var blockProps = blockEditor.useBlockProps();
			return el(
				'div',
				blockProps,
				el(
					blockEditor.InspectorControls,
					{},
					el(
						components.PanelBody,
						{ title: __( 'Source', 'brand-fleet' ) },
						el( components.TextControl, {
							label: __( 'Master page slug', 'brand-fleet' ),
							help: __( 'Path of the page on the master site. Leave empty for its front page.', 'brand-fleet' ),
							value: props.attributes.slug,
							onChange: function ( value ) {
								props.setAttributes( { slug: value } );
							},
						} )
					)
				),
				el( serverSideRender, {
					block: 'brand-fleet/shared-content',
					attributes: props.attributes,
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.components, wp.serverSideRender, wp.i18n );
