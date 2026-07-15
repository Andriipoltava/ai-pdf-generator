/**
 * AI PDF Generator — редактор шаблону: живе превю.
 * Підставляє демо-дані у плейсхолдери й показує результат в iframe.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $textarea = $( '#aipdf-html-content' ),
			preview   = document.getElementById( 'aipdf-editor-preview' ),
			sample    = ( window.aipdfEditor && window.aipdfEditor.sample ) || {},
			timer     = null;

		if ( ! $textarea.length || ! preview ) {
			return;
		}

		// Заміна {{key}} на демо-значення (невідомі — на порожньо).
		function fill( html ) {
			return html.replace( /\{\{\s*([a-z0-9_]+)\s*\}\}/gi, function ( match, key ) {
				var value = sample[ key.toLowerCase() ];
				return ( 'undefined' === typeof value ) ? '' : value;
			} );
		}

		function render() {
			// <base target="_blank"> — щоб посилання не намагались вести всередині sandbox.
			preview.srcdoc = '<base target="_blank">' + fill( $textarea.val() );
		}

		function scheduleRender() {
			window.clearTimeout( timer );
			timer = window.setTimeout( render, 300 );
		}

		$textarea.on( 'input', scheduleRender );
		$( '#aipdf-preview-refresh' ).on( 'click', render );

		render(); // Первинний рендер.
	} );
}( jQuery ) );
