<?php
/**
 * mPDF-based PDF renderer.
 *
 * Pulls a saved AI template from the CPT, substitutes data into
 * placeholders like {{client_name}}, {{qr_code}}, etc., and converts the
 * HTML into a PDF.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_PDF_Renderer {

	/**
	 * Subdirectory in uploads for generated PDFs and mPDF's temp files.
	 */
	private const UPLOADS_SUBDIR = 'ai-pdf-generator';

	/**
	 * Whether the mPDF library is installed (composer install in the plugin folder).
	 */
	public static function is_available(): bool {
		return class_exists( \Mpdf\Mpdf::class );
	}

	/**
	 * Most recently published template for the given trigger.
	 */
	public function find_template_by_trigger( string $trigger ): ?WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => AIPDF_Plugin::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- single post, admin-triggered scenario.
					array(
						'key'   => '_aipdf_trigger_plugin',
						'value' => $trigger,
					),
				),
			)
		);

		return $posts[0] ?? null;
	}

	/**
	 * Renders a template into raw PDF bytes.
	 *
	 * @param int                   $post_id Template post ID.
	 * @param array<string, string> $data    Placeholder data (client_name, email, …).
	 * @return string|WP_Error PDF as a binary string.
	 */
	public function render( int $post_id, array $data ) {
		$post = get_post( $post_id );
		if ( ! $post || AIPDF_Plugin::CPT !== $post->post_type ) {
			return new WP_Error( 'aipdf_no_template', __( 'Template not found.', 'ai-pdf-generator' ) );
		}

		// Priority order (later overrides earlier):
		// brand -> visual field values -> concrete event data.
		$data = array_merge(
			AIPDF_Brand::placeholders(),
			AIPDF_Fields::values( AIPDF_Fields::get( $post_id ) ),
			$data
		);

		$html       = $this->fill_placeholders( $post->post_content, $data );
		$paper_size = (string) get_post_meta( $post_id, '_aipdf_paper_size', true );

		$pdf = $this->render_html( $html, $post->post_title, $paper_size );

		if ( is_wp_error( $pdf ) ) {
			AIPDF_Logger::get_instance()->error(
				sprintf( 'mPDF render error (template #%d): %s', $post_id, $pdf->get_error_message() )
			);
			return $pdf;
		}

		AIPDF_Logger::get_instance()->info(
			sprintf(
				'PDF generated: template #%d ("%s"), trigger %s, format %s, %d bytes.',
				$post_id,
				$post->post_title,
				(string) get_post_meta( $post_id, '_aipdf_trigger_plugin', true ),
				'' !== $paper_size ? $paper_size : 'A4',
				strlen( $pdf )
			)
		);

		return $pdf;
	}

	/**
	 * Renders already-substituted HTML into PDF bytes. Shared by render()
	 * (AI-generated CPT templates) and static, no-AI templates.
	 */
	public function render_html( string $html, string $title, string $paper_size = 'A4' ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'aipdf_no_mpdf',
				__( 'The mPDF library is not installed. Run `composer install` inside the plugin folder.', 'ai-pdf-generator' )
			);
		}

		try {
			$mpdf = new \Mpdf\Mpdf( $this->build_mpdf_config( $paper_size ) );
			$mpdf->SetTitle( $title );
			// UTF-8 and Cyrillic work out of the box: mode 'utf-8' + DejaVu Sans.
			$mpdf->WriteHTML( $html );

			return $mpdf->Output( '', \Mpdf\Output\Destination::STRING_RETURN );
		} catch ( \Mpdf\MpdfException $e ) {
			return new WP_Error(
				'aipdf_mpdf_error',
				sprintf(
					/* translators: %s: mPDF error message. */
					__( 'mPDF error: %s', 'ai-pdf-generator' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Renders to a file in uploads. Returns the path and URL.
	 *
	 * @return array{path:string,url:string}|WP_Error
	 */
	public function render_to_file( int $post_id, array $data ) {
		$pdf = $this->render( $post_id, $data );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}

		$dir = $this->get_storage_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		// Unpredictable filename so the URL can't be guessed.
		$filename = sanitize_file_name(
			sprintf( 'pdf-%d-%s.pdf', $post_id, wp_generate_password( 16, false ) )
		);
		$path     = trailingslashit( $dir ) . $filename;

		if ( false === file_put_contents( $path, $pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- local write inside uploads.
			return new WP_Error( 'aipdf_write_failed', __( 'Failed to write the PDF file.', 'ai-pdf-generator' ) );
		}

		$uploads = wp_upload_dir();

		return array(
			'path' => $path,
			'url'  => trailingslashit( $uploads['baseurl'] ) . self::UPLOADS_SUBDIR . '/' . $filename,
		);
	}

	/**
	 * mPDF config: page format + temp directory.
	 *
	 * "800x400" is treated as pixels at 96 DPI and converted to mm
	 * (1 px = 25.4 / 96 mm) — mPDF accepts a custom format in mm.
	 *
	 * @return array<string, mixed>
	 */
	private function build_mpdf_config( string $paper_size ): array {
		$config = array(
			'mode'    => 'utf-8',
			'tempDir' => $this->get_temp_dir(),
			// DejaVu Sans — mPDF's bundled font with full Cyrillic support.
			'default_font' => 'dejavusans',
			'margin_left'   => 0,
			'margin_right'  => 0,
			'margin_top'    => 0,
			'margin_bottom' => 0,
		);

		if ( preg_match( '/^(\d{2,5})x(\d{2,5})$/i', trim( $paper_size ), $m ) ) {
			$px_to_mm         = 25.4 / 96;
			$config['format'] = array(
				round( (int) $m[1] * $px_to_mm, 2 ),
				round( (int) $m[2] * $px_to_mm, 2 ),
			);
		} else {
			$known            = array( 'a3', 'a4', 'a5', 'letter', 'legal' );
			$size             = strtolower( trim( $paper_size ) );
			$config['format'] = in_array( $size, $known, true ) ? strtoupper( $size ) : 'A4';
			// Keep normal document margins for standard paper formats.
			$config['margin_left']   = 10;
			$config['margin_right']  = 10;
			$config['margin_top']    = 10;
			$config['margin_bottom'] = 10;
		}

		return $config;
	}

	/**
	 * Substitutes data into {{key}}-style placeholders.
	 *
	 * Values are escaped: URL-like keys (qr_code, logo, *_url) via esc_url,
	 * everything else via esc_html. Unknown placeholders are replaced with
	 * an empty string.
	 */
	private function fill_placeholders( string $html, array $data ): string {
		return self::substitute( $html, $data );
	}

	/**
	 * Sanitizes an AI-generated (or manually edited) HTML template.
	 *
	 * A plain `wp_kses_post()` would strip mPDF's native `<barcode>` tag —
	 * it isn't part of WordPress's standard "post" allowed-tags list, since
	 * it's an mPDF-specific PDF-rendering instruction, not real HTML. This
	 * starts from the same trusted baseline (everything wp_kses_post()
	 * allows: tables, inline style, <img>, etc. — still no <script>, no
	 * event handlers) and additionally allows <barcode code type size>,
	 * so QR codes generated via the native mPDF tag survive sanitization.
	 */
	public static function sanitize_html( string $html ): string {
		$allowed = wp_kses_allowed_html( 'post' );

		$allowed['barcode'] = array(
			'code'          => true,
			'type'          => true,
			'size'          => true,
			'height'        => true,
			'color'         => true,
			'bgcolor'       => true,
			'text'          => true,
			'showtext'      => true,
			'disableborder' => true,
			'error'         => true,
			'quietzone'     => true,
		);

		return wp_kses( $html, $allowed );
	}

	/**
	 * Substitutes data into {{key}} placeholders (shared logic for PDF
	 * rendering and for the preview in the admin/Playground).
	 *
	 * @param array<string, string> $data
	 */
	public static function substitute( string $html, array $data ): string {
		// Drop <img> tags whose src is a single placeholder (e.g.
		// {{logo_url}}) that has no value — an empty src otherwise renders
		// as a broken-image icon in both the browser preview and the PDF.
		$html = (string) preg_replace_callback(
			'/<img\b[^>]*\bsrc\s*=\s*"\{\{\s*([a-z0-9_]+)\s*\}\}"[^>]*\/?>/i',
			static function ( array $m ) use ( $data ): string {
				$key   = strtolower( $m[1] );
				$value = $data[ $key ] ?? '';
				return '' === trim( (string) $value ) ? '' : $m[0];
			},
			$html
		);

		return (string) preg_replace_callback(
			'/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
			static function ( array $m ) use ( $data ): string {
				$key   = strtolower( $m[1] );
				$value = $data[ $key ] ?? '';

				if ( '' === $value ) {
					return '';
				}

				$is_url_key = in_array( $key, array( 'qr_code', 'logo', 'image' ), true )
					|| str_ends_with( $key, '_url' );

				return $is_url_key ? esc_url( $value ) : esc_html( $value );
			},
			$html
		);
	}

	/**
	 * Sample data for previews (brand + typical event fields). Shared
	 * source for the template editor and the Playground, so previews look
	 * consistent everywhere.
	 *
	 * @return array<string, string>
	 */
	public static function sample_data(): array {
		return array_merge(
			AIPDF_Brand::sample_placeholders(),
			array(
				'client_name'   => 'John Smith',
				'customer_name' => 'John Smith',
				'billing_name'  => 'John Smith',
				'attendee_name' => 'John Smith',
				'user_name'     => 'John Smith',
				'donor_name'    => 'John Smith',
				'email'         => 'client@example.com',
				'billing_email' => 'client@example.com',
				'user_email'    => 'client@example.com',
				'sender_email'  => 'client@example.com',
				'date'          => wp_date( get_option( 'date_format' ) ),
				'order_id'      => '1024',
				'order_total'   => '$1,250.00',
				'amount'        => '$1,250.00',
				'ticket_id'     => 'TCK-58291',
				'booking_date'  => wp_date( 'd.m.Y H:i' ),
				'service_name'  => 'Consultation',
				'event_name'    => 'Sample Event',
				'course_name'   => 'Sample Course',
				'products_table' => 'Product A x 1 — $1,250.00',
				'qr_code'       => 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=DEMO',
			)
		);
	}

	/**
	 * Directory for stored PDFs: uploads/ai-pdf-generator/.
	 * An index.html is placed there to disable directory listing.
	 *
	 * @return string|WP_Error
	 */
	private function get_storage_dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::UPLOADS_SUBDIR;

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'aipdf_mkdir_failed', __( 'Failed to create the PDF storage directory.', 'ai-pdf-generator' ) );
		}

		$index = $dir . '/index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/**
	 * mPDF needs a writable temp directory (it caches fonts there).
	 */
	private function get_temp_dir(): string {
		$uploads = wp_upload_dir();
		$tmp     = trailingslashit( $uploads['basedir'] ) . self::UPLOADS_SUBDIR . '/tmp';
		wp_mkdir_p( $tmp );

		return $tmp;
	}
}
