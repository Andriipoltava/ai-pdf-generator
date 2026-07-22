/**
 * AI PDF Generator — the Playground as a chat with a live preview.
 *
 * Conversation state is NOT a list of messages, but a single `chat.layout`
 * object (the current layout: trigger_plugin/action_type/paper_size/
 * html_template/editable_fields). Every message (the first one and any
 * refinement) sends this layout back to the server along with the new
 * text — that IS the chat's "memory". The preview and fields only update
 * after a successful response; on error the previous state is left
 * untouched, and an error message is appended to the chat instead.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		// ---------- Tabs (nav-tab) ----------
		function activateTab( name ) {
			if ( ! $( '#aipdf-tab-' + name ).length ) {
				name = 'instructions';
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
			// Hash in the URL — so the tab survives F5 and redirects.
			if ( window.history.replaceState ) {
				window.history.replaceState( null, '', '#' + name );
			}
		} );

		// Onboarding step links — same tab-switch, from outside #aipdf-tabs.
		$( document ).on( 'click', '.aipdf-onboard-link', function ( e ) {
			e.preventDefault();
			var name = ( $( this ).attr( 'href' ) || '' ).replace( '#', '' );
			if ( ! name ) {
				return;
			}
			activateTab( name );
			if ( window.history.replaceState ) {
				window.history.replaceState( null, '', '#' + name );
			}
		} );

		// Initial tab: hash -> after saving settings -> after clearing the log -> first-run Instructions.
		( function () {
			var initial = ( window.location.hash || '' ).replace( '#', '' ),
				search  = window.location.search;

			if ( ! initial && search.indexOf( 'settings-updated=true' ) !== -1 ) {
				initial = 'settings';
			}
			if ( ! initial && search.indexOf( 'aipdf_log_cleared=1' ) !== -1 ) {
				initial = 'logs';
			}

			activateTab( initial || 'instructions' );
		}() );

		// ---------- Branding: choosing a logo via the media library ----------
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
					title:    'Logo',
					button:   { text: 'Use this image' },
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

		// ==================== CHAT (Playground) ====================

		var $history   = $( '#aipdf-chat-history' ),
			$input     = $( '#aipdf-chat-input' ),
			$sendBtn   = $( '#aipdf-chat-send' ),
			$refId     = $( '#aipdf-ref-id' ),
			$refPrev   = $( '#aipdf-ref-preview' ),
			$refRemove = $( '#aipdf-ref-remove' ),
			$refHint   = $( '#aipdf-ref-hint' ),
			$preview   = $( '#aipdf-preview' ),
			$previewPh = $( '#aipdf-preview-placeholder' ),
			$fields    = $( '#aipdf-chat-fields' ),
			$metaWrap  = $( '#aipdf-chat-meta' ),
			$metaLine  = $( '#aipdf-meta-line' ),
			$draftBadge = $( '#aipdf-draft-badge' ),
			$saveBtn   = $( '#aipdf-save-btn' ),
			$saved     = $( '#aipdf-saved' ),
			i18n       = aipdfData.i18n || {},
			sample     = aipdfData.sample || {};

		if ( ! $history.length ) {
			return; // Not the Playground tab (guards against double init elsewhere).
		}

		// Single chat state: the current layout + the JSON the server last
		// returned, which we send back unchanged on the next turn.
		var chat = {
			layout:     null,
			layoutJson: '',
			basePrompt: '',
			busy:       false,
			// True right after a successful save, as long as the layout
			// hasn't changed since (new chat message or manual field edit
			// flips this back to false, showing "unsaved" again).
			savedAndCurrent: false
		};

		function escapeHtml( str ) {
			return $( '<div/>' ).text( str == null ? '' : str ).html();
		}

		function scrollHistoryToBottom() {
			$history.scrollTop( $history.get( 0 ).scrollHeight );
		}

		/**
		 * Appends a bubble to the chat history.
		 *
		 * @param {string} role user | ai | error | pending
		 * @param {string} html Already-escaped HTML to insert.
		 * @return {jQuery} The created element (so a pending bubble can be removed later).
		 */
		function appendMessage( role, html ) {
			var $bubble = $( '<div/>' )
				.addClass( 'aipdf-chat-msg aipdf-chat-msg-' + role )
				.css( {
					margin:       '0 0 10px',
					padding:      '8px 12px',
					borderRadius: '6px',
					fontSize:     '13px',
					lineHeight:   '1.5',
					maxWidth:     '92%',
					whiteSpace:   'pre-wrap',
					wordBreak:    'break-word'
				} );

			if ( 'user' === role ) {
				$bubble.css( { background: '#2271b1', color: '#fff', marginLeft: 'auto' } );
			} else if ( 'error' === role ) {
				$bubble.css( { background: '#fcf0f1', color: '#8a2424', border: '1px solid #f5c2c2' } );
			} else if ( 'pending' === role ) {
				$bubble.css( { background: '#f0f0f1', color: '#646970', fontStyle: 'italic' } );
			} else {
				$bubble.css( { background: '#f0f0f1', color: '#1d2327' } );
			}

			$bubble.html( html );
			$history.append( $bubble );
			scrollHistoryToBottom();

			return $bubble;
		}

		// ---------- Reference image (attached to the next message) ----------
		( function () {
			var frame;

			$( '#aipdf-ref-upload' ).on( 'click', function ( e ) {
				e.preventDefault();
				if ( frame ) {
					frame.open();
					return;
				}
				frame = wp.media( {
					title:    'Reference Image',
					button:   { text: 'Use this image' },
					library:  { type: 'image' },
					multiple: false
				} );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					$refId.val( att.id );
					$refPrev.attr( 'src', att.url ).show();
					$refRemove.show();
					$refHint.show();
				} );
				frame.open();
			} );

			$refRemove.on( 'click', function ( e ) {
				e.preventDefault();
				clearReference();
			} );
		}() );

		function clearReference() {
			$refId.val( '' );
			$refPrev.attr( 'src', '' ).hide();
			$refHint.hide();
			$refRemove.hide();
		}

		// ---------- Placeholders and quick-start insert into the chat field ----------
		$( '#aipdf-quickstart' ).on( 'change', function () {
			var text = $( this ).val();
			if ( text ) {
				$input.val( text ).trigger( 'focus' );
			}
		} );

		$( document ).on( 'click', '.aipdf-ph', function () {
			var tag = $( this ).data( 'ph' ),
				ta  = $input.get( 0 ),
				start, end, value;

			if ( ! ta || ! tag ) {
				return;
			}

			value = ta.value;
			start = 'number' === typeof ta.selectionStart ? ta.selectionStart : value.length;
			end   = 'number' === typeof ta.selectionEnd ? ta.selectionEnd : value.length;

			ta.value = value.slice( 0, start ) + tag + value.slice( end );
			ta.selectionStart = ta.selectionEnd = start + tag.length;
			ta.focus();

			$( this ).css( 'background', '#c3e6cb' );
			setTimeout( function ( el ) {
				$( el ).css( 'background', '' );
			}, 350, this );
		} );

		// ---------- Live client-side preview: {{key}} substitution, no AJAX ----------

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

		function fillPlaceholders( html, fieldValues ) {
			var data = $.extend( {}, sample, fieldValues );
			html = hideEmptyImages( html, data );
			html = html.replace( /\{\{\s*([a-z0-9_]+)\s*\}\}/gi, function ( match, key ) {
				var value = data[ key.toLowerCase() ];
				return ( 'undefined' === typeof value ) ? '' : value;
			} );
			return renderQrPreview( html );
		}

		function currentFieldValues() {
			var map = {};
			if ( chat.layout && chat.layout.editable_fields ) {
				chat.layout.editable_fields.forEach( function ( f ) {
					map[ String( f.key ).toLowerCase() ] = f.value;
				} );
			}
			return map;
		}

		function renderPreview() {
			if ( ! chat.layout ) {
				return;
			}
			var html = fillPlaceholders( chat.layout.html_template, currentFieldValues() );
			$preview.attr( 'srcdoc', '<base target="_blank">' + html );
			$preview.show();
			$previewPh.hide();
		}

		// ---------- Dynamic editable_fields controls (colors/text) ----------
		function updateFieldValue( idx, value ) {
			if ( chat.layout && chat.layout.editable_fields && chat.layout.editable_fields[ idx ] ) {
				chat.layout.editable_fields[ idx ].value = value;
				renderPreview();
				// A manual edit after saving means the saved post no longer
				// matches the live preview — show "unsaved" again.
				chat.savedAndCurrent = false;
				updateMeta();
			}
		}

		function renderFields() {
			$fields.empty();

			if ( ! chat.layout || ! chat.layout.editable_fields || ! chat.layout.editable_fields.length ) {
				return;
			}

			chat.layout.editable_fields.forEach( function ( field, idx ) {
				var $row   = $( '<div/>' ).css( { marginBottom: '10px' } ),
					fieldId = 'aipdf-cf-' + idx,
					$label = $( '<label/>', { text: field.label, 'for': fieldId } )
						.css( { display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '3px' } ),
					$control;

				$row.append( $label );

				if ( 'color' === field.type ) {
					$control = $( '<input/>', { type: 'text', id: fieldId, value: field.value } ).addClass( 'aipdf-cf aipdf-cf-color' );
				} else if ( 'textarea' === field.type ) {
					$control = $( '<textarea/>', { id: fieldId, rows: 2 } ).addClass( 'large-text aipdf-cf' ).val( field.value );
				} else {
					$control = $( '<input/>', { type: 'text', id: fieldId, value: field.value } ).addClass( 'regular-text aipdf-cf' );
				}

				$control.data( 'field-index', idx );
				$row.append( $control );
				$fields.append( $row );

				if ( 'color' === field.type && $.fn.wpColorPicker ) {
					$control.wpColorPicker( {
						change: function ( event, ui ) {
							updateFieldValue( idx, ui.color.toString() );
						},
						clear: function () {
							updateFieldValue( idx, '' );
						}
					} );
				}
			} );
		}

		$( document ).on( 'input', '.aipdf-cf:not(.aipdf-cf-color)', function () {
			updateFieldValue( $( this ).data( 'field-index' ), $( this ).val() );
		} );

		function actionLabel( actionType ) {
			return 'attach_to_email' === actionType
				? ( i18n.actionEmail || actionType )
				: ( i18n.actionDl || actionType );
		}

		function updateMeta() {
			if ( ! chat.layout ) {
				$metaWrap.hide();
				return;
			}
			$metaLine.text(
				chat.layout.trigger_plugin + ' · ' + actionLabel( chat.layout.action_type ) + ' · ' + chat.layout.paper_size
			);
			// Only show the "unsaved" badge while the live layout doesn't
			// match what's actually persisted in the DB.
			$draftBadge.toggle( ! chat.savedAndCurrent );
			$metaWrap.show();
		}

		/**
		 * Applies a SUCCESSFUL server response as the new chat state.
		 * Only called on success — otherwise the previous state is untouched.
		 */
		function applyLayout( d ) {
			chat.layout = {
				trigger_plugin:  d.trigger_plugin,
				action_type:     d.action_type,
				paper_size:      d.paper_size,
				html_template:   d.html_template,
				editable_fields: d.editable_fields || []
			};
			// The verbatim JSON from the server — this is exactly what we send back next time.
			chat.layoutJson = d.current_layout || JSON.stringify( chat.layout );
			// A fresh or refined layout hasn't been saved yet, even if an
			// earlier version of this template was.
			chat.savedAndCurrent = false;

			renderPreview();
			renderFields();
			updateMeta();

			$saveBtn.show();
			$saved.hide();
		}

		function setBusy( on ) {
			chat.busy = on;
			$sendBtn.prop( 'disabled', on ).text( on ? i18n.sending : i18n.send );
			$input.prop( 'disabled', on );
		}

		function sendMessage() {
			if ( chat.busy ) {
				return;
			}

			var text = $.trim( $input.val() );
			if ( ! text ) {
				appendMessage( 'error', escapeHtml( i18n.emptyInput ) );
				return;
			}

			var refId    = $refId.val(),
				userLine = '<strong>' + escapeHtml( i18n.you ) + ':</strong> ' + escapeHtml( text );

			if ( refId ) {
				userLine += '<br/><img src="' + escapeHtml( $refPrev.attr( 'src' ) || '' ) + '" style="max-height:60px;margin-top:4px;border:1px solid rgba(255,255,255,.5);border-radius:3px;" />';
			}
			appendMessage( 'user', userLine );

			var $pending = appendMessage( 'pending', escapeHtml( i18n.assistant ) + '…' );

			if ( ! chat.basePrompt ) {
				chat.basePrompt = text;
			}

			setBusy( true );

			$.post( aipdfData.ajaxUrl, {
				action:         'aipdf_chat',
				nonce:          aipdfData.nonce,
				prompt:         text,
				current_layout: chat.layoutJson || '',
				reference_id:   refId || ''
			} )
				.done( function ( response ) {
					$pending.remove();

					if ( ! response || ! response.success ) {
						// Don't touch layout/preview/fields — only append a chat message.
						appendMessage( 'error', escapeHtml( ( response && response.data && response.data.message ) || i18n.error ) );
						return;
					}

					applyLayout( response.data );
					appendMessage(
						'ai',
						escapeHtml(
							( i18n.updatedMsg || 'Done: %1$s / %2$s / %3$s' )
								.replace( '%1$s', response.data.trigger_plugin )
								.replace( '%2$s', actionLabel( response.data.action_type ) )
								.replace( '%3$s', response.data.paper_size )
						)
					);
				} )
				.fail( function ( xhr ) {
					$pending.remove();
					var message = i18n.error;
					if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
						message = xhr.responseJSON.data.message;
					}
					// Again: don't touch the previous state here either.
					appendMessage( 'error', escapeHtml( message ) );
				} )
				.always( function () {
					setBusy( false );
					// The reference is one-shot: attached only to the message
					// just sent; re-attach it for the next one if needed.
					clearReference();
					$input.val( '' ).trigger( 'focus' );
				} );
		}

		$sendBtn.on( 'click', sendMessage );
		$input.on( 'keydown', function ( e ) {
			if ( 13 === e.which && ! e.shiftKey ) {
				e.preventDefault();
				sendMessage();
			}
		} );

		// ---------- Save Template (finalizes the current layout as a CPT post) ----------
		$saveBtn.on( 'click', function () {
			if ( ! chat.layout ) {
				return;
			}

			$saveBtn.prop( 'disabled', true );

			$.post( aipdfData.ajaxUrl, {
				action:          'aipdf_save',
				nonce:           aipdfData.nonce,
				prompt:          chat.basePrompt,
				trigger_plugin:  chat.layout.trigger_plugin,
				action_type:     chat.layout.action_type,
				paper_size:      chat.layout.paper_size,
				html_template:   chat.layout.html_template,
				editable_fields: JSON.stringify( chat.layout.editable_fields )
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						appendMessage( 'error', escapeHtml( ( response && response.data && response.data.message ) || i18n.error ) );
						return;
					}
					var d = response.data;
					$( '#aipdf-saved-msg' ).text( ( i18n.saved || 'Saved' ) + ' (#' + d.post_id + ').' );
					$( '#aipdf-edit-link' ).attr( 'href', d.edit_link );
					$( '#aipdf-test-pdf-link' ).attr( 'href', d.test_pdf_url ).toggle( !! d.pdf_available );
					$saved.show();
					// The saved post now matches what's on screen — hide the "unsaved" badge.
					chat.savedAndCurrent = true;
					updateMeta();
				} )
				.fail( function ( xhr ) {
					var message = i18n.error;
					if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
						message = xhr.responseJSON.data.message;
					}
					appendMessage( 'error', escapeHtml( message ) );
				} )
				.always( function () {
					$saveBtn.prop( 'disabled', false );
				} );
		} );

		// Welcome chat message.
		if ( i18n.welcomeMsg ) {
			appendMessage( 'ai', escapeHtml( i18n.welcomeMsg ) );
		}
	} );
}( jQuery ) );
