<?php
/**
 * Trigger dispatcher: listens to real plugin hooks (Amelia, WooCommerce,
 * Contact Form 7, …), finds the saved AI template for the trigger, and
 * kicks off PDF generation.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Trigger_Dispatcher {

	private AIPDF_PDF_Renderer $renderer;

	/**
	 * The most recent generation result within the CURRENT request:
	 * [ 'url' => string, 'action_type' => string ]. Needed by delivery
	 * (CF7/Elementor), which filters the form's JSON response in the same request.
	 *
	 * @var array{url:string,action_type:string}|null
	 */
	private static ?array $last_result = null;

	public function __construct() {
		$this->renderer = new AIPDF_PDF_Renderer();

		// --- Third-party plugin hooks are registered ONLY if the plugin is active. ---
		if ( AIPDF_Triggers::is_available( 'woocommerce_payment_complete' ) ) {
			add_action( 'woocommerce_payment_complete', array( $this, 'on_wc_order_paid' ), 10, 1 );
		}
		if ( AIPDF_Triggers::is_available( 'amelia_after_booking_added' ) ) {
			add_action( 'amelia_after_booking_added', array( $this, 'on_amelia_booking' ), 10, 1 );
		}
		if ( AIPDF_Triggers::is_available( 'wpcf7_mail_sent' ) ) {
			add_action( 'wpcf7_mail_sent', array( $this, 'on_cf7_submit' ), 10, 1 );
		}
		if ( AIPDF_Triggers::is_available( 'elementor_pro/forms/new_record' ) ) {
			add_action( 'elementor_pro/forms/new_record', array( $this, 'on_elementor_form' ), 10, 2 );
		}

		/**
		 * Generic entry point for the remaining triggers:
		 * do_action( 'aipdf_run_trigger', 'bookly_booking_done', [ 'client_name' => …, 'email' => … ] );
		 */
		add_action( 'aipdf_run_trigger', array( $this, 'run_trigger' ), 10, 2 );
	}

	/**
	 * Main pipeline: trigger -> template from the DB -> PDF -> delivery.
	 *
	 * @param string                $trigger One of AIPDF_Triggers' catalog keys.
	 * @param array<string, string> $data    Placeholder data.
	 */
	public function run_trigger( string $trigger, array $data ): void {
		if ( ! in_array( $trigger, AIPDF_Triggers::all(), true ) ) {
			return;
		}

		$template = $this->renderer->find_template_by_trigger( $trigger );
		if ( ! $template ) {
			return; // No template generated for this trigger yet — bail out quietly.
		}

		$result = $this->renderer->render_to_file( $template->ID, $data );
		if ( is_wp_error( $result ) ) {
			// Don't break someone else's process (payment/booking) — just log it.
			AIPDF_Logger::get_instance()->error(
				sprintf( 'Generation for trigger %s failed: %s', $trigger, $result->get_error_message() )
			);
			return;
		}

		$action_type = (string) get_post_meta( $template->ID, '_aipdf_action_type', true );

		self::$last_result = array(
			'url'         => $result['url'],
			'action_type' => $action_type,
		);

		if ( 'attach_to_email' === $action_type && ! empty( $data['email'] ) && is_email( $data['email'] ) ) {
			$this->send_email_with_pdf( $data['email'], $template, $result['path'], $data );
		}

		/**
		 * Extension hook: download_link delivery, CRM logging, etc.
		 *
		 * @param array   $result   { path, url } of the generated PDF.
		 * @param string  $trigger  The trigger that fired.
		 * @param WP_Post $template Template post.
		 * @param array   $data     Placeholder data.
		 */
		do_action( 'aipdf_pdf_generated', $result, $trigger, $template, $data );
	}

	/**
	 * URL of the PDF generated in the current request with
	 * action_type = download_link. Used by delivery (CF7 / Elementor) in
	 * response filters.
	 */
	public static function last_download_url(): string {
		if ( self::$last_result && 'download_link' === self::$last_result['action_type'] ) {
			return self::$last_result['url'];
		}

		return '';
	}

	/* -------------------------------------------------------------------
	 * Adapters for specific plugins: extract data into a unified shape.
	 * ---------------------------------------------------------------- */

	/**
	 * WooCommerce: order paid.
	 */
	public function on_wc_order_paid( $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$this->run_trigger( 'woocommerce_payment_complete', self::build_wc_order_data( $order ) );
	}

	/**
	 * WooCommerce order data in the shape run_trigger() expects. Static
	 * and public — the bulk-generation feature (AIPDF_Bulk_Actions) reuses
	 * this exact code for EXISTING orders, without a second
	 * AIPDF_Trigger_Dispatcher instance (which would double-register the
	 * woocommerce_payment_complete subscription and other events).
	 *
	 * @param \WC_Order $order
	 * @return array<string, string>
	 */
	public static function build_wc_order_data( $order ): array {
		return array(
			'client_name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email'            => $order->get_billing_email(),
			'order_id'         => (string) $order->get_order_number(),
			'order_total'      => $order->get_total() . ' ' . $order->get_currency(),
			'date'             => wp_date( get_option( 'date_format' ) ),
			// Internal key (not a placeholder): delivery writes the URL to order meta.
			'_wc_order_id'     => (string) $order->get_id(),
		);
	}

	/**
	 * Elementor Pro Forms: add the PDF URL to the AJAX response — front-end
	 * JS picks it up on the submit_success event.
	 *
	 * @param mixed $record       \ElementorPro\Modules\Forms\Classes\Form_Record.
	 * @param mixed $ajax_handler \ElementorPro\Modules\Forms\Classes\Ajax_Handler.
	 */
	public function on_elementor_form( $record, $ajax_handler ): void {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		// Form fields: id => [ 'value' => … ]. Name and email are guessed defensively.
		$raw    = (array) $record->get( 'fields' );
		$name   = '';
		$email  = '';

		foreach ( $raw as $id => $field ) {
			$value = sanitize_text_field( (string) ( $field['value'] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			if ( '' === $email && is_email( $value ) ) {
				$email = $value;
			} elseif ( '' === $name && ! is_email( $value ) && in_array( $id, array( 'name', 'your-name', 'client_name', 'field_name' ), true ) ) {
				$name = $value;
			}
		}

		// Fallback: the first non-empty, non-email field.
		if ( '' === $name ) {
			foreach ( $raw as $field ) {
				$value = sanitize_text_field( (string) ( $field['value'] ?? '' ) );
				if ( '' !== $value && ! is_email( $value ) ) {
					$name = $value;
					break;
				}
			}
		}

		$this->run_trigger(
			'elementor_pro/forms/new_record',
			array(
				'client_name' => $name,
				'email'       => $email,
				'date'        => wp_date( get_option( 'date_format' ) ),
			)
		);

		$url = self::last_download_url();
		if ( $url && is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_response_data' ) ) {
			$ajax_handler->add_response_data( 'aipdf_download_url', esc_url_raw( $url ) );
		}
	}

	/**
	 * Amelia: a booking was created.
	 *
	 * The $args structure varies between Amelia versions, so data is read
	 * as defensively as possible, with several fallback paths.
	 *
	 * @param mixed $args Reservation/booking array from Amelia.
	 */
	public function on_amelia_booking( $args ): void {
		$args = is_array( $args ) ? $args : array();

		$customer = $args['customer']
			?? ( $args['booking']['customer'] ?? array() );

		$first = $customer['firstName'] ?? '';
		$last  = $customer['lastName'] ?? '';

		$this->run_trigger(
			'amelia_after_booking_added',
			array(
				'client_name' => trim( $first . ' ' . $last ),
				'email'       => $customer['email'] ?? '',
				'ticket_id'    => (string) ( $args['booking']['id'] ?? $args['id'] ?? '' ),
				'date'         => wp_date( get_option( 'date_format' ) ),
				'booking_date' => $args['appointment']['bookingStart'] ?? '',
				'service_name' => $args['service']['name'] ?? ( $args['appointment']['service']['name'] ?? '' ),
				// QR code with the booking number: kept for templates still
				// using the old <img src="{{qr_code}}"> approach — new AI
				// templates use mPDF's native <barcode> tag instead.
				'qr_code'     => $this->qr_url( 'amelia-' . ( $args['booking']['id'] ?? '' ) ),
			)
		);
	}

	/**
	 * Contact Form 7: a form was submitted.
	 *
	 * @param mixed $contact_form WPCF7_ContactForm.
	 */
	public function on_cf7_submit( $contact_form ): void {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}

		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}

		$posted = $submission->get_posted_data();

		$this->run_trigger(
			'wpcf7_mail_sent',
			array(
				'client_name' => sanitize_text_field( (string) ( $posted['your-name'] ?? '' ) ),
				'email'       => sanitize_email( (string) ( $posted['your-email'] ?? '' ) ),
				'date'        => wp_date( get_option( 'date_format' ) ),
			)
		);
	}

	/* -------------------------------------------------------------------
	 * Delivery.
	 * ---------------------------------------------------------------- */

	private function send_email_with_pdf( string $email, WP_Post $template, string $pdf_path, array $data ): void {
		$subject = apply_filters(
			'aipdf_email_subject',
			sprintf(
				/* translators: %s: site name. */
				__( 'Your document from %s', 'ai-pdf-generator' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			$template,
			$data
		);

		$body = apply_filters(
			'aipdf_email_body',
			__( "Hello!\n\nYour document is attached to this email.", 'ai-pdf-generator' ),
			$template,
			$data
		);

		$sent = wp_mail( $email, $subject, $body, array(), array( $pdf_path ) );

		if ( $sent ) {
			AIPDF_Logger::get_instance()->info(
				sprintf( 'Email with PDF (template #%d) sent to %s.', $template->ID, $email )
			);
		} else {
			AIPDF_Logger::get_instance()->error(
				sprintf( 'wp_mail failed to send the PDF email (template #%d) to %s.', $template->ID, $email )
			);
		}
	}

	/**
	 * QR code URL via a public service (for the MVP).
	 * In production, prefer a local library (endroid/qr-code) or mPDF's
	 * native <barcode> tag, which the AI-generated templates now use directly.
	 */
	private function qr_url( string $payload ): string {
		return 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query(
			array(
				'size' => '120x120',
				'data' => $payload,
			)
		);
	}
}
