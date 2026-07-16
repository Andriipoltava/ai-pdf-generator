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

		// ---------- Вкладки (nav-tab) ----------
		function activateTab( name ) {
			if ( ! $( '#aipdf-tab-' + name ).length ) {
				name = 'playground';
			}

			$( '#aipdf-tabs .nav-tab' )
				.removeClass( 'nav-tab-active' )
				.filter( '[data-tab="' + name + '"]' )
				.addClass( 'nav-tab-active' );

			$( '.aipdf-tab' ).hide();
			$( '#aipdf-tab-' + name ).show();
		}

		$( '#aipdf-tabs' ).on( 'click', '.nav-tab', function ( e ) {
			e.preventDefault();
			var name = $( this ).data( 'tab' );
			activateTab( name );
			// Хеш в URL — щоб вкладка переживала F5 і редиректи.
			if ( window.history.replaceState ) {
				window.history.replaceState( null, '', '#' + name );
			}
		} );

		// Початкова вкладка: хеш → після збереження налаштувань → після очищення логу.
		( function () {
			var initial = ( window.location.hash || '' ).replace( '#', '' ),
				search  = window.location.search;

			if ( ! initial && search.indexOf( 'settings-updated=true' ) !== -1 ) {
				initial = 'settings';
			}
			if ( ! initial && search.indexOf( 'aipdf_log_cleared=1' ) !== -1 ) {
				initial = 'logs';
			}

			activateTab( initial || 'playground' );
		}() );

		// ---------- Брендинг: вибір логотипу через медіатеку ----------
		( function () {
			var frame,
				$url     = $( '#aipdf-logo-url' ),
				$preview = $( '#aipdf-logo-preview' ),
				$remove  = $( '#aipdf-logo-remove' );

			$( '#aipdf-logo-upload' ).on( 'click', function ( e ) {
				e.preventDefault();
				if ( frame ) {
					frame.open();
					return;
				}
				frame = wp.media( {
					title:    'Логотип',
					button:   { text: 'Використати' },
					library:  { type: 'image' },
					multiple: false
				} );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					$url.val( att.url );
					$preview.attr( 'src', att.url ).show();
					$remove.show();
				} );
				frame.open();
			} );

			$remove.on( 'click', function ( e ) {
				e.preventDefault();
				$url.val( '' );
				$preview.attr( 'src', '' ).hide();
				$( this ).hide();
			} );
		}() );

		// ---------- Референс-зображення для Playground ----------
		( function () {
			var frame,
				$id      = $( '#aipdf-ref-id' ),
				$preview = $( '#aipdf-ref-preview' ),
				$remove  = $( '#aipdf-ref-remove' );

			$( '#aipdf-ref-upload' ).on( 'click', function ( e ) {
				e.preventDefault();
				if ( frame ) {
					frame.open();
					return;
				}
				frame = wp.media( {
					title:    'Референс-зображення',
					button:   { text: 'Використати' },
					library:  { type: 'image' },
					multiple: false
				} );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					$id.val( att.id );
					$preview.attr( 'src', att.url ).show();
					$remove.show();
				} );
				frame.open();
			} );

			$remove.on( 'click', function ( e ) {
				e.preventDefault();
				$id.val( '' );
				$preview.attr( 'src', '' ).hide();
				$( this ).hide();
			} );
		}() );

		// ---------- Швидкий старт: готові промпти ----------
		$( '#aipdf-quickstart' ).on( 'change', function () {
			var text = $( this ).val();
			if ( text ) {
				$prompt.val( text ).trigger( 'focus' );
			}
		} );

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

		function failHandler( xhr ) {
			var message = aipdfData.i18n.error;
			if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
				message = xhr.responseJSON.data.message;
			}
			showError( message );
		}

		// Поточна чернетка (не збережена в БД до кнопки «Зберегти шаблон»).
		var currentDraft = null;

		var $refine        = $( '#aipdf-refine' ),
			$refineBtn     = $( '#aipdf-refine-btn' ),
			$saveBtn       = $( '#aipdf-save-btn' ),
			$refineSpinner = $( '#aipdf-refine-spinner' ),
			$saved         = $( '#aipdf-saved' ),
			$history       = $( '#aipdf-history' );

		function applyDraft( d ) {
			currentDraft = {
				html_template:   d.html_template,
				editable_fields: d.editable_fields || [],
				trigger_plugin:  d.trigger_plugin,
				action_type:     d.action_type,
				paper_size:      d.paper_size,
				base_prompt:     d.base_prompt || ''
			};
			$( '#aipdf-res-trigger' ).text( d.trigger_plugin );
			$( '#aipdf-res-action' ).text( d.action_type );
			$( '#aipdf-res-paper' ).text( d.paper_size );
			$( '#aipdf-preview' ).attr( 'srcdoc', '<base target="_blank">' + ( d.preview_html || d.html_template ) );
			// Повертаємось у стан «чернетка».
			$saved.hide();
			$( '#aipdf-draft-badge' ).show();
			$result.show();
		}

		function addHistory( text ) {
			$( '<li/>', { text: text } ).appendTo( $history );
		}

		function busy( on ) {
			$refineBtn.prop( 'disabled', on );
			$saveBtn.prop( 'disabled', on );
			$refineSpinner.toggleClass( 'is-active', on );
		}

		// --- КРОК 1: генерація ЧЕРНЕТКИ (без збереження) ---
		$btn.on( 'click', function () {
			var prompt = $.trim( $prompt.val() );
			$error.hide();
			$result.hide();
			$history.empty();

			if ( ! prompt ) {
				showError( aipdfData.i18n.emptyInput );
				return;
			}

			$btn.prop( 'disabled', true ).text( aipdfData.i18n.generating );
			$spinner.addClass( 'is-active' );

			$.post( aipdfData.ajaxUrl, {
				action:       'aipdf_generate',
				nonce:        aipdfData.nonce,
				prompt:       prompt,
				reference_id: $( '#aipdf-ref-id' ).val() || ''
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						showError( ( response && response.data && response.data.message ) || aipdfData.i18n.error );
						return;
					}
					applyDraft( response.data );
					addHistory( 'Запит: ' + prompt );
				} )
				.fail( failHandler )
				.always( function () {
					$btn.prop( 'disabled', false ).text( 'Згенерувати' );
					$spinner.removeClass( 'is-active' );
				} );
		} );

		// --- КРОК 2: УТОЧНЕННЯ чернетки ---
		$refineBtn.on( 'click', function () {
			if ( ! currentDraft ) {
				return;
			}
			var instruction = $.trim( $refine.val() );
			$error.hide();
			if ( ! instruction ) {
				showError( aipdfData.i18n.emptyRefine );
				return;
			}

			busy( true );
			$.post( aipdfData.ajaxUrl, {
				action:          'aipdf_refine',
				nonce:           aipdfData.nonce,
				instruction:     instruction,
				base_prompt:     currentDraft.base_prompt,
				html_template:   currentDraft.html_template,
				editable_fields: JSON.stringify( currentDraft.editable_fields )
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						showError( ( response && response.data && response.data.message ) || aipdfData.i18n.error );
						return;
					}
					applyDraft( response.data );
					addHistory( 'Уточнення: ' + instruction );
					$refine.val( '' );
				} )
				.fail( failHandler )
				.always( function () {
					busy( false );
				} );
		} );

		// --- КРОК 3: ЗБЕРЕЖЕННЯ чернетки як CPT ---
		$saveBtn.on( 'click', function () {
			if ( ! currentDraft ) {
				return;
			}
			$error.hide();
			busy( true );
			$.post( aipdfData.ajaxUrl, {
				action:          'aipdf_save',
				nonce:           aipdfData.nonce,
				prompt:          currentDraft.base_prompt,
				trigger_plugin:  currentDraft.trigger_plugin,
				action_type:     currentDraft.action_type,
				paper_size:      currentDraft.paper_size,
				html_template:   currentDraft.html_template,
				editable_fields: JSON.stringify( currentDraft.editable_fields )
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						showError( ( response && response.data && response.data.message ) || aipdfData.i18n.error );
						return;
					}
					var d = response.data;
					$( '#aipdf-saved-msg' ).text( ( aipdfData.i18n.saved || 'Збережено' ) + ' (#' + d.post_id + ').' );
					$( '#aipdf-edit-link' ).attr( 'href', d.edit_link );
					$( '#aipdf-test-pdf-link' ).attr( 'href', d.test_pdf_url ).toggle( !! d.pdf_available );
					$( '#aipdf-draft-badge' ).hide();
					$saved.show();
				} )
				.fail( failHandler )
				.always( function () {
					busy( false );
				} );
		} );
	} );
}( jQuery ) );
