/**
 * The logo picker, and nothing else.
 *
 * No build step and no framework: one file of the source that was written is
 * what ships, which is what the plugin directory's review asks for and what
 * anybody reading it a year from now will thank us for.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var frame = null;
		var input = $( '#oxyddt-logo-id' );
		var preview = $( '.oxyddt-logo-preview' );

		$( '#oxyddt-choose-logo' ).on( 'click', function ( event ) {
			event.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title: window.oxyddtAdmin ? window.oxyddtAdmin.chooseLogo : '',
				button: { text: window.oxyddtAdmin ? window.oxyddtAdmin.useLogo : '' },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var source = attachment.sizes && attachment.sizes.medium
					? attachment.sizes.medium.url
					: attachment.url;

				input.val( attachment.id );
				preview.html( $( '<img>' ).attr( 'src', source ).css( { maxWidth: '200px', height: 'auto' } ) );
			} );

			frame.open();
		} );

		$( '#oxyddt-remove-logo' ).on( 'click', function ( event ) {
			event.preventDefault();

			input.val( '0' );
			preview.empty();
		} );
	} );
}( jQuery ) );
