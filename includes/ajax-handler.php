<?php
/**
 * AJAX handler: accepts the user's request, calls the Gemini API,
 * validates the JSON response, and saves the template to the CPT.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Ajax_Handler {

	/**
	 * Option storing the Gemini model name (configurable in the admin).
	 */
	public const OPTION_MODEL = 'aipdf_gemini_model';

	/**
	 * Default model: an alias Google always keeps pointed at the current
	 * flash model — doesn't break on deprecation.
	 */
	public const DEFAULT_MODEL = 'gemini-flash-latest';

	private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	/**
	 * Option storing the cloud license/trial token.
	 */
	public const OPTION_CLOUD_TOKEN = 'aipdf_cloud_token';

	/**
	 * Option storing the active generation mode ('gemini_direct' | 'cloud_service').
	 */
	public const OPTION_GENERATION_MODE = 'aipdf_generation_mode';

	/**
	 * Trial-license endpoint on our licensing backend.
	 */
	private const TRIAL_ENDPOINT = 'http://chnplugi79173.avalon.chost.com.ua/api/v1/license/trial';

	/**
	 * License status endpoint on our licensing backend.
	 */
	private const STATUS_ENDPOINT = 'http://chnplugi79173.avalon.chost.com.ua/api/v1/license/status';

	public function __construct() {
		// Logged-in admins only — wp_ajax_nopriv is deliberately not registered.
		add_action( 'wp_ajax_aipdf_chat', array( $this, 'handle_chat' ) );
		add_action( 'wp_ajax_aipdf_save', array( $this, 'handle_save' ) );
		add_action( 'wp_ajax_aipdf_get_trial', array( $this, 'handle_get_trial' ) );
		add_action( 'wp_ajax_aipdf_check_license_status', array( $this, 'handle_check_license_status' ) );
	}

	/**
	 * Shared access check + API key retrieval. Ends the request with an
	 * error if something's wrong; otherwise returns the API key.
	 */
	private function guard_and_key(): string {
		check_ajax_referer( 'aipdf_generate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-pdf-generator' ) ), 403 );
		}

		// A constant in wp-config.php takes priority over the DB option.
		$api_key = defined( 'AIPDF_GEMINI_API_KEY' )
			? (string) AIPDF_GEMINI_API_KEY
			: (string) get_option( AIPDF_Plugin::OPTION_API_KEY, '' );
		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => __( 'Please save your Gemini API key in Settings first.', 'ai-pdf-generator' ) ), 400 );
		}

		return $api_key;
	}

	/**
	 * Requests a free trial cloud license (3 generations) from our
	 * licensing backend, tied to the current admin's email and this site's
	 * domain. On success, stores the returned token and switches the
	 * generation mode to 'cloud_service'. The backend is the source of
	 * truth for anti-abuse (one trial per email/domain) — a 403/422 there
	 * simply surfaces as an error message here.
	 */
	public function handle_get_trial(): void {
		check_ajax_referer( 'aipdf_generate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-pdf-generator' ) ), 403 );
		}

		$email  = (string) wp_get_current_user()->user_email;
		$domain = (string) wp_parse_url( site_url(), PHP_URL_HOST );

		if ( '' === $email || '' === $domain ) {
			wp_send_json_error( array( 'message' => __( 'Could not determine your admin email or site domain.', 'ai-pdf-generator' ) ), 400 );
		}

		$response = wp_remote_post(
			self::TRIAL_ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'email'        => $email,
					'domain'       => $domain,
					'product_slug' => 'ai-pdf-generator',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			AIPDF_Logger::get_instance()->error( 'Trial request failed: ' . $response->get_error_message() );
			wp_send_json_error( array( 'message' => __( 'Could not reach the licensing server. Please try again later.', 'ai-pdf-generator' ) ), 502 );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code && 201 !== $code ) {
			// Laravel-style error payloads: a top-level "message", or a
			// validation "errors" map — fall back to a generic message if
			// neither is present.
			$message = '';
			if ( is_array( $data ) ) {
				if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
					$message = $data['message'];
				} elseif ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
					$first = reset( $data['errors'] );
					$message = is_array( $first ) ? (string) reset( $first ) : (string) $first;
				}
			}
			if ( '' === $message ) {
				$message = __( 'The trial request was rejected.', 'ai-pdf-generator' );
			}
			$message = sanitize_text_field( $message );

			AIPDF_Logger::get_instance()->warning( sprintf( 'Trial request rejected (%d) for %s / %s: %s', $code, $email, $domain, $message ) );
			wp_send_json_error( array( 'message' => $message ), $code );
		}

		if ( empty( $data['plainTextToken'] ) || ! is_string( $data['plainTextToken'] ) ) {
			AIPDF_Logger::get_instance()->error( 'Trial response missing plainTextToken.' );
			wp_send_json_error( array( 'message' => __( 'The licensing server returned an unexpected response.', 'ai-pdf-generator' ) ), 502 );
		}

		$token = sanitize_text_field( $data['plainTextToken'] );
		update_option( self::OPTION_CLOUD_TOKEN, $token );
		update_option( self::OPTION_GENERATION_MODE, 'cloud_service' );

		AIPDF_Logger::get_instance()->info( sprintf( 'Trial license activated for %s (%s).', $email, $domain ) );

		wp_send_json_success(
			array(
				'message' => __( 'Trial activated!', 'ai-pdf-generator' ),
				'token'   => $token,
			)
		);
	}

	/**
	 * Fetches the current cloud license status (plan, remaining credits,
	 * domain usage, expiry) from our licensing backend, for display in
	 * Settings. The response body is passed through to the client as-is —
	 * the exact field set is defined by the backend's pricing model, so
	 * the JS renderer reads it defensively rather than this handler
	 * enforcing a fixed shape.
	 */
	public function handle_check_license_status(): void {
		check_ajax_referer( 'aipdf_generate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-pdf-generator' ) ), 403 );
		}

		$token = (string) get_option( self::OPTION_CLOUD_TOKEN, '' );
		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'No license key saved yet.', 'ai-pdf-generator' ) ), 400 );
		}

		// Origin: scheme + host of this site, no path — e.g. https://example.com.
		$parsed_home = wp_parse_url( home_url() );
		$origin      = ( $parsed_home['scheme'] ?? 'https' ) . '://' . ( $parsed_home['host'] ?? '' );

		$response = wp_remote_get(
			self::STATUS_ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
					'Origin'        => $origin,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			AIPDF_Logger::get_instance()->error( 'License status request failed: ' . $response->get_error_message() );
			wp_send_json_error( array( 'message' => __( 'Could not reach the licensing server. Please try again later.', 'ai-pdf-generator' ) ), 502 );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = ( is_array( $data ) && ! empty( $data['message'] ) && is_string( $data['message'] ) )
				? sanitize_text_field( $data['message'] )
				: __( 'Could not verify the license status.', 'ai-pdf-generator' );

			AIPDF_Logger::get_instance()->warning( sprintf( 'License status check failed (%d): %s', $code, $message ) );
			wp_send_json_error( array( 'message' => $message ), $code );
		}

		if ( ! is_array( $data ) ) {
			AIPDF_Logger::get_instance()->error( 'License status response was not valid JSON.' );
			wp_send_json_error( array( 'message' => __( 'The licensing server returned an unexpected response.', 'ai-pdf-generator' ) ), 502 );
		}

		wp_send_json_success( $data );
	}

	/**
	 * The SINGLE chat entry point: the first message AND every follow-up
	 * refinement go through the same code path — this eliminates a whole
	 * class of bugs where generate and refine used to diverge (e.g. a
	 * reference image that was only sent on the first generation and got
	 * lost on refinement).
	 *
	 * The client stores the full `current_layout` (JSON) after every
	 * successful response and sends it back with the next message — that
	 * IS the chat's "memory", rather than a growing message history.
	 *
	 * No post is created here: only a preview, until an explicit "Save Template".
	 */
	public function handle_chat(): void {
		$api_key = $this->guard_and_key();

		$user_prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( '' === $user_prompt ) {
			wp_send_json_error( array( 'message' => __( 'Message is empty.', 'ai-pdf-generator' ) ), 400 );
		}

		// Current layout (if this isn't the first message in the chat).
		$current_layout_json = isset( $_POST['current_layout'] ) ? (string) wp_unslash( $_POST['current_layout'] ) : '';
		$current_layout       = $this->decode_client_layout( $current_layout_json );

		// Reference image (WP Media) — on ANY message, not just the first:
		// the same code path handles both generate and refine.
		$image = $this->build_reference_image( isset( $_POST['reference_id'] ) ? $_POST['reference_id'] : 0 );

		$turn = array(
			'role' => 'user',
			'text' => $current_layout ? $this->build_refine_prompt( $current_layout, $user_prompt ) : $user_prompt,
		);
		if ( $image ) {
			$turn['image'] = $image;
		}

		$template = $this->ask_gemini( $api_key, array( $turn ) );
		if ( is_wp_error( $template ) ) {
			wp_send_json_error( array( 'message' => $this->friendly_ai_error( $template ) ), 502 );
		}

		wp_send_json_success( $this->draft_payload( $template, $user_prompt ) );
	}

	/**
	 * Wraps the instruction for a refinement turn: explicitly hands the
	 * current layout back to the model along with the requested change.
	 * This IS the chat's "memory".
	 *
	 * @param array{trigger_plugin:string,action_type:string,paper_size:string,html_template:string,editable_fields:array} $current_layout
	 */
	private function build_refine_prompt( array $current_layout, string $user_prompt ): string {
		$layout_json = wp_json_encode( $current_layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return "CURRENT_LAYOUT (JSON, same schema as your output):\n{$layout_json}\n\n"
			. "USER REQUEST: {$user_prompt}\n\n"
			. 'Apply ONLY the requested change to CURRENT_LAYOUT and return the COMPLETE updated JSON with the same schema. '
			. 'Do NOT change trigger_plugin, action_type, or paper_size unless the user explicitly asked to. '
			. 'Keep every unrelated part of html_template and editable_fields exactly as given.';
	}

	/**
	 * Decodes the layout JSON sent by the client (the chat's previous
	 * state) and runs it through the same validation as an AI response.
	 * Any problem (empty, invalid JSON, corrupted structure) is treated as
	 * "this is the first message" rather than a fatal error: the chat
	 * simply starts generation from scratch.
	 *
	 * @return array{trigger_plugin:string,action_type:string,paper_size:string,html_template:string,editable_fields:array}|null
	 */
	private function decode_client_layout( string $json ): ?array {
		if ( '' === trim( $json ) ) {
			return null;
		}

		$parsed = json_decode( $json, true );
		if ( ! is_array( $parsed ) ) {
			return null;
		}

		$normalized = $this->normalize_template( $parsed );
		return is_wp_error( $normalized ) ? null : $normalized;
	}

	/**
	 * A friendly chat message instead of a technical detail — used when the
	 * AI returned an empty response or broken/incomplete JSON. Real
	 * HTTP/network errors (API key, rate limits) are passed through as-is,
	 * since those are actionable.
	 */
	private function friendly_ai_error( WP_Error $error ): string {
		$shape_errors = array( 'aipdf_bad_json', 'aipdf_missing_field', 'aipdf_empty_html', 'aipdf_gemini_empty' );

		if ( in_array( $error->get_error_code(), $shape_errors, true ) ) {
			return __( 'Could not generate the layout. Try rephrasing your request.', 'ai-pdf-generator' );
		}

		return $error->get_error_message();
	}

	/**
	 * Prepares a reference image for Gemini's inline_data.
	 *
	 * @param mixed $attachment_id Media library attachment ID.
	 * @return array{mime:string,data:string}|null base64 data, or null.
	 */
	private function build_reference_image( $attachment_id ): ?array {
		$id = absint( $attachment_id );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return null;
		}

		$mime = (string) get_post_mime_type( $id );
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) {
			return null;
		}

		$path = get_attached_file( $id );
		if ( ! $path || ! file_exists( $path ) ) {
			return null;
		}

		// 5 MB limit — to avoid bloating the API request.
		$size = filesize( $path );
		if ( false === $size || $size > 5 * 1024 * 1024 ) {
			AIPDF_Logger::get_instance()->warning( 'Reference image too large (>5MB) — skipped.' );
			return null;
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local file inside uploads.
		if ( false === $bytes ) {
			return null;
		}

		return array(
			'mime' => $mime,
			'data' => base64_encode( $bytes ),
		);
	}

	/**
	 * SAVES the finalized draft as a CPT post.
	 */
	public function handle_save(): void {
		check_ajax_referer( 'aipdf_generate', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-pdf-generator' ) ), 403 );
		}

		// Draft data from the client — re-validate/sanitize it just like an AI response.
		$raw = array(
			'trigger_plugin'  => isset( $_POST['trigger_plugin'] ) ? wp_unslash( $_POST['trigger_plugin'] ) : '',
			'action_type'     => isset( $_POST['action_type'] ) ? wp_unslash( $_POST['action_type'] ) : '',
			'paper_size'      => isset( $_POST['paper_size'] ) ? wp_unslash( $_POST['paper_size'] ) : '',
			'html_template'   => isset( $_POST['html_template'] ) ? wp_unslash( $_POST['html_template'] ) : '',
			'editable_fields' => isset( $_POST['editable_fields'] ) ? json_decode( wp_unslash( $_POST['editable_fields'] ), true ) : array(),
		);

		$template = $this->normalize_template( $raw );
		if ( is_wp_error( $template ) ) {
			wp_send_json_error( array( 'message' => $template->get_error_message() ), 422 );
		}

		$user_prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( '' === $user_prompt ) {
			$user_prompt = __( 'PDF Template', 'ai-pdf-generator' );
		}

		$post_id = $this->save_template( $user_prompt, $template );
		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'post_id'       => $post_id,
				'edit_link'     => get_edit_post_link( $post_id, 'raw' ),
				'test_pdf_url'  => AIPDF_Admin_Page::get_test_pdf_url( $post_id ),
				'pdf_available' => AIPDF_PDF_Renderer::is_available(),
			)
		);
	}

	/**
	 * Calls Gemini + parses/validates the response. Returns a normalized
	 * template or a WP_Error (with logging).
	 *
	 * @param array<int, array{role:string,text:string}> $contents
	 * @return array|WP_Error
	 */
	private function ask_gemini( string $api_key, array $contents ) {
		$ai_response = $this->request_gemini( $api_key, $contents );
		if ( is_wp_error( $ai_response ) ) {
			AIPDF_Logger::get_instance()->error( 'Gemini API: ' . $ai_response->get_error_message() );
			return $ai_response;
		}

		$template = $this->parse_and_validate( $ai_response );
		if ( is_wp_error( $template ) ) {
			AIPDF_Logger::get_instance()->error(
				sprintf(
					'Invalid Gemini response (%s): %s Response start: %s',
					$template->get_error_code(),
					$template->get_error_message(),
					mb_substr( $ai_response, 0, 500 )
				)
			);
		}

		return $template;
	}

	/**
	 * Builds the draft payload for the client (without creating a post).
	 *
	 * @param array  $template    Normalized template.
	 * @param string $base_prompt Original request (used as the title on save).
	 * @return array<string, mixed>
	 */
	private function draft_payload( array $template, string $base_prompt ): array {
		return array(
			'draft'           => true,
			'base_prompt'     => $base_prompt,
			'trigger_plugin'  => $template['trigger_plugin'],
			'action_type'     => $template['action_type'],
			'paper_size'      => $template['paper_size'],
			'html_template'   => $template['html_template'],
			'editable_fields' => $template['editable_fields'],
			// The client stores this VERBATIM and sends it back with the
			// next chat message — the single source of "memory" for the state.
			'current_layout'  => wp_json_encode( $template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			// Ready-to-show preview: skeleton + field values + sample data.
			'preview_html'    => AIPDF_PDF_Renderer::substitute(
				$template['html_template'],
				array_merge(
					AIPDF_PDF_Renderer::sample_data(),
					AIPDF_Fields::values( $template['editable_fields'] )
				)
			),
		);
	}

	/**
	 * Strict JSON schema for the response (Gemini structured output).
	 * Constrains `trigger_plugin` to the same dynamic list of available
	 * triggers shown in the prompt — the model physically cannot return a
	 * trigger for a disabled plugin or malform the editable_fields shape.
	 *
	 * @return array<string, mixed>
	 */
	private function response_schema(): array {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'trigger_plugin' => array(
					'type' => 'STRING',
					'enum' => AIPDF_Triggers::available(),
				),
				'action_type'    => array(
					'type' => 'STRING',
					'enum' => array( 'attach_to_email', 'download_link' ),
				),
				'paper_size'     => array( 'type' => 'STRING' ),
				'html_template'  => array( 'type' => 'STRING' ),
				'editable_fields' => array(
					'type'  => 'ARRAY',
					'items' => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'key'   => array( 'type' => 'STRING' ),
							'type'  => array(
								'type' => 'STRING',
								'enum' => array( 'color', 'text', 'textarea' ),
							),
							'label' => array( 'type' => 'STRING' ),
							'value' => array( 'type' => 'STRING' ),
						),
						'required' => array( 'key', 'type', 'label', 'value' ),
					),
				),
			),
			'required'   => array( 'trigger_plugin', 'action_type', 'paper_size', 'html_template', 'editable_fields' ),
		);
	}

	/**
	 * Calls the Gemini API via wp_remote_post.
	 *
	 * The key is sent in the x-goog-api-key header (not the URL), so it
	 * doesn't end up in proxy/server logs.
	 *
	 * @param array<int, array{role:string,text:string}> $contents Conversation turns.
	 * @return string|WP_Error Raw text of the model's response.
	 */
	private function request_gemini( string $api_key, array $contents ) {
		// Model comes from settings (a safety net against Google
		// deprecating it); the filter remains for programmatic overrides.
		$model = (string) get_option( self::OPTION_MODEL, self::DEFAULT_MODEL );
		if ( '' === trim( $model ) ) {
			$model = self::DEFAULT_MODEL;
		}
		$model = apply_filters( 'aipdf_gemini_model', $model );
		$url   = sprintf( self::GEMINI_ENDPOINT, rawurlencode( $model ) );

		$mapped = array();
		foreach ( $contents as $turn ) {
			$parts = array();
			// Reference image (inline_data) goes before the text.
			if ( ! empty( $turn['image']['data'] ) ) {
				$parts[] = array(
					'inline_data' => array(
						'mime_type' => (string) ( $turn['image']['mime'] ?? 'image/png' ),
						'data'      => (string) $turn['image']['data'],
					),
				);
			}
			$parts[] = array( 'text' => (string) ( $turn['text'] ?? '' ) );

			$mapped[] = array(
				'role'  => ( 'model' === ( $turn['role'] ?? 'user' ) ) ? 'model' : 'user',
				'parts' => $parts,
			);
		}

		$body = array(
			'system_instruction' => array(
				'parts' => array(
					array( 'text' => $this->get_system_prompt() ),
				),
			),
			'contents'           => $mapped,
			'generationConfig'   => array(
				// response_mime_type by itself only "asks" for JSON-ish
				// text — in practice Gemini occasionally returns
				// syntactically broken JSON (e.g. a stray closing brace).
				// response_schema forces the API to guarantee
				// structurally valid JSON in exactly this shape.
				'response_mime_type' => 'application/json',
				'response_schema'    => $this->response_schema(),
				'temperature'        => 0.4,
				'maxOutputTokens'    => 16384,
				// Thinking is disabled: it's unnecessary for template
				// generation, and "thoughts" eat into the token budget
				// and can truncate the JSON.
				'thinkingConfig'     => array( 'thinkingBudget' => 0 ),
			),
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$api_message = $data['error']['message'] ?? __( 'Unknown API error.', 'ai-pdf-generator' );
			return new WP_Error(
				'aipdf_gemini_http',
				sprintf(
					/* translators: 1: HTTP code, 2: API error message. */
					__( 'Gemini API returned error %1$d: %2$s', 'ai-pdf-generator' ),
					$code,
					$api_message
				)
			);
		}

		$candidate = $data['candidates'][0] ?? array();

		// Diagnose truncated/blocked responses right away, into the log.
		$finish_reason = $candidate['finishReason'] ?? '';
		if ( '' !== $finish_reason && 'STOP' !== $finish_reason ) {
			AIPDF_Logger::get_instance()->warning( 'Gemini finishReason=' . $finish_reason . ' — the response may be incomplete.' );
		}

		// Concatenate all text parts (thinking models can split the response).
		$text = '';
		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			if ( empty( $part['thought'] ) && isset( $part['text'] ) ) {
				$text .= $part['text'];
			}
		}

		if ( '' === $text ) {
			return new WP_Error( 'aipdf_gemini_empty', __( 'Gemini returned an empty response.', 'ai-pdf-generator' ) );
		}

		return $text;
	}

	/**
	 * Parses and validates the JSON from the model.
	 *
	 * @return array{trigger_plugin:string,action_type:string,paper_size:string,html_template:string}|WP_Error
	 */
	private function parse_and_validate( string $raw ) {
		// Guard against the model's "over-politeness": strip any ```json … ``` fences.
		$raw = trim( $raw );
		$raw = preg_replace( '/^```(?:json)?\s*|\s*```$/', '', $raw );

		$parsed = json_decode( $raw, true );
		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'aipdf_bad_json', __( 'The model\'s response is not valid JSON.', 'ai-pdf-generator' ) );
		}

		return $this->normalize_template( $parsed );
	}

	/**
	 * Validates/sanitizes the template structure (shared between the
	 * Gemini response and a draft submitted by the client on save).
	 *
	 * @param array<string, mixed> $parsed
	 * @return array{trigger_plugin:string,action_type:string,paper_size:string,html_template:string,editable_fields:array}|WP_Error
	 */
	private function normalize_template( array $parsed ) {
		foreach ( array( 'trigger_plugin', 'action_type', 'paper_size', 'html_template' ) as $field ) {
			if ( empty( $parsed[ $field ] ) || ! is_string( $parsed[ $field ] ) ) {
				return new WP_Error(
					'aipdf_missing_field',
					sprintf(
						/* translators: %s: field name. */
						__( 'The model\'s response is missing the "%s" field.', 'ai-pdf-generator' ),
						$field
					)
				);
			}
		}

		// trigger_plugin and action_type — only from the allowed list.
		// AIPDF_Triggers::sanitize preserves "/" (elementor_pro/forms/new_record).
		$trigger = AIPDF_Triggers::sanitize( $parsed['trigger_plugin'] );
		if ( ! in_array( $trigger, AIPDF_Triggers::all(), true ) ) {
			$trigger = 'manual_generation';
		}

		$action = sanitize_key( $parsed['action_type'] );
		if ( ! in_array( $action, AIPDF_Plugin::ALLOWED_ACTIONS, true ) ) {
			$action = 'download_link';
		}

		// paper_size: A4 / Letter / 800x400 etc. — safe characters only.
		$paper = sanitize_text_field( $parsed['paper_size'] );
		if ( ! preg_match( '/^[A-Za-z0-9x\- ]{1,20}$/', $paper ) ) {
			$paper = 'A4';
		}

		// Sanitize the HTML: strips <script>, onclick, etc., but keeps
		// tables, inline style, <img>, and mPDF's native <barcode> tag
		// (a plain wp_kses_post() would strip <barcode> as an unknown tag).
		$html = AIPDF_PDF_Renderer::sanitize_html( $parsed['html_template'] );
		if ( '' === trim( $html ) ) {
			return new WP_Error( 'aipdf_empty_html', __( 'The HTML template is empty after sanitization.', 'ai-pdf-generator' ) );
		}

		// Visual fields (colors, headings, captions) — the source of truth
		// for editing. The skeleton references them as {{field_key}}.
		$fields = AIPDF_Fields::normalize( $parsed['editable_fields'] ?? array() );

		// Deterministic safety net: gemini-flash occasionally hardcodes
		// #hex colors directly in the markup despite the prompt forbidding
		// it. Regardless of whether the AI complied, find any hex colors
		// still in the HTML, extract them into {{auto_color_N}}, and add a
		// color field. The editor stays bulletproof even when the model doesn't comply.
		$extracted = AIPDF_Fields::extract_hardcoded_colors( $html, $fields );
		$html      = $extracted['html'];
		$fields    = array_merge( $fields, $extracted['fields'] );

		// Same safety net for QR/barcode content: a hardcoded value inside
		// <barcode code="..."> would otherwise be invisible and uneditable
		// in the UI. Extract it into a text field so the user can see and
		// change exactly what the QR code encodes.
		$qr_extracted = AIPDF_Fields::extract_qr_values( $html, $fields );
		$html         = $qr_extracted['html'];
		$fields       = array_merge( $fields, $qr_extracted['fields'] );

		// Drop "orphaned" fields not present in the skeleton, so the editor
		// doesn't show controls that don't affect anything.
		$fields = array_values(
			array_filter(
				$fields,
				static function ( $field ) use ( $html ) {
					return (bool) preg_match( '/\{\{\s*' . preg_quote( $field['key'], '/' ) . '\s*\}\}/i', $html );
				}
			)
		);

		return array(
			'trigger_plugin'  => $trigger,
			'action_type'     => $action,
			'paper_size'      => $paper,
			'html_template'   => $html,
			'editable_fields' => $fields,
		);
	}

	/**
	 * Creates the CPT post and saves its meta.
	 *
	 * @param array{trigger_plugin:string,action_type:string,paper_size:string,html_template:string} $template Validated data.
	 * @return int|WP_Error New post ID.
	 */
	private function save_template( string $user_prompt, array $template ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => AIPDF_Plugin::CPT,
				'post_status'  => 'publish',
				'post_title'   => wp_trim_words( $user_prompt, 10, '…' ),
				'post_content' => $template['html_template'],
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_aipdf_trigger_plugin', $template['trigger_plugin'] );
		update_post_meta( $post_id, '_aipdf_action_type', $template['action_type'] );
		update_post_meta( $post_id, '_aipdf_paper_size', $template['paper_size'] );
		AIPDF_Fields::save( $post_id, $template['editable_fields'] ?? array() );

		return $post_id;
	}

	/**
	 * System instruction for Gemini: trigger-selection rules and HTML
	 * requirements for the PDF converter.
	 */
	private function get_system_prompt(): string {
		// Catalog of available triggers with their contextual placeholders
		// — so the AI only picks an event that's actually present and uses its data.
		$lines = array();
		foreach ( AIPDF_Triggers::available() as $key ) {
			$ph = implode( ', ', AIPDF_Triggers::placeholders( $key ) );
			$lines[] = sprintf( '- %s — %s. Placeholders: %s', $key, AIPDF_Triggers::label( $key ), $ph );
		}
		$trigger_block = implode( "\n", $lines );
		$trigger_keys  = implode( ', ', AIPDF_Triggers::available() );

		return <<<PROMPT
You are a Senior WordPress Developer and an AI assistant for a PDF generation plugin.
Analyze the user's request, understand WHERE and WHEN they want to generate a PDF, and create the HTML template.

TRIGGER RULES (trigger_plugin) — pick EXACTLY ONE key from this list of AVAILABLE triggers (each shows the placeholders you may use for that context):
{$trigger_block}

Return the trigger key verbatim (e.g. "woocommerce_payment_complete"). If nothing fits, use "manual_generation". Allowed keys: {$trigger_keys}.

REFERENCE IMAGE: if an image is attached, treat it as a visual reference — replicate its layout, structure, color scheme and overall style as closely as possible within the table-based HTML constraints. Turn its colors/titles into editable_fields. This applies on ANY turn, not just the first message — if the user attaches a new reference while refining an existing layout, incorporate it into the update too.

CHAT / REFINEMENT MODE: the user message may start with "CURRENT_LAYOUT (JSON, same schema as your output):" followed by the existing layout and a "USER REQUEST:" line. When you see this, you are editing an EXISTING template, not starting fresh — apply only the requested change and return the complete updated JSON in the exact same schema, preserving everything not explicitly asked to change.

HTML RULES (html_template):
1. Only basic HTML with inline CSS (style="...").
2. STRICTLY FORBIDDEN: CSS Grid, Flexbox, calc(). Use classic <table> layout or position: absolute.
3. Use ONLY placeholders as data — never real values. Prefer the placeholders listed for the chosen trigger above, plus the always-available {{client_name}}, {{email}}, {{date}}, {{qr_code}}.
4. For graphics use <img> with hard-coded width and height attributes.
5. Do NOT include <script>, <style> blocks, event handlers, or external CSS.
6. BRANDING — never hardcode a logo, company name/address/email, or brand color. ALWAYS use these placeholders so the user can change them later without editing HTML:
   - Logo: <img src="{{logo_url}}" width="200" height="70" alt="Logo" /> (never write the word "LOGO" as text).
   - Brand/accent color: use {{brand_color}} inside inline styles, e.g. style="color: {{brand_color}}" or style="background-color: {{brand_color}}".
   - Seller/company details: {{company_name}}, {{company_address}}, {{company_email}}. Do NOT invent a company name like "WooCommerce Store".
7. QR CODES — mandatory native format. This PDF converter is mPDF, which generates QR codes natively — you do NOT need <img> tags or any external API/service for QR codes. If the user requests a QR code (or a ticket/badge/pass that implies one), DO NOT use an <img> tag and DO NOT reference {{qr_code}} as an image URL. You MUST use mPDF's native barcode tag instead:
   <barcode code="{{placeholder_or_url}}" type="QR" size="1" />
   You can use any dynamic placeholder inside the "code" attribute, for example:
   <barcode code="{{ticket_id}}" type="QR" size="1.5" />
   Adjust "size" from 0.5 (small) to 2.0 (large) depending on the document's layout and importance of the code.
   If the QR code should encode a fixed, static value (a specific URL, a fixed ID) rather than trigger data, put that value in an editable_field (type "text") and reference it in the "code" attribute — same reasoning as colors: never leave a static value hardcoded where the user can't see or change it.

EDITABLE FIELDS (editable_fields) — the KEY feature. The user must be able to visually edit the template WITHOUT touching HTML. So:
- Extract every STATIC, human-editable piece of content into an editable field: headings/titles, captions, static labels ("Invoice", "Thank you", "Total:"), accent colors, background colors, footer notes, button texts. NOT dynamic data (client_name, order_id, dates) — those stay as data placeholders from the trigger list.
- In html_template reference each field as {{field_key}} (snake_case, unique). Example: <h1 style="color: {{accent_color}}">{{heading}}</h1>.
- MANDATORY: every key you list in editable_fields MUST actually appear in html_template as {{key}} at least once. Do NOT declare a field you don't use (e.g. a background color you never apply). If you introduce a color/title/caption, wire it into the HTML via its placeholder.
- COLORS ARE MANDATORY FIELDS: html_template must contain NO raw hex colors. Every single color you use — text, borders, backgrounds, accents — must come from either {{brand_color}} or a "color" editable_field placeholder. Writing style="color: #333333" is FORBIDDEN; write style="color: {{text_color}}" and declare text_color as a color field instead. Always include at least 2 color fields (e.g. accent_color, text_color) so the user can re-skin the document.
- If a reference image is attached, derive the color field VALUES from the dominant colors you actually see in that image.
- Return an "editable_fields" array. Each item: {"key","type","label","value"}.
  - "type" is one of: "color" (hex value like #1a1a2e), "text" (short single line), "textarea" (multi-line).
  - "label" is a short human label in the user's language (e.g. "Heading", "Accent Color").
  - "value" is a sensible default that matches what you put in the HTML.
- Aim for 3–8 editable fields. Do NOT duplicate brand placeholders ({{logo_url}}, {{brand_color}}, {{company_name}}…) as editable fields — those are managed separately.

OUTPUT FORMAT: respond with VALID JSON ONLY, no Markdown fences, no surrounding text:
{"trigger_plugin": "...", "action_type": "attach_to_email or download_link", "paper_size": "A4 | Letter | 800x400 | ...", "html_template": "HTML skeleton using {{field_key}} and data placeholders", "editable_fields": [{"key":"heading","type":"text","label":"Heading","value":"INVOICE"},{"key":"accent_color","type":"color","label":"Accent Color","value":"#1a1a2e"}]}
PROMPT;
	}
}
