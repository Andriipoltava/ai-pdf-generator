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
