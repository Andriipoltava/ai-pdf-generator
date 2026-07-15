<?php
/**
 * AJAX-обробник: приймає запит користувача, звертається до Gemini API,
 * валідує JSON-відповідь і зберігає шаблон у CPT.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Ajax_Handler {

	/**
	 * Опція з назвою моделі Gemini (налаштовується в адмінці).
	 */
	public const OPTION_MODEL = 'aipdf_gemini_model';

	/**
	 * Модель за замовчуванням, якщо опція порожня.
	 */
	public const DEFAULT_MODEL = 'gemini-1.5-flash';

	private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	public function __construct() {
		// Лише для залогінених адмінів — wp_ajax_nopriv не реєструємо свідомо.
		add_action( 'wp_ajax_aipdf_generate', array( $this, 'handle_generate' ) );
	}

	/**
	 * Точка входу AJAX.
	 */
	public function handle_generate(): void {
		check_ajax_referer( 'aipdf_generate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'ai-pdf-generator' ) ), 403 );
		}

		$user_prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( '' === $user_prompt ) {
			wp_send_json_error( array( 'message' => __( 'Запит порожній.', 'ai-pdf-generator' ) ), 400 );
		}

		// Константа з wp-config.php має пріоритет над опцією в БД:
		// define( 'AIPDF_GEMINI_API_KEY', '…' ); — ключ не потрапляє в БД/бекапи.
		$api_key = defined( 'AIPDF_GEMINI_API_KEY' )
			? (string) AIPDF_GEMINI_API_KEY
			: (string) get_option( AIPDF_Plugin::OPTION_API_KEY, '' );
		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => __( 'Спершу збережіть Gemini API Key у налаштуваннях.', 'ai-pdf-generator' ) ), 400 );
		}

		$ai_response = $this->request_gemini( $api_key, $user_prompt );
		if ( is_wp_error( $ai_response ) ) {
			AIPDF_Logger::get_instance()->error( 'Gemini API: ' . $ai_response->get_error_message() );
			wp_send_json_error( array( 'message' => $ai_response->get_error_message() ), 502 );
		}

		$template = $this->parse_and_validate( $ai_response );
		if ( is_wp_error( $template ) ) {
			AIPDF_Logger::get_instance()->error(
				sprintf( 'Невалідна відповідь Gemini (%s): %s', $template->get_error_code(), $template->get_error_message() )
			);
			wp_send_json_error( array( 'message' => $template->get_error_message() ), 422 );
		}

		$post_id = $this->save_template( $user_prompt, $template );
		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'post_id'        => $post_id,
				'edit_link'      => get_edit_post_link( $post_id, 'raw' ),
				'test_pdf_url'   => AIPDF_Admin_Page::get_test_pdf_url( $post_id ),
				'pdf_available'  => AIPDF_PDF_Renderer::is_available(),
				'trigger_plugin' => $template['trigger_plugin'],
				'action_type'    => $template['action_type'],
				'paper_size'     => $template['paper_size'],
				'html_template'  => $template['html_template'],
			)
		);
	}

	/**
	 * Запит до Gemini API через wp_remote_post.
	 *
	 * Ключ передаємо у заголовку x-goog-api-key (не в URL),
	 * щоб він не потрапляв у логи проксі/серверів.
	 *
	 * @return string|WP_Error Сирий текст відповіді моделі.
	 */
	private function request_gemini( string $api_key, string $user_prompt ) {
		// Модель — з налаштувань (захист від deprecation у Google),
		// фільтр залишається для програмного перевизначення.
		$model = (string) get_option( self::OPTION_MODEL, self::DEFAULT_MODEL );
		if ( '' === trim( $model ) ) {
			$model = self::DEFAULT_MODEL;
		}
		$model = apply_filters( 'aipdf_gemini_model', $model );
		$url   = sprintf( self::GEMINI_ENDPOINT, rawurlencode( $model ) );

		$body = array(
			'system_instruction' => array(
				'parts' => array(
					array( 'text' => $this->get_system_prompt() ),
				),
			),
			'contents'           => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => $user_prompt ),
					),
				),
			),
			'generationConfig'   => array(
				// Просимо модель віддавати строго JSON.
				'response_mime_type' => 'application/json',
				'temperature'        => 0.4,
				'maxOutputTokens'    => 8192,
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
			$api_message = $data['error']['message'] ?? __( 'Невідома помилка API.', 'ai-pdf-generator' );
			return new WP_Error(
				'aipdf_gemini_http',
				sprintf(
					/* translators: 1: HTTP code, 2: API error message. */
					__( 'Gemini API повернув помилку %1$d: %2$s', 'ai-pdf-generator' ),
					$code,
					$api_message
				)
			);
		}

		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
		if ( '' === $text ) {
			return new WP_Error( 'aipdf_gemini_empty', __( 'Gemini повернув порожню відповідь.', 'ai-pdf-generator' ) );
		}

		return $text;
	}

	/**
	 * Парсинг та валідація JSON від моделі.
	 *
	 * @return array{trigger_plugin:string,action_type:string,paper_size:string,html_template:string}|WP_Error
	 */
	private function parse_and_validate( string $raw ) {
		// Захист від «зайвої ввічливості» моделі: зрізаємо можливі ```json … ``` огорожі.
		$raw = trim( $raw );
		$raw = preg_replace( '/^```(?:json)?\s*|\s*```$/', '', $raw );

		$parsed = json_decode( $raw, true );
		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'aipdf_bad_json', __( 'Відповідь моделі не є валідним JSON.', 'ai-pdf-generator' ) );
		}

		foreach ( array( 'trigger_plugin', 'action_type', 'paper_size', 'html_template' ) as $field ) {
			if ( empty( $parsed[ $field ] ) || ! is_string( $parsed[ $field ] ) ) {
				return new WP_Error(
					'aipdf_missing_field',
					sprintf(
						/* translators: %s: field name. */
						__( 'У відповіді моделі відсутнє поле «%s».', 'ai-pdf-generator' ),
						$field
					)
				);
			}
		}

		// trigger_plugin та action_type — лише зі списку дозволених.
		$trigger = sanitize_key( $parsed['trigger_plugin'] );
		if ( ! in_array( $trigger, AIPDF_Plugin::ALLOWED_TRIGGERS, true ) ) {
			$trigger = 'manual_generation';
		}

		$action = sanitize_key( $parsed['action_type'] );
		if ( ! in_array( $action, AIPDF_Plugin::ALLOWED_ACTIONS, true ) ) {
			$action = 'download_link';
		}

		// paper_size: A4 / Letter / 800x400 тощо — тільки безпечні символи.
		$paper = sanitize_text_field( $parsed['paper_size'] );
		if ( ! preg_match( '/^[A-Za-z0-9x\- ]{1,20}$/', $paper ) ) {
			$paper = 'A4';
		}

		// Санітизація HTML: wp_kses_post прибирає <script>, onclick тощо,
		// але зберігає таблиці, інлайнові style та <img>.
		$html = wp_kses_post( $parsed['html_template'] );
		if ( '' === trim( $html ) ) {
			return new WP_Error( 'aipdf_empty_html', __( 'HTML-шаблон порожній після санітизації.', 'ai-pdf-generator' ) );
		}

		return array(
			'trigger_plugin' => $trigger,
			'action_type'    => $action,
			'paper_size'     => $paper,
			'html_template'  => $html,
		);
	}

	/**
	 * Створення поста у CPT та збереження meta.
	 *
	 * @param array{trigger_plugin:string,action_type:string,paper_size:string,html_template:string} $template Валідовані дані.
	 * @return int|WP_Error ID нового поста.
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

		return $post_id;
	}

	/**
	 * Системна інструкція для Gemini: правила вибору тригера
	 * та вимоги до HTML під PDF-конвертери.
	 */
	private function get_system_prompt(): string {
		$triggers = implode( ', ', AIPDF_Plugin::ALLOWED_TRIGGERS );

		return <<<PROMPT
You are a Senior WordPress Developer and an AI assistant for a PDF generation plugin.
Analyze the user's request, understand WHERE and WHEN they want to generate a PDF, and create the HTML template.

TRIGGER RULES (trigger_plugin) — pick exactly one of: {$triggers}.
- wc_order_paid: WooCommerce order paid, receipt, invoice, product purchase.
- cf7_submit, wpforms_submit, gform_submit, ninja_forms_submit, formidable_submit, elementor_pro_form_submit, fluentform_submit, forminator_submit, wsform_submit, everest_forms_submit: submissions of the corresponding form plugins.
- amelia_booking_done, tec_event_booking, event_tickets_purchase, bookly_booking_done, event_espresso_registration, mec_booking_done, wc_bookings_done: bookings/events of the corresponding plugins.
- manual_generation: manual generation from admin, or when the system is not specified.

HTML RULES (html_template):
1. Only basic HTML with inline CSS (style="...").
2. STRICTLY FORBIDDEN: CSS Grid, Flexbox, calc(). Use classic <table> layout or position: absolute.
3. Use placeholders instead of real data: {{client_name}}, {{email}}, {{date}}, {{qr_code}}, {{order_id}}, {{order_total}}, {{ticket_id}}, {{booking_date}}, {{service_name}}.
4. For graphics use <img> with hard-coded width and height attributes.
5. Do NOT include <script>, <style> blocks, event handlers, or external CSS.

OUTPUT FORMAT: respond with VALID JSON ONLY, no Markdown fences, no surrounding text:
{"trigger_plugin": "...", "action_type": "attach_to_email or download_link", "paper_size": "A4 | Letter | 800x400 | ...", "html_template": "clean HTML document code"}
PROMPT;
	}
}
