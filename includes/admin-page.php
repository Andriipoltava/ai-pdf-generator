<?php
/**
 * Сторінка налаштувань та Playground в адмінці.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Admin_Page {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Тестова генерація PDF зі збереженого шаблону + посилання у списку CPT.
		add_action( 'admin_post_aipdf_test_pdf', array( $this, 'handle_test_pdf' ) );
		add_action( 'admin_post_aipdf_clear_log', array( $this, 'handle_clear_log' ) );
		add_filter( 'post_row_actions', array( $this, 'add_test_pdf_row_action' ), 10, 2 );
	}

	/**
	 * Nonce-захищений URL тестової генерації PDF для шаблону.
	 */
	public static function get_test_pdf_url( int $post_id ): string {
		// НЕ wp_nonce_url(): вона повертає HTML-екранований URL (&amp;),
		// який ламається при передачі через JSON у JS (сервер бачить
		// параметр `amp;post_id` і nonce-перевірка провалюється).
		return add_query_arg(
			array(
				'action'   => 'aipdf_test_pdf',
				'post_id'  => $post_id,
				'_wpnonce' => wp_create_nonce( 'aipdf_test_pdf_' . $post_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Кнопка «Тест PDF» у списку шаблонів CPT.
	 *
	 * @param array<string, string> $actions Наявні row actions.
	 */
	public function add_test_pdf_row_action( array $actions, WP_Post $post ): array {
		if ( AIPDF_Plugin::CPT === $post->post_type && current_user_can( 'manage_options' ) ) {
			$actions['aipdf_test'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::get_test_pdf_url( $post->ID ) ),
				esc_html__( 'Тест PDF', 'ai-pdf-generator' )
			);
		}

		return $actions;
	}

	/**
	 * Рендерить шаблон із демо-даними та віддає PDF на завантаження.
	 */
	public function handle_test_pdf(): void {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'aipdf_test_pdf_' . $post_id ) ) {
			wp_die( esc_html__( 'Недостатньо прав або протерміноване посилання.', 'ai-pdf-generator' ) );
		}

		$renderer = new AIPDF_PDF_Renderer();

		// Демо-дані для всіх стандартних плейсхолдерів.
		$sample_data = apply_filters(
			'aipdf_test_placeholder_data',
			array(
				'client_name' => 'Іван Петренко',
				'email'       => 'client@example.com',
				'order_id'    => '1024',
				'order_total' => '1250.00 UAH',
				'date'        => wp_date( get_option( 'date_format' ) ),
				'ticket_id'   => 'TCK-58291',
				'booking_date'=> wp_date( 'd.m.Y H:i', time() + WEEK_IN_SECONDS ),
				'service_name'=> 'Консультація',
				'qr_code'     => 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=TCK-58291',
			)
		);

		$pdf = $renderer->render( $post_id, $sample_data );

		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="aipdf-test-' . $post_id . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- бінарний PDF.
		exit;
	}

	/**
	 * Пункт меню верхнього рівня. CPT «Шаблони» чіпляється сюди
	 * автоматично через show_in_menu.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'AI PDF Generator', 'ai-pdf-generator' ),
			__( 'AI PDF', 'ai-pdf-generator' ),
			'manage_options',
			AIPDF_Plugin::ADMIN_SLUG,
			array( $this, 'render_page' ),
			'dashicons-pdf',
			58
		);

		// Явний перший підпункт з тим самим slug, що й батьківське меню.
		// Без нього CPT «Шаблони» (show_in_menu => ADMIN_SLUG) витісняє
		// сторінку налаштувань/Playground із підменю повністю.
		add_submenu_page(
			AIPDF_Plugin::ADMIN_SLUG,
			__( 'AI PDF Generator', 'ai-pdf-generator' ),
			__( 'Генератор і налаштування', 'ai-pdf-generator' ),
			'manage_options',
			AIPDF_Plugin::ADMIN_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Реєстрація опції API-ключа через Settings API —
	 * WordPress сам обробить nonce та збереження на options.php.
	 */
	public function register_settings(): void {
		register_setting(
			'aipdf_settings_group',
			AIPDF_Plugin::OPTION_API_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'default'           => '',
			)
		);

		register_setting(
			'aipdf_settings_group',
			AIPDF_Ajax_Handler::OPTION_MODEL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_model' ),
				'default'           => AIPDF_Ajax_Handler::DEFAULT_MODEL,
			)
		);

		// --- Брендинг ---
		register_setting( 'aipdf_settings_group', AIPDF_Brand::OPT_LOGO, array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );
		register_setting( 'aipdf_settings_group', AIPDF_Brand::OPT_COLOR, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_color' ),
			'default'           => AIPDF_Brand::DEFAULT_COLOR,
		) );
		register_setting( 'aipdf_settings_group', AIPDF_Brand::OPT_NAME, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'aipdf_settings_group', AIPDF_Brand::OPT_ADDRESS, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'aipdf_settings_group', AIPDF_Brand::OPT_EMAIL, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
			'default'           => '',
		) );

		register_setting(
			'aipdf_settings_group',
			AIPDF_Cron_Cleanup::OPTION_RETENTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
				'default'           => AIPDF_Cron_Cleanup::DEFAULT_RETENTION_DAYS,
			)
		);
	}

	/**
	 * HEX-колір; некоректне значення → колір бренду за замовчуванням.
	 */
	public function sanitize_color( $value ): string {
		$color = sanitize_hex_color( (string) $value );
		return $color ? $color : AIPDF_Brand::DEFAULT_COLOR;
	}

	/**
	 * Назва моделі: лише латиниця, цифри, крапки й дефіси.
	 * Некоректне значення → модель за замовчуванням.
	 */
	public function sanitize_model( $value ): string {
		$value = strtolower( trim( (string) $value ) );

		if ( '' === $value || ! preg_match( '/^[a-z0-9.\-]{3,60}$/', $value ) ) {
			return AIPDF_Ajax_Handler::DEFAULT_MODEL;
		}

		return $value;
	}

	/**
	 * 1–365 днів; усе некоректне → 7.
	 */
	public function sanitize_retention_days( $value ): int {
		$days = absint( $value );

		if ( $days < 1 || $days > 365 ) {
			return AIPDF_Cron_Cleanup::DEFAULT_RETENTION_DAYS;
		}

		return $days;
	}

	/**
	 * Якщо поле прийшло порожнім (адмін не хоче міняти ключ) —
	 * залишаємо попереднє значення, щоб не затерти ключ випадково.
	 */
	public function sanitize_api_key( ?string $value ): string {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return (string) get_option( AIPDF_Plugin::OPTION_API_KEY, '' );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * JS підключаємо лише на сторінці плагіна.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . AIPDF_Plugin::ADMIN_SLUG !== $hook_suffix ) {
			return;
		}

		// Медіатека (лого, референс) + WP Color Picker (динамічні поля-кольори чату).
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_script(
			'aipdf-admin',
			AIPDF_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			AIPDF_Plugin::asset_version( 'assets/admin.js' ),
			true
		);

		wp_localize_script(
			'aipdf-admin',
			'aipdfData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'aipdf_generate' ),
				// Демо-дані для клієнтського live-превю чату (той самий набір,
				// що й у рендері PDF та в редакторі шаблону).
				'sample'  => AIPDF_PDF_Renderer::sample_data(),
				'i18n'    => array(
					'sending'      => __( 'Надсилання…', 'ai-pdf-generator' ),
					'send'         => __( 'Відправити', 'ai-pdf-generator' ),
					'error'        => __( 'Сталася помилка. Спробуйте ще раз.', 'ai-pdf-generator' ),
					'emptyInput'   => __( 'Введіть повідомлення.', 'ai-pdf-generator' ),
					'saved'        => __( 'Шаблон збережено', 'ai-pdf-generator' ),
					'you'          => __( 'Ви', 'ai-pdf-generator' ),
					'assistant'    => __( 'AI', 'ai-pdf-generator' ),
					'updatedMsg'   => __( 'Готово. Тригер: %1$s · Дія: %2$s · Формат: %3$s.', 'ai-pdf-generator' ),
					'actionEmail'  => __( 'лист із вкладенням', 'ai-pdf-generator' ),
					'actionDl'     => __( 'посилання на завантаження', 'ai-pdf-generator' ),
					'welcomeMsg'   => __( 'Опишіть документ, який потрібно згенерувати, або почніть із готового прикладу нижче.', 'ai-pdf-generator' ),
				),
			)
		);
	}

	/**
	 * Розмітка сторінки: налаштування + Playground.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостатньо прав.', 'ai-pdf-generator' ) );
		}

		$api_key = (string) get_option( AIPDF_Plugin::OPTION_API_KEY, '' );

		// Смарт-плейсхолдери: показуємо групи лише для активних плагінів.
		$placeholder_groups = array(
			__( 'Базові (завжди)', 'ai-pdf-generator' ) => array( '{{client_name}}', '{{email}}', '{{date}}', '{{qr_code}}' ),
			__( 'Брендинг', 'ai-pdf-generator' )        => array( '{{logo_url}}', '{{brand_color}}', '{{company_name}}', '{{company_address}}', '{{company_email}}' ),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$placeholder_groups[ __( 'WooCommerce', 'ai-pdf-generator' ) ] = array( '{{order_id}}', '{{order_total}}' );
		}
		if ( defined( 'AMELIA_VERSION' ) || class_exists( '\AmeliaBooking\Plugin' ) ) {
			$placeholder_groups[ __( 'Amelia / бронювання', 'ai-pdf-generator' ) ] = array( '{{ticket_id}}', '{{booking_date}}', '{{service_name}}' );
		}

		// Заготовлені промпти для «Швидкого старту».
		$quickstart_prompts = array(
			__( 'Інвойс A4 для WooCommerce', 'ai-pdf-generator' )   => __( 'Створи стандартний інвойс A4 для WooCommerce. Зверху логотип, нижче таблиця з даними: номер замовлення {{order_id}}, клієнт {{client_name}}, сума {{order_total}}, дата {{date}}.', 'ai-pdf-generator' ),
			__( 'Квиток 800x400 для Amelia', 'ai-pdf-generator' )   => __( 'Створи індивідуальний квиток 800x400 px для Amelia. Додай іконку календаря, дату бронювання {{booking_date}}, назву послуги {{service_name}} та великий QR-код {{qr_code}}.', 'ai-pdf-generator' ),
			__( 'Лист-подяка після форми (Letter)', 'ai-pdf-generator' ) => __( 'Створи лист-подяку після заповнення форми у форматі Letter. Текст по центру, звернення до {{client_name}}, дата {{date}}.', 'ai-pdf-generator' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI PDF Generator', 'ai-pdf-generator' ); ?></h1>

			<?php if ( isset( $_GET['aipdf_log_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- лише інформаційний notice. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Лог очищено.', 'ai-pdf-generator' ); ?></p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper" id="aipdf-tabs">
				<a href="#playground" class="nav-tab nav-tab-active" data-tab="playground"><?php esc_html_e( 'Генератор (Playground)', 'ai-pdf-generator' ); ?></a>
				<a href="#settings" class="nav-tab" data-tab="settings"><?php esc_html_e( 'Налаштування', 'ai-pdf-generator' ); ?></a>
				<a href="#logs" class="nav-tab" data-tab="logs"><?php esc_html_e( 'Журнал подій', 'ai-pdf-generator' ); ?></a>
			</h2>

			<!-- ============ Вкладка 1: Генератор (чат) ============ -->
			<div id="aipdf-tab-playground" class="aipdf-tab" style="padding-top:16px;">

				<div class="aipdf-chat-layout" style="display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;">

					<!-- Ліва колонка: чат -->
					<div class="aipdf-chat-col" style="flex:1 1 420px;min-width:340px;max-width:560px;">

						<p>
							<label for="aipdf-quickstart"><strong><?php esc_html_e( 'Швидкий старт:', 'ai-pdf-generator' ); ?></strong></label><br />
							<select id="aipdf-quickstart" style="width:100%;margin-top:4px;">
								<option value=""><?php esc_html_e( '-- Виберіть готовий приклад --', 'ai-pdf-generator' ); ?></option>
								<?php foreach ( $quickstart_prompts as $label => $prompt_text ) : ?>
									<option value="<?php echo esc_attr( $prompt_text ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>

						<div class="aipdf-placeholders" style="margin:0 0 10px 0;padding:10px 12px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;">
							<strong style="display:block;margin-bottom:6px;font-size:12px;"><?php esc_html_e( 'Плейсхолдери — клік вставляє в поле вводу:', 'ai-pdf-generator' ); ?></strong>
							<?php foreach ( $placeholder_groups as $group_label => $tags ) : ?>
								<p style="margin:3px 0;">
									<span style="display:inline-block;min-width:110px;color:#646970;font-size:11px;"><?php echo esc_html( $group_label ); ?>:</span>
									<?php foreach ( $tags as $tag ) : ?>
										<code
											class="aipdf-ph"
											data-ph="<?php echo esc_attr( $tag ); ?>"
											title="<?php esc_attr_e( 'Клікніть, щоб вставити в повідомлення', 'ai-pdf-generator' ); ?>"
											style="cursor:pointer;margin:2px 3px 2px 0;padding:2px 6px;display:inline-block;border-radius:3px;font-size:11px;"
										><?php echo esc_html( $tag ); ?></code>
									<?php endforeach; ?>
								</p>
							<?php endforeach; ?>
						</div>

						<!-- Історія чату -->
						<div id="aipdf-chat-history" style="border:1px solid #ccd0d4;border-radius:4px;background:#fff;height:380px;overflow-y:auto;padding:12px;margin-bottom:8px;"></div>

						<!-- Референс-зображення (прикріплюється до наступного повідомлення) -->
						<p style="margin:0 0 6px;">
							<input type="hidden" id="aipdf-ref-id" value="" />
							<button type="button" class="button button-small" id="aipdf-ref-upload"><?php esc_html_e( '📎 Референс-зображення', 'ai-pdf-generator' ); ?></button>
							<button type="button" class="button button-small" id="aipdf-ref-remove" style="display:none;"><?php esc_html_e( 'Прибрати', 'ai-pdf-generator' ); ?></button>
							<img id="aipdf-ref-preview" src="" alt="" style="display:none;max-height:32px;vertical-align:middle;margin-left:6px;border:1px solid #ddd;padding:1px;background:#fff;" />
							<span id="aipdf-ref-hint" class="description" style="display:none;margin-left:6px;font-size:11px;"><?php esc_html_e( 'додасться до наступного повідомлення', 'ai-pdf-generator' ); ?></span>
						</p>

						<!-- Поле вводу + відправка -->
						<div style="display:flex;gap:8px;align-items:flex-end;">
							<textarea id="aipdf-chat-input" rows="2" class="large-text" style="flex:1;" placeholder="<?php esc_attr_e( 'Опишіть документ або що змінити…', 'ai-pdf-generator' ); ?>"></textarea>
							<button type="button" class="button button-primary" id="aipdf-chat-send" style="height:auto;">
								<?php esc_html_e( 'Відправити', 'ai-pdf-generator' ); ?>
							</button>
						</div>
						<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Enter — відправити, Shift+Enter — новий рядок.', 'ai-pdf-generator' ); ?></p>
					</div>

					<!-- Права колонка: живе превю + динамічні поля -->
					<div class="aipdf-preview-col" style="flex:1 1 380px;min-width:340px;">

						<div id="aipdf-chat-meta" style="display:none;margin-bottom:8px;font-size:12px;color:#646970;">
							<span id="aipdf-draft-badge" style="font-weight:600;color:#b26900;background:#fcf3e6;padding:2px 8px;border-radius:3px;"><?php esc_html_e( 'не збережено', 'ai-pdf-generator' ); ?></span>
							<span id="aipdf-meta-line" style="margin-left:8px;"></span>
						</div>

						<iframe id="aipdf-preview" style="width:100%;height:360px;border:1px solid #ccd0d4;background:#fff;display:none;" sandbox=""></iframe>
						<p id="aipdf-preview-placeholder" class="description" style="border:1px dashed #ccd0d4;border-radius:4px;padding:40px 16px;text-align:center;">
							<?php esc_html_e( 'Превю з’явиться тут після першого повідомлення.', 'ai-pdf-generator' ); ?>
						</p>

						<div id="aipdf-chat-fields" style="margin-top:12px;"></div>

						<p style="margin-top:12px;">
							<button type="button" class="button button-primary button-hero" id="aipdf-save-btn" style="display:none;">
								<?php esc_html_e( 'Зберегти шаблон', 'ai-pdf-generator' ); ?>
							</button>
						</p>

						<div id="aipdf-saved" class="notice notice-success" style="display:none;padding:10px 12px;">
							<p id="aipdf-saved-msg" style="margin:0 0 8px;"></p>
							<p style="margin:0;">
								<a href="#" id="aipdf-edit-link" class="button"><?php esc_html_e( 'Відкрити в редакторі', 'ai-pdf-generator' ); ?></a>
								<a href="#" id="aipdf-test-pdf-link" class="button" target="_blank" style="display:none;"><?php esc_html_e( 'Завантажити тестовий PDF', 'ai-pdf-generator' ); ?></a>
							</p>
						</div>
					</div>
				</div>
			</div>

			<!-- ============ Вкладка 2: Налаштування ============ -->
			<div id="aipdf-tab-settings" class="aipdf-tab" style="display:none;padding-top:16px;">
				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
					<?php settings_fields( 'aipdf_settings_group' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="aipdf-api-key"><?php esc_html_e( 'Gemini API Key', 'ai-pdf-generator' ); ?></label>
							</th>
							<td>
								<input
									type="password"
									id="aipdf-api-key"
									name="<?php echo esc_attr( AIPDF_Plugin::OPTION_API_KEY ); ?>"
									value=""
									class="regular-text"
									autocomplete="new-password"
									placeholder="<?php echo $api_key ? esc_attr__( '•••••••• (ключ збережено, введіть новий, щоб замінити)', 'ai-pdf-generator' ) : esc_attr__( 'Вставте ключ Gemini API', 'ai-pdf-generator' ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Ключ зберігається в опціях сайту й ніколи не виводиться назад у HTML. Безпечніший варіант: додайте define( \'AIPDF_GEMINI_API_KEY\', \'…\' ) у wp-config.php — константа має пріоритет.', 'ai-pdf-generator' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="aipdf-model"><?php esc_html_e( 'Модель Gemini (Gemini Model)', 'ai-pdf-generator' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="aipdf-model"
									name="<?php echo esc_attr( AIPDF_Ajax_Handler::OPTION_MODEL ); ?>"
									value="<?php echo esc_attr( (string) get_option( AIPDF_Ajax_Handler::OPTION_MODEL, AIPDF_Ajax_Handler::DEFAULT_MODEL ) ); ?>"
									class="regular-text"
									placeholder="<?php echo esc_attr( AIPDF_Ajax_Handler::DEFAULT_MODEL ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Технічна назва моделі з Google AI Studio (наприклад: gemini-1.5-flash, gemini-1.5-pro). Якщо Google виведе модель з експлуатації (помилка 404) — просто впишіть тут актуальну.', 'ai-pdf-generator' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="aipdf-retention-days"><?php esc_html_e( 'Час зберігання PDF (днів)', 'ai-pdf-generator' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									id="aipdf-retention-days"
									name="<?php echo esc_attr( AIPDF_Cron_Cleanup::OPTION_RETENTION ); ?>"
									value="<?php echo esc_attr( (string) absint( get_option( AIPDF_Cron_Cleanup::OPTION_RETENTION, AIPDF_Cron_Cleanup::DEFAULT_RETENTION_DAYS ) ) ); ?>"
									min="1"
									max="365"
									step="1"
									class="small-text"
								/>
								<p class="description">
									<?php esc_html_e( 'Згенеровані PDF-файли, старіші за вказану кількість днів, щодня видаляються автоматично (WP Cron).', 'ai-pdf-generator' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Брендинг', 'ai-pdf-generator' ); ?></h2>
					<p class="description" style="margin-bottom:8px;">
						<?php esc_html_e( 'Ці значення підставляються у шаблони через плейсхолдери {{logo_url}}, {{brand_color}}, {{company_name}}, {{company_address}}, {{company_email}} — щоб лого, кольори та реквізити можна було міняти без правки HTML.', 'ai-pdf-generator' ); ?>
					</p>
					<?php
					$brand_logo    = (string) get_option( AIPDF_Brand::OPT_LOGO, '' );
					$brand_color   = (string) get_option( AIPDF_Brand::OPT_COLOR, AIPDF_Brand::DEFAULT_COLOR );
					$brand_name    = (string) get_option( AIPDF_Brand::OPT_NAME, '' );
					$brand_address = (string) get_option( AIPDF_Brand::OPT_ADDRESS, '' );
					$brand_email   = (string) get_option( AIPDF_Brand::OPT_EMAIL, '' );
					?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Логотип', 'ai-pdf-generator' ); ?></th>
							<td>
								<input type="hidden" id="aipdf-logo-url" name="<?php echo esc_attr( AIPDF_Brand::OPT_LOGO ); ?>" value="<?php echo esc_attr( $brand_logo ); ?>" />
								<img id="aipdf-logo-preview" src="<?php echo esc_url( $brand_logo ); ?>" alt="" style="max-width:200px;max-height:70px;display:<?php echo $brand_logo ? 'block' : 'none'; ?>;margin-bottom:8px;border:1px solid #ddd;padding:4px;background:#fff;" />
								<button type="button" class="button" id="aipdf-logo-upload"><?php esc_html_e( 'Вибрати зображення', 'ai-pdf-generator' ); ?></button>
								<button type="button" class="button" id="aipdf-logo-remove" style="display:<?php echo $brand_logo ? 'inline-block' : 'none'; ?>;"><?php esc_html_e( 'Прибрати', 'ai-pdf-generator' ); ?></button>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="aipdf-color"><?php esc_html_e( 'Основний колір', 'ai-pdf-generator' ); ?></label></th>
							<td><input type="color" id="aipdf-color" name="<?php echo esc_attr( AIPDF_Brand::OPT_COLOR ); ?>" value="<?php echo esc_attr( $brand_color ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="aipdf-company"><?php esc_html_e( 'Назва компанії', 'ai-pdf-generator' ); ?></label></th>
							<td><input type="text" id="aipdf-company" name="<?php echo esc_attr( AIPDF_Brand::OPT_NAME ); ?>" value="<?php echo esc_attr( $brand_name ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="aipdf-address"><?php esc_html_e( 'Адреса', 'ai-pdf-generator' ); ?></label></th>
							<td><input type="text" id="aipdf-address" name="<?php echo esc_attr( AIPDF_Brand::OPT_ADDRESS ); ?>" value="<?php echo esc_attr( $brand_address ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="aipdf-brand-email"><?php esc_html_e( 'Email компанії', 'ai-pdf-generator' ); ?></label></th>
							<td><input type="email" id="aipdf-brand-email" name="<?php echo esc_attr( AIPDF_Brand::OPT_EMAIL ); ?>" value="<?php echo esc_attr( $brand_email ); ?>" class="regular-text" /></td>
						</tr>
					</table>
					<?php submit_button( __( 'Зберегти налаштування', 'ai-pdf-generator' ) ); ?>
				</form>
			</div>

			<!-- ============ Вкладка 3: Журнал подій ============ -->
			<div id="aipdf-tab-logs" class="aipdf-tab" style="display:none;padding-top:16px;">
				<?php $log_lines = AIPDF_Logger::get_instance()->tail( 50 ); ?>
				<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;max-height:400px;overflow:auto;font-size:12px;line-height:1.6;border-radius:4px;"><?php
					echo $log_lines
						? esc_html( implode( "\n", $log_lines ) )
						: esc_html__( 'Лог порожній.', 'ai-pdf-generator' );
				?></pre>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					onsubmit="return confirm( '<?php echo esc_js( __( 'Очистити журнал подій?', 'ai-pdf-generator' ) ); ?>' );">
					<input type="hidden" name="action" value="aipdf_clear_log" />
					<?php wp_nonce_field( 'aipdf_clear_log' ); ?>
					<?php submit_button( __( 'Очистити лог', 'ai-pdf-generator' ), 'delete', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Очищення файлу логу (admin-post + nonce).
	 */
	public function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостатньо прав.', 'ai-pdf-generator' ) );
		}

		check_admin_referer( 'aipdf_clear_log' );

		AIPDF_Logger::get_instance()->clear();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => AIPDF_Plugin::ADMIN_SLUG,
					'aipdf_log_cleared' => '1',
				),
				admin_url( 'admin.php' )
			) . '#logs' // Повертаємось одразу на вкладку журналу.
		);
		exit;
	}
}
