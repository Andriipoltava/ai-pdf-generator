/**
 * AI PDF Generator — Playground.
 * Надсилає запит у AJAX-обробник і показує результат.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $btn     = $( '#aipdf-generate-btn' ),
			$prompt  = $( '#aipdf-prompt' ),
			$spinner = $( '#aipdf-spinner' ),
			$result  = $( '#aipdf-result' ),
			$error   = $( '#aipdf-error' );

		// Клік по плейсхолдеру: вставка в textarea на позицію курсора.
		$( document ).on( 'click', '.aipdf-ph', function () {
			var tag = $( this ).data( 'ph' ),
				ta  = $prompt.get( 0 ),
				start, end, value;

			if ( ! ta || ! tag ) {
				return;
			}

			value = ta.value;
			start = 'number' === typeof ta.selectionStart ? ta.selectionStart : value.length;
			end   = 'number' === typeof ta.selectionEnd ? ta.selectionEnd : value.length;

			ta.value = value.slice( 0, start ) + tag + value.slice( end );

			// Курсор — одразу після вставленого тега.
			ta.selectionStart = ta.selectionEnd = start + tag.length;
			ta.focus();

			// Коротка візуальна реакція на клік.
			$( this ).css( 'background', '#c3e6cb' );
			setTimeout( function ( el ) {
				$( el ).css( 'background', '' );
			}, 350, this );
		} );

		function showError( message ) {
			$error.find( 'p' ).text( message );
			$error.show();
		}

		$btn.on( 'click', function () {
			var prompt = $.trim( $prompt.val() );

			$error.hide();
			$result.hide();

			if ( ! prompt ) {
				showError( aipdfData.i18n.emptyInput );
				return;
			}

			$btn.prop( 'disabled', true ).text( aipdfData.i18n.generating );
			$spinner.addClass( 'is-active' );

			$.post( aipdfData.ajaxUrl, {
				action: 'aipdf_generate',
				nonce:  aipdfData.nonce,
				prompt: prompt
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						showError( ( response && response.data && response.data.message ) || aipdfData.i18n.error );
						return;
					}

					var d = response.data;

					$( '#aipdf-res-link' ).html(
						$( '<a/>', { href: d.edit_link, text: '#' + d.post_id } )
					);
					$( '#aipdf-res-trigger' ).text( d.trigger_plugin );
					$( '#aipdf-res-action' ).text( d.action_type );
					$( '#aipdf-res-paper' ).text( d.paper_size );

					// Кнопка тестового PDF: показуємо лише якщо mPDF встановлено.
					$( '#aipdf-test-pdf-link' )
						.attr( 'href', d.test_pdf_url )
						.toggle( !! d.pdf_available );

					// Попередній перегляд у пісочниці iframe (sandbox="" вимикає JS).
					$( '#aipdf-preview' ).attr(
						'srcdoc',
						'<base target="_blank">' + d.html_template
					);

					$result.show();
				} )
				.fail( function ( xhr ) {
					var message = aipdfData.i18n.error;
					if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
						message = xhr.responseJSON.data.message;
					}
					showError( message );
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( 'Згенерувати' );
					$spinner.removeClass( 'is-active' );
				} );
		} );
	} );
}( jQuery ) );
