<?php
/**
 * Trigger Conditional Logic.
 *
 * Lets PDF generation be gated on the event's data — e.g. "only if
 * order_total > 1000" or "only if product_category contains Tickets".
 * All of a template's conditions are combined with AND: if even one fails,
 * generation is silently skipped (no conditions → always fires, unchanged
 * from before).
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Conditions {

	public const META = '_aipdf_conditions';

	/**
	 * Allowed comparison operators.
	 */
	public const OPERATORS = array( '=', '!=', '>', '<', '>=', '<=', 'contains' );

	/**
	 * Normalizes/sanitizes a list of conditions (from the editor's POST repeater).
	 *
	 * @param array<int, array{field?:mixed,operator?:mixed,value?:mixed}> $raw
	 * @return array<int, array{field:string,operator:string,value:string}>
	 */
	public static function normalize( array $raw ): array {
		$out = array();

		foreach ( $raw as $cond ) {
			if ( ! is_array( $cond ) ) {
				continue;
			}

			$field = sanitize_key( (string) ( $cond['field'] ?? '' ) );
			if ( '' === $field ) {
				continue; // Row without a field — ignore (an empty repeater row).
			}

			$operator = (string) ( $cond['operator'] ?? '=' );
			if ( ! in_array( $operator, self::OPERATORS, true ) ) {
				$operator = '=';
			}

			$value = sanitize_text_field( (string) ( $cond['value'] ?? '' ) );

			$out[] = array(
				'field'    => $field,
				'operator' => $operator,
				'value'    => $value,
			);
		}

		return $out;
	}

	/**
	 * A template's conditions from the DB.
	 *
	 * @return array<int, array{field:string,operator:string,value:string}>
	 */
	public static function get( int $post_id ): array {
		$json = (string) get_post_meta( $post_id, self::META, true );
		$arr  = json_decode( $json, true );
		return self::normalize( is_array( $arr ) ? $arr : array() );
	}

	/**
	 * Saves a template's conditions.
	 *
	 * @param array<int, array<string, mixed>> $conditions
	 */
	public static function save( int $post_id, array $conditions ): void {
		$json = wp_json_encode( self::normalize( $conditions ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		update_post_meta( $post_id, self::META, wp_slash( (string) $json ) );
	}

	/**
	 * Whether the event data ($data) satisfies all of a template's
	 * conditions. No conditions → always true (the "always fires" default
	 * behavior is unchanged).
	 *
	 * @param array<string, string> $data Trigger data (client_name, order_total, product_category…).
	 */
	public static function matches( int $post_id, array $data ): bool {
		$conditions = self::get( $post_id );
		if ( empty( $conditions ) ) {
			return true;
		}

		foreach ( $conditions as $cond ) {
			$actual = (string) ( $data[ $cond['field'] ] ?? '' );
			if ( ! self::compare( $actual, $cond['operator'], $cond['value'] ) ) {
				return false; // AND: one failed condition is enough to skip generation.
			}
		}

		return true;
	}

	/**
	 * Compares a single value using the given operator. For >, <, >=, <=
	 * both sides are parsed as numbers (so "1250.00 UAH" compares correctly
	 * against "1000"); if a number can't be extracted, the condition is
	 * treated as not met.
	 */
	private static function compare( string $actual, string $operator, string $expected ): bool {
		if ( 'contains' === $operator ) {
			// mb_stripos, not stripos: the latter only case-folds ASCII, so
			// "Tickets" wouldn't match "tickets" in Cyrillic (or other
			// multibyte) text.
			return '' !== $expected && false !== mb_stripos( $actual, $expected, 0, 'UTF-8' );
		}

		if ( in_array( $operator, array( '>', '<', '>=', '<=' ), true ) ) {
			$actual_num   = self::extract_numeric( $actual );
			$expected_num = self::extract_numeric( $expected );
			if ( null === $actual_num || null === $expected_num ) {
				return false;
			}

			switch ( $operator ) {
				case '>':
					return $actual_num > $expected_num;
				case '<':
					return $actual_num < $expected_num;
				case '>=':
					return $actual_num >= $expected_num;
				case '<=':
					return $actual_num <= $expected_num;
			}
		}

		// = / != : numeric comparison if both sides are numbers, otherwise
		// case-insensitive string comparison.
		$actual_num   = self::extract_numeric( $actual );
		$expected_num = self::extract_numeric( $expected );
		$equal        = ( null !== $actual_num && null !== $expected_num )
			? ( $actual_num === $expected_num )
			// strcasecmp only case-folds ASCII — for multibyte text like
			// Cyrillic "Іван" vs "іван" it fails. mb_strtolower handles UTF-8 correctly.
			: ( mb_strtolower( trim( $actual ), 'UTF-8' ) === mb_strtolower( trim( $expected ), 'UTF-8' ) );

		return '!=' === $operator ? ! $equal : $equal;
	}

	/**
	 * Extracts the first number from a string ("1250.00 UAH" -> 1250.0,
	 * "~15 pcs." -> 15.0). A comma is treated as a decimal separator.
	 */
	private static function extract_numeric( string $str ): ?float {
		$str = trim( $str );
		if ( '' === $str ) {
			return null;
		}
		if ( ! preg_match( '/-?\d+(?:[.,]\d+)?/', $str, $m ) ) {
			return null;
		}
		return (float) str_replace( ',', '.', $m[0] );
	}
}
