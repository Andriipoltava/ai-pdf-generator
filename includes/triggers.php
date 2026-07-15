<?php
/**
 * Реєстр тригерів: єдине джерело правди про те, які тригери підтримує
 * плагін і які з них ДОСТУПНІ зараз (відповідний плагін активний).
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Triggers {

	/**
	 * Кеш результату detect() в межах запиту.
	 *
	 * @var array<string, bool>|null
	 */
	private static ?array $cache = null;

	/**
	 * Людські назви тригерів для UI.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'wc_order_paid'               => 'WooCommerce — оплата замовлення',
			'wc_bookings_done'            => 'WooCommerce Bookings — бронювання',
			'cf7_submit'                  => 'Contact Form 7 — відправка форми',
			'wpforms_submit'              => 'WPForms — відправка форми',
			'gform_submit'                => 'Gravity Forms — відправка форми',
			'ninja_forms_submit'          => 'Ninja Forms — відправка форми',
			'formidable_submit'           => 'Formidable Forms — відправка форми',
			'elementor_pro_form_submit'   => 'Elementor Pro — відправка форми',
			'fluentform_submit'           => 'Fluent Forms — відправка форми',
			'forminator_submit'           => 'Forminator — відправка форми',
			'wsform_submit'               => 'WS Form — відправка форми',
			'everest_forms_submit'        => 'Everest Forms — відправка форми',
			'amelia_booking_done'         => 'Amelia — бронювання',
			'tec_event_booking'           => 'The Events Calendar — подія',
			'event_tickets_purchase'      => 'Event Tickets — квиток',
			'bookly_booking_done'         => 'Bookly — бронювання',
			'event_espresso_registration' => 'Event Espresso — реєстрація',
			'mec_booking_done'            => 'Modern Events Calendar — бронювання',
			'manual_generation'           => 'Ручна генерація (завжди)',
		);
	}

	/**
	 * Чи активний плагін для кожного тригера. manual_generation — завжди.
	 *
	 * @return array<string, bool>
	 */
	public static function detect(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$woo = class_exists( 'WooCommerce' );

		self::$cache = array(
			'manual_generation'           => true,
			'wc_order_paid'               => $woo,
			'wc_bookings_done'            => $woo && class_exists( 'WC_Bookings' ),
			'cf7_submit'                  => defined( 'WPCF7_VERSION' ) || class_exists( 'WPCF7' ),
			'wpforms_submit'              => function_exists( 'wpforms' ),
			'gform_submit'                => class_exists( 'GFForms' ),
			'ninja_forms_submit'          => function_exists( 'Ninja_Forms' ),
			'formidable_submit'           => class_exists( 'FrmHooksController' ),
			'elementor_pro_form_submit'   => defined( 'ELEMENTOR_PRO_VERSION' ),
			'fluentform_submit'           => defined( 'FLUENTFORM_VERSION' ) || function_exists( 'wpFluentForm' ),
			'forminator_submit'           => class_exists( 'Forminator' ),
			'wsform_submit'               => class_exists( 'WS_Form' ) || function_exists( 'wsf_form_get' ),
			'everest_forms_submit'        => function_exists( 'EVF' ) || function_exists( 'evf' ),
			'amelia_booking_done'         => defined( 'AMELIA_VERSION' ) || class_exists( '\\AmeliaBooking\\Plugin' ),
			'tec_event_booking'           => class_exists( 'Tribe__Events__Main' ),
			'event_tickets_purchase'      => class_exists( 'Tribe__Tickets__Main' ),
			'bookly_booking_done'         => class_exists( '\\Bookly\\Lib\\Plugin' ),
			'event_espresso_registration' => function_exists( 'espresso_version' ),
			'mec_booking_done'            => class_exists( 'MEC' ) || defined( 'MEC_VERSION' ),
		);

		/**
		 * Дозволяє програмно змінити доступність тригерів.
		 *
		 * @param array<string, bool> $detected Мапа trigger => доступний.
		 */
		self::$cache = (array) apply_filters( 'aipdf_trigger_availability', self::$cache );

		return self::$cache;
	}

	/**
	 * Список доступних (активних) тригерів. manual_generation завжди присутній.
	 *
	 * @return string[]
	 */
	public static function available(): array {
		return array_values( array_filter( array_keys( self::detect() ), array( __CLASS__, 'is_available' ) ) );
	}

	/**
	 * Усі відомі тригери (повний список, для валідації).
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_keys( self::labels() );
	}

	public static function is_available( string $trigger ): bool {
		$map = self::detect();
		return ! empty( $map[ $trigger ] );
	}

	public static function label( string $trigger ): string {
		$labels = self::labels();
		return $labels[ $trigger ] ?? $trigger;
	}
}
