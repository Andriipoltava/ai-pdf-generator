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
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=aipdf_test_pdf&post_id=' . $post_id ),
			'aipdf_test_pdf_' . $post_id
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
			AIPDF_Cron_Cleanup::OPTION_RETENTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
				'default'           => AIPDF_Cron_Cleanup::DEFAULT_RETENTION_DAYS,
			)
		);
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

		wp_enqueue_script(
			'aipdf-admin',
			AIPDF_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			AIPDF_VERSION,
			true
		);

		wp_localize_script(
			'aipdf-admin',
			'aipdfData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'aipdf_generate' ),
				'i18n'    => array(
					'generating' => __( 'Генерація… (може тривати до 30 сек)', 'ai-pdf-generator' ),
					'error'      => __( 'Сталася помилка. Спробуйте ще раз.', 'ai-pdf-generator' ),
					'emptyInput' => __( 'Опишіть, який документ потрібен.', 'ai-pdf-generator' ),
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
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI PDF Generator', 'ai-pdf-generator' ); ?></h1>

			<h2><?php esc_html_e( 'Налаштування', 'ai-pdf-generator' ); ?></h2>
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
				<?php submit_button( __( 'Зберегти налаштування', 'ai-pdf-generator' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Playground', 'ai-pdf-generator' ); ?></h2>
			<p><?php esc_html_e( 'Опишіть документ, який потрібно згенерувати. Наприклад: «Створи квиток для Amelia 800x400 з QR-кодом та логотипом».', 'ai-pdf-generator' ); ?></p>

			<div class="aipdf-placeholders" style="margin:0 0 10px 0;padding:12px 16px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;max-width:800px;">
				<strong style="display:block;margin-bottom:8px;"><?php esc_html_e( 'Доступні змінні (Плейсхолдери) — клікніть, щоб вставити у запит:', 'ai-pdf-generator' ); ?></strong>
				<?php
				// Групи плейсхолдерів: label => tags.
				$placeholder_groups = array(
					__( 'Базові (завжди)', 'ai-pdf-generator' )      => array( '{{client_name}}', '{{email}}', '{{date}}', '{{qr_code}}' ),
					__( 'WooCommerce', 'ai-pdf-generator' )          => array( '{{order_id}}', '{{order_total}}' ),
					__( 'Amelia / бронювання', 'ai-pdf-generator' )  => array( '{{ticket_id}}', '{{booking_date}}', '{{service_name}}' ),
				);
				foreach ( $placeholder_groups as $group_label => $tags ) :
					?>
					<p style="margin:4px 0;">
						<span style="display:inline-block;min-width:160px;color:#646970;font-size:12px;"><?php echo esc_html( $group_label ); ?>:</span>
						<?php foreach ( $tags as $tag ) : ?>
							<code
								class="aipdf-ph"
								data-ph="<?php echo esc_attr( $tag ); ?>"
								title="<?php esc_attr_e( 'Клікніть, щоб вставити в запит', 'ai-pdf-generator' ); ?>"
								style="cursor:pointer;margin:2px 4px 2px 0;padding:3px 8px;display:inline-block;border-radius:3px;"
							><?php echo esc_html( $tag ); ?></code>
						<?php endforeach; ?>
					</p>
				<?php endforeach; ?>
			</div>

			<textarea id="aipdf-prompt" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'Ваш запит…', 'ai-pdf-generator' ); ?>"></textarea>
			<p>
				<button type="button" class="button button-primary" id="aipdf-generate-btn">
					<?php esc_html_e( 'Згенерувати', 'ai-pdf-generator' ); ?>
				</button>
				<span class="spinner" id="aipdf-spinner" style="float:none;"></span>
			</p>

			<div id="aipdf-result" style="display:none;">
				<h3><?php esc_html_e( 'Результат', 'ai-pdf-generator' ); ?></h3>
				<table class="widefat striped" style="max-width:700px;">
					<tbody>
						<tr><td><strong><?php esc_html_e( 'Шаблон', 'ai-pdf-generator' ); ?></strong></td><td id="aipdf-res-link"></td></tr>
						<tr><td><strong>trigger_plugin</strong></td><td id="aipdf-res-trigger"></td></tr>
						<tr><td><strong>action_type</strong></td><td id="aipdf-res-action"></td></tr>
						<tr><td><strong>paper_size</strong></td><td id="aipdf-res-paper"></td></tr>
					</tbody>
				</table>
				<p>
					<a href="#" id="aipdf-test-pdf-link" class="button" target="_blank">
						<?php esc_html_e( 'Завантажити тестовий PDF', 'ai-pdf-generator' ); ?>
					</a>
				</p>
				<h4><?php esc_html_e( 'Попередній перегляд HTML', 'ai-pdf-generator' ); ?></h4>
				<iframe id="aipdf-preview" style="width:100%;max-width:820px;height:450px;border:1px solid #ccd0d4;background:#fff;" sandbox=""></iframe>
			</div>

			<div id="aipdf-error" class="notice notice-error" style="display:none;"><p></p></div>

			<hr />

			<h2><?php esc_html_e( 'Журнал подій (Logs)', 'ai-pdf-generator' ); ?></h2>
			<?php $log_lines = AIPDF_Logger::get_instance()->tail( 50 ); ?>
			<?php if ( isset( $_GET['aipdf_log_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- лише інформаційний notice. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Лог очищено.', 'ai-pdf-generator' ); ?></p></div>
			<?php endif; ?>
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
			)
		);
		exit;
	}
}
