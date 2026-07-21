<?php
/**
 * Bulk PDF generation for existing WooCommerce orders (Bulk Actions).
 *
 * Lets old orders be selected in the list and queues PDF generation
 * through Action Scheduler (the same scheduler bundled with WooCommerce).
 * Documents are created one at a time in the background — NOT all at once
 * synchronously within a single request, which for dozens or hundreds of
 * orders could exhaust the execution time limit and bring the site down.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Bulk_Actions {

	/**
	 * Bulk-action slug in the orders list dropdown.
	 */
	private const ACTION = 'aipdf_bulk_generate';

	/**
	 * Action Scheduler background job name.
	 */
	private const HOOK = 'aipdf_bulk_generate_order';

	/**
	 * Delay (seconds) between scheduled jobs — deliberately spreading the
	 * load over time instead of trying to process everything in one wave.
	 */
	private const STAGGER_SECONDS = 4;

	public function __construct() {
		if ( ! AIPDF_Triggers::is_available( 'woocommerce_payment_complete' ) ) {
			return; // WooCommerce inactive — bulk-generating its orders makes no sense.
		}

		// Legacy orders screen (CPT shop_order) and the HPOS screen
		// (wc-orders) — support both, since WooCommerce is gradually
		// migrating sites to HPOS.
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'register_bulk_action' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'register_bulk_action' ) );

		add_filter( 'handle_bulk_action-edit-shop_order', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'handle_bulk_action-woocommerce_page_wc-orders', array( $this, 'handle_bulk_action' ), 10, 3 );

		add_action( 'admin_notices', array( $this, 'render_notice' ) );

		// Handler for ONE job in the Action Scheduler queue.
		add_action( self::HOOK, array( $this, 'process_order' ), 10, 1 );
	}

	/**
	 * Adds an entry to the orders list "Bulk actions" dropdown.
	 *
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function register_bulk_action( array $actions ): array {
		$actions[ self::ACTION ] = __( 'AI PDF: Generate Documents', 'ai-pdf-generator' );
		return $actions;
	}

	/**
	 * Handles the selected bulk action: schedules one background job per
	 * selected order via Action Scheduler, staggered slightly over time.
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
			// Action Scheduler always ships with an active WooCommerce;
			// its absence signals a broken install. Deliberately NOT
			// falling back to synchronous processing — rendering dozens
			// of PDFs in one request is exactly the load this feature
			// exists to avoid.
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
				'Bulk generation: %d job(s) scheduled (orders: %s).',
				$scheduled,
				implode( ', ', array_map( 'absint', $order_ids ) )
			)
		);

		return add_query_arg( 'aipdf_bulk_scheduled', $scheduled, $redirect_to );
	}

	/**
	 * ONE Action Scheduler background job: generates a PDF for a single
	 * order via the exact same code path as the live "payment complete"
	 * event — including the Conditional Logic check and delivery
	 * (email / download link).
	 */
	public function process_order( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			AIPDF_Logger::get_instance()->warning( "Bulk generation: order #{$order_id} not found." );
			return;
		}

		do_action(
			'aipdf_run_trigger',
			'woocommerce_payment_complete',
			AIPDF_Trigger_Dispatcher::build_wc_order_data( $order )
		);
	}

	/**
	 * Admin notice shown right after the bulk action (the redirect from the
	 * orders list carries a query arg — the standard WP bulk-actions pattern).
	 */
	public function render_notice(): void {
		if ( isset( $_REQUEST['aipdf_bulk_scheduled'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- informational notice only, value is cast to an integer.
			$count = absint( $_REQUEST['aipdf_bulk_scheduled'] );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of scheduled background jobs. */
						_n(
							'AI PDF Generator: %d document scheduled in the background. Check the "Event Log" in a few minutes.',
							'AI PDF Generator: %d documents scheduled in the background. Check the "Event Log" in a few minutes.',
							$count,
							'ai-pdf-generator'
						),
						$count
					)
				)
			);
		}

		if ( isset( $_REQUEST['aipdf_bulk_error'] ) && 'no_scheduler' === $_REQUEST['aipdf_bulk_error'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- informational notice only.
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'AI PDF Generator: Action Scheduler is unavailable — make sure WooCommerce is active and up to date.', 'ai-pdf-generator' )
			);
		}
	}
}
