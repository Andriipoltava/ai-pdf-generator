<?php
/**
 * Plugin Name:       AI PDF Generator
 * Plugin URI:        https://example.com/ai-pdf-generator
 * Description:       Generates HTML templates for PDF documents via the Gemini API and stores them in a hidden CPT.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Andrii
 * License:           GPL-2.0-or-later
 * Text Domain:       ai-pdf-generator
 */

defined( 'ABSPATH' ) || exit;

define( 'AIPDF_VERSION', '0.2.0' );
define( 'AIPDF_PLUGIN_FILE', __FILE__ );
define( 'AIPDF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIPDF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Composer dependencies (mPDF). Installed via `composer install` inside the plugin folder.
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
require_once AIPDF_PLUGIN_DIR . 'includes/bulk-actions.php';
require_once AIPDF_PLUGIN_DIR . 'includes/delivery.php';
require_once AIPDF_PLUGIN_DIR . 'includes/cron-cleanup.php';

// Activation/deactivation hooks MUST be registered in the plugin's main file.
register_activation_hook( __FILE__, array( 'AIPDF_Cron_Cleanup', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AIPDF_Cron_Cleanup', 'deactivate' ) );

/**
 * Main plugin bootstrap class (singleton).
 */
final class AIPDF_Plugin {

	/**
	 * CPT name for stored templates.
	 */
	public const CPT = 'pdf_ai_template';

	/**
	 * Option name that stores the Gemini API key.
	 */
	public const OPTION_API_KEY = 'aipdf_gemini_api_key';

	/**
	 * Option name that stores the OpenAI API key.
	 */
	public const OPTION_OPENAI_API_KEY = 'aipdf_openai_api_key';

	/**
	 * Option name that stores which AI provider generation uses ('gemini' | 'openai').
	 */
	public const OPTION_AI_PROVIDER = 'aipdf_ai_provider';

	/**
	 * Option name that toggles the Event Log on/off ('1' enabled, '' disabled).
	 * Enabled by default — existing sites keep logging until turned off.
	 */
	public const OPTION_LOGGING_ENABLED = 'aipdf_logging_enabled';

	/**
	 * Admin settings page slug.
	 */
	public const ADMIN_SLUG = 'ai-pdf-generator';

	/**
	 * Allowed trigger_plugin values — used both in the Gemini prompt
	 * and to validate the response.
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
	 * Allowed action_type values.
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

	/**
	 * Enqueue cache-busting version. Under WP_DEBUG (typically on a local
	 * dev site) — the file's mtime, so the browser never keeps a stale
	 * JS/CSS copy after an edit. In production (WP_DEBUG off) — the stable
	 * AIPDF_VERSION, as expected for normal HTTP caching.
	 *
	 * @param string $relative_path Path relative to the plugin root, e.g. 'assets/admin.js'.
	 */
	public static function asset_version( string $relative_path ): string {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$full = AIPDF_PLUGIN_DIR . ltrim( $relative_path, '/' );
			$mtime = file_exists( $full ) ? filemtime( $full ) : false;
			if ( false !== $mtime ) {
				return (string) $mtime;
			}
		}
		return AIPDF_VERSION;
	}

	private function __construct() {
		new AIPDF_CPT_Register();
		new AIPDF_Template_Editor();
		new AIPDF_Admin_Page();
		new AIPDF_Ajax_Handler();
		new AIPDF_Trigger_Dispatcher();
		new AIPDF_Bulk_Actions();
		new AIPDF_Delivery();
		new AIPDF_Cron_Cleanup();

		add_action( 'admin_notices', array( $this, 'maybe_notice_missing_mpdf' ) );
	}

	/**
	 * Notice shown if mPDF isn't installed via Composer yet.
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
			esc_html__( 'Error: plugin core (mPDF) not found. Please make sure you installed the plugin from a ready-made release archive. Template generation and settings still work, but PDF files will not be created.', 'ai-pdf-generator' )
		);
	}
}

add_action( 'plugins_loaded', array( 'AIPDF_Plugin', 'instance' ) );
