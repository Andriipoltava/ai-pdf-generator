<?php
/**
 * Branding: logo, color, company details.
 *
 * Moves "hardcoded" values out of templates and into settings, so the
 * logo/colors/details can be changed without editing HTML. Values are
 * substituted into the {{logo_url}}, {{brand_color}}, {{company_name}},
 * {{company_address}}, {{company_email}} placeholders when rendering a PDF.
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
	 * Default color if no brand color has been configured.
	 */
	public const DEFAULT_COLOR = '#1a1a2e';

	/**
	 * Brand values as placeholder data.
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
	 * Brand placeholders with sample values (for preview and the test PDF,
	 * before real company details have been filled in).
	 *
	 * @return array<string, string>
	 */
	public static function sample_placeholders(): array {
		$real = self::placeholders();

		$defaults = array(
			'logo_url'        => 'https://placehold.co/240x80/' . ltrim( self::DEFAULT_COLOR, '#' ) . '/ffffff?text=LOGO',
			'brand_color'     => self::DEFAULT_COLOR,
			'company_name'    => 'Your Company LLC',
			'company_address' => '123 Main St, Springfield',
			'company_email'   => 'hello@example.com',
		);

		// Real values take priority over the sample ones.
		foreach ( $real as $key => $value ) {
			if ( '' !== $value ) {
				$defaults[ $key ] = $value;
			}
		}

		return $defaults;
	}
}
