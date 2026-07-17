/**
 * AI PDF Generator — front-end download_link delivery.
 * Draws a PDF download button after a successful CF7 or Elementor Pro
 * form submission.
 */
( function () {
	'use strict';

	/**
	 * Inserts a download button right after a form element.
	 *
	 * @param {Element} anchor Element after which to insert the button.
	 * @param {string}  url    PDF file URL.
	 */
	function insertButton( anchor, url ) {
		if ( ! anchor || ! url || anchor.parentNode.querySelector( '.aipdf-download-btn' ) ) {
			return; // Button already present — don't duplicate it.
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'aipdf-download-wrap';
		wrap.style.margin = '16px 0';

		var link = document.createElement( 'a' );
		link.className = 'button aipdf-download-btn';
		link.href = url; // URL comes from our own backend (esc_url_raw).
		link.target = '_blank';
		link.rel = 'noopener';
		link.textContent = ( window.aipdfFront && window.aipdfFront.buttonText ) || 'Download PDF';
		link.style.cssText = 'display:inline-block;padding:14px 28px;font-size:16px;font-weight:600;';

		wrap.appendChild( link );
		anchor.parentNode.insertBefore( wrap, anchor.nextSibling );
	}

	// Contact Form 7: the wpcf7mailsent event carries apiResponse with our field.
	document.addEventListener( 'wpcf7mailsent', function ( event ) {
		var api = event.detail && event.detail.apiResponse;
		if ( api && api.aipdf_download_url ) {
			insertButton( event.target, api.aipdf_download_url );
		}
	} );

	// Elementor Pro: jQuery's submit_success event with response.data.
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'submit_success', function ( event, response ) {
			var url = response && response.data && response.data.aipdf_download_url;
			if ( url ) {
				var form = event.target && event.target.closest ? event.target.closest( 'form' ) : null;
				insertButton( form || event.target, url );
			}
		} );
	}
}() );
