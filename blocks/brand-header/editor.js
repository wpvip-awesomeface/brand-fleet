/* global wp */
( function ( blocks, element, blockEditor, serverSideRender ) {
	'use strict';
	var el = element.createElement;

	blocks.registerBlockType( 'brand-fleet/brand-header', {
		edit: function ( props ) {
			var blockProps = blockEditor.useBlockProps();
			return el(
				'div',
				blockProps,
				el( serverSideRender, {
					block: 'brand-fleet/brand-header',
					attributes: props.attributes,
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.serverSideRender );
