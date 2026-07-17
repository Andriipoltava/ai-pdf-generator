/**
 * AI PDF Generator — template editor.
 * Visual fields (colors/text) + live preview: substitutes field values
 * and sample data into the HTML skeleton and renders it in an iframe.
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

		// Visual field values: key => value (color/text/textarea).
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

		// {{key}} substitution: field values first, then sample data.
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

		// WP Color Picker for color fields.
		if ( $.fn.wpColorPicker ) {
			$( '.aipdf-color-field' ).wpColorPicker( {
				change: scheduleRender,
				clear:  scheduleRender
			} );
		}

		// Live update whenever any field or the skeleton changes.
		$( document ).on( 'input', '.aipdf-field', scheduleRender );
		$skeleton.on( 'input', scheduleRender );
		$( '#aipdf-preview-refresh' ).on( 'click', render );

		render();

		// ---------- Generation conditions (Conditional Logic): repeater ----------
		var $condRows = $( '#aipdf-cond-rows' ),
			$template = $( '#aipdf-cond-row-template' );

		if ( $condRows.length && $template.length ) {
			$( '#aipdf-cond-add' ).on( 'click', function () {
				var index = $condRows.children( '.aipdf-cond-row' ).length,
					html  = $template.html().replace( /__INDEX__/g, index );

				$condRows.append( html );
			} );

			$condRows.on( 'click', '.aipdf-cond-remove', function () {
				$( this ).closest( '.aipdf-cond-row' ).remove();
			} );
		}
	} );
}( jQuery ) );
