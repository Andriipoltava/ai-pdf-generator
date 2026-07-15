<?php
/**
 * Доставка download_link: показ посилання на згенерований PDF
 * одразу після дії користувача.
 *
 * - WooCommerce: кнопка на сторінці подяки (woocommerce_thankyou).
 * - CF7 / Elementor Pro: URL додається у JSON-відповідь форми,
 *   фронтенд-JS домальовує кнопку під формою.
 * - Amelia (Vue/AJAX): токен у cookie + transient; на кастомній
 *   сторінці подяки (redirect) кнопку виводить шорткод
 *   [aipdf_download_button].
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Delivery {

	/**
	 * Cookie з одноразовим токеном завантаження (для redirect-сценаріїв).
	 */
	private const COOKIE = 'aipdf_dl_token';

	/**
	 * Скільки живе посилання у transient.
	 */
	private const TOKEN_TTL = 15 * MINUTE_IN_SECONDS;

	public function __construct() {
		// Універсальний перехоплювач: спрацьовує після кожної генерації.
		add_action( 'aipdf_pdf_generated', array( $this, 'store_download' ), 10, 4 );

		// WooCommerce: кнопка на сторінці подяки.
		add_action( 'woocommerce_thankyou', array( $this, 'render_wc_button' ), 20 );

		// CF7: додаємо URL у JSON-відповідь форми.
		add_filter( 'wpcf7_feedback_response', array( $this, 'cf7_add_download_url' ) );

		// Сторінка подяки для Amelia та інших redirect-сценаріїв.
		add_shortcode( 'aipdf_download_button', array( $this, 'render_shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
	}

	/**
	 * Після генерації PDF з action_type = download_link:
	 * - для WooCommerce пишемо URL у мета замовлення (сторінка подяки —
	 *   це ОКРЕМИЙ запит, тому потрібне постійне сховище);
	 * - для решти — короткоживучий токен у cookie + transient
	 *   (працює для Amelia AJAX: заголовки ще не відправлені).
	 *
	 * @param array{path:string,url:string} $result   Результат рендеру.
	 * @param string                        $trigger  Тригер.
	 * @param WP_Post                       $template Пост-шаблон.
	 * @param array<string, string>         $data     Дані плейсхолдерів.
	 */
	public function store_download( array $result, string $trigger, WP_Post $template, array $data ): void {
		if ( 'download_link' !== get_post_meta( $template->ID, '_aipdf_action_type', true ) ) {
			return;
		}

		// WooCommerce: постійне сховище в мета замовлення.
		if ( 'wc_order_paid' === $trigger && ! empty( $data['_wc_order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $data['_wc_order_id'] );
			if ( $order ) {
				$order->update_meta_data( '_aipdf_pdf_url', esc_url_raw( $result['url'] ) );
				$order->save();
			}
			return;
		}

		// Redirect-сценарії (Amelia тощо): токен живе 15 хвилин.
		if ( headers_sent() ) {
			return; // Пізно ставити cookie — залишиться лише email/хук.
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
	 * WooCommerce: велика кнопка «Завантажити документ» на сторінці подяки.
	 *
	 * Якщо payment_complete ще не спрацював (COD, банківський переказ) —
	 * генеруємо PDF прямо тут і зберігаємо в мета, щоб не робити це двічі.
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
			$template = $renderer->find_template_by_trigger( 'wc_order_paid' );

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
			esc_html( apply_filters( 'aipdf_download_button_text', __( 'Завантажити ваш документ (PDF)', 'ai-pdf-generator' ) ) )
		);
	}

	/**
	 * CF7: наш обробник wpcf7_mail_sent уже відпрацював у ЦЬОМУ Ж запиті,
	 * тож просто додаємо URL у відповідь. Кнопку домалює frontend.js
	 * за подією wpcf7mailsent.
	 *
	 * @param array<string, mixed> $response JSON-відповідь CF7.
	 */
	public function cf7_add_download_url( $response ) {
		$url = AIPDF_Trigger_Dispatcher::last_download_url();
		if ( $url ) {
			$response['aipdf_download_url'] = esc_url_raw( $url );
		}

		return $response;
	}

	/**
	 * Шорткод для кастомної сторінки подяки (Amelia redirect тощо):
	 * [aipdf_download_button text="Завантажити квиток"]
	 *
	 * УВАГА: сторінку з шорткодом треба виключити з кешу (токен персональний).
	 *
	 * @param array<string, string>|string $atts Атрибути шорткода.
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array( 'text' => __( 'Завантажити ваш документ (PDF)', 'ai-pdf-generator' ) ),
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
	 * Фронтенд-JS потрібен лише коли активні CF7 або Elementor Pro.
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
				'buttonText' => apply_filters( 'aipdf_download_button_text', __( 'Завантажити ваш документ (PDF)', 'ai-pdf-generator' ) ),
			)
		);
	}
}
