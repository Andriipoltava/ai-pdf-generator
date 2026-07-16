<?php
/**
 * Візуальні поля шаблону (editable_fields).
 *
 * JSON — джерело правди: Gemini повертає HTML-каркас із плейсхолдерами
 * {{field_key}} та масив полів (тип, лейбл, значення). Значення зберігаються
 * окремою post meta як JSON і підставляються в каркас при рендері.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Fields {

	public const META = '_aipdf_editable_fields';

	/**
	 * Підтримувані типи полів редактора.
	 */
	public const TYPES = array( 'color', 'text', 'textarea' );

	/**
	 * Нормалізує/санітизує список полів (із AI-JSON або з POST).
	 *
	 * @param mixed $raw Сирий масив визначень полів.
	 * @return array<int, array{key:string,type:string,label:string,value:string}>
	 */
	public static function normalize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out  = array();
		$seen = array();

		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$type  = in_array( ( $field['type'] ?? '' ), self::TYPES, true ) ? (string) $field['type'] : 'text';
			$label = sanitize_text_field( (string) ( $field['label'] ?? $key ) );
			$value = self::sanitize_value( $type, $field['value'] ?? '' );

			$out[]         = compact( 'key', 'type', 'label', 'value' );
			$seen[ $key ]  = true;
		}

		return $out;
	}

	/**
	 * Санітизація значення поля відповідно до типу.
	 */
	public static function sanitize_value( string $type, $value ): string {
		if ( 'color' === $type ) {
			$color = sanitize_hex_color( (string) $value );
			return $color ? $color : '#000000';
		}
		return sanitize_textarea_field( (string) $value );
	}

	/**
	 * Детерміністичний «запобіжник» від хардкоду кольорів. gemini-flash
	 * подеколи хардкодить #hex просто в розмітці, попри пряму заборону
	 * в промпті. Незалежно від слухняності моделі — сканує HTML на предмет
	 * hex-кольорів у значеннях атрибутів (style="...", bgcolor="...",
	 * color="..."), замінює кожен УНІКАЛЬНИЙ колір на {{auto_color_N}}
	 * і повертає готове color-поле для editable_fields. Однакові кольори
	 * (той самий hex у кількох місцях) отримують один спільний плейсхолдер.
	 *
	 * @param string                                              $html            Уже санітизований (wp_kses_post) HTML.
	 * @param array<int, array{key:string,type:string,label:string,value:string}> $existing_fields Поля, які вже задекларувала AI — щоб не зіткнутись ключами.
	 * @return array{html:string, fields:array<int, array{key:string,type:string,label:string,value:string}>}
	 */
	public static function extract_hardcoded_colors( string $html, array $existing_fields ): array {
		$used_keys = array();
		foreach ( $existing_fields as $field ) {
			if ( isset( $field['key'] ) ) {
				$used_keys[ $field['key'] ] = true;
			}
		}

		$hex_to_key = array(); // нормалізований hex => вже присвоєний ключ (дедуп на весь документ).
		$new_fields = array();
		$counter    = 0;

		$html = (string) preg_replace_callback(
			'/(style|bgcolor|color)(\s*=\s*)"([^"]*)"/i',
			static function ( array $attr_match ) use ( &$hex_to_key, &$new_fields, &$used_keys, &$counter ) {
				$value = preg_replace_callback(
					'/#(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/',
					static function ( array $hex_match ) use ( &$hex_to_key, &$new_fields, &$used_keys, &$counter ) {
						$normalized = AIPDF_Fields::expand_hex( $hex_match[0] );

						if ( ! isset( $hex_to_key[ $normalized ] ) ) {
							do {
								++$counter;
								$key = 'auto_color_' . $counter;
							} while ( isset( $used_keys[ $key ] ) );

							$used_keys[ $key ]        = true;
							$hex_to_key[ $normalized ] = $key;
							$new_fields[]              = array(
								'key'   => $key,
								'type'  => 'color',
								'label' => sprintf(
									/* translators: %d: sequential number of the auto-detected color. */
									__( 'Колір %d (виявлено автоматично)', 'ai-pdf-generator' ),
									count( $new_fields ) + 1
								),
								'value' => $normalized,
							);
						}

						return '{{' . $hex_to_key[ $normalized ] . '}}';
					},
					$attr_match[3]
				);

				// Групи 1 і 2 — ім'я атрибута й «=» з пробілами саме такі, якими були.
				return $attr_match[1] . $attr_match[2] . '"' . $value . '"';
			},
			$html
		);

		return array( 'html' => $html, 'fields' => $new_fields );
	}

	/**
	 * Нормалізує hex-колір (3 або 6 знаків, з «#» або без) у формат «#rrggbb».
	 */
	private static function expand_hex( string $hex ): string {
		$hex = ltrim( strtolower( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return '#' . $hex;
	}

	/**
	 * Мапа key => value для підстановки в плейсхолдери.
	 *
	 * @param array<int, array{key:string,value:string}> $fields
	 * @return array<string, string>
	 */
	public static function values( array $fields ): array {
		$map = array();
		foreach ( $fields as $field ) {
			if ( isset( $field['key'] ) ) {
				$map[ $field['key'] ] = $field['value'] ?? '';
			}
		}
		return $map;
	}

	/**
	 * Поля шаблону з БД (нормалізовані).
	 *
	 * @return array<int, array{key:string,type:string,label:string,value:string}>
	 */
	public static function get( int $post_id ): array {
		$json = (string) get_post_meta( $post_id, self::META, true );
		$arr  = json_decode( $json, true );
		return self::normalize( is_array( $arr ) ? $arr : array() );
	}

	/**
	 * Збереження полів у БД як JSON.
	 *
	 * @param array<int, array<string, mixed>> $fields
	 */
	public static function save( int $post_id, array $fields ): void {
		// Зберігаємо кирилицю як UTF-8 (без \uXXXX) і слешимо перед записом:
		// update_post_meta застосовує wp_unslash, який інакше зрізав би
		// бекслеші JSON-екранування та ламав би структуру.
		$json = wp_json_encode( self::normalize( $fields ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		update_post_meta( $post_id, self::META, wp_slash( (string) $json ) );
	}
}
