<?php
/**
 * Garbage collection: daily deletion of old PDF files from
 * uploads/ai-pdf-generator/ via WP Cron.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Cron_Cleanup {

	/**
	 * Cron event name.
	 */
	public const HOOK = 'aipdf_daily_cleanup';

	/**
	 * Option storing the PDF retention period (in days).
	 */
	public const OPTION_RETENTION = 'aipdf_retention_days';

	/**
	 * Fallback if the option is empty or invalid.
	 */
	public const DEFAULT_RETENTION_DAYS = 7;

	public function __construct() {
		add_action( self::HOOK, array( $this, 'run_cleanup' ) );

		// Self-healing schedule: if the plugin was updated without
		// reactivation (or the cron event got removed), reschedule it.
		add_action( 'init', array( $this, 'maybe_reschedule' ) );
	}

	/**
	 * Plugin activation: schedules the daily event.
	 * Called from register_activation_hook in the main file.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Plugin deactivation: removes the event from the schedule.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Defensive rescheduling (a cheap check — a single query against the cron array).
	 */
	public function maybe_reschedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Event handler: deletes .pdf files older than N days.
	 *
	 * Only the top level of uploads/ai-pdf-generator/ is scanned —
	 * glob('*.pdf') doesn't descend into subfolders, so tmp/ (mPDF's font
	 * cache) and index.html are left untouched by construction.
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
			// Double safety net: files only, .pdf only, no symlinks.
			if ( ! is_file( $file ) || is_link( $file ) ) {
				continue;
			}

			$mtime = filemtime( $file );
			if ( false === $mtime || $mtime > $threshold ) {
				continue;
			}

			if ( unlink( $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- cleaning up our own files in uploads.
				$deleted++;
			}
		}

		/**
		 * Fires after cleanup, for logging or monitoring.
		 *
		 * @param int $deleted Number of deleted files.
		 * @param int $days    Retention threshold in days.
		 */
		do_action( 'aipdf_cleanup_done', $deleted, $days );
	}
}
