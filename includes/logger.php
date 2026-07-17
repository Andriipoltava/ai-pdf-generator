<?php
/**
 * Audit log: a file-based logger with rotation.
 *
 * Usage:     AIPDF_Logger::get_instance()->log( 'Message', 'ERROR' );
 * Shortcuts: AIPDF_Logger::get_instance()->info() / ->error() / ->warning().
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Logger {

	/**
	 * Log file name inside uploads/ai-pdf-generator/.
	 */
	private const LOG_FILENAME = 'aipdf.log';

	/**
	 * Rotation threshold: 5 MB.
	 */
	private const MAX_SIZE_BYTES = 5 * 1024 * 1024;

	/**
	 * How many of the most recent lines to keep after rotation.
	 */
	private const KEEP_LINES = 1000;

	/**
	 * Allowed levels. An unknown level falls back to INFO.
	 */
	private const LEVELS = array( 'DEBUG', 'INFO', 'WARNING', 'ERROR' );

	private static ?AIPDF_Logger $instance = null;

	/**
	 * Full path to the log file (empty if the directory is unavailable).
	 */
	private string $log_file = '';

	public static function get_instance(): AIPDF_Logger {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'ai-pdf-generator';

		if ( ! wp_mkdir_p( $dir ) ) {
			return; // Disk unavailable — the logger becomes a no-op but doesn't break the plugin.
		}

		$this->log_file = $dir . '/' . self::LOG_FILENAME;
		$this->protect_dir( $dir );
	}

	/**
	 * Main write method.
	 *
	 * @param string $message Event text (line breaks are flattened to spaces).
	 * @param string $level   DEBUG | INFO | WARNING | ERROR.
	 */
	public function log( string $message, string $level = 'INFO' ): void {
		if ( '' === $this->log_file ) {
			return;
		}

		$level = strtoupper( $level );
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'INFO';
		}

		$this->maybe_rotate();

		// One entry = one line: flatten multi-line messages.
		$message = trim( (string) preg_replace( '/\s+/', ' ', $message ) );

		$line = sprintf(
			'[%s] [%s] %s' . PHP_EOL,
			wp_date( 'Y-m-d H:i:s' ),
			$level,
			$message
		);

		// FILE_APPEND + LOCK_EX: atomic append, no race between requests.
		file_put_contents( $this->log_file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function info( string $message ): void {
		$this->log( $message, 'INFO' );
	}

	public function warning( string $message ): void {
		$this->log( $message, 'WARNING' );
	}

	public function error( string $message ): void {
		$this->log( $message, 'ERROR' );
	}

	/**
	 * Last N lines of the log, for display in the admin.
	 *
	 * @return string[] Lines in chronological order.
	 */
	public function tail( int $lines = 50 ): array {
		if ( '' === $this->log_file || ! is_file( $this->log_file ) ) {
			return array();
		}

		$all = file( $this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! is_array( $all ) ) {
			return array();
		}

		return array_slice( $all, -max( 1, $lines ) );
	}

	/**
	 * Fully clears the log (the admin's "Clear log" button).
	 */
	public function clear(): bool {
		if ( '' === $this->log_file || ! is_file( $this->log_file ) ) {
			return true;
		}

		return false !== file_put_contents( $this->log_file, '', LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Rotation: if the file exceeds 5 MB, keep only the last 1000 lines.
	 */
	private function maybe_rotate(): void {
		if ( ! is_file( $this->log_file ) ) {
			return;
		}

		clearstatcache( true, $this->log_file );
		$size = filesize( $this->log_file );
		if ( false === $size || $size <= self::MAX_SIZE_BYTES ) {
			return;
		}

		$lines = file( $this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! is_array( $lines ) ) {
			// Couldn't read it — just clear it out so the disk doesn't fill up.
			file_put_contents( $this->log_file, '', LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return;
		}

		$tail = array_slice( $lines, -self::KEEP_LINES );
		array_unshift( $tail, sprintf( '[%s] [INFO] — log truncated by rotation (was %d lines) —', wp_date( 'Y-m-d H:i:s' ), count( $lines ) ) );

		file_put_contents( $this->log_file, implode( PHP_EOL, $tail ) . PHP_EOL, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Blocks direct web access via .htaccess (Apache/LiteSpeed).
	 * For nginx, a rule is needed in the server config — see README/notes.
	 */
	private function protect_dir( string $dir ): void {
		$htaccess = $dir . '/.htaccess';
		if ( file_exists( $htaccess ) ) {
			return;
		}

		$rules = "<Files \"*.log\">\nRequire all denied\n</Files>\n";
		file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
