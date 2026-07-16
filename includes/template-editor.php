<?php
/**
 * Власний редактор шаблону для CPT `pdf_ai_template`.
 *
 * Замінює стандартний редактор WordPress (який ламає HTML-таблиці й
 * інлайнові стилі) на:
 *  - meta box із сирим HTML (моноширинний textarea);
 *  - живе превю в iframe, що оновлюється під час набору;
 *  - meta box параметрів (тригер, тип дії, розмір) із випадаючими списками.
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
	 * Gutenberg для цього CPT вимкнено (шаблон — сирий HTML, не блоки).
	 */
	public function disable_block_editor( $use, $post_type ) {
		return AIPDF_Plugin::CPT === $post_type ? false : $use;
	}

	/**
	 * Скрипт превю — лише на екрані редагування шаблону.
	 */
	public function enqueue( string $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || AIPDF_Plugin::CPT !== $screen->post_type ) {
			return;
		}

		// WP Color Picker для полів-кольорів.
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
				// Демо-дані для превю (спільні з рендером PDF та Playground).
				'sample' => AIPDF_PDF_Renderer::sample_data(),
			)
		);
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'aipdf_fields',
			__( 'Візуальне редагування', 'ai-pdf-generator' ),
			array( $this, 'box_fields' ),
			AIPDF_Plugin::CPT,
			'normal',
			'high'
		);
		add_meta_box(
			'aipdf_preview',
			__( 'Попередній перегляд', 'ai-pdf-generator' ),
			array( $this, 'box_preview' ),
			AIPDF_Plugin::CPT,
			'normal',
			'high'
		);
		add_meta_box(
			'aipdf_html',
			__( 'Розширено: HTML-каркас', 'ai-pdf-generator' ),
			array( $this, 'box_html' ),
			AIPDF_Plugin::CPT,
			'normal',
			'low'
		);
		add_meta_box(
			'aipdf_params',
			__( 'Параметри генерації', 'ai-pdf-generator' ),
			array( $this, 'box_params' ),
			AIPDF_Plugin::CPT,
			'side',
			'default'
		);
	}

	/**
	 * Meta box: візуальні поля (кольори/тексти) з editable_fields.
	 * Сирий HTML тут прихований — редагування лише через поля.
	 */
	public function box_fields( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );

		$fields = AIPDF_Fields::get( $post->ID );

		if ( empty( $fields ) ) {
			?>
			<p class="description">
				<?php esc_html_e( 'Цей шаблон не має візуальних полів (згенерований раніше або без них). Ви можете редагувати HTML-каркас у блоці «Розширено» нижче, або згенерувати новий шаблон у Playground — нові шаблони отримують візуальні поля автоматично.', 'ai-pdf-generator' ); ?>
			</p>
			<?php
			return;
		}
		?>
		<p class="description" style="margin:0 0 12px;">
			<?php esc_html_e( 'Змінюйте кольори та тексти — превю оновлюється миттєво. HTML-структуру чіпати не потрібно.', 'ai-pdf-generator' ); ?>
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
	 * Meta box: сирий HTML-каркас (розширено). Прихований у згорнутому блоці —
	 * основне редагування через візуальні поля вище.
	 */
	public function box_html( WP_Post $post ): void {
		?>
		<p class="description" style="margin:0 0 8px;">
			<?php esc_html_e( 'HTML-каркас документа з плейсхолдерами {{field_key}} (візуальні поля) та даними події ({{client_name}}, {{order_id}}…). Редагуйте лише якщо потрібно змінити структуру.', 'ai-pdf-generator' ); ?>
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
	 * Meta box: живе превю (iframe у пісочниці).
	 */
	public function box_preview( WP_Post $post ): void {
		?>
		<p class="description" style="margin:0 0 8px;">
			<?php esc_html_e( 'Оновлюється автоматично під час редагування. Плейсхолдери замінені демо-даними.', 'ai-pdf-generator' ); ?>
			<button type="button" class="button button-small" id="aipdf-preview-refresh"><?php esc_html_e( 'Оновити', 'ai-pdf-generator' ); ?></button>
		</p>
		<iframe id="aipdf-editor-preview" sandbox="" style="width:100%;height:520px;border:1px solid #ccd0d4;background:#fff;"></iframe>
		<?php
	}

	/**
	 * Meta box: тригер, тип дії, розмір — редаговані списки.
	 */
	public function box_params( WP_Post $post ): void {
		$trigger = (string) get_post_meta( $post->ID, '_aipdf_trigger_plugin', true );
		$action  = (string) get_post_meta( $post->ID, '_aipdf_action_type', true );
		$paper   = (string) get_post_meta( $post->ID, '_aipdf_paper_size', true );
		?>
		<p>
			<label for="aipdf-trigger"><strong><?php esc_html_e( 'Тригер', 'ai-pdf-generator' ); ?></strong></label><br />
			<select id="aipdf-trigger" name="aipdf_trigger" style="width:100%;">
				<?php
				// Показуємо лише доступні тригери (активні плагіни). Якщо у шаблону
				// збережений тригер плагіна, який зараз вимкнено, — додаємо його
				// окремо, щоб значення не загубилось при збереженні.
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
						echo $is_inactive ? ' ' . esc_html__( '(плагін неактивний)', 'ai-pdf-generator' ) : '';
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="aipdf-action"><strong><?php esc_html_e( 'Тип дії', 'ai-pdf-generator' ); ?></strong></label><br />
			<select id="aipdf-action" name="aipdf_action" style="width:100%;">
				<?php foreach ( AIPDF_Plugin::ALLOWED_ACTIONS as $a ) : ?>
					<option value="<?php echo esc_attr( $a ); ?>" <?php selected( $action, $a ); ?>><?php echo esc_html( $a ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="aipdf-paper"><strong><?php esc_html_e( 'Розмір (A4, Letter, 800x400…)', 'ai-pdf-generator' ); ?></strong></label><br />
			<input type="text" id="aipdf-paper" name="aipdf_paper" value="<?php echo esc_attr( $paper ); ?>" style="width:100%;" />
		</p>
		<?php
	}

	/**
	 * Збереження: пишемо HTML у post_content та оновлюємо meta.
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

		// HTML: сира розмітка, очищена так само, як при генерації (wp_kses_post
		// зберігає таблиці, інлайнові стилі, <img>, але прибирає <script>).
		if ( isset( $_POST['aipdf_html'] ) ) {
			$html = wp_kses_post( wp_unslash( $_POST['aipdf_html'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_kses_post і є санітизацією.

			// Оновлюємо post_content без рекурсії save_post.
			remove_action( 'save_post_' . AIPDF_Plugin::CPT, array( $this, 'save' ), 10 );
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $html,
				)
			);
			add_action( 'save_post_' . AIPDF_Plugin::CPT, array( $this, 'save' ), 10, 2 );
		}

		// Візуальні поля: беремо збережені визначення (тип/лейбл), оновлюємо
		// лише значення з POST — щоб тип не можна було підмінити з форми.
		if ( isset( $_POST['aipdf_field'] ) && is_array( $_POST['aipdf_field'] ) ) {
			$posted = wp_unslash( $_POST['aipdf_field'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- санітизуємо нижче через AIPDF_Fields.
			$fields = AIPDF_Fields::get( $post_id );
			foreach ( $fields as &$field ) {
				if ( array_key_exists( $field['key'], $posted ) ) {
					$field['value'] = AIPDF_Fields::sanitize_value( $field['type'], $posted[ $field['key'] ] );
				}
			}
			unset( $field );
			AIPDF_Fields::save( $post_id, $fields );
		}

		// Параметри генерації.
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
