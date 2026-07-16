<?php
/**
 * Масова генерація PDF для існуючих замовлень WooCommerce (Bulk Actions).
 *
 * Дозволяє виділити старі замовлення в списку та поставити генерацію PDF
 * у чергу через Action Scheduler (той самий планувальник, що йде в комплекті
 * з WooCommerce). Документи створюються по одному у фоновому режимі —
 * НЕ всі одразу синхронно в межах одного запиту, що при десятках чи сотнях
 * замовлень могло б вичерпати ліміт часу виконання й «покласти» сайт.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Bulk_Actions {

	/**
	 * Слаг bulk-дії в dropdown списку замовлень.
	 */
	private const ACTION = 'aipdf_bulk_generate';

	/**
	 * Назва фонового завдання Action Scheduler.
	 */
	private const HOOK = 'aipdf_bulk_generate_order';

	/**
	 * Затримка (сек) між запланованими завданнями — навмисне «розтягування»
	 * навантаження в часі, а не спроба обробити все однією хвилею.
	 */
	private const STAGGER_SECONDS = 4;

	public function __construct() {
		if ( ! AIPDF_Triggers::is_available( 'woocommerce_payment_complete' ) ) {
			return; // WooCommerce неактивний — масова генерація його замовлень не має сенсу.
		}

		// Легасі-екран замовлень (CPT shop_order) та HPOS-екран (wc-orders) —
		// підтримуємо обидва, бо WooCommerce поступово мігрує сайти на HPOS.
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'register_bulk_action' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'register_bulk_action' ) );

		add_filter( 'handle_bulk_action-edit-shop_order', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'handle_bulk_action-woocommerce_page_wc-orders', array( $this, 'handle_bulk_action' ), 10, 3 );

		add_action( 'admin_notices', array( $this, 'render_notice' ) );

		// Обробник ОДНОГО завдання в черзі Action Scheduler.
		add_action( self::HOOK, array( $this, 'process_order' ), 10, 1 );
	}

	/**
	 * Додає пункт у dropdown «Bulk actions» списку замовлень.
	 *
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function register_bulk_action( array $actions ): array {
		$actions[ self::ACTION ] = __( 'AI PDF: Згенерувати документи', 'ai-pdf-generator' );
		return $actions;
	}

	/**
	 * Обробляє вибрану bulk-дію: планує по одному фоновому завданню на кожне
	 * обране замовлення через Action Scheduler, з невеликим зсувом у часі.
	 *
	 * @param string          $redirect_to
	 * @param string          $action
	 * @param array<int, int> $order_ids
	 */
	public function handle_bulk_action( string $redirect_to, string $action, array $order_ids ): string {
		if ( self::ACTION !== $action ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			// Action Scheduler завжди йде в комплекті з активним WooCommerce;
			// його відсутність — ознака зламаної інсталяції. Свідомо НЕ робимо
			// синхронний fallback (рендер десятків PDF в одному запиті —
			// саме те навантаження, якого просили уникнути).
			return add_query_arg( 'aipdf_bulk_error', 'no_scheduler', $redirect_to );
		}

		$scheduled = 0;
		foreach ( array_values( $order_ids ) as $i => $order_id ) {
			as_schedule_single_action(
				time() + ( $i * self::STAGGER_SECONDS ),
				self::HOOK,
				array( 'order_id' => (int) $order_id ),
				'ai-pdf-generator'
			);
			++$scheduled;
		}

		AIPDF_Logger::get_instance()->info(
			sprintf(
				'Bulk-генерація: заплановано %d завдань (замовлення: %s).',
				$scheduled,
				implode( ', ', array_map( 'absint', $order_ids ) )
			)
		);

		return add_query_arg( 'aipdf_bulk_scheduled', $scheduled, $redirect_to );
	}

	/**
	 * ОДНЕ фонове завдання Action Scheduler: генерує PDF для одного
	 * замовлення тим самим кодовим шляхом, що й реальна подія «оплата
	 * пройшла», — включно з перевіркою умов генерації (Conditional Logic)
	 * та доставкою (лист / download-link).
	 */
	public function process_order( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			AIPDF_Logger::get_instance()->warning( "Bulk-генерація: замовлення #{$order_id} не знайдено." );
			return;
		}

		do_action(
			'aipdf_run_trigger',
			'woocommerce_payment_complete',
			AIPDF_Trigger_Dispatcher::build_wc_order_data( $order )
		);
	}

	/**
	 * Повідомлення в адмінці одразу після bulk-дії (редирект зі списку
	 * замовлень несе query-параметр — стандартний патерн WP bulk actions).
	 */
	public function render_notice(): void {
		if ( isset( $_REQUEST['aipdf_bulk_scheduled'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- лише інформаційний notice, значення приводимо до числа.
			$count = absint( $_REQUEST['aipdf_bulk_scheduled'] );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of scheduled background jobs. */
						_n(
							'AI PDF Generator: заплановано %d документ у фоновому режимі. Перевірте «Журнал подій» за кілька хвилин.',
							'AI PDF Generator: заплановано %d документів у фоновому режимі. Перевірте «Журнал подій» за кілька хвилин.',
							$count,
							'ai-pdf-generator'
						),
						$count
					)
				)
			);
		}

		if ( isset( $_REQUEST['aipdf_bulk_error'] ) && 'no_scheduler' === $_REQUEST['aipdf_bulk_error'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- лише інформаційний notice.
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'AI PDF Generator: Action Scheduler недоступний — переконайтесь, що WooCommerce активний і оновлений.', 'ai-pdf-generator' )
			);
		}
	}
}
