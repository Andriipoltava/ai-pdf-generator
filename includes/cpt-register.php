<?php
/**
 * Registers the hidden `pdf_ai_template` Custom Post Type.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_CPT_Register {

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
	}

	/**
	 * The CPT isn't public (no front-end pages, not searchable), but it
	 * has an admin UI as a submenu of the plugin's menu — so generated
	 * templates can be viewed and deleted.
	 */
	public function register_cpt(): void {
		register_post_type(
			AIPDF_Plugin::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'PDF Templates', 'ai-pdf-generator' ),
					'singular_name' => __( 'PDF Template', 'ai-pdf-generator' ),
					'menu_name'     => __( 'Templates', 'ai-pdf-generator' ),
					'edit_item'     => __( 'Edit Template', 'ai-pdf-generator' ),
					'search_items'  => __( 'Search Templates', 'ai-pdf-generator' ),
					'not_found'     => __( 'No templates found', 'ai-pdf-generator' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				// UI for admins only, under the plugin's menu.
				'show_ui'             => true,
				'show_in_menu'        => AIPDF_Plugin::ADMIN_SLUG,
				'supports'            => array( 'title' ), // No 'editor': it mangles the HTML template; our own editor is AIPDF_Template_Editor.
				'capability_type'     => 'post',
				'capabilities'        => array(
					'create_posts' => 'do_not_allow', // Creation only via the AJAX generator.
				),
				'map_meta_cap'        => true,
			)
		);

		// Register meta fields with sanitize callbacks.
		// _aipdf_trigger_plugin: custom sanitizer, because hook-based keys
		// contain "/" (sanitize_meta applies this callback even on update_post_meta).
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
