<?php
/**
 * Plugin Name:       AI PDF Generator
 * Plugin URI:        https://example.com/ai-pdf-generator
 * Description:       Генерує HTML-шаблони для PDF-документів через Gemini API та зберігає їх у прихованому CPT.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Andrii
 * License:           GPL-2.0-or-later
 * Text Domain:       ai-pdf-generator
 */

defined( 'ABSPATH' ) || exit;

define( 'AIPDF_VERSION', '0.1.0' );
define( 'AIPDF_PLUGIN_FILE', __FILE__ );
define( 'AIPDF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIPDF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Composer-залежності (mPDF). Встановлюються командою `composer install` у папці плагіна.
if ( file_exists( AIPDF_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once AIPDF_PLUGIN_DIR . 'vendor/autoload.php';
}

require_once AIPDF_PLUGIN_DIR . 'includes/logger.php';
require_once AIPDF_PLUGIN_DIR . 'includes/triggers.php';
require_once AIPDF_PLUGIN_DIR . 'includes/fields.php';
require_once AIPDF_PLUGIN_DIR . 'includes/brand.php';
require_once AIPDF_PLUGIN_DIR . 'includes/cpt-register.php';
require_once AIPDF_PLUGIN_DIR . 'includes/template-editor.php';
require_once AIPDF_PLUGIN_DIR . 'includes/admin-page.php';
require_once AIPDF_PLUGIN_DIR . 'includes/ajax-handler.php';
require_once AIPDF_PLUGIN_DIR . 'includes/pdf-renderer.php';
require_once AIPDF_PLUGIN_DIR . 'includes/trigger-dispatcher.php';
require_once AIPDF_PLUGIN_DIR . 'includes/delivery.php';
require_once AIPDF_PLUGIN_DIR . 'includes/cron-cleanup.php';

// Хуки активації/деактивації МУСЯТЬ реєструватися у головному файлі плагіна.
register_activation_hook( __FILE__, array( 'AIPDF_Cron_Cleanup', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AIPDF_Cron_Cleanup', 'deactivate' ) );

/**
 * Головний клас-завантажувач плагіна (singleton).
 */
final class AIPDF_Plugin {

	/**
	 * Назва CPT для збережених шаблонів.
	 */
	public const CPT = 'pdf_ai_template';

	/**
	 * Опція, у якій зберігається Gemini API Key.
	 */
	public const OPTION_API_KEY = 'aipdf_gemini_api_key';

	/**
	 * Slug сторінки налаштувань в адмінці.
	 */
	public const ADMIN_SLUG = 'ai-pdf-generator';

	/**
	 * Дозволені значення trigger_plugin — використовуються і в промпті
	 * до Gemini, і для валідації відповіді.
	 *
	 * @var string[]
	 */
	public const ALLOWED_TRIGGERS = array(
		'wc_order_paid',
		'cf7_submit',
		'wpforms_submit',
		'gform_submit',
		'ninja_forms_submit',
		'formidable_submit',
		'elementor_pro_form_submit',
		'fluentform_submit',
		'forminator_submit',
		'wsform_submit',
		'everest_forms_submit',
		'amelia_booking_done',
		'tec_event_booking',
		'event_tickets_purchase',
		'bookly_booking_done',
		'event_espresso_registration',
		'mec_booking_done',
		'wc_bookings_done',
		'manual_generation',
	);

	/**
	 * Дозволені значення action_type.
	 *
	 * @var string[]
	 */
	public const ALLOWED_ACTIONS = array(
		'attach_to_email',
		'download_link',
	);

	private static ?AIPDF_Plugin $instance = null;

	public static function instance(): AIPDF_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		new AIPDF_CPT_Register();
		new AIPDF_Template_Editor();
		new AIPDF_Admin_Page();
		new AIPDF_Ajax_Handler();
		new AIPDF_Trigger_Dispatcher();
		new AIPDF_Delivery();
		new AIPDF_Cron_Cleanup();

		add_action( 'admin_notices', array( $this, 'maybe_notice_missing_mpdf' ) );
	}

	/**
	 * Попередження, якщо mPDF ще не встановлено через Composer.
	 */
	public function maybe_notice_missing_mpdf(): void {
		if ( AIPDF_PDF_Renderer::is_available() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, self::ADMIN_SLUG ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>AI PDF Generator:</strong> %s</p></div>',
			esc_html__( 'Помилка: Не знайдено ядро плагіна (mPDF). Будь ласка, переконайтеся, що ви встановили плагін із готового release-архіву. Генерація шаблонів та налаштування працюють, але PDF-файли не створюватимуться.', 'ai-pdf-generator' )
		);
	}
}

add_action( 'plugins_loaded', array( 'AIPDF_Plugin', 'instance' ) );
