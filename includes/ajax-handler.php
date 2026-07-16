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
	 * Модель за замовчуванням: аліас, який Google завжди тримає
	 * на актуальній flash-моделі — не ламається при deprecation.
	 */
	public const DEFAULT_MODEL = 'gemini-flash-latest';

	private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	public function __construct() {
		// Лише для залогінених адмінів — wp_ajax_nopriv не реєструємо свідомо.
		add_action( 'wp_ajax_aipdf_chat', array( $this, 'handle_chat' ) );
		add_action( 'wp_ajax_aipdf_save', array( $this, 'handle_save' ) );
	}

	/**
	 * Спільна перевірка доступу + отримання ключа. Завершує запит помилкою,
	 * якщо щось не так; інакше повертає API-ключ.
	 */
	private function guard_and_key(): string {
		check_ajax_referer( 'aipdf_generate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'ai-pdf-generator' ) ), 403 );
		}

		// Константа з wp-config.php має пріоритет над опцією в БД.
		$api_key = defined( 'AIPDF_GEMINI_API_KEY' )
			? (string) AIPDF_GEMINI_API_KEY
			: (string) get_option( AIPDF_Plugin::OPTION_API_KEY, '' );
		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => __( 'Спершу збережіть Gemini API Key у налаштуваннях.', 'ai-pdf-generator' ) ), 400 );
		}

		return $api_key;
	}

	/**
	 * ЄДИНА точка входу чату: перше повідомлення І кожне наступне уточнення
	 * проходять один і той самий код — це усуває клас багів, коли generate
	 * і refine розходились (напр. референс, який передавався лише при
	 * першій генерації, але губився при уточненні).
	 *
	 * Клієнт зберігає повний `current_layout` (JSON) після кожної успішної
	 * відповіді і надсилає його назад разом із наступним повідомленням —
	 * це і є «пам'ять» чату, а не наростаюча історія реплік.
	 *
	 * Пост НЕ створюється: лише превю, до явного «Зберегти шаблон».
	 */
	public function handle_chat(): void {
		$api_key = $this->guard_and_key();

		$user_prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( '' === $user_prompt ) {
			wp_send_json_error( array( 'message' => __( 'Повідомлення порожнє.', 'ai-pdf-generator' ) ), 400 );
		}

		// Поточний макет (якщо це не перше повідомлення в чаті).
		$current_layout_json = isset( $_POST['current_layout'] ) ? (string) wp_unslash( $_POST['current_layout'] ) : '';
		$current_layout       = $this->decode_client_layout( $current_layout_json );

		// Зображення-референс (WP Media) — на БУДЬ-якому повідомленні,
		// не лише на першому: той самий код для generate і refine.
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
	 * Обгортка інструкції для ходу-уточнення: явно передає модель поточного
	 * макета назад разом із запитом на зміну. Саме це — «пам'ять» чату.
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
	 * Декодує JSON макета, надісланий клієнтом (попередній стан чату),
	 * і проганяє через ту саму валідацію, що й відповідь AI. Будь-яка
	 * проблема (порожньо, невалідний JSON, зіпсована структура) —
	 * трактується як «це перше повідомлення», а не як фатальна помилка:
	 * чат просто почне генерацію з нуля.
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
	 * Дружнє повідомлення для чату замість технічної деталі — коли AI
	 * повернула порожню відповідь або зламаний/неповний JSON. Реальні
	 * HTTP/мережеві помилки (ключ, ліміти) лишаються як є — вони дієві.
	 */
	private function friendly_ai_error( WP_Error $error ): string {
		$shape_errors = array( 'aipdf_bad_json', 'aipdf_missing_field', 'aipdf_empty_html', 'aipdf_gemini_empty' );

		if ( in_array( $error->get_error_code(), $shape_errors, true ) ) {
			return __( 'Не вдалося згенерувати структуру. Спробуйте переформулювати запит.', 'ai-pdf-generator' );
		}

		return $error->get_error_message();
	}

	/**
	 * Готує зображення-референс для Gemini inline_data.
	 *
	 * @param mixed $attachment_id ID вкладення з медіатеки.
	 * @return array{mime:string,data:string}|null base64-дані або null.
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

		// Ліміт 5 МБ — щоб не роздувати запит до API.
		$size = filesize( $path );
		if ( false === $size || $size > 5 * 1024 * 1024 ) {
			AIPDF_Logger::get_instance()->warning( 'Референс-зображення завелике (>5MB) — пропущено.' );
			return null;
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- локальний файл в uploads.
		if ( false === $bytes ) {
			return null;
		}

		return array(
			'mime' => $mime,
			'data' => base64_encode( $bytes ),
		);
	}

	/**
	 * ЗБЕРЕЖЕННЯ фіналізованої чернетки як CPT.
	 */
	public function handle_save(): void {
		check_ajax_referer( 'aipdf_generate', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'ai-pdf-generator' ) ), 403 );
		}

		// Дані чернетки з клієнта — повторно валідуємо/санітизуємо як від AI.
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
			$user_prompt = __( 'Шаблон PDF', 'ai-pdf-generator' );
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
	 * Виклик Gemini + парсинг/валідація. Повертає нормалізований шаблон
	 * або WP_Error (з логуванням).
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
					'Невалідна відповідь Gemini (%s): %s Початок відповіді: %s',
					$template->get_error_code(),
					$template->get_error_message(),
					mb_substr( $ai_response, 0, 500 )
				)
			);
		}

		return $template;
	}

	/**
	 * Формує payload чернетки для клієнта (без створення поста).
	 *
	 * @param array  $template   Нормалізований шаблон.
	 * @param string $base_prompt Початковий запит (для title при збереженні).
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
			// Клієнт зберігає це ДОСЛІВНО і надсилає назад із наступним
			// повідомленням чату — єдине джерело «пам'яті» стану.
			'current_layout'  => wp_json_encode( $template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			// Готове превю: каркас + значення полів + демо-дані.
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
	 * Запит до Gemini API через wp_remote_post.
	 *
	 * Ключ передаємо у заголовку x-goog-api-key (не в URL),
	 * щоб він не потрапляв у логи проксі/серверів.
	 *
	 * @param array<int, array{role:string,text:string}> $contents Ходи розмови.
	 * @return string|WP_Error Сирий текст відповіді моделі.
	 */
	/**
	 * Строга JSON-схема відповіді (Gemini structured output). Обмежує
	 * `trigger_plugin` тим самим динамічним переліком доступних тригерів,
	 * що й система показує в промпті, — модель фізично не зможе повернути
	 * тригер вимкненого плагіна чи зламати форму editable_fields.
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

	private function request_gemini( string $api_key, array $contents ) {
		// Модель — з налаштувань (захист від deprecation у Google),
		// фільтр залишається для програмного перевизначення.
		$model = (string) get_option( self::OPTION_MODEL, self::DEFAULT_MODEL );
		if ( '' === trim( $model ) ) {
			$model = self::DEFAULT_MODEL;
		}
		$model = apply_filters( 'aipdf_gemini_model', $model );
		$url   = sprintf( self::GEMINI_ENDPOINT, rawurlencode( $model ) );

		$mapped = array();
		foreach ( $contents as $turn ) {
			$parts = array();
			// Зображення-референс (inline_data) — перед текстом.
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
				// response_mime_type сам собою лише «просить» JSON-подібний текст —
				// на практиці Gemini подеколи повертає синтаксично зламаний JSON
				// (напр. зайву закриваючу дужку). response_schema змушує API
				// гарантувати структурно коректний JSON саме цієї форми.
				'response_mime_type' => 'application/json',
				'response_schema'    => $this->response_schema(),
				'temperature'        => 0.4,
				'maxOutputTokens'    => 16384,
				// Thinking вимкнено: для генерації шаблону воно зайве,
				// а «думки» з'їдають ліміт токенів і обрізають JSON.
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

		$candidate = $data['candidates'][0] ?? array();

		// Діагностика обрізаних/заблокованих відповідей — одразу в лог.
		$finish_reason = $candidate['finishReason'] ?? '';
		if ( '' !== $finish_reason && 'STOP' !== $finish_reason ) {
			AIPDF_Logger::get_instance()->warning( 'Gemini finishReason=' . $finish_reason . ' — відповідь може бути неповною.' );
		}

		// Склеюємо всі текстові частини (thinking-моделі можуть ділити відповідь).
		$text = '';
		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			if ( empty( $part['thought'] ) && isset( $part['text'] ) ) {
				$text .= $part['text'];
			}
		}

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

		return $this->normalize_template( $parsed );
	}

	/**
	 * Валідація/санітизація структури шаблону (спільна для відповіді Gemini
	 * і для чернетки, що приходить від клієнта при збереженні).
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
						__( 'У відповіді моделі відсутнє поле «%s».', 'ai-pdf-generator' ),
						$field
					)
				);
			}
		}

		// trigger_plugin та action_type — лише зі списку дозволених.
		// AIPDF_Triggers::sanitize зберігає «/» (elementor_pro/forms/new_record).
		$trigger = AIPDF_Triggers::sanitize( $parsed['trigger_plugin'] );
		if ( ! in_array( $trigger, AIPDF_Triggers::all(), true ) ) {
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

		// Візуальні поля (кольори, заголовки, підписи) — джерело правди для
		// редагування. Каркас використовує їх як {{field_key}}.
		$fields = AIPDF_Fields::normalize( $parsed['editable_fields'] ?? array() );

		// Детерміністичний запобіжник: gemini-flash подеколи хардкодить #hex
		// прямо в розмітці попри заборону в промпті. Незалежно від того,
		// послухалась AI чи ні, — знаходимо будь-які hex-кольори, що лишились
		// у HTML, виносимо їх у {{auto_color_N}} і додаємо color-поле.
		// Редактор лишається куленепробивним навіть при непослуху моделі.
		$extracted = AIPDF_Fields::extract_hardcoded_colors( $html, $fields );
		$html      = $extracted['html'];
		$fields    = array_merge( $fields, $extracted['fields'] );

		// Відкидаємо «сирітські» поля, яких немає в каркасі, — щоб у редакторі
		// не було контролів, що ні на що не впливають.
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
		AIPDF_Fields::save( $post_id, $template['editable_fields'] ?? array() );

		return $post_id;
	}

	/**
	 * Системна інструкція для Gemini: правила вибору тригера
	 * та вимоги до HTML під PDF-конвертери.
	 */
	private function get_system_prompt(): string {
		// Каталог доступних тригерів із їхніми контекстними плейсхолдерами —
		// щоб AI обирав лише наявну подію й використовував саме її дані.
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

EDITABLE FIELDS (editable_fields) — the KEY feature. The user must be able to visually edit the template WITHOUT touching HTML. So:
- Extract every STATIC, human-editable piece of content into an editable field: headings/titles, captions, static labels ("Invoice", "Thank you", "Total:"), accent colors, background colors, footer notes, button texts. NOT dynamic data (client_name, order_id, dates) — those stay as data placeholders from the trigger list.
- In html_template reference each field as {{field_key}} (snake_case, unique). Example: <h1 style="color: {{accent_color}}">{{heading}}</h1>.
- MANDATORY: every key you list in editable_fields MUST actually appear in html_template as {{key}} at least once. Do NOT declare a field you don't use (e.g. a background color you never apply). If you introduce a color/title/caption, wire it into the HTML via its placeholder.
- COLORS ARE MANDATORY FIELDS: html_template must contain NO raw hex colors. Every single color you use — text, borders, backgrounds, accents — must come from either {{brand_color}} or a "color" editable_field placeholder. Writing style="color: #333333" is FORBIDDEN; write style="color: {{text_color}}" and declare text_color as a color field instead. Always include at least 2 color fields (e.g. accent_color, text_color) so the user can re-skin the document.
- If a reference image is attached, derive the color field VALUES from the dominant colors you actually see in that image.
- Return an "editable_fields" array. Each item: {"key","type","label","value"}.
  - "type" is one of: "color" (hex value like #1a1a2e), "text" (short single line), "textarea" (multi-line).
  - "label" is a short human label in the user's language (e.g. "Заголовок", "Колір акценту").
  - "value" is a sensible default that matches what you put in the HTML.
- Aim for 3–8 editable fields. Do NOT duplicate brand placeholders ({{logo_url}}, {{brand_color}}, {{company_name}}…) as editable fields — those are managed separately.

OUTPUT FORMAT: respond with VALID JSON ONLY, no Markdown fences, no surrounding text:
{"trigger_plugin": "...", "action_type": "attach_to_email or download_link", "paper_size": "A4 | Letter | 800x400 | ...", "html_template": "HTML skeleton using {{field_key}} and data placeholders", "editable_fields": [{"key":"heading","type":"text","label":"Заголовок","value":"ІНВОЙС"},{"key":"accent_color","type":"color","label":"Колір акценту","value":"#1a1a2e"}]}
PROMPT;
	}
}
