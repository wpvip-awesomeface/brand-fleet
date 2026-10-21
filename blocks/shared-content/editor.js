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
						el( components.SelectControl, {
							label: __( 'Show', 'brand-fleet' ),
							value: props.attributes.kind,
							options: [
								{ label: __( 'A page', 'brand-fleet' ), value: 'page' },
								{ label: __( 'A synced pattern (FAQ set, team list…)', 'brand-fleet' ), value: 'pattern' },
							],
							onChange: function ( value ) {
								props.setAttributes( { kind: value } );
							},
						} ),
						el( components.TextControl, {
							label: 'pattern' === props.attributes.kind ? __( 'Master pattern slug', 'brand-fleet' ) : __( 'Master page slug', 'brand-fleet' ),
							help: __( 'Path on the master site. Add a variable for regional versions: privacy-{{province}} shows privacy-on on Ontario sites and falls back to privacy. Leave empty for the master front page.', 'brand-fleet' ),
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
