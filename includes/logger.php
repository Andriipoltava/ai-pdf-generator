<?php
/**
 * Audit Log: файловий логер із ротацією.
 *
 * Використання: AIPDF_Logger::get_instance()->log( 'Повідомлення', 'ERROR' );
 * Скорочення:   AIPDF_Logger::get_instance()->info() / ->error() / ->warning().
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Logger {

	/**
	 * Ім'я файлу логу в uploads/ai-pdf-generator/.
	 */
	private const LOG_FILENAME = 'aipdf.log';

	/**
	 * Поріг ротації: 5 MB.
	 */
	private const MAX_SIZE_BYTES = 5 * 1024 * 1024;

	/**
	 * Скільки останніх рядків лишати після ротації.
	 */
	private const KEEP_LINES = 1000;

	/**
	 * Дозволені рівні. Невідомий рівень приводиться до INFO.
	 */
	private const LEVELS = array( 'DEBUG', 'INFO', 'WARNING', 'ERROR' );

	private static ?AIPDF_Logger $instance = null;

	/**
	 * Повний шлях до файлу логу (порожній, якщо директорія недоступна).
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
			return; // Диск недоступний — логер стає no-op, але не валить плагін.
		}

		$this->log_file = $dir . '/' . self::LOG_FILENAME;
		$this->protect_dir( $dir );
	}

	/**
	 * Головний метод запису.
	 *
	 * @param string $message Текст події (без переносів — вони замінюються пробілами).
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

		// Один запис = один рядок: багаторядкові повідомлення сплющуємо.
		$message = trim( (string) preg_replace( '/\s+/', ' ', $message ) );

		$line = sprintf(
			'[%s] [%s] %s' . PHP_EOL,
			wp_date( 'Y-m-d H:i:s' ),
			$level,
			$message
		);

		// FILE_APPEND + LOCK_EX: атомарний дозапис без гонок між запитами.
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
	 * Останні N рядків логу для показу в адмінці.
	 *
	 * @return string[] Рядки у хронологічному порядку.
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
	 * Повне очищення логу (кнопка в адмінці).
	 */
	public function clear(): bool {
		if ( '' === $this->log_file || ! is_file( $this->log_file ) ) {
			return true;
		}

		return false !== file_put_contents( $this->log_file, '', LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Ротація: якщо файл перевищив 5 MB — лишаємо останні 1000 рядків.
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
			// Не вдалося прочитати — просто очищаємо, щоб не переповнити диск.
			file_put_contents( $this->log_file, '', LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return;
		}

		$tail = array_slice( $lines, -self::KEEP_LINES );
		array_unshift( $tail, sprintf( '[%s] [INFO] — лог обрізано ротацією (було %d рядків) —', wp_date( 'Y-m-d H:i:s' ), count( $lines ) ) );

		file_put_contents( $this->log_file, implode( PHP_EOL, $tail ) . PHP_EOL, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Захист від прямого доступу з вебу: .htaccess (Apache/LiteSpeed).
	 * Для nginx потрібне правило в конфігу сервера — див. README/нотатки.
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
