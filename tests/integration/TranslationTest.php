<?php
/**
 * Spanish is the plugin's base language: the specification's own example
 * must come out, character for character, on a site in Spanish.
 *
 * @package DFXPRAY
 */

/**
 * Translation behaviour.
 */
class TranslationTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * Switch to Spanish. The test library ships only part of core's Spanish
	 * translation, so the weekday and month names core would translate are supplied here.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Madrid' );
		add_filter( 'gettext', array( $this, 'core_weekdays' ), 10, 3 );
		switch_to_locale( 'es_ES' );
		DFXPRAY_Settings::get_instance()->flush_cache();
		$this->freeze_time( '2026-09-24 10:00' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Back to English.
	 */
	public function tear_down(): void {
		restore_previous_locale();
		remove_filter( 'gettext', array( $this, 'core_weekdays' ), 10 );
		parent::tear_down();
	}

	/**
	 * Core's weekday names in Spanish, and the month of the frozen date.
	 *
	 * @param string $translation Translation.
	 * @param string $text        Original.
	 * @param string $domain      Domain.
	 */
	public function core_weekdays( $translation, $text, $domain ) {
		$days = array(
			'Monday'    => 'lunes',
			'Tuesday'   => 'martes',
			'Wednesday' => 'miércoles',
			'Thursday'  => 'jueves',
			'Friday'    => 'viernes',
			'Saturday'  => 'sábado',
			'Sunday'    => 'domingo',
			'September' => 'septiembre',
		);

		return 'default' === $domain && isset( $days[ $text ] ) ? $days[ $text ] : $translation;
	}

	/**
	 * The index example of the specification.
	 */
	public function test_specification_index_example_in_spanish(): void {
		$group = $this->make_group();
		$this->make_active(
			$group,
			array(
				'requester_name'           => 'Pablo Sanz',
				'beneficiary_is_requester' => true,
				'type'                     => 'event',
				'reference_date'           => '2026-09-25',
				'summary'                  => 'entrevista de trabajo',
			)
		);
		$this->make_active(
			$group,
			array(
				'type'           => 'event',
				'reference_date' => '2026-09-28',
				'reference_time' => '09:30',
				'summary'        => 'operación',
			)
		);
		$this->make_active(
			$group,
			array(
				'requester_name'   => 'Javier Pérez',
				'beneficiary_name' => 'Antonio Pérez',
				'relationship'     => 'padre de',
				'type'             => 'deceased',
				'reference_date'   => '2026-09-20',
				'summary'          => 'por su eterno descanso',
			)
		);
		$high = $this->make_active(
			$group,
			array(
				'beneficiary_name' => 'Marta García',
				'relationship'     => '',
				'discretion'       => 'initials',
				'summary'          => 'tratamiento de quimioterapia',
			)
		);
		DFXPRAY_Intentions::get_instance()->set_priority( $high, 'high' );
		$this->make_active(
			$group,
			array(
				'discretion' => 'anonymous',
				'summary'    => 'por su familia',
			)
		);
		$this->make_active(
			$group,
			array(
				'type'       => 'thanksgiving',
				'discretion' => 'anonymous',
				'summary'    => 'Por el trabajo encontrado de Luis',
			)
		);

		$expected = implode(
			"\n",
			array(
				'🙏 *Intenciones activas* – 24/09',
				'',
				'*Eventos próximos*',
				'• Pablo: entrevista de trabajo _(mañana)_',
				'• Carmen, madre de Luis: operación _(lunes 28, 9:30)_',
				'',
				'*Por nuestros difuntos*',
				'• Antonio, padre de Javier: por su eterno descanso († 20/09)',
				'',
				'*Enfermos y situaciones difíciles*',
				'• ❗ M. G.: tratamiento de quimioterapia',
				'• Una intención particular: por su familia',
				'',
				'*Acción de gracias*',
				'• Una intención particular: Por el trabajo encontrado de Luis',
			)
		);

		$this->assertSame( $expected, DFXPRAY_Index_Composer::get_instance()->compose( $group ) );
	}

	/**
	 * Plain-language form choices and the obfuscated URL base.
	 */
	public function test_form_and_url_in_spanish(): void {
		$group = DFXPRAY_Groups::get_instance()->get( $this->make_group() );
		$html  = DFXPRAY_Form::get_instance()->render( $group['id'] );

		$this->assertMatchesRegularExpression( '/^peticiones-[a-z0-9]{8}$/', $group['slug'] );
		foreach ( array( 'un momento concreto', 'un fallecimiento', 'una situación que se alarga', 'dar gracias', 'Nombre de pila', 'Iniciales', 'Anónimo' ) as $text ) {
			$this->assertStringContainsString( $text, $html );
		}
	}

	/**
	 * The DFX footer in Spanish, with the Spanish page of the author's site.
	 */
	public function test_footer_in_spanish(): void {
		ob_start();
		DFXPRAY_Admin::get_instance()->render_footer();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Funcionalidad de gestión de intenciones de oración proporcionada a través del plugin <strong>DFX Prayer Intentions</strong> por <a href="https://davefx.com/es/plugins-de-wordpress/">David Marín Carreño</a>. A.M.D.G.', $html );
	}

	/**
	 * The long date of the prayer proposals header, in Spanish.
	 */
	public function test_long_date_in_spanish(): void {
		$this->assertSame( 'Jueves, 24 de septiembre de 2026', DFXPRAY_Formatter::get_instance()->fill( '{long_date}' ) );
		$this->assertSame( array( 'Especialmente', 'También por', 'Damos gracias', 'Por nuestros difuntos' ), array_values( array_intersect_key( DFXPRAY_Index_Composer::get_instance()->titles(), array_flip( array( 'urgent', 'others', 'thanks', 'departed' ) ) ) ) );
	}

	/**
	 * Every string of the catalogue is translated.
	 */
	public function test_catalogue_is_complete(): void {
		$po = file_get_contents( DFXPRAY_PLUGIN_DIR . 'languages/dfx-prayer-intentions-es_ES.po' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertNotFalse( $po );

		$translations = new PO();
		$translations->import_from_file( DFXPRAY_PLUGIN_DIR . 'languages/dfx-prayer-intentions-es_ES.po' );
		$template = new PO();
		$template->import_from_file( DFXPRAY_PLUGIN_DIR . 'languages/dfx-prayer-intentions.pot' );

		$missing = array();
		foreach ( $template->entries as $key => $entry ) {
			$translated = $translations->entries[ $key ] ?? null;
			if ( ! $translated || '' === implode( '', $translated->translations ) || in_array( '', $translated->translations, true ) ) {
				$missing[] = $entry->singular;
			}
		}

		$this->assertSame( array(), $missing );
	}
}
