/**
 * AI PDF Generator — фронтенд-доставка download_link.
 * Домальовує кнопку завантаження PDF після успішної відправки
 * форм CF7 та Elementor Pro.
 */
( function () {
	'use strict';

	/**
	 * Вставляє кнопку завантаження після елемента форми.
	 *
	 * @param {Element} anchor Елемент, після якого вставити кнопку.
	 * @param {string}  url    URL PDF-файлу.
	 */
	function insertButton( anchor, url ) {
		if ( ! anchor || ! url || anchor.parentNode.querySelector( '.aipdf-download-btn' ) ) {
			return; // Кнопка вже є — не дублюємо.
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'aipdf-download-wrap';
		wrap.style.margin = '16px 0';

		var link = document.createElement( 'a' );
		link.className = 'button aipdf-download-btn';
		link.href = url; // URL приходить із нашого ж бекенду (esc_url_raw).
		link.target = '_blank';
		link.rel = 'noopener';
		link.textContent = ( window.aipdfFront && window.aipdfFront.buttonText ) || 'Download PDF';
		link.style.cssText = 'display:inline-block;padding:14px 28px;font-size:16px;font-weight:600;';

		wrap.appendChild( link );
		anchor.parentNode.insertBefore( wrap, anchor.nextSibling );
	}

	// Contact Form 7: подія wpcf7mailsent несе apiResponse із нашим полем.
	document.addEventListener( 'wpcf7mailsent', function ( event ) {
		var api = event.detail && event.detail.apiResponse;
		if ( api && api.aipdf_download_url ) {
			insertButton( event.target, api.aipdf_download_url );
		}
	} );

	// Elementor Pro: jQuery-подія submit_success із response.data.
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
