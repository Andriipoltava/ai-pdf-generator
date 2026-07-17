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

		// Drop <img> tags whose src is a single placeholder with no value
		// (e.g. no logo uploaded yet) — an empty src otherwise shows a
		// broken-image icon instead of just not being there.
		function hideEmptyImages( html, data ) {
			return html.replace( /<img\b[^>]*\bsrc\s*=\s*"\{\{\s*([a-z0-9_]+)\s*\}\}"[^>]*\/?>/gi, function ( match, key ) {
				var value = data[ key.toLowerCase() ];
				return value ? match : '';
			} );
		}

		// The browser can't render mPDF's native <barcode> tag — replace it
		// (preview only, never the saved template) with a QR image so the
		// user can actually see what will end up in the PDF.
		function renderQrPreview( html ) {
			return html.replace( /<barcode\b([^>]*)\/?>/gi, function ( match, attrs ) {
				var typeMatch = attrs.match( /type\s*=\s*"([^"]*)"/i );
				if ( ! typeMatch || 'qr' !== typeMatch[1].toLowerCase() ) {
					return ''; // Only QR is previewable in-browser; other barcode types are dropped here.
				}
				var codeMatch = attrs.match( /code\s*=\s*"([^"]*)"/i );
				var code = codeMatch ? codeMatch[1] : '';
				if ( ! code ) {
					return '';
				}
				var sizeMatch = attrs.match( /size\s*=\s*"([^"]*)"/i ),
					multiplier = sizeMatch ? ( parseFloat( sizeMatch[1] ) || 1 ) : 1,
					px = Math.max( 40, Math.round( 90 * multiplier ) );

				return '<img src="https://api.qrserver.com/v1/create-qr-code/?size=' + px + 'x' + px + '&data=' + encodeURIComponent( code ) +
					'" width="' + px + '" height="' + px + '" alt="QR code" title="' + code.replace( /"/g, '&quot;' ) + '" style="display:inline-block;" />';
			} );
		}

		// {{key}} substitution: field values first, then sample data.
		function fill( html ) {
			var data = $.extend( {}, sample, fieldValues() );
			html = hideEmptyImages( html, data );
			html = html.replace( /\{\{\s*([a-z0-9_]+)\s*\}\}/gi, function ( match, key ) {
				var value = data[ key.toLowerCase() ];
				return ( 'undefined' === typeof value ) ? '' : value;
			} );
			return renderQrPreview( html );
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
