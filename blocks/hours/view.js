/* Opening Hours: open-now line and today's row, worked out in the visitor's browser so cached pages stay correct. */
( function () {
	'use strict';
	var days = [ 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ];
	function today( zone ) {
		try {
			var name = new Intl.DateTimeFormat( 'en-US', { weekday: 'short', timeZone: zone } ).format( new Date() ).toLowerCase().slice( 0, 3 );
			return days.indexOf( name ) > -1 ? name : '';
		} catch ( e ) { return ''; }
	}
	document.querySelectorAll( '.brand-fleet-hours' ).forEach( function ( block ) {
		var table = block.querySelector( '.brand-fleet-hours__week' );
		var day = table ? today( table.getAttribute( 'data-zone' ) ) : '';
		if ( day ) {
			table.querySelectorAll( 'tr[data-days]' ).forEach( function ( row ) {
				if ( row.getAttribute( 'data-days' ).split( ',' ).indexOf( day ) > -1 ) { row.classList.add( 'is-today' ); row.setAttribute( 'aria-current', 'date' ); }
			} );
		}
		var status = block.querySelector( '.brand-fleet-hours__status' );
		if ( ! status ) { return; }
		var now = Date.now() / 1000, intervals = [];
		try { intervals = JSON.parse( status.getAttribute( 'data-intervals' ) || '[]' ); } catch ( e ) { return; }
		for ( var i = 0; i < intervals.length; i++ ) {
			if ( now >= intervals[ i ][ 0 ] && now < intervals[ i ][ 1 ] ) {
				status.textContent = status.getAttribute( 'data-open' ) + ' · ' + status.getAttribute( 'data-closes' ).replace( '%s', intervals[ i ][ 2 ] );
				status.classList.add( 'is-open' ); status.hidden = false; return;
			}
			if ( now < intervals[ i ][ 0 ] ) {
				status.textContent = status.getAttribute( 'data-closed' ) + ' · ' + status.getAttribute( 'data-opens' ).replace( '%s', intervals[ i ][ 3 ] );
				status.classList.add( 'is-closed' ); status.hidden = false; return;
			}
		}
	} );
}() );
