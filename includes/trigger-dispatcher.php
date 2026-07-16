<?php
/**
 * Диспетчер тригерів: слухає реальні хуки плагінів (Amelia, WooCommerce,
 * Contact Form 7, …), знаходить збережений ШІ-шаблон за тригером
 * і запускає генерацію PDF.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Trigger_Dispatcher {

	private AIPDF_PDF_Renderer $renderer;

	/**
	 * Результат останньої генерації в межах ПОТОЧНОГО запиту:
	 * [ 'url' => string, 'action_type' => string ]. Потрібен доставці
	 * (CF7/Elementor), яка фільтрує JSON-відповідь у тому ж запиті.
	 *
	 * @var array{url:string,action_type:string}|null
	 */
	private static ?array $last_result = null;

	public function __construct() {
		$this->renderer = new AIPDF_PDF_Renderer();

		// --- Хуки сторонніх плагінів реєструємо ЛИШЕ якщо плагін активний. ---
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
		 * Універсальна точка входу для решти тригерів:
		 * do_action( 'aipdf_run_trigger', 'bookly_booking_done', [ 'client_name' => …, 'email' => … ] );
		 */
		add_action( 'aipdf_run_trigger', array( $this, 'run_trigger' ), 10, 2 );
	}

	/**
	 * Головний конвеєр: тригер → шаблон із БД → PDF → доставка.
	 *
	 * @param string                $trigger Один із ключів каталогу AIPDF_Triggers.
	 * @param array<string, string> $data    Дані для плейсхолдерів.
	 */
	public function run_trigger( string $trigger, array $data ): void {
		if ( ! in_array( $trigger, AIPDF_Triggers::all(), true ) ) {
			return;
		}

		$template = $this->renderer->find_template_by_trigger( $trigger );
		if ( ! $template ) {
			return; // Для цього тригера шаблон ще не згенерований — тихо виходимо.
		}

		if ( ! AIPDF_Conditions::matches( $template->ID, $data ) ) {
			AIPDF_Logger::get_instance()->info(
				sprintf( 'Тригер %s: умови генерації не виконано — PDF не створюється.', $trigger )
			);
			return; // Умови (Conditional Logic) не виконані — не витрачаємо рендер/API даремно.
		}

		$result = $this->renderer->render_to_file( $template->ID, $data );
		if ( is_wp_error( $result ) ) {
			// Не валимо чужий процес (оплату/бронювання) — лише лог.
			AIPDF_Logger::get_instance()->error(
				sprintf( 'Генерація за тригером %s не вдалася: %s', $trigger, $result->get_error_message() )
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
		 * Хук для розширення: доставка download_link, запис у CRM тощо.
		 *
		 * @param array   $result   { path, url } згенерованого PDF.
		 * @param string  $trigger  Тригер, що спрацював.
		 * @param WP_Post $template Пост-шаблон.
		 * @param array   $data     Дані плейсхолдерів.
		 */
		do_action( 'aipdf_pdf_generated', $result, $trigger, $template, $data );
	}

	/**
	 * URL PDF, згенерованого в поточному запиті з action_type = download_link.
	 * Використовується доставкою (CF7 / Elementor) у фільтрах відповіді.
	 */
	public static function last_download_url(): string {
		if ( self::$last_result && 'download_link' === self::$last_result['action_type'] ) {
			return self::$last_result['url'];
		}

		return '';
	}

	/* -------------------------------------------------------------------
	 * Адаптери під конкретні плагіни: витягуємо дані у єдиний формат.
	 * ---------------------------------------------------------------- */

	/**
	 * WooCommerce: замовлення оплачено.
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
	 * Дані WooCommerce-замовлення у форматі для run_trigger(). Статичний
	 * і публічний — той самий код перевикористовує масова генерація
	 * (AIPDF_Bulk_Actions) для СТАРИХ замовлень, без повторної реєстрації
	 * хуків (на відміну від `new AIPDF_Trigger_Dispatcher()`, що задублювало
	 * б підписку на woocommerce_payment_complete та інші події).
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
			// Не плейсхолдер шаблону — лише для перевірки умов (Conditional Logic).
			'product_category' => self::wc_order_categories( $order ),
			// Внутрішній ключ (не плейсхолдер): доставка пише URL у мета замовлення.
			'_wc_order_id'     => (string) $order->get_id(),
		);
	}

	/**
	 * Назви всіх категорій товарів у замовленні (через кому) — доступно
	 * як поле «product_category» в умовах генерації (Conditional Logic).
	 */
	private static function wc_order_categories( $order ): string {
		$names = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$terms = get_the_terms( $product->get_id(), 'product_cat' );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$names[ $term->name ] = true;
				}
			}
		}

		return implode( ', ', array_keys( $names ) );
	}

	/**
	 * Elementor Pro Forms: додаємо URL PDF у відповідь AJAX —
	 * фронтенд-JS підхопить його в події submit_success.
	 *
	 * @param mixed $record       \ElementorPro\Modules\Forms\Classes\Form_Record.
	 * @param mixed $ajax_handler \ElementorPro\Modules\Forms\Classes\Ajax_Handler.
	 */
	public function on_elementor_form( $record, $ajax_handler ): void {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		// Поля форми: id => [ 'value' => … ]. Ім'я та email вгадуємо захищено.
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

		// Fallback: перше непорожнє не-email поле.
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
	 * Amelia: бронювання створене.
	 *
	 * Структура $args відрізняється між версіями Amelia, тому читаємо
	 * дані максимально захищено, з кількома fallback-шляхами.
	 *
	 * @param mixed $args Масив reservation/booking від Amelia.
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
				// QR з номером бронювання: mPDF вставить <img> за цим URL.
				'qr_code'     => $this->qr_url( 'amelia-' . ( $args['booking']['id'] ?? '' ) ),
			)
		);
	}

	/**
	 * Contact Form 7: форма відправлена.
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
	 * Доставка.
	 * ---------------------------------------------------------------- */

	private function send_email_with_pdf( string $email, WP_Post $template, string $pdf_path, array $data ): void {
		$subject = apply_filters(
			'aipdf_email_subject',
			sprintf(
				/* translators: %s: site name. */
				__( 'Ваш документ від %s', 'ai-pdf-generator' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			$template,
			$data
		);

		$body = apply_filters(
			'aipdf_email_body',
			__( "Вітаємо!\n\nВаш документ у вкладенні до цього листа.", 'ai-pdf-generator' ),
			$template,
			$data
		);

		$sent = wp_mail( $email, $subject, $body, array(), array( $pdf_path ) );

		if ( $sent ) {
			AIPDF_Logger::get_instance()->info(
				sprintf( 'Лист із PDF (шаблон #%d) відправлено на %s.', $template->ID, $email )
			);
		} else {
			AIPDF_Logger::get_instance()->error(
				sprintf( 'wp_mail не зміг відправити лист із PDF (шаблон #%d) на %s.', $template->ID, $email )
			);
		}
	}

	/**
	 * URL QR-коду через публічний сервіс (для MVP).
	 * На проді краще замінити локальною генерацією (endroid/qr-code).
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
