/* global wp, jQuery */
( function ( $ ) {
	'use strict';

	$( function () {
		// Accent color → WordPress color picker (iris).
		$( '.brand-fleet-color-field' ).wpColorPicker();

		// Image fields → media library picker writing the URL into the input.
		$( document ).on( 'click', '.brand-fleet-media-btn', function ( e ) {
			e.preventDefault();
			var target = $( '#' + $( this ).data( 'target' ) );
			var frame = wp.media( {
				title: 'Select or upload an image',
				library: { type: 'image' },
				button: { text: 'Use this image' },
				multiple: false,
			} );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				target.val( attachment.url ).trigger( 'change' );
			} );
			frame.open();
		} );
	} );
} )( jQuery );
