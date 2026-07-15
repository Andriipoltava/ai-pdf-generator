<?php
/**
 * Реєстрація прихованого Custom Post Type `pdf_ai_template`.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_CPT_Register {

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
	}

	/**
	 * CPT не є публічним (не має фронтенд-сторінок, не потрапляє в пошук),
	 * але має UI в адмінці як підпункт меню плагіна — щоб можна було
	 * переглядати та видаляти згенеровані шаблони.
	 */
	public function register_cpt(): void {
		register_post_type(
			AIPDF_Plugin::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'PDF Templates', 'ai-pdf-generator' ),
					'singular_name' => __( 'PDF Template', 'ai-pdf-generator' ),
					'menu_name'     => __( 'Шаблони', 'ai-pdf-generator' ),
					'edit_item'     => __( 'Редагувати шаблон', 'ai-pdf-generator' ),
					'search_items'  => __( 'Шукати шаблони', 'ai-pdf-generator' ),
					'not_found'     => __( 'Шаблонів не знайдено', 'ai-pdf-generator' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				// UI лише для адмінів, у меню плагіна.
				'show_ui'             => true,
				'show_in_menu'        => AIPDF_Plugin::ADMIN_SLUG,
				'supports'            => array( 'title' ), // Без 'editor': він псує HTML-шаблон; свій редактор — AIPDF_Template_Editor.
				'capability_type'     => 'post',
				'capabilities'        => array(
					'create_posts' => 'do_not_allow', // Створення лише через AJAX-генератор.
				),
				'map_meta_cap'        => true,
			)
		);

		// Реєструємо meta-поля з sanitize-колбеками.
		// _aipdf_trigger_plugin: власний санітайзер, бо ключі-хуки містять «/»
		// (sanitize_meta застосовує цей колбек навіть при update_post_meta).
		$meta_fields = array(
			'_aipdf_trigger_plugin' => array( 'AIPDF_Triggers', 'sanitize' ),
			'_aipdf_action_type'    => 'sanitize_key',
			'_aipdf_paper_size'     => 'sanitize_text_field',
		);

		foreach ( $meta_fields as $key => $sanitize ) {
			register_post_meta(
				AIPDF_Plugin::CPT,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'sanitize_callback' => $sanitize,
					'auth_callback'     => static function () {
						return current_user_can( 'manage_options' );
					},
				)
			);
		}
	}
}
