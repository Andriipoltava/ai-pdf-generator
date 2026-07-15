<?php
/**
 * Garbage Collection: щоденне видалення старих PDF-файлів
 * із uploads/ai-pdf-generator/ через WP Cron.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Cron_Cleanup {

	/**
	 * Назва cron-події.
	 */
	public const HOOK = 'aipdf_daily_cleanup';

	/**
	 * Опція з часом зберігання PDF (у днях).
	 */
	public const OPTION_RETENTION = 'aipdf_retention_days';

	/**
	 * Fallback, якщо опція порожня або некоректна.
	 */
	public const DEFAULT_RETENTION_DAYS = 7;

	public function __construct() {
		add_action( self::HOOK, array( $this, 'run_cleanup' ) );

		// Самовідновлення розкладу: якщо плагін оновили без реактивації
		// (або cron-подію хтось зніс), плануємо заново.
		add_action( 'init', array( $this, 'maybe_reschedule' ) );
	}

	/**
	 * Активація плагіна: плануємо щоденну подію.
	 * Викликається з register_activation_hook у головному файлі.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Деактивація плагіна: знімаємо подію з розкладу.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Захисне перепланування (дешева перевірка — один запит до cron-масиву).
	 */
	public function maybe_reschedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Обробник події: видаляє .pdf-файли, старіші за N днів.
	 *
	 * Скануємо ЛИШЕ верхній рівень uploads/ai-pdf-generator/ —
	 * glob('*.pdf') не заходить у підтеки, тож tmp/ (кеш шрифтів mPDF)
	 * та index.html лишаються недоторканими за побудовою.
	 */
	public function run_cleanup(): void {
		$days = absint( get_option( self::OPTION_RETENTION, self::DEFAULT_RETENTION_DAYS ) );
		if ( $days < 1 ) {
			$days = self::DEFAULT_RETENTION_DAYS;
		}

		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'ai-pdf-generator';

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$threshold = time() - $days * DAY_IN_SECONDS;
		$files     = glob( $dir . '/*.pdf' );
		$deleted   = 0;

		foreach ( (array) $files as $file ) {
			// Подвійний захист: тільки файли, тільки .pdf, без символічних посилань.
			if ( ! is_file( $file ) || is_link( $file ) ) {
				continue;
			}

			$mtime = filemtime( $file );
			if ( false === $mtime || $mtime > $threshold ) {
				continue;
			}

			if ( unlink( $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- прибирання власних файлів в uploads.
				$deleted++;
			}
		}

		/**
		 * Після прибирання: для логування або моніторингу.
		 *
		 * @param int $deleted Кількість видалених файлів.
		 * @param int $days    Поріг зберігання у днях.
		 */
		do_action( 'aipdf_cleanup_done', $deleted, $days );
	}
}
