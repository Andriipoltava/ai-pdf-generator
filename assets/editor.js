/**
 * AI PDF Generator — редактор шаблону.
 * Візуальні поля (кольори/тексти) + живе превю: підставляє значення полів
 * і демо-дані у HTML-каркас та рендерить у iframe.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $skeleton = $( '#aipdf-html-content' ),
			preview   = document.getElementById( 'aipdf-editor-preview' ),
			sample    = ( window.aipdfEditor && window.aipdfEditor.sample ) || {},
			timer     = null;

		if ( ! preview ) {
			return;
		}

		// Значення візуальних полів: key => value (color/text/textarea).
		function fieldValues() {
			var map = {};
			$( '.aipdf-field' ).each( function () {
				var key = $( this ).data( 'field-key' );
				if ( key ) {
					map[ String( key ).toLowerCase() ] = $( this ).val();
				}
			} );
			return map;
		}

		// Підстановка {{key}}: спершу значення полів, потім демо-дані.
		function fill( html ) {
			var data = $.extend( {}, sample, fieldValues() );
			return html.replace( /\{\{\s*([a-z0-9_]+)\s*\}\}/gi, function ( match, key ) {
				var value = data[ key.toLowerCase() ];
				return ( 'undefined' === typeof value ) ? '' : value;
			} );
		}

		function render() {
			var html = $skeleton.length ? $skeleton.val() : '';
			preview.srcdoc = '<base target="_blank">' + fill( html );
		}

		function scheduleRender() {
			window.clearTimeout( timer );
			timer = window.setTimeout( render, 250 );
		}

		// WP Color Picker для полів-кольорів.
		if ( $.fn.wpColorPicker ) {
			$( '.aipdf-color-field' ).wpColorPicker( {
				change: scheduleRender,
				clear:  scheduleRender
			} );
		}

		// Живе оновлення при зміні будь-якого поля або каркаса.
		$( document ).on( 'input', '.aipdf-field', scheduleRender );
		$skeleton.on( 'input', scheduleRender );
		$( '#aipdf-preview-refresh' ).on( 'click', render );

		render();
	} );
}( jQuery ) );
