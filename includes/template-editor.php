<?php
/**
 * Custom template editor for the `pdf_ai_template` CPT.
 *
 * Replaces the standard WordPress editor (which mangles HTML tables and
 * inline styles) with:
 *  - a meta box with the raw HTML (a monospace textarea);
 *  - a live preview in an iframe that updates as you type;
 *  - a parameters meta box (trigger, action type, size) with dropdowns.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Template_Editor {

	private const NONCE = 'aipdf_save_template';

	public function __construct() {
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_block_editor' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . AIPDF_Plugin::CPT, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Gutenberg is disabled for this CPT (a template is raw HTML, not blocks).
	 */
	public function disable_block_editor( $use, $post_type ) {
		return AIPDF_Plugin::CPT === $post_type ? false : $use;
	}

	/**
	 * The preview script — only on the template editing screen.
	 */
	public function enqueue( string $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || AIPDF_Plugin::CPT !== $screen->post_type ) {
			return;
		}

		// WP Color Picker for color fields.
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_script(
			'aipdf-editor',
			AIPDF_PLUGIN_URL . 'assets/editor.js',
			array( 'jquery', 'wp-color-picker' ),
			AIPDF_Plugin::asset_version( 'assets/editor.js' ),
			true
		);

		wp_localize_script(
			'aipdf-editor',
			'aipdfEditor',
			array(
				// Sample data for the preview (shared with the PDF renderer and the Playground).
				'sample' => AIPDF_PDF_Renderer::sample_data(),
			)
		);
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'aipdf_fields',
			__( 'Visual Editing', 'ai-pdf-generator' ),
			array( $this, 'box_fields' ),
			AIPDF_Plugin::CPT,
			'normal',
			'high'
		);
		add_meta_box(
			'aipdf_preview',
			__( 'Preview', 'ai-pdf-generator' ),
			array( $this, 'box_preview' ),
			AIPDF_Plugin::CPT,
			'normal',
			'high'
		);
		add_meta_box(
			'aipdf_html',
			__( 'Advanced: HTML Skeleton', 'ai-pdf-generator' ),
			array( $this, 'box_html' ),
			AIPDF_Plugin::CPT,
			'normal',
			'low'
		);
		add_meta_box(
			'aipdf_params',
			__( 'Generation Parameters', 'ai-pdf-generator' ),
			array( $this, 'box_params' ),
			AIPDF_Plugin::CPT,
			'side',
			'default'
		);
		add_meta_box(
			'aipdf_placeholders',
			__( 'Dynamic Fields (Cheat Sheet)', 'ai-pdf-generator' ),
			array( $this, 'box_placeholders' ),
			AIPDF_Plugin::CPT,
			'side',
			'low'
		);
	}

	/**
	 * Meta box: visual fields (colors/text) from editable_fields.
	 * The raw HTML is hidden here — editing happens only via these fields.
	 */
	public function box_fields( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );

		$fields = AIPDF_Fields::get( $post->ID );

		if ( empty( $fields ) ) {
			?>
			<p class="description">
				<?php esc_html_e( 'This template has no visual fields (generated earlier, or without any). You can edit the HTML skeleton in the "Advanced" box below, or generate a new template in the Playground — new templates get visual fields automatically.', 'ai-pdf-generator' ); ?>
			</p>
			<?php
			return;
		}
		?>
		<p class="description" style="margin:0 0 12px;">
			<?php esc_html_e( 'Edit colors and text — the preview updates instantly. There\'s no need to touch the HTML structure.', 'ai-pdf-generator' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<?php foreach ( $fields as $field ) : ?>
				<tr>
					<th scope="row" style="width:200px;">
						<label for="aipdf-field-<?php echo esc_attr( $field['key'] ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
					</th>
					<td>
						<?php if ( 'color' === $field['type'] ) : ?>
							<input
								type="text"
								id="aipdf-field-<?php echo esc_attr( $field['key'] ); ?>"
								name="aipdf_field[<?php echo esc_attr( $field['key'] ); ?>]"
								value="<?php echo esc_attr( $field['value'] ); ?>"
								class="aipdf-color-field aipdf-field"
								data-field-key="<?php echo esc_attr( $field['key'] ); ?>"
							/>
						<?php elseif ( 'textarea' === $field['type'] ) : ?>
							<textarea
								id="aipdf-field-<?php echo esc_attr( $field['key'] ); ?>"
								name="aipdf_field[<?php echo esc_attr( $field['key'] ); ?>]"
								rows="3"
								class="large-text aipdf-field"
								data-field-key="<?php echo esc_attr( $field['key'] ); ?>"
							><?php echo esc_textarea( $field['value'] ); ?></textarea>
						<?php else : ?>
							<input
								type="text"
								id="aipdf-field-<?php echo esc_attr( $field['key'] ); ?>"
								name="aipdf_field[<?php echo esc_attr( $field['key'] ); ?>]"
								value="<?php echo esc_attr( $field['value'] ); ?>"
								class="regular-text aipdf-field"
								data-field-key="<?php echo esc_attr( $field['key'] ); ?>"
							/>
						<?php endif; ?>
						<code style="margin-left:8px;color:#8c8f94;">{{<?php echo esc_html( $field['key'] ); ?>}}</code>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Meta box: raw HTML skeleton (advanced). Collapsed by default —
	 * the primary way to edit is via the visual fields above.
	 */
	public function box_html( WP_Post $post ): void {
		?>
		<p class="description" style="margin:0 0 8px;">
			<?php esc_html_e( 'The document\'s HTML skeleton with {{field_key}} placeholders (visual fields) and event data ({{client_name}}, {{order_id}}…). Only edit this if you need to change the structure.', 'ai-pdf-generator' ); ?>
		</p>
		<textarea
			id="aipdf-html-content"
			name="aipdf_html"
			rows="16"
			style="width:100%;font-family:Menlo,Consolas,monospace;font-size:12px;line-height:1.5;white-space:pre;overflow:auto;"
			spellcheck="false"
		><?php echo esc_textarea( $post->post_content ); ?></textarea>
		<?php
	}

	/**
	 * Meta box: live preview (a sandboxed iframe).
	 */
	public function box_preview( WP_Post $post ): void {
		?>
		<p class="description" style="margin:0 0 8px;">
			<?php esc_html_e( 'Updates automatically as you edit. Placeholders are replaced with sample data.', 'ai-pdf-generator' ); ?>
			<button type="button" class="button button-small" id="aipdf-preview-refresh"><?php esc_html_e( 'Refresh', 'ai-pdf-generator' ); ?></button>
		</p>
		<iframe id="aipdf-editor-preview" sandbox="" style="width:100%;height:520px;border:1px solid #ccd0d4;background:#fff;"></iframe>
		<?php
	}

	/**
	 * Meta box: trigger, action type, size — editable dropdowns.
	 */
	public function box_params( WP_Post $post ): void {
		$trigger = (string) get_post_meta( $post->ID, '_aipdf_trigger_plugin', true );
		$action  = (string) get_post_meta( $post->ID, '_aipdf_action_type', true );
		$paper   = (string) get_post_meta( $post->ID, '_aipdf_paper_size', true );
		?>
		<p>
			<label for="aipdf-trigger"><strong><?php esc_html_e( 'Trigger', 'ai-pdf-generator' ); ?></strong></label><br />
			<select id="aipdf-trigger" name="aipdf_trigger" style="width:100%;">
				<?php
				// Show only the available triggers (active plugins). If the
				// template's saved trigger belongs to a now-inactive plugin,
				// add it separately so the value isn't lost on save.
				$options = AIPDF_Triggers::available();
				if ( '' !== $trigger && ! in_array( $trigger, $options, true ) ) {
					$options[] = $trigger;
				}
				foreach ( $options as $t ) :
					$is_inactive = ! AIPDF_Triggers::is_available( $t );
					?>
					<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $trigger, $t ); ?>>
						<?php
						echo esc_html( AIPDF_Triggers::label( $t ) );
						echo $is_inactive ? ' ' . esc_html__( '(plugin inactive)', 'ai-pdf-generator' ) : '';
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="aipdf-action"><strong><?php esc_html_e( 'Action Type', 'ai-pdf-generator' ); ?></strong></label><br />
			<select id="aipdf-action" name="aipdf_action" style="width:100%;">
				<?php foreach ( AIPDF_Plugin::ALLOWED_ACTIONS as $a ) : ?>
					<option value="<?php echo esc_attr( $a ); ?>" <?php selected( $action, $a ); ?>><?php echo esc_html( $a ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="aipdf-paper"><strong><?php esc_html_e( 'Size (A4, Letter, 800x400…)', 'ai-pdf-generator' ); ?></strong></label><br />
			<input type="text" id="aipdf-paper" name="aipdf_paper" value="<?php echo esc_attr( $paper ); ?>" style="width:100%;" />
		</p>
		<?php
	}

	/**
	 * Meta box: a copy/paste cheat sheet of the always-available branding
	 * tags — the ones every template can use regardless of its trigger,
	 * since they come from Settings/Branding rather than event data.
	 */
	public function box_placeholders(): void {
		$tags = array(
			'{{company_name}}'    => __( 'Your company name (from Branding)', 'ai-pdf-generator' ),
			'{{company_address}}' => __( 'Company address', 'ai-pdf-generator' ),
			'{{company_email}}'   => __( 'Company email', 'ai-pdf-generator' ),
			'{{logo_url}}'        => __( 'Company logo (image URL)', 'ai-pdf-generator' ),
			'{{brand_color}}'     => __( 'Primary brand color', 'ai-pdf-generator' ),
			'{{date}}'            => __( "Today's date", 'ai-pdf-generator' ),
		);
		?>
		<p class="description" style="margin:0 0 10px;">
			<?php esc_html_e( 'Copy these tags and paste them into the template text. They\'re automatically replaced with real data when the PDF is generated.', 'ai-pdf-generator' ); ?>
		</p>
		<ul style="margin:0;padding:0;list-style:none;">
			<?php foreach ( $tags as $tag => $label ) : ?>
				<li style="margin-bottom:8px;">
					<code style="display:inline-block;padding:2px 6px;background:#f0f0f1;border-radius:3px;font-size:12px;"><?php echo esc_html( $tag ); ?></code>
					<br />
					<span style="color:#646970;font-size:12px;"><?php echo esc_html( $label ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Save: writes the HTML to post_content and updates the meta.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// HTML: raw markup, sanitized the same way as on generation
		// (keeps tables, inline styles, <img>, and the native <barcode>
		// QR tag; strips <script> etc.).
		if ( isset( $_POST['aipdf_html'] ) ) {
			$html = AIPDF_PDF_Renderer::sanitize_html( wp_unslash( $_POST['aipdf_html'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_html() IS the sanitization.

			// Update post_content without triggering save_post recursively.
			remove_action( 'save_post_' . AIPDF_Plugin::CPT, array( $this, 'save' ), 10 );
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $html,
				)
			);
			add_action( 'save_post_' . AIPDF_Plugin::CPT, array( $this, 'save' ), 10, 2 );
		}

		// Visual fields: keep the saved definitions (type/label), only
		// update the values from POST — so the type can't be spoofed via the form.
		if ( isset( $_POST['aipdf_field'] ) && is_array( $_POST['aipdf_field'] ) ) {
			$posted = wp_unslash( $_POST['aipdf_field'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below via AIPDF_Fields.
			$fields = AIPDF_Fields::get( $post_id );
			foreach ( $fields as &$field ) {
				if ( array_key_exists( $field['key'], $posted ) ) {
					$field['value'] = AIPDF_Fields::sanitize_value( $field['type'], $posted[ $field['key'] ] );
				}
			}
			unset( $field );
			AIPDF_Fields::save( $post_id, $fields );
		}

		// Generation parameters.
		if ( isset( $_POST['aipdf_trigger'] ) ) {
			$trigger = AIPDF_Triggers::sanitize( wp_unslash( $_POST['aipdf_trigger'] ) );
			if ( in_array( $trigger, AIPDF_Triggers::all(), true ) ) {
				update_post_meta( $post_id, '_aipdf_trigger_plugin', $trigger );
			}
		}
		if ( isset( $_POST['aipdf_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['aipdf_action'] ) );
			if ( in_array( $action, AIPDF_Plugin::ALLOWED_ACTIONS, true ) ) {
				update_post_meta( $post_id, '_aipdf_action_type', $action );
			}
		}
		if ( isset( $_POST['aipdf_paper'] ) ) {
			$paper = sanitize_text_field( wp_unslash( $_POST['aipdf_paper'] ) );
			if ( preg_match( '/^[A-Za-z0-9x\- ]{1,20}$/', $paper ) ) {
				update_post_meta( $post_id, '_aipdf_paper_size', $paper );
			}
		}
	}
}
