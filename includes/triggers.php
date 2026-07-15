<?php
/**
 * Каталог тригерів: єдине джерело правди про підтримувані тригери,
 * їхню доступність (активний плагін) та контекстні плейсхолдери.
 *
 * Ключ = реальне ім'я WordPress-хука (може містити «/», напр.
 * elementor_pro/forms/new_record). Кожен запис: label, condition (bool),
 * placeholders (string[]).
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Triggers {

	/**
	 * Кеш каталогу в межах запиту.
	 *
	 * @var array<string, array{label:string,condition:bool,placeholders:string[]}>|null
	 */
	private static ?array $cache = null;

	/**
	 * Повний каталог тригерів. Умови (condition) обчислюються при виклику.
	 *
	 * @return array<string, array{label:string,condition:bool,placeholders:string[]}>
	 */
	public static function catalog(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$catalog = array(
			// === ВБУДОВАНІ / РУЧНІ ===
			'manual_generation' => array(
				'label'        => 'Система: Ручна генерація',
				'condition'    => true,
				'placeholders' => array( '{{client_name}}', '{{email}}', '{{date}}' ),
			),

			// === WOOCOMMERCE ===
			'woocommerce_payment_complete' => array(
				'label'        => 'WooCommerce: Оплата успішна',
				'condition'    => class_exists( 'WooCommerce' ),
				'placeholders' => array( '{{order_id}}', '{{order_total}}', '{{billing_email}}', '{{billing_name}}', '{{products_table}}' ),
			),
			'woocommerce_order_status_completed' => array(
				'label'        => 'WooCommerce: Замовлення виконано',
				'condition'    => class_exists( 'WooCommerce' ),
				'placeholders' => array( '{{order_id}}', '{{order_total}}', '{{billing_email}}', '{{shipping_address}}' ),
			),
			'woocommerce_order_status_processing' => array(
				'label'        => 'WooCommerce: Замовлення в обробці',
				'condition'    => class_exists( 'WooCommerce' ),
				'placeholders' => array( '{{order_id}}', '{{shipping_address}}', '{{products_table}}' ),
			),
			'woocommerce_order_status_refunded' => array(
				'label'        => 'WooCommerce: Кошти повернено',
				'condition'    => class_exists( 'WooCommerce' ),
				'placeholders' => array( '{{order_id}}', '{{refund_amount}}', '{{billing_email}}' ),
			),
			'woocommerce_new_order' => array(
				'label'        => 'WooCommerce: Нове замовлення створено',
				'condition'    => class_exists( 'WooCommerce' ),
				'placeholders' => array( '{{order_id}}', '{{order_total}}', '{{billing_name}}' ),
			),
			'woocommerce_subscription_status_active' => array(
				'label'        => 'WooCommerce Subscriptions: Підписка активна',
				'condition'    => class_exists( 'WC_Subscriptions' ),
				'placeholders' => array( '{{subscription_id}}', '{{next_payment_date}}', '{{billing_email}}' ),
			),
			'woocommerce_subscription_renewal_payment_complete' => array(
				'label'        => 'WooCommerce Subscriptions: Оплата подовження',
				'condition'    => class_exists( 'WC_Subscriptions' ),
				'placeholders' => array( '{{subscription_id}}', '{{renewal_amount}}' ),
			),
			'woocommerce_booking_in-cart_to_paid' => array(
				'label'        => 'WooCommerce Bookings: Бронювання оплачено',
				'condition'    => class_exists( 'WC_Bookings' ),
				'placeholders' => array( '{{booking_id}}', '{{resource_name}}', '{{start_date}}', '{{end_date}}' ),
			),
			'woocommerce_booking_confirmed' => array(
				'label'        => 'WooCommerce Bookings: Бронювання підтверджено',
				'condition'    => class_exists( 'WC_Bookings' ),
				'placeholders' => array( '{{booking_id}}', '{{start_date}}' ),
			),

			// === EASY DIGITAL DOWNLOADS ===
			'edd_complete_purchase' => array(
				'label'        => 'EDD: Успішна покупка',
				'condition'    => class_exists( 'Easy_Digital_Downloads' ),
				'placeholders' => array( '{{payment_id}}', '{{total}}', '{{user_email}}', '{{download_links}}' ),
			),
			'edd_update_payment_status' => array(
				'label'        => 'EDD: Зміна статусу оплати',
				'condition'    => class_exists( 'Easy_Digital_Downloads' ),
				'placeholders' => array( '{{payment_id}}', '{{status}}' ),
			),
			'edd_recurring_add_subscription_payment' => array(
				'label'        => 'EDD Recurring: Новий платіж підписки',
				'condition'    => class_exists( 'EDD_Recurring' ),
				'placeholders' => array( '{{subscription_id}}', '{{amount}}' ),
			),

			// === БРОНЮВАННЯ ТА ІВЕНТИ ===
			'amelia_after_booking_added' => array(
				'label'        => 'Amelia: Нове бронювання',
				'condition'    => class_exists( 'AmeliaBooking\\Plugin' ),
				'placeholders' => array( '{{ticket_id}}', '{{booking_date}}', '{{service_name}}', '{{customer_name}}' ),
			),
			'amelia_after_payment_completed' => array(
				'label'        => 'Amelia: Бронювання оплачено',
				'condition'    => class_exists( 'AmeliaBooking\\Plugin' ),
				'placeholders' => array( '{{ticket_id}}', '{{payment_amount}}', '{{service_name}}' ),
			),
			'amelia_after_booking_status_updated' => array(
				'label'        => 'Amelia: Статус бронювання змінено',
				'condition'    => class_exists( 'AmeliaBooking\\Plugin' ),
				'placeholders' => array( '{{ticket_id}}', '{{new_status}}' ),
			),
			'bookly_booking_created' => array(
				'label'        => 'Bookly: Новий запис створено',
				'condition'    => class_exists( 'Bookly\\Lib\\Plugin' ),
				'placeholders' => array( '{{appointment_id}}', '{{service_name}}', '{{appointment_date}}' ),
			),
			'bookly_payment_completed' => array(
				'label'        => 'Bookly: Оплата успішна',
				'condition'    => class_exists( 'Bookly\\Lib\\Plugin' ),
				'placeholders' => array( '{{appointment_id}}', '{{amount}}' ),
			),
			'latepoint_booking_created' => array(
				'label'        => 'LatePoint: Нове бронювання',
				'condition'    => class_exists( 'LatePoint' ),
				'placeholders' => array( '{{booking_id}}', '{{agent_name}}', '{{service_name}}' ),
			),
			'event_tickets_after_purchase' => array(
				'label'        => 'The Events Calendar: Квиток куплено',
				'condition'    => class_exists( 'Tribe__Tickets__Main' ),
				'placeholders' => array( '{{ticket_id}}', '{{event_name}}', '{{event_date}}' ),
			),
			'tec_tickets_ticket_generated' => array(
				'label'        => 'The Events Calendar: Квиток згенеровано',
				'condition'    => class_exists( 'Tribe__Tickets__Main' ),
				'placeholders' => array( '{{ticket_id}}', '{{qr_code}}', '{{attendee_name}}' ),
			),
			'event_espresso_registration_completed' => array(
				'label'        => 'Event Espresso: Реєстрація успішна',
				'condition'    => class_exists( 'EE_Plugin' ),
				'placeholders' => array( '{{registration_id}}', '{{event_name}}', '{{attendee_name}}' ),
			),
			'mec_booking_done' => array(
				'label'        => 'Modern Events Calendar: Бронювання завершено',
				'condition'    => class_exists( 'MEC' ),
				'placeholders' => array( '{{booking_id}}', '{{event_title}}', '{{attendee_email}}' ),
			),

			// === ФОРМИ ===
			'wpcf7_mail_sent' => array(
				'label'        => 'Contact Form 7: Форма відправлена',
				'condition'    => defined( 'WPCF7_VERSION' ),
				'placeholders' => array( '{{form_name}}', '{{sender_email}}', '{{sender_name}}' ),
			),
			'elementor_pro/forms/new_record' => array(
				'label'        => 'Elementor Pro Forms: Новий запис',
				'condition'    => class_exists( 'ElementorPro\\Plugin' ),
				'placeholders' => array( '{{form_name}}', '{{form_fields}}' ),
			),
			'gform_after_submission' => array(
				'label'        => 'Gravity Forms: Форма надіслана',
				'condition'    => class_exists( 'GFForms' ),
				'placeholders' => array( '{{entry_id}}', '{{form_title}}', '{{user_email}}' ),
			),
			'wpforms_process_complete' => array(
				'label'        => 'WPForms: Успішне відправлення',
				'condition'    => class_exists( 'WPForms' ),
				'placeholders' => array( '{{entry_id}}', '{{form_name}}', '{{fields_data}}' ),
			),
			'fluentform_submission_inserted' => array(
				'label'        => 'Fluent Forms: Нова заявка',
				'condition'    => defined( 'FLUENTFORM' ),
				'placeholders' => array( '{{submission_id}}', '{{form_title}}', '{{user_email}}' ),
			),
			'ninja_forms_after_submission' => array(
				'label'        => 'Ninja Forms: Форма відправлена',
				'condition'    => class_exists( 'Ninja_Forms' ),
				'placeholders' => array( '{{form_id}}', '{{user_email}}' ),
			),
			'forminator_custom_form_submit_before_set_fields' => array(
				'label'        => 'Forminator: Відправка форми',
				'condition'    => class_exists( 'Forminator' ),
				'placeholders' => array( '{{entry_id}}', '{{form_name}}' ),
			),
			'frm_after_create_entry' => array(
				'label'        => 'Formidable Forms: Запис створено',
				'condition'    => class_exists( 'FrmHooksController' ),
				'placeholders' => array( '{{entry_id}}', '{{form_name}}' ),
			),
			'wsf_action_email_send' => array(
				'label'        => 'WS Form: Відправка email',
				'condition'    => class_exists( 'WS_Form' ),
				'placeholders' => array( '{{form_id}}', '{{email_address}}' ),
			),

			// === LMS ===
			'learndash_course_completed' => array(
				'label'        => 'LearnDash: Курс завершено',
				'condition'    => defined( 'LEARNDASH_VERSION' ),
				'placeholders' => array( '{{course_name}}', '{{user_name}}', '{{completion_date}}' ),
			),
			'learndash_lesson_completed' => array(
				'label'        => 'LearnDash: Урок пройдено',
				'condition'    => defined( 'LEARNDASH_VERSION' ),
				'placeholders' => array( '{{lesson_name}}', '{{user_name}}' ),
			),
			'learndash_quiz_completed' => array(
				'label'        => 'LearnDash: Тест здано',
				'condition'    => defined( 'LEARNDASH_VERSION' ),
				'placeholders' => array( '{{quiz_name}}', '{{score}}', '{{total_points}}' ),
			),
			'tutor_course_complete_after' => array(
				'label'        => 'TutorLMS: Курс завершено',
				'condition'    => defined( 'TUTOR_VERSION' ),
				'placeholders' => array( '{{course_title}}', '{{student_name}}', '{{date}}' ),
			),
			'tutor_quiz_edit_post_after' => array(
				'label'        => 'TutorLMS: Тест завершено',
				'condition'    => defined( 'TUTOR_VERSION' ),
				'placeholders' => array( '{{quiz_title}}', '{{earned_marks}}' ),
			),
			'lifterlms_course_completed' => array(
				'label'        => 'LifterLMS: Курс завершено',
				'condition'    => function_exists( 'llms' ),
				'placeholders' => array( '{{course_title}}', '{{student_name}}' ),
			),
			'lifterlms_quiz_completed' => array(
				'label'        => 'LifterLMS: Тест пройдено',
				'condition'    => function_exists( 'llms' ),
				'placeholders' => array( '{{quiz_title}}', '{{grade}}' ),
			),
			'sensei_course_status_updated' => array(
				'label'        => 'Sensei LMS: Статус курсу оновлено',
				'condition'    => class_exists( 'Sensei_Main' ),
				'placeholders' => array( '{{course_name}}', '{{learner_name}}' ),
			),

			// === ЧЛЕНСТВО ТА ПІДПИСКИ ===
			'mepr-event-transaction-completed' => array(
				'label'        => 'MemberPress: Оплата підписки',
				'condition'    => class_exists( 'MeprPlugin' ),
				'placeholders' => array( '{{transaction_id}}', '{{member_name}}', '{{membership_level}}' ),
			),
			'mepr-signup-completed' => array(
				'label'        => 'MemberPress: Реєстрація успішна',
				'condition'    => class_exists( 'MeprPlugin' ),
				'placeholders' => array( '{{member_name}}', '{{user_email}}' ),
			),
			'rcp_transition_to_active' => array(
				'label'        => 'Restrict Content Pro: Акаунт активовано',
				'condition'    => class_exists( 'RCP_Member' ),
				'placeholders' => array( '{{member_name}}', '{{subscription_level}}' ),
			),
			'rcp_payment_completed' => array(
				'label'        => 'Restrict Content Pro: Платіж успішний',
				'condition'    => class_exists( 'RCP_Member' ),
				'placeholders' => array( '{{payment_id}}', '{{amount}}' ),
			),
			'pmpro_after_checkout' => array(
				'label'        => 'Paid Memberships Pro: Чекаут завершено',
				'condition'    => defined( 'PMPRO_VERSION' ),
				'placeholders' => array( '{{order_id}}', '{{level_name}}', '{{user_email}}' ),
			),
			'um_registration_complete' => array(
				'label'        => 'Ultimate Member: Реєстрація завершена',
				'condition'    => class_exists( 'UM' ),
				'placeholders' => array( '{{user_name}}', '{{user_email}}' ),
			),
			'um_user_approved' => array(
				'label'        => 'Ultimate Member: Акаунт схвалено',
				'condition'    => class_exists( 'UM' ),
				'placeholders' => array( '{{user_name}}', '{{role}}' ),
			),

			// === БЛАГОДІЙНІСТЬ І ДОНАТИ ===
			'give_insert_payment' => array(
				'label'        => 'GiveWP: Новий донат',
				'condition'    => class_exists( 'Give' ),
				'placeholders' => array( '{{donation_id}}', '{{amount}}', '{{donor_name}}' ),
			),
			'give_payment_status_changed' => array(
				'label'        => 'GiveWP: Статус платежу змінено',
				'condition'    => class_exists( 'Give' ),
				'placeholders' => array( '{{donation_id}}', '{{new_status}}' ),
			),
			'charitable_after_campaign_donation' => array(
				'label'        => 'Charitable: Донат у кампанію',
				'condition'    => class_exists( 'Charitable' ),
				'placeholders' => array( '{{campaign_name}}', '{{amount}}', '{{donor_name}}' ),
			),

			// === CRM ТА ПІДТРИМКА ===
			'fluentcrm_contact_created' => array(
				'label'        => 'FluentCRM: Новий контакт',
				'condition'    => defined( 'FLUENTCRM' ),
				'placeholders' => array( '{{contact_name}}', '{{contact_email}}' ),
			),
			'fluentcrm_contact_added_to_list' => array(
				'label'        => 'FluentCRM: Додано до списку',
				'condition'    => defined( 'FLUENTCRM' ),
				'placeholders' => array( '{{contact_email}}', '{{list_name}}' ),
			),
			'wpas_ticket_created' => array(
				'label'        => 'Awesome Support: Новий тикет',
				'condition'    => class_exists( 'Awesome_Support' ),
				'placeholders' => array( '{{ticket_id}}', '{{subject}}', '{{client_name}}' ),
			),
			'wpas_ticket_closed' => array(
				'label'        => 'Awesome Support: Тикет закрито',
				'condition'    => class_exists( 'Awesome_Support' ),
				'placeholders' => array( '{{ticket_id}}', '{{resolution}}' ),
			),
			'fluent_support/ticket_created' => array(
				'label'        => 'Fluent Support: Тикет створено',
				'condition'    => defined( 'FLUENT_SUPPORT_VERSION' ),
				'placeholders' => array( '{{ticket_id}}', '{{customer_name}}' ),
			),
			'fluent_support/ticket_closed' => array(
				'label'        => 'Fluent Support: Тикет закрито',
				'condition'    => defined( 'FLUENT_SUPPORT_VERSION' ),
				'placeholders' => array( '{{ticket_id}}', '{{closed_by}}' ),
			),

			// === ГЕЙМІФІКАЦІЯ ТА АФІЛІАТИ ===
			'affwp_insert_affiliate' => array(
				'label'        => 'AffiliateWP: Новий партнер',
				'condition'    => class_exists( 'Affiliate_WP' ),
				'placeholders' => array( '{{affiliate_id}}', '{{affiliate_name}}' ),
			),
			'affwp_referral_accepted' => array(
				'label'        => 'AffiliateWP: Реферал підтверджено',
				'condition'    => class_exists( 'Affiliate_WP' ),
				'placeholders' => array( '{{referral_amount}}', '{{affiliate_id}}' ),
			),
			'gamipress_award_achievement' => array(
				'label'        => 'GamiPress: Отримано досягнення',
				'condition'    => function_exists( 'gamipress' ),
				'placeholders' => array( '{{achievement_title}}', '{{user_name}}' ),
			),

			// === ЯДРО WORDPRESS ===
			'user_register' => array(
				'label'        => 'WordPress: Новий користувач',
				'condition'    => true,
				'placeholders' => array( '{{user_id}}', '{{user_login}}', '{{user_email}}' ),
			),
			'profile_update' => array(
				'label'        => 'WordPress: Профіль оновлено',
				'condition'    => true,
				'placeholders' => array( '{{user_id}}', '{{updated_fields}}' ),
			),
			'publish_post' => array(
				'label'        => 'WordPress: Запис опубліковано',
				'condition'    => true,
				'placeholders' => array( '{{post_title}}', '{{post_url}}', '{{author_name}}' ),
			),
			'publish_page' => array(
				'label'        => 'WordPress: Сторінку опубліковано',
				'condition'    => true,
				'placeholders' => array( '{{page_title}}', '{{page_url}}' ),
			),
			'wp_login' => array(
				'label'        => 'WordPress: Вхід в систему',
				'condition'    => true,
				'placeholders' => array( '{{user_login}}', '{{login_time}}' ),
			),
			'password_reset' => array(
				'label'        => 'WordPress: Скидання пароля',
				'condition'    => true,
				'placeholders' => array( '{{user_login}}', '{{reset_date}}' ),
			),
		);

		/**
		 * Дозволяє програмно розширити/змінити каталог тригерів.
		 *
		 * @param array<string, array{label:string,condition:bool,placeholders:string[]}> $catalog
		 */
		self::$cache = (array) apply_filters( 'aipdf_trigger_catalog', $catalog );

		return self::$cache;
	}

	/**
	 * Ключі доступних тригерів (condition === true). manual_generation завжди.
	 *
	 * @return string[]
	 */
	public static function available(): array {
		$out = array();
		foreach ( self::catalog() as $key => $def ) {
			if ( ! empty( $def['condition'] ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Усі ключі каталогу (для валідації).
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_keys( self::catalog() );
	}

	public static function is_available( string $trigger ): bool {
		$catalog = self::catalog();
		return ! empty( $catalog[ $trigger ]['condition'] );
	}

	public static function label( string $trigger ): string {
		$catalog = self::catalog();
		return $catalog[ $trigger ]['label'] ?? $trigger;
	}

	/**
	 * Плейсхолдери конкретного тригера.
	 *
	 * @return string[]
	 */
	public static function placeholders( string $trigger ): array {
		$catalog = self::catalog();
		return $catalog[ $trigger ]['placeholders'] ?? array();
	}

	/**
	 * Санітизація ключа тригера. На відміну від sanitize_key, ЗБЕРІГАЄ «/»
	 * (потрібно для хуків на кшталт elementor_pro/forms/new_record).
	 */
	public static function sanitize( $raw ): string {
		$raw = strtolower( trim( (string) $raw ) );
		return (string) preg_replace( '#[^a-z0-9_/\-]#', '', $raw );
	}
}
