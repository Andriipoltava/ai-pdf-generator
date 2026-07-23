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

		// Per-template branding override values — only when the "Use
		// individual settings" checkbox is on, and only the ones actually
		// filled in (matches the same "empty falls back to global" rule
		// the PHP side uses in AIPDF_PDF_Renderer::override_placeholders()).
		function overrideValues() {
			var map = {};

			if ( ! $( '#aipdf-override-toggle' ).is( ':checked' ) ) {
				return map;
			}

			var pairs = {
				logo_url:         $( '#aipdf-custom-logo-url' ).val(),
				brand_color:      $( '#aipdf-custom-color' ).val(),
				company_name:     $( '#aipdf-custom-company-name' ).val(),
				company_address:  $( '#aipdf-custom-company-address' ).val(),
				company_email:    $( '#aipdf-custom-company-email' ).val()
			};

			$.each( pairs, function ( key, value ) {
				if ( value ) {
					map[ key ] = value;
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

		// {{key}} substitution: sample/global first, then this template's
		// branding override (if enabled), then visual field values — same
		// priority order as AIPDF_PDF_Renderer::render() on the server.
		function fill( html ) {
			var data = $.extend( {}, sample, overrideValues(), fieldValues() );
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

		// ---------- Override Branding meta box ----------
		( function () {
			var $toggle = $( '#aipdf-override-toggle' ),
				$fields = $( '#aipdf-override-fields' );

			if ( ! $toggle.length ) {
				return;
			}

			// Dim (not disable) the override fields when the toggle is off —
			// values are kept either way, so switching back on doesn't lose
			// anything already typed in.
			$toggle.on( 'change', function () {
				$fields.css( 'opacity', this.checked ? '' : '.5' );
				scheduleRender();
			} );

			// Color picker for the override color — a dedicated class, not
			// .aipdf-field (fieldValues() has its own {{field_key}} meaning),
			// but it still needs to trigger the live preview, via overrideValues().
			if ( $.fn.wpColorPicker ) {
				$( '.aipdf-override-color-field' ).wpColorPicker( {
					change: scheduleRender,
					clear:  scheduleRender
				} );
			}

			// Company name/address/email: plain text inputs, live-update like everything else.
			$( '#aipdf-custom-company-name, #aipdf-custom-company-address, #aipdf-custom-company-email' )
				.on( 'input', scheduleRender );

			// Logo media picker (same pattern as the global Branding logo picker).
			var frame,
				$logoUrl    = $( '#aipdf-custom-logo-url' ),
				$logoPrev   = $( '#aipdf-custom-logo-preview' ),
				$logoRemove = $( '#aipdf-custom-logo-remove' );

			$( '#aipdf-custom-logo-upload' ).on( 'click', function ( e ) {
				e.preventDefault();
				if ( frame ) {
					frame.open();
					return;
				}
				frame = wp.media( {
					title:   'Logo',
					button:  { text: 'Use this image' },
					library: { type: 'image' },
					multiple: false
				} );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					$logoUrl.val( att.url );
					$logoPrev.attr( 'src', att.url ).show();
					$logoRemove.show();
					scheduleRender();
				} );
				frame.open();
			} );

			$logoRemove.on( 'click', function ( e ) {
				e.preventDefault();
				$logoUrl.val( '' );
				$logoPrev.attr( 'src', '' ).hide();
				$( this ).hide();
				scheduleRender();
			} );

			// Paper size: a friendly select (A4/Letter/Legal/Custom) that
			// keeps the real #aipdf-paper hidden input as the single source
			// of truth submitted on save.
			var $paperHidden = $( '#aipdf-paper' ),
				$paperSelect = $( '#aipdf-paper-size-select' ),
				$paperCustom = $( '#aipdf-paper-custom' );

			$paperSelect.on( 'change', function () {
				var isCustom = 'custom' === $paperSelect.val();
				$paperCustom.toggle( isCustom );
				$paperHidden.val( isCustom ? $paperCustom.val() : $paperSelect.val() );
			} );

			$paperCustom.on( 'input', function () {
				$paperHidden.val( $paperCustom.val() );
			} );
		}() );
	} );
}( jQuery ) );
