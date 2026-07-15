<?php
/**
 * Брендинг: логотип, колір, реквізити компанії.
 *
 * Виносить «хардкод» із шаблонів у налаштування, щоб лого/кольори/поля
 * можна було міняти без правки HTML. Значення підставляються у плейсхолдери
 * {{logo_url}}, {{brand_color}}, {{company_name}}, {{company_address}},
 * {{company_email}} під час рендеру PDF.
 *
 * @package AI_PDF_Generator
 */

defined( 'ABSPATH' ) || exit;

class AIPDF_Brand {

	public const OPT_LOGO    = 'aipdf_brand_logo';
	public const OPT_COLOR   = 'aipdf_brand_color';
	public const OPT_NAME    = 'aipdf_brand_company';
	public const OPT_ADDRESS = 'aipdf_brand_address';
	public const OPT_EMAIL   = 'aipdf_brand_email';

	/**
	 * Колір за замовчуванням, якщо бренд не налаштований.
	 */
	public const DEFAULT_COLOR = '#1a1a2e';

	/**
	 * Значення бренду як дані для плейсхолдерів.
	 *
	 * @return array<string, string>
	 */
	public static function placeholders(): array {
		$color = (string) get_option( self::OPT_COLOR, self::DEFAULT_COLOR );

		return array(
			'logo_url'        => (string) get_option( self::OPT_LOGO, '' ),
			'brand_color'     => '' !== $color ? $color : self::DEFAULT_COLOR,
			'company_name'    => (string) get_option( self::OPT_NAME, '' ),
			'company_address' => (string) get_option( self::OPT_ADDRESS, '' ),
			'company_email'   => (string) get_option( self::OPT_EMAIL, '' ),
		);
	}

	/**
	 * Плейсхолдери бренду з демо-значеннями (для превю та тестового PDF,
	 * коли реальні реквізити ще не заповнені).
	 *
	 * @return array<string, string>
	 */
	public static function sample_placeholders(): array {
		$real = self::placeholders();

		$defaults = array(
			'logo_url'        => 'https://placehold.co/240x80/' . ltrim( self::DEFAULT_COLOR, '#' ) . '/ffffff?text=LOGO',
			'brand_color'     => self::DEFAULT_COLOR,
			'company_name'    => 'Your Company LLC',
			'company_address' => 'вул. Хрещатик, 1, Київ',
			'company_email'   => 'hello@example.com',
		);

		// Реальні значення мають пріоритет над демо.
		foreach ( $real as $key => $value ) {
			if ( '' !== $value ) {
				$defaults[ $key ] = $value;
			}
		}

		return $defaults;
	}
}
