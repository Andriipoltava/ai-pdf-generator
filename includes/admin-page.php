<?php
/**
 * Settings page and Playground in the admin.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Admin_Page {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Test PDF generation from a saved template + a link in the CPT list.
		add_action( 'admin_post_aipdf_test_pdf', array( $this, 'handle_test_pdf' ) );
		add_action( 'admin_post_aipdf_clear_log', array( $this, 'handle_clear_log' ) );
		add_filter( 'post_row_actions', array( $this, 'add_test_pdf_row_action' ), 10, 2 );
	}

	/**
	 * Nonce-protected URL for test-generating a template's PDF.
	 */
	public static function get_test_pdf_url( int $post_id ): string {
		// NOT wp_nonce_url(): it returns an HTML-escaped URL (&amp;), which
		// breaks when passed through JSON in JS (the server sees an
		// `amp;post_id` parameter and the nonce check fails).
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
	 * "Test PDF" button in the templates CPT list.
	 *
	 * @param array<string, string> $actions Existing row actions.
	 */
	public function add_test_pdf_row_action( array $actions, WP_Post $post ): array {
		if ( AIPDF_Plugin::CPT === $post->post_type && current_user_can( 'manage_options' ) ) {
			$actions['aipdf_test'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::get_test_pdf_url( $post->ID ) ),
				esc_html__( 'Test PDF', 'ai-pdf-generator' )
			);
		}

		return $actions;
	}

	/**
	 * Renders a template with sample data and serves the PDF for download.
	 */
	public function handle_test_pdf(): void {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'aipdf_test_pdf_' . $post_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions or an expired link.', 'ai-pdf-generator' ) );
		}

		$renderer = new AIPDF_PDF_Renderer();

		// Sample data for all standard placeholders.
		$sample_data = apply_filters(
			'aipdf_test_placeholder_data',
			array(
				'client_name' => 'John Smith',
				'email'       => 'client@example.com',
				'order_id'    => '1024',
				'order_total' => '$1,250.00',
				'date'        => wp_date( get_option( 'date_format' ) ),
				'ticket_id'   => 'TCK-58291',
				'booking_date'=> wp_date( 'd.m.Y H:i', time() + WEEK_IN_SECONDS ),
				'service_name'=> 'Consultation',
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
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF.
		exit;
	}

	/**
	 * Top-level menu item. The "Templates" CPT attaches to it automatically
	 * via show_in_menu.
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

		// An explicit first submenu item with the same slug as the parent
		// menu. Without it, the "Templates" CPT (show_in_menu =>
		// ADMIN_SLUG) pushes the settings/Playground page out of the submenu entirely.
		add_submenu_page(
			AIPDF_Plugin::ADMIN_SLUG,
			__( 'AI PDF Generator', 'ai-pdf-generator' ),
			__( 'Generator & Settings', 'ai-pdf-generator' ),
			'manage_options',
			AIPDF_Plugin::ADMIN_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers the API key option via the Settings API — WordPress
	 * handles the nonce and saving through options.php itself.
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

		register_setting(
			'aipdf_settings_group',
			AIPDF_Ajax_Handler::OPTION_CLOUD_TOKEN,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_cloud_token' ),
				'default'           => '',
			)
		);

		register_setting(
			'aipdf_settings_group',
			AIPDF_Ajax_Handler::OPTION_GENERATION_MODE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_generation_mode' ),
				'default'           => 'gemini_direct',
			)
		);

		// --- Branding ---
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
	 * HEX color; an invalid value falls back to the default brand color.
	 */
	public function sanitize_color( $value ): string {
		$color = sanitize_hex_color( (string) $value );
		return $color ? $color : AIPDF_Brand::DEFAULT_COLOR;
	}

	/**
	 * Model name: letters, digits, dots and hyphens only.
	 * An invalid value falls back to the default model.
	 */
	public function sanitize_model( $value ): string {
		$value = strtolower( trim( (string) $value ) );

		if ( '' === $value || ! preg_match( '/^[a-z0-9.\-]{3,60}$/', $value ) ) {
			return AIPDF_Ajax_Handler::DEFAULT_MODEL;
		}

		return $value;
	}

	/**
	 * 1–365 days; anything invalid falls back to 7.
	 */
	public function sanitize_retention_days( $value ): int {
		$days = absint( $value );

		if ( $days < 1 || $days > 365 ) {
			return AIPDF_Cron_Cleanup::DEFAULT_RETENTION_DAYS;
		}

		return $days;
	}

	/**
	 * If the field is submitted empty (the admin doesn't want to change
	 * the key), keep the previous value so the key isn't wiped by accident.
	 */
	public function sanitize_api_key( ?string $value ): string {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return (string) get_option( AIPDF_Plugin::OPTION_API_KEY, '' );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Restricts the generation mode to the two known values; anything else
	 * (tampered request, missing field) falls back to the direct-key mode.
	 */
	public function sanitize_generation_mode( $value ): string {
		$value = (string) $value;
		return in_array( $value, array( 'gemini_direct', 'cloud_service' ), true ) ? $value : 'gemini_direct';
	}

	/**
	 * Same "don't wipe on empty submit" behavior as the Gemini API key —
	 * this field is normally set automatically by the trial-activation
	 * AJAX call, but can also be pasted manually (e.g. a purchased license).
	 */
	public function sanitize_cloud_token( ?string $value ): string {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return (string) get_option( AIPDF_Ajax_Handler::OPTION_CLOUD_TOKEN, '' );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * JS is only enqueued on the plugin's own page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . AIPDF_Plugin::ADMIN_SLUG !== $hook_suffix ) {
			return;
		}

		// Media library (logo, reference) + WP Color Picker (dynamic chat color fields).
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
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'aipdf_generate' ),
				// Sample data for the client-side live chat preview (the
				// same set used by the PDF renderer and the template editor).
				'sample'        => AIPDF_PDF_Renderer::sample_data(),
				'cloudMode'     => ( 'cloud_service' === get_option( AIPDF_Ajax_Handler::OPTION_GENERATION_MODE, '' ) ),
				'hasCloudToken' => ( '' !== (string) get_option( AIPDF_Ajax_Handler::OPTION_CLOUD_TOKEN, '' ) ),
				'i18n'    => array(
					'sending'      => __( 'Sending…', 'ai-pdf-generator' ),
					'send'         => __( 'Send', 'ai-pdf-generator' ),
					'error'        => __( 'Something went wrong. Please try again.', 'ai-pdf-generator' ),
					'emptyInput'   => __( 'Please enter a message.', 'ai-pdf-generator' ),
					'saved'        => __( 'Template saved', 'ai-pdf-generator' ),
					'you'          => __( 'You', 'ai-pdf-generator' ),
					'assistant'    => __( 'AI', 'ai-pdf-generator' ),
					'updatedMsg'   => __( 'Done. Trigger: %1$s · Action: %2$s · Format: %3$s.', 'ai-pdf-generator' ),
					'actionEmail'  => __( 'email attachment', 'ai-pdf-generator' ),
					'actionDl'     => __( 'download link', 'ai-pdf-generator' ),
					'welcomeMsg'   => __( 'Describe the document you need, or start from a ready-made example below.', 'ai-pdf-generator' ),
					'trialButton'    => __( 'Get Free Trial (3 Generations)', 'ai-pdf-generator' ),
					'trialRequesting' => __( 'Requesting…', 'ai-pdf-generator' ),
					'trialActivated' => __( 'Trial activated!', 'ai-pdf-generator' ),
					'licensePlan'    => __( 'Plan', 'ai-pdf-generator' ),
					'licenseCredits' => __( 'Credits Remaining', 'ai-pdf-generator' ),
					'licenseDomains' => __( 'Domains', 'ai-pdf-generator' ),
					'licenseExpires' => __( 'Valid Until', 'ai-pdf-generator' ),
					'licenseUnlimited'     => __( 'Unlimited', 'ai-pdf-generator' ),
					'licenseNoExpiration'  => __( 'No Expiration', 'ai-pdf-generator' ),
				),
			)
		);
	}

	/**
	 * Page markup: settings + Playground.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-pdf-generator' ) );
		}

		$api_key         = (string) get_option( AIPDF_Plugin::OPTION_API_KEY, '' );
		$cloud_token     = (string) get_option( AIPDF_Ajax_Handler::OPTION_CLOUD_TOKEN, '' );
		$generation_mode = (string) get_option( AIPDF_Ajax_Handler::OPTION_GENERATION_MODE, 'gemini_direct' );

		// Smart placeholders: only show groups for active plugins.
		$placeholder_groups = array(
			__( 'Basic (always)', 'ai-pdf-generator' ) => array( '{{client_name}}', '{{email}}', '{{date}}', '{{qr_code}}' ),
			__( 'Branding', 'ai-pdf-generator' )        => array( '{{logo_url}}', '{{brand_color}}', '{{company_name}}', '{{company_address}}', '{{company_email}}' ),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$placeholder_groups[ __( 'WooCommerce', 'ai-pdf-generator' ) ] = array( '{{order_id}}', '{{order_total}}' );
		}
		if ( defined( 'AMELIA_VERSION' ) || class_exists( '\AmeliaBooking\Plugin' ) ) {
			$placeholder_groups[ __( 'Amelia / Bookings', 'ai-pdf-generator' ) ] = array( '{{ticket_id}}', '{{booking_date}}', '{{service_name}}' );
		}

		// Ready-made prompts for "Quick Start".
		$quickstart_prompts = array(
			__( 'WooCommerce A4 Invoice', 'ai-pdf-generator' )     => __( 'Create a standard A4 invoice for WooCommerce. Logo at the top, then a table with: order number {{order_id}}, client {{client_name}}, total {{order_total}}, date {{date}}.', 'ai-pdf-generator' ),
			__( 'Amelia 800x400 Ticket', 'ai-pdf-generator' )      => __( 'Create a custom 800x400 px ticket for Amelia. Add a calendar icon, booking date {{booking_date}}, service name {{service_name}}, and a large QR code {{qr_code}}.', 'ai-pdf-generator' ),
			__( 'Form Thank-You Letter (Letter)', 'ai-pdf-generator' ) => __( 'Create a thank-you letter after a form submission, Letter format. Centered text, addressed to {{client_name}}, date {{date}}.', 'ai-pdf-generator' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI PDF Generator', 'ai-pdf-generator' ); ?></h1>

			<?php if ( '' === $cloud_token && '' === $api_key ) : ?>
				<div id="aipdf-promo-banner" class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: link to the License tab. */
							esc_html__( '🚀 AI PDF Generator: Unlock premium templates and smart conditions! %s', 'ai-pdf-generator' ),
							'<a href="#license" class="aipdf-promo-link">' . esc_html__( 'View Pricing', 'ai-pdf-generator' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['aipdf_log_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- informational notice only. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Log cleared.', 'ai-pdf-generator' ); ?></p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper" id="aipdf-tabs">
				<a href="#playground" class="nav-tab nav-tab-active" data-tab="playground"><?php esc_html_e( 'Generator (Playground)', 'ai-pdf-generator' ); ?></a>
				<a href="#settings" class="nav-tab" data-tab="settings"><?php esc_html_e( 'Settings', 'ai-pdf-generator' ); ?></a>
				<a href="#license" class="nav-tab" data-tab="license"><?php esc_html_e( 'License', 'ai-pdf-generator' ); ?></a>
				<a href="#logs" class="nav-tab" data-tab="logs"><?php esc_html_e( 'Event Log', 'ai-pdf-generator' ); ?></a>
			</h2>

			<!-- ============ Tab 1: Generator (chat) ============ -->
			<div id="aipdf-tab-playground" class="aipdf-tab" style="padding-top:16px;">

				<div class="aipdf-chat-layout" style="display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;">

					<!-- Left column: chat -->
					<div class="aipdf-chat-col" style="flex:1 1 420px;min-width:340px;max-width:560px;">

						<p>
							<label for="aipdf-quickstart"><strong><?php esc_html_e( 'Quick Start:', 'ai-pdf-generator' ); ?></strong></label><br />
							<select id="aipdf-quickstart" style="width:100%;margin-top:4px;">
								<option value=""><?php esc_html_e( '-- Choose a ready-made example --', 'ai-pdf-generator' ); ?></option>
								<?php foreach ( $quickstart_prompts as $label => $prompt_text ) : ?>
									<option value="<?php echo esc_attr( $prompt_text ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>

						<div class="aipdf-placeholders" style="margin:0 0 10px 0;padding:10px 12px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;">
							<strong style="display:block;margin-bottom:6px;font-size:12px;"><?php esc_html_e( 'Placeholders — click to insert into the message field:', 'ai-pdf-generator' ); ?></strong>
							<?php foreach ( $placeholder_groups as $group_label => $tags ) : ?>
								<p style="margin:3px 0;">
									<span style="display:inline-block;min-width:110px;color:#646970;font-size:11px;"><?php echo esc_html( $group_label ); ?>:</span>
									<?php foreach ( $tags as $tag ) : ?>
										<code
											class="aipdf-ph"
											data-ph="<?php echo esc_attr( $tag ); ?>"
											title="<?php esc_attr_e( 'Click to insert into the message', 'ai-pdf-generator' ); ?>"
											style="cursor:pointer;margin:2px 3px 2px 0;padding:2px 6px;display:inline-block;border-radius:3px;font-size:11px;"
										><?php echo esc_html( $tag ); ?></code>
									<?php endforeach; ?>
								</p>
							<?php endforeach; ?>
						</div>

						<!-- Chat history -->
						<div id="aipdf-chat-history" style="border:1px solid #ccd0d4;border-radius:4px;background:#fff;height:380px;overflow-y:auto;padding:12px;margin-bottom:8px;"></div>

						<!-- Reference image (attached to the next message) -->
						<p style="margin:0 0 6px;">
							<input type="hidden" id="aipdf-ref-id" value="" />
							<button type="button" class="button button-small" id="aipdf-ref-upload"><?php esc_html_e( '📎 Reference Image', 'ai-pdf-generator' ); ?></button>
							<button type="button" class="button button-small" id="aipdf-ref-remove" style="display:none;"><?php esc_html_e( 'Remove', 'ai-pdf-generator' ); ?></button>
							<img id="aipdf-ref-preview" src="" alt="" style="display:none;max-height:32px;vertical-align:middle;margin-left:6px;border:1px solid #ddd;padding:1px;background:#fff;" />
							<span id="aipdf-ref-hint" class="description" style="display:none;margin-left:6px;font-size:11px;"><?php esc_html_e( 'will be attached to the next message', 'ai-pdf-generator' ); ?></span>
						</p>

						<!-- Input field + send -->
						<div style="display:flex;gap:8px;align-items:flex-end;">
							<textarea id="aipdf-chat-input" rows="2" class="large-text" style="flex:1;" placeholder="<?php esc_attr_e( 'Describe the document, or what to change…', 'ai-pdf-generator' ); ?>"></textarea>
							<button type="button" class="button button-primary" id="aipdf-chat-send" style="height:auto;">
								<?php esc_html_e( 'Send', 'ai-pdf-generator' ); ?>
							</button>
						</div>
						<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Enter to send, Shift+Enter for a new line.', 'ai-pdf-generator' ); ?></p>
					</div>

					<!-- Right column: live preview + dynamic fields -->
					<div class="aipdf-preview-col" style="flex:1 1 380px;min-width:340px;">

						<div id="aipdf-chat-meta" style="display:none;margin-bottom:8px;font-size:12px;color:#646970;">
							<span id="aipdf-draft-badge" style="font-weight:600;color:#b26900;background:#fcf3e6;padding:2px 8px;border-radius:3px;"><?php esc_html_e( 'unsaved', 'ai-pdf-generator' ); ?></span>
							<span id="aipdf-meta-line" style="margin-left:8px;"></span>
						</div>

						<iframe id="aipdf-preview" style="width:100%;height:360px;border:1px solid #ccd0d4;background:#fff;display:none;" sandbox=""></iframe>
						<p id="aipdf-preview-placeholder" class="description" style="border:1px dashed #ccd0d4;border-radius:4px;padding:40px 16px;text-align:center;">
							<?php esc_html_e( 'The preview will appear here after your first message.', 'ai-pdf-generator' ); ?>
						</p>

						<div id="aipdf-chat-fields" style="margin-top:12px;"></div>

						<p style="margin-top:12px;">
							<button type="button" class="button button-primary button-hero" id="aipdf-save-btn" style="display:none;">
								<?php esc_html_e( 'Save Template', 'ai-pdf-generator' ); ?>
							</button>
						</p>

						<div id="aipdf-saved" class="notice notice-success" style="display:none;padding:10px 12px;">
							<p id="aipdf-saved-msg" style="margin:0 0 8px;"></p>
							<p style="margin:0;">
								<a href="#" id="aipdf-edit-link" class="button"><?php esc_html_e( 'Open in Editor', 'ai-pdf-generator' ); ?></a>
								<a href="#" id="aipdf-test-pdf-link" class="button" target="_blank" style="display:none;"><?php esc_html_e( 'Download Test PDF', 'ai-pdf-generator' ); ?></a>
							</p>
						</div>
					</div>
				</div>
			</div>

			<!-- ============ Tab 2: Settings ============ -->
			<div id="aipdf-tab-settings" class="aipdf-tab" style="display:none;padding-top:16px;">
				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
					<?php settings_fields( 'aipdf_settings_group' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="aipdf-model"><?php esc_html_e( 'Gemini Model', 'ai-pdf-generator' ); ?></label>
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
									<?php esc_html_e( 'The technical model name from Google AI Studio (e.g. gemini-1.5-flash, gemini-1.5-pro). If Google retires this model (a 404 error), just enter a current one here.', 'ai-pdf-generator' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="aipdf-retention-days"><?php esc_html_e( 'PDF Retention Period (days)', 'ai-pdf-generator' ); ?></label>
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
									<?php esc_html_e( 'Generated PDF files older than this many days are automatically deleted every day (WP Cron).', 'ai-pdf-generator' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Branding', 'ai-pdf-generator' ); ?></h2>
					<p class="description" style="margin-bottom:8px;">
						<?php esc_html_e( 'These values are substituted into templates via the {{logo_url}}, {{brand_color}}, {{company_name}}, {{company_address}}, {{company_email}} placeholders — so the logo, colors, and details can be changed without editing HTML.', 'ai-pdf-generator' ); ?>
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
							<th scope="row"><?php esc_html_e( 'Logo', 'ai-pdf-generator' ); ?></th>
							<td>
								<input type="hidden" id="aipdf-logo-url" name="<?php echo esc_attr( AIPDF_Brand::OPT_LOGO ); ?>" value="<?php echo esc_attr( $brand_logo ); ?>" />
								<img id="aipdf-logo-preview" src="<?php echo esc_url( $brand_logo ); ?>" alt="" style="max-width:200px;max-height:70px;display:<?php echo $brand_logo ? 'block' : 'none'; ?>;margin-bottom:8px;border:1px solid #ddd;padding:4px;background:#fff;" />
								<button type="button" class="button" id="aipdf-logo-upload"><?php esc_html_e( 'Choose Image', 'ai-pdf-generator' ); ?></button>
								<button type="button" class="button" id="aipdf-logo-remove" style="display:<?php echo $brand_logo ? 'inline-block' : 'none'; ?>;"><?php esc_html_e( 'Remove', 'ai-pdf-generator' ); ?></button>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="aipdf-color"><?php esc_html_e( 'Primary Color', 'ai-pdf-generator' ); ?></label></th>
							<td><input type="color" id="aipdf-color" name="<?php echo esc_attr( AIPDF_Brand::OPT_COLOR ); ?>" value="<?php echo esc_attr( $brand_color ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="aipdf-company"><?php esc_html_e( 'Company Name', 'ai-pdf-generator' ); ?></label></th>
							<td><input type="text" id="aipdf-company" name="<?php echo esc_attr( AIPDF_Brand::OPT_NAME ); ?>" value="<?php echo esc_attr( $brand_name ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="aipdf-address"><?php esc_html_e( 'Address', 'ai-pdf-generator' ); ?></label></th>
							<td><input type="text" id="aipdf-address" name="<?php echo esc_attr( AIPDF_Brand::OPT_ADDRESS ); ?>" value="<?php echo esc_attr( $brand_address ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="aipdf-brand-email"><?php esc_html_e( 'Company Email', 'ai-pdf-generator' ); ?></label></th>
							<td><input type="email" id="aipdf-brand-email" name="<?php echo esc_attr( AIPDF_Brand::OPT_EMAIL ); ?>" value="<?php echo esc_attr( $brand_email ); ?>" class="regular-text" /></td>
						</tr>
					</table>
					<?php submit_button( __( 'Save Settings', 'ai-pdf-generator' ) ); ?>
				</form>
			</div>

			<!-- ============ Tab: License / Account ============ -->
			<div id="aipdf-tab-license" class="aipdf-tab" style="display:none;padding-top:16px;">
				<style>
					.aipdf-license-card { max-width: 460px; padding: 16px 20px; border-radius: 6px; }
					.aipdf-license-row { display: flex; justify-content: space-between; align-items: baseline; padding: 7px 0; border-bottom: 1px solid rgba(0,0,0,.06); }
					.aipdf-license-row:last-child { border-bottom: none; }
					.aipdf-license-label { color: #646970; font-size: 13px; }
					.aipdf-license-value { font-size: 15px; font-weight: 600; color: #1d2327; }
					.aipdf-license-value small { font-weight: 400; color: #646970; }

					.aipdf-pricing-grid { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 24px; max-width: 900px; }
					.aipdf-pricing-card { flex: 1 1 240px; min-width: 220px; background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; padding: 20px; position: relative; }
					.aipdf-pricing-card.is-featured { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
					.aipdf-pricing-badge { position: absolute; top: -11px; right: 16px; background: #2271b1; color: #fff; font-size: 11px; font-weight: 600; padding: 2px 10px; border-radius: 10px; text-transform: uppercase; letter-spacing: .03em; }
					.aipdf-pricing-name { font-size: 15px; font-weight: 700; margin: 0 0 4px; color: #1d2327; }
					.aipdf-pricing-price { font-size: 24px; font-weight: 700; margin: 0 0 14px; color: #1d2327; }
					.aipdf-pricing-price small { font-size: 12px; font-weight: 400; color: #646970; }
					.aipdf-pricing-features { list-style: none; margin: 0; padding: 0; font-size: 13px; color: #3c434a; }
					.aipdf-pricing-features li { padding: 4px 0 4px 20px; position: relative; }
					.aipdf-pricing-features li::before { content: "✓"; position: absolute; left: 0; color: #2271b1; font-weight: 700; }
				</style>

				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
					<?php settings_fields( 'aipdf_settings_group' ); ?>
					<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( admin_url( 'admin.php?page=' . AIPDF_Plugin::ADMIN_SLUG ) . '#license' ); ?>" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Generation Mode', 'ai-pdf-generator' ); ?></th>
							<td>
								<label style="margin-right:20px;">
									<input type="radio" id="aipdf-mode-own" name="<?php echo esc_attr( AIPDF_Ajax_Handler::OPTION_GENERATION_MODE ); ?>" value="gemini_direct" <?php checked( $generation_mode, 'gemini_direct' ); ?> />
									<?php esc_html_e( 'Own Gemini API Key', 'ai-pdf-generator' ); ?>
								</label>
								<label>
									<input type="radio" id="aipdf-mode-cloud" name="<?php echo esc_attr( AIPDF_Ajax_Handler::OPTION_GENERATION_MODE ); ?>" value="cloud_service" <?php checked( $generation_mode, 'cloud_service' ); ?> />
									<?php esc_html_e( 'Cloud Service', 'ai-pdf-generator' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Own key: generation runs directly against your own Gemini API key, free of charge. Cloud Service: generation runs through our managed backend and is billed against your license credits.', 'ai-pdf-generator' ); ?>
								</p>
							</td>
						</tr>
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
									placeholder="<?php echo $api_key ? esc_attr__( '•••••••• (key saved — enter a new one to replace it)', 'ai-pdf-generator' ) : esc_attr__( 'Enter your Gemini API key', 'ai-pdf-generator' ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'The key is stored in the site\'s options and never echoed back into HTML. A safer option: add define( \'AIPDF_GEMINI_API_KEY\', \'…\' ) to wp-config.php — the constant takes priority.', 'ai-pdf-generator' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="aipdf-cloud-token"><?php esc_html_e( 'Cloud License Key', 'ai-pdf-generator' ); ?></label>
							</th>
							<td>
								<input
									type="password"
									id="aipdf-cloud-token"
									name="<?php echo esc_attr( AIPDF_Ajax_Handler::OPTION_CLOUD_TOKEN ); ?>"
									value=""
									class="regular-text"
									autocomplete="new-password"
									placeholder="<?php echo $cloud_token ? esc_attr__( '•••••••• (license active — enter a new key to replace it)', 'ai-pdf-generator' ) : esc_attr__( 'No license key yet', 'ai-pdf-generator' ); ?>"
								/>
								<?php if ( '' === $cloud_token ) : ?>
									<button type="button" class="button" id="aipdf-get-trial-btn"><?php esc_html_e( 'Get Free Trial (3 Generations)', 'ai-pdf-generator' ); ?></button>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'Powers cloud-based generation. Click "Get Free Trial" for 3 free generations tied to your admin email and this domain, or paste a purchased license key here.', 'ai-pdf-generator' ); ?>
								</p>
								<div id="aipdf-license-status-card" style="display: none;"></div>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save License Settings', 'ai-pdf-generator' ) ); ?>
				</form>

				<?php if ( '' === $cloud_token ) : ?>
					<h2 style="margin-top:28px;"><?php esc_html_e( 'Plans', 'ai-pdf-generator' ); ?></h2>
					<div class="aipdf-pricing-grid">
						<div class="aipdf-pricing-card">
							<p class="aipdf-pricing-name"><?php esc_html_e( 'Trial', 'ai-pdf-generator' ); ?></p>
							<p class="aipdf-pricing-price"><?php esc_html_e( 'Free', 'ai-pdf-generator' ); ?></p>
							<ul class="aipdf-pricing-features">
								<li><?php esc_html_e( '3 free generations', 'ai-pdf-generator' ); ?></li>
								<li><?php esc_html_e( 'Try everything, no card required', 'ai-pdf-generator' ); ?></li>
							</ul>
						</div>
						<div class="aipdf-pricing-card">
							<p class="aipdf-pricing-name"><?php esc_html_e( 'Starter', 'ai-pdf-generator' ); ?></p>
							<p class="aipdf-pricing-price"><?php esc_html_e( '50 PDF', 'ai-pdf-generator' ); ?> <small><?php esc_html_e( '/ month', 'ai-pdf-generator' ); ?></small></p>
							<ul class="aipdf-pricing-features">
								<li><?php esc_html_e( '50 PDFs per month', 'ai-pdf-generator' ); ?></li>
								<li><?php esc_html_e( 'Email delivery support', 'ai-pdf-generator' ); ?></li>
							</ul>
						</div>
						<div class="aipdf-pricing-card is-featured">
							<span class="aipdf-pricing-badge"><?php esc_html_e( 'Popular', 'ai-pdf-generator' ); ?></span>
							<p class="aipdf-pricing-name"><?php esc_html_e( 'Pro', 'ai-pdf-generator' ); ?></p>
							<p class="aipdf-pricing-price"><?php esc_html_e( '250 PDF', 'ai-pdf-generator' ); ?> <small><?php esc_html_e( '/ month', 'ai-pdf-generator' ); ?></small></p>
							<ul class="aipdf-pricing-features">
								<li><?php esc_html_e( '250 PDFs per month', 'ai-pdf-generator' ); ?></li>
								<li><?php esc_html_e( 'Email delivery support', 'ai-pdf-generator' ); ?></li>
								<li><?php esc_html_e( 'Conditional logic', 'ai-pdf-generator' ); ?></li>
								<li><?php esc_html_e( 'No branding', 'ai-pdf-generator' ); ?></li>
							</ul>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<!-- ============ Tab 3: Event Log ============ -->
			<div id="aipdf-tab-logs" class="aipdf-tab" style="display:none;padding-top:16px;">
				<?php $log_lines = AIPDF_Logger::get_instance()->tail( 50 ); ?>
				<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;max-height:400px;overflow:auto;font-size:12px;line-height:1.6;border-radius:4px;"><?php
					echo $log_lines
						? esc_html( implode( "\n", $log_lines ) )
						: esc_html__( 'Log is empty.', 'ai-pdf-generator' );
				?></pre>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					onsubmit="return confirm( '<?php echo esc_js( __( 'Clear the event log?', 'ai-pdf-generator' ) ); ?>' );">
					<input type="hidden" name="action" value="aipdf_clear_log" />
					<?php wp_nonce_field( 'aipdf_clear_log' ); ?>
					<?php submit_button( __( 'Clear Log', 'ai-pdf-generator' ), 'delete', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Clears the log file (admin-post + nonce).
	 */
	public function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-pdf-generator' ) );
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
			) . '#logs' // Return straight to the log tab.
		);
		exit;
	}
}
