<?php
/**
 * download_link delivery: shows a link to the generated PDF right after
 * the user's action.
 *
 * - WooCommerce: a button on the thank-you page (woocommerce_thankyou).
 * - CF7 / Elementor Pro: the URL is added to the form's JSON response,
 *   front-end JS draws a button under the form.
 * - Amelia (Vue/AJAX): a token in a cookie + transient; on a custom
 *   thank-you page (redirect) the button is rendered by the
 *   [aipdf_download_button] shortcode.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Delivery {

	/**
	 * Cookie holding a one-time download token (for redirect scenarios).
	 */
	private const COOKIE = 'aipdf_dl_token';

	/**
	 * How long the download link's transient lives.
	 */
	private const TOKEN_TTL = 15 * MINUTE_IN_SECONDS;

	public function __construct() {
		// Universal hook: fires after every generation.
		add_action( 'aipdf_pdf_generated', array( $this, 'store_download' ), 10, 4 );

		// WooCommerce: button on the thank-you page.
		add_action( 'woocommerce_thankyou', array( $this, 'render_wc_button' ), 20 );

		// CF7: add the URL to the form's JSON response.
		add_filter( 'wpcf7_feedback_response', array( $this, 'cf7_add_download_url' ) );

		// Thank-you page for Amelia and other redirect scenarios.
		add_shortcode( 'aipdf_download_button', array( $this, 'render_shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
	}

	/**
	 * After generating a PDF with action_type = download_link:
	 * - for WooCommerce, store the URL in order meta (the thank-you page is
	 *   a SEPARATE request, so persistent storage is needed);
	 * - for everything else, a short-lived cookie + transient token
	 *   (works for Amelia's AJAX flow: headers haven't been sent yet).
	 *
	 * @param array{path:string,url:string} $result   Render result.
	 * @param string                        $trigger  Trigger key.
	 * @param WP_Post                       $template Template post.
	 * @param array<string, string>         $data     Placeholder data.
	 */
	public function store_download( array $result, string $trigger, WP_Post $template, array $data ): void {
		if ( 'download_link' !== get_post_meta( $template->ID, '_aipdf_action_type', true ) ) {
			return;
		}

		// WooCommerce: persistent storage in order meta.
		if ( 'woocommerce_payment_complete' === $trigger && ! empty( $data['_wc_order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $data['_wc_order_id'] );
			if ( $order ) {
				$order->update_meta_data( '_aipdf_pdf_url', esc_url_raw( $result['url'] ) );
				$order->save();
			}
			return;
		}

		// Redirect scenarios (Amelia etc.): the token lives for 15 minutes.
		if ( headers_sent() ) {
			return; // Too late to set a cookie — only the email/hook delivery remains.
		}

		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			return;
		}

		set_transient( 'aipdf_dl_' . $token, esc_url_raw( $result['url'] ), self::TOKEN_TTL );

		setcookie(
			self::COOKIE,
			$token,
			array(
				'expires'  => time() + self::TOKEN_TTL,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * WooCommerce: a large "Download document" button on the thank-you page.
	 *
	 * If payment_complete hasn't fired yet (COD, bank transfer), the PDF is
	 * generated right here and cached in meta, to avoid doing it twice.
	 */
	public function render_wc_button( $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$url = (string) $order->get_meta( '_aipdf_pdf_url' );

		if ( '' === $url ) {
			$renderer = new AIPDF_PDF_Renderer();
			$template = $renderer->find_template_by_trigger( 'woocommerce_payment_complete' );

			if ( ! $template || 'download_link' !== get_post_meta( $template->ID, '_aipdf_action_type', true ) ) {
				return;
			}

			$result = $renderer->render_to_file(
				$template->ID,
				array(
					'client_name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'email'       => $order->get_billing_email(),
					'order_id'    => (string) $order->get_order_number(),
					'date'        => wp_date( get_option( 'date_format' ) ),
				)
			);

			if ( is_wp_error( $result ) ) {
				return;
			}

			$url = $result['url'];
			$order->update_meta_data( '_aipdf_pdf_url', esc_url_raw( $url ) );
			$order->save();
		}

		printf(
			'<div class="aipdf-download-wrap" style="margin:24px 0;"><a href="%s" target="_blank" rel="noopener" class="button aipdf-download-btn" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:600;">%s</a></div>',
			esc_url( $url ),
			esc_html( apply_filters( 'aipdf_download_button_text', __( 'Download your document (PDF)', 'ai-pdf-generator' ) ) )
		);
	}

	/**
	 * CF7: our wpcf7_mail_sent handler has already run within THIS SAME
	 * request, so we just add the URL to the response. frontend.js draws
	 * the button on the wpcf7mailsent event.
	 *
	 * @param array<string, mixed> $response CF7's JSON response.
	 */
	public function cf7_add_download_url( $response ) {
		$url = AIPDF_Trigger_Dispatcher::last_download_url();
		if ( $url ) {
			$response['aipdf_download_url'] = esc_url_raw( $url );
		}

		return $response;
	}

	/**
	 * Shortcode for a custom thank-you page (Amelia redirect, etc.):
	 * [aipdf_download_button text="Download ticket"]
	 *
	 * NOTE: the page containing the shortcode must be excluded from caching
	 * (the token is personal to the visitor).
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array( 'text' => __( 'Download your document (PDF)', 'ai-pdf-generator' ) ),
			$atts,
			'aipdf_download_button'
		);

		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $token ) {
			return '';
		}

		$url = get_transient( 'aipdf_dl_' . $token );
		if ( ! $url ) {
			return '';
		}

		return sprintf(
			'<a href="%s" target="_blank" rel="noopener" class="button aipdf-download-btn" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:600;">%s</a>',
			esc_url( $url ),
			esc_html( $atts['text'] )
		);
	}

	/**
	 * Front-end JS is only needed when CF7 or Elementor Pro is active.
	 */
	public function enqueue_frontend(): void {
		if ( ! defined( 'WPCF7_VERSION' ) && ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			return;
		}

		wp_enqueue_script(
			'aipdf-frontend',
			AIPDF_PLUGIN_URL . 'assets/frontend.js',
			array(),
			AIPDF_VERSION,
			true
		);

		wp_localize_script(
			'aipdf-frontend',
			'aipdfFront',
			array(
				'buttonText' => apply_filters( 'aipdf_download_button_text', __( 'Download your document (PDF)', 'ai-pdf-generator' ) ),
			)
		);
	}
}
