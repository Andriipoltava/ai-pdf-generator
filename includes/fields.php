<?php
/**
 * Visual template fields (editable_fields).
 *
 * JSON is the source of truth: Gemini returns an HTML skeleton with
 * {{field_key}} placeholders plus an array of fields (type, label, value).
 * Values are stored in a separate post meta field as JSON and substituted
 * into the skeleton when rendering.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Fields {

	public const META = '_aipdf_editable_fields';

	/**
	 * Supported editor field types.
	 */
	public const TYPES = array( 'color', 'text', 'textarea' );

	/**
	 * Normalizes/sanitizes a list of fields (from AI JSON or from POST).
	 *
	 * @param mixed $raw Raw array of field definitions.
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
	 * Sanitizes a field value according to its type.
	 */
	public static function sanitize_value( string $type, $value ): string {
		if ( 'color' === $type ) {
			$color = sanitize_hex_color( (string) $value );
			return $color ? $color : '#000000';
		}
		return sanitize_textarea_field( (string) $value );
	}

	/**
	 * A deterministic safety net against hardcoded colors. gemini-flash
	 * occasionally hardcodes a #hex color right in the markup, despite the
	 * prompt explicitly forbidding it. Regardless of how well the model
	 * complies, this scans the HTML for hex colors inside attribute values
	 * (style="...", bgcolor="...", color="..."), replaces every UNIQUE
	 * color with {{auto_color_N}}, and returns a ready-made color field for
	 * editable_fields. Identical colors (the same hex in multiple places)
	 * share one placeholder.
	 *
	 * @param string                                                                $html            Already-sanitized (wp_kses_post) HTML.
	 * @param array<int, array{key:string,type:string,label:string,value:string}> $existing_fields Fields the AI already declared — to avoid key collisions.
	 * @return array{html:string, fields:array<int, array{key:string,type:string,label:string,value:string}>}
	 */
	public static function extract_hardcoded_colors( string $html, array $existing_fields ): array {
		$used_keys = array();
		foreach ( $existing_fields as $field ) {
			if ( isset( $field['key'] ) ) {
				$used_keys[ $field['key'] ] = true;
			}
		}

		$hex_to_key = array(); // normalized hex => already-assigned key (dedup across the whole document).
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
									__( 'Color %d (auto-detected)', 'ai-pdf-generator' ),
									count( $new_fields ) + 1
								),
								'value' => $normalized,
							);
						}

						return '{{' . $hex_to_key[ $normalized ] . '}}';
					},
					$attr_match[3]
				);

				// Groups 1 and 2 are the attribute name and "=" with whatever spacing was there.
				return $attr_match[1] . $attr_match[2] . '"' . $value . '"';
			},
			$html
		);

		return array( 'html' => $html, 'fields' => $new_fields );
	}

	/**
	 * Normalizes a hex color (3 or 6 digits, with or without "#") into "#rrggbb".
	 */
	private static function expand_hex( string $hex ): string {
		$hex = ltrim( strtolower( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return '#' . $hex;
	}

	/**
	 * key => value map for substituting placeholders.
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
	 * Template fields from the DB (normalized).
	 *
	 * @return array<int, array{key:string,type:string,label:string,value:string}>
	 */
	public static function get( int $post_id ): array {
		$json = (string) get_post_meta( $post_id, self::META, true );
		$arr  = json_decode( $json, true );
		return self::normalize( is_array( $arr ) ? $arr : array() );
	}

	/**
	 * Saves fields to the DB as JSON.
	 *
	 * @param array<int, array<string, mixed>> $fields
	 */
	public static function save( int $post_id, array $fields ): void {
		// Store Cyrillic/multibyte text as plain UTF-8 (no \uXXXX escapes) and
		// slash it before writing: update_post_meta runs wp_unslash, which
		// would otherwise strip the JSON escaping backslashes and corrupt it.
		$json = wp_json_encode( self::normalize( $fields ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		update_post_meta( $post_id, self::META, wp_slash( (string) $json ) );
	}
}
