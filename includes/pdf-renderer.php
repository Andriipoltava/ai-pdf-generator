<?php
/**
 * Рендерер PDF на базі mPDF.
 *
 * Витягує збережений ШІ-шаблон із CPT, підставляє дані у плейсхолдери
 * {{client_name}}, {{qr_code}} тощо та конвертує HTML у PDF.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_PDF_Renderer {

	/**
	 * Піддиректорія в uploads для згенерованих PDF та temp-файлів mPDF.
	 */
	private const UPLOADS_SUBDIR = 'ai-pdf-generator';

	/**
	 * Чи встановлена бібліотека mPDF (composer install у папці плагіна).
	 */
	public static function is_available(): bool {
		return class_exists( \Mpdf\Mpdf::class );
	}

	/**
	 * Останній опублікований шаблон для заданого тригера.
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
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- один пост, адмін-сценарій.
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
	 * Рендер шаблону в сирі PDF-байти.
	 *
	 * @param int                   $post_id ID поста-шаблону.
	 * @param array<string, string> $data    Дані для плейсхолдерів (client_name, email, …).
	 * @return string|WP_Error PDF як бінарний рядок.
	 */
	public function render( int $post_id, array $data ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'aipdf_no_mpdf',
				__( 'Бібліотека mPDF не встановлена. Виконайте `composer install` у папці плагіна.', 'ai-pdf-generator' )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || AIPDF_Plugin::CPT !== $post->post_type ) {
			return new WP_Error( 'aipdf_no_template', __( 'Шаблон не знайдено.', 'ai-pdf-generator' ) );
		}

		// Порядок пріоритету (пізніше перекриває раніше):
		// бренд → значення візуальних полів → конкретні дані події.
		$data = array_merge(
			AIPDF_Brand::placeholders(),
			AIPDF_Fields::values( AIPDF_Fields::get( $post_id ) ),
			$data
		);

		$html = $this->fill_placeholders( $post->post_content, $data );

		$paper_size = (string) get_post_meta( $post_id, '_aipdf_paper_size', true );

		try {
			$mpdf = new \Mpdf\Mpdf( $this->build_mpdf_config( $paper_size ) );
			$mpdf->SetTitle( $post->post_title );
			// UTF-8 та кирилиця працюють з коробки: mode 'utf-8' + DejaVu Sans.
			$mpdf->WriteHTML( $html );

			$pdf = $mpdf->Output( '', \Mpdf\Output\Destination::STRING_RETURN );

			AIPDF_Logger::get_instance()->info(
				sprintf(
					'PDF згенеровано: шаблон #%d («%s»), тригер %s, формат %s, %d байт.',
					$post_id,
					$post->post_title,
					(string) get_post_meta( $post_id, '_aipdf_trigger_plugin', true ),
					'' !== $paper_size ? $paper_size : 'A4',
					strlen( $pdf )
				)
			);

			return $pdf;
		} catch ( \Mpdf\MpdfException $e ) {
			AIPDF_Logger::get_instance()->error(
				sprintf( 'Помилка рендеру mPDF (шаблон #%d): %s', $post_id, $e->getMessage() )
			);

			return new WP_Error(
				'aipdf_mpdf_error',
				sprintf(
					/* translators: %s: mPDF error message. */
					__( 'Помилка mPDF: %s', 'ai-pdf-generator' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Рендер у файл в uploads. Повертає шлях та URL.
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

		// Непередбачуване ім'я файлу, щоб URL не можна було вгадати.
		$filename = sanitize_file_name(
			sprintf( 'pdf-%d-%s.pdf', $post_id, wp_generate_password( 16, false ) )
		);
		$path     = trailingslashit( $dir ) . $filename;

		if ( false === file_put_contents( $path, $pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- локальний запис в uploads.
			return new WP_Error( 'aipdf_write_failed', __( 'Не вдалося записати PDF-файл.', 'ai-pdf-generator' ) );
		}

		$uploads = wp_upload_dir();

		return array(
			'path' => $path,
			'url'  => trailingslashit( $uploads['baseurl'] ) . self::UPLOADS_SUBDIR . '/' . $filename,
		);
	}

	/**
	 * Конфіг mPDF: формат сторінки + temp-директорія.
	 *
	 * «800x400» трактуємо як пікселі при 96 DPI і конвертуємо в мм
	 * (1 px = 25.4 / 96 мм) — mPDF приймає кастомний формат у мм.
	 *
	 * @return array<string, mixed>
	 */
	private function build_mpdf_config( string $paper_size ): array {
		$config = array(
			'mode'    => 'utf-8',
			'tempDir' => $this->get_temp_dir(),
			// DejaVu Sans — вбудований шрифт mPDF із повною кирилицею.
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
			// Для стандартних форматів залишаємо звичні поля документа.
			$config['margin_left']   = 10;
			$config['margin_right']  = 10;
			$config['margin_top']    = 10;
			$config['margin_bottom'] = 10;
		}

		return $config;
	}

	/**
	 * Підстановка даних у плейсхолдери виду {{key}}.
	 *
	 * Значення екрануються: URL-подібні ключі (qr_code, logo, *_url) — через
	 * esc_url, решта — esc_html. Невідомі плейсхолдери замінюються на порожньо.
	 */
	private function fill_placeholders( string $html, array $data ): string {
		return self::substitute( $html, $data );
	}

	/**
	 * Підстановка даних у плейсхолдери {{key}} (спільна логіка для рендеру
	 * PDF і для превю в адмінці/Playground).
	 *
	 * @param array<string, string> $data
	 */
	public static function substitute( string $html, array $data ): string {
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
	 * Демо-дані для превю (бренд + типові поля подій). Спільне джерело для
	 * редактора шаблону та Playground, щоб превю було однаковим.
	 *
	 * @return array<string, string>
	 */
	public static function sample_data(): array {
		return array_merge(
			AIPDF_Brand::sample_placeholders(),
			array(
				'client_name'   => 'Іван Петренко',
				'customer_name' => 'Іван Петренко',
				'billing_name'  => 'Іван Петренко',
				'attendee_name' => 'Іван Петренко',
				'user_name'     => 'Іван Петренко',
				'donor_name'    => 'Іван Петренко',
				'email'         => 'client@example.com',
				'billing_email' => 'client@example.com',
				'user_email'    => 'client@example.com',
				'sender_email'  => 'client@example.com',
				'date'          => wp_date( get_option( 'date_format' ) ),
				'order_id'      => '1024',
				'order_total'   => '1250.00 UAH',
				'amount'        => '1250.00 UAH',
				'ticket_id'     => 'TCK-58291',
				'booking_date'  => wp_date( 'd.m.Y H:i' ),
				'service_name'  => 'Консультація',
				'event_name'    => 'Демо-подія',
				'course_name'   => 'Демо-курс',
				'products_table' => 'Товар A × 1 — 1250.00 UAH',
				'qr_code'       => 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=DEMO',
			)
		);
	}

	/**
	 * Директорія для збережених PDF: uploads/ai-pdf-generator/.
	 * Кладемо index.html, щоб вимкнути лістинг директорії.
	 *
	 * @return string|WP_Error
	 */
	private function get_storage_dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::UPLOADS_SUBDIR;

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'aipdf_mkdir_failed', __( 'Не вдалося створити директорію для PDF.', 'ai-pdf-generator' ) );
		}

		$index = $dir . '/index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/**
	 * mPDF потребує writable temp-директорію (генерує кеш шрифтів).
	 */
	private function get_temp_dir(): string {
		$uploads = wp_upload_dir();
		$tmp     = trailingslashit( $uploads['basedir'] ) . self::UPLOADS_SUBDIR . '/tmp';
		wp_mkdir_p( $tmp );

		return $tmp;
	}
}
