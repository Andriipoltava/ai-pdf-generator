<?php
/**
 * Умовна логіка тригерів (Conditional Logic).
 *
 * Дозволяє обмежити генерацію PDF умовами на дані події — наприклад,
 * «лише якщо order_total > 1000» або «лише якщо product_category містить
 * Квитки». Усі умови шаблону поєднуються через І (AND): якщо хоч одна не
 * виконується — генерація тихо пропускається (немає умов → спрацьовує
 * завжди, як і раніше).
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Conditions {

	public const META = '_aipdf_conditions';

	/**
	 * Дозволені оператори порівняння.
	 */
	public const OPERATORS = array( '=', '!=', '>', '<', '>=', '<=', 'contains' );

	/**
	 * Нормалізує/санітизує список умов (з POST-репітера редактора).
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
				continue; // Рядок без поля — ігноруємо (порожній рядок репітера).
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
	 * Умови шаблону з БД.
	 *
	 * @return array<int, array{field:string,operator:string,value:string}>
	 */
	public static function get( int $post_id ): array {
		$json = (string) get_post_meta( $post_id, self::META, true );
		$arr  = json_decode( $json, true );
		return self::normalize( is_array( $arr ) ? $arr : array() );
	}

	/**
	 * Збереження умов шаблону.
	 *
	 * @param array<int, array<string, mixed>> $conditions
	 */
	public static function save( int $post_id, array $conditions ): void {
		$json = wp_json_encode( self::normalize( $conditions ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		update_post_meta( $post_id, self::META, wp_slash( (string) $json ) );
	}

	/**
	 * Чи задовольняють дані події ($data) усі умови шаблону. Немає умов
	 * → завжди true (поточна поведінка «спрацьовує завжди» не змінюється).
	 *
	 * @param array<string, string> $data Дані тригера (client_name, order_total, product_category…).
	 */
	public static function matches( int $post_id, array $data ): bool {
		$conditions = self::get( $post_id );
		if ( empty( $conditions ) ) {
			return true;
		}

		foreach ( $conditions as $cond ) {
			$actual = (string) ( $data[ $cond['field'] ] ?? '' );
			if ( ! self::compare( $actual, $cond['operator'], $cond['value'] ) ) {
				return false; // AND: одна невиконана умова — генерацію пропускаємо.
			}
		}

		return true;
	}

	/**
	 * Порівняння одного значення за оператором. Для >, <, >=, <= обидва
	 * боки парсяться як числа (щоб «1250.00 UAH» коректно порівнювалось
	 * із «1000»); якщо число не витягнути — умова вважається невиконаною.
	 */
	private static function compare( string $actual, string $operator, string $expected ): bool {
		if ( 'contains' === $operator ) {
			// mb_stripos, не stripos: останній case-fold-ить лише ASCII,
			// «Квитки» не знайшов би «квитки» в кириличному тексті.
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

		// = / != : числове порівняння, якщо обидва боки — числа; інакше рядкове (без регістру).
		$actual_num   = self::extract_numeric( $actual );
		$expected_num = self::extract_numeric( $expected );
		$equal        = ( null !== $actual_num && null !== $expected_num )
			? ( $actual_num === $expected_num )
			// strcasecmp зіставляє регістр лише для ASCII — з кирилицею
			// «Іван» ≠ «іван» для нього. mb_strtolower коректно працює з UTF-8.
			: ( mb_strtolower( trim( $actual ), 'UTF-8' ) === mb_strtolower( trim( $expected ), 'UTF-8' ) );

		return '!=' === $operator ? ! $equal : $equal;
	}

	/**
	 * Витягує перше число з рядка («1250.00 UAH» → 1250.0, «~15 шт.» → 15.0).
	 * Кома трактується як десятковий роздільник.
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
