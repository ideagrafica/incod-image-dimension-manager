<?php
/**
 * Gestione delle impostazioni del plugin (Settings API).
 *
 * @package IncodImageDimensionManager
 */

namespace IncodImageDimensionManager\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Classe Idim_Settings
 */
final class Idim_Settings {

	/**
	 * Nome dell'opzione in wp_options.
	 *
	 * @var string
	 */
	const OPTION = 'idim_settings';

	/**
	 * Modalità di ridimensionamento supportate.
	 *
	 * @var string[]
	 */
	const MODES = array( 'letterbox', 'crop', 'scale' );

	/**
	 * Punti focale supportati per l'Hard Crop.
	 *
	 * @var string[]
	 */
	const FOCUS = array( 'center', 'top', 'bottom', 'left', 'right' );

	/**
	 * Valori di default delle impostazioni.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'target_width'       => 1200,
			'target_height'      => 800,
			'mode'               => 'letterbox',
			'background_color'   => '#ffffff',
			'transparent_canvas' => false,
			'crop_focus'         => 'center',
			'post_types'         => array( 'post', 'page' ),
			'all_media'          => true,
			'auto_resize'        => true,
			'skip_sized'         => true,
			'allow_upscale'      => false,
			'backup_originals'   => false,
			'batch_size'         => 10,
			'jpeg_quality'       => 82,
		);
	}

	/**
	 * Restituisce tutte le impostazioni messe a default.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Restituisce un'impostazione singola.
	 *
	 * @param string $key Chiave dell'impostazione.
	 * @return mixed
	 */
	public static function get( $key ) {
		$settings = self::all();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Sostituisce tutte le impostazioni.
	 *
	 * @param array $settings Impostazioni da salvare.
	 * @return bool
	 */
	public static function update( $settings ) {
		return update_option( self::OPTION, self::sanitize( $settings ), false );
	}

	/**
	 * Crea l'impostazione di default alla prima attivazione.
	 *
	 * @return void
	 */
	public static function seed() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/**
	 * Callback di sanitizzazione per register_setting().
	 *
	 * @param mixed $input Dati grezzi provenienti dal form.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		$input   = is_array( $input ) ? $input : array();
		$output  = array();

		$output['target_width']       = self::sanitize_dimension( $input, 'target_width', $current['target_width'] );
		$output['target_height']      = self::sanitize_dimension( $input, 'target_height', $current['target_height'] );
		$output['mode']               = self::sanitize_choice( $input, 'mode', self::MODES, $current['mode'] );
		$output['crop_focus']         = self::sanitize_choice( $input, 'crop_focus', self::FOCUS, $current['crop_focus'] );
		$output['transparent_canvas'] = ! empty( $input['transparent_canvas'] );

		$color = isset( $input['background_color'] ) ? sanitize_hex_color( $input['background_color'] ) : '';
		$output['background_color']   = $color ? $color : $current['background_color'];

		$output['post_types'] = self::sanitize_post_types( isset( $input['post_types'] ) ? $input['post_types'] : array() );

		$output['all_media']        = ! empty( $input['all_media'] );
		$output['auto_resize']      = ! empty( $input['auto_resize'] );
		$output['skip_sized']       = ! empty( $input['skip_sized'] );
		$output['allow_upscale']    = ! empty( $input['allow_upscale'] );
		$output['backup_originals'] = ! empty( $input['backup_originals'] );

		$output['batch_size']   = self::clamp( isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : 0, 1, 50, $current['batch_size'] );
		$output['jpeg_quality'] = self::clamp( isset( $input['jpeg_quality'] ) ? absint( $input['jpeg_quality'] ) : 0, 1, 100, $current['jpeg_quality'] );

		/**
		 * Filtra le impostazioni sanitate prima del salvataggio.
		 *
		 * @param array $output Impostazioni sanitate.
		 * @param array $input  Input grezzo.
		 */
		return apply_filters( 'idim_sanitized_settings', $output, $input );
	}

	/**
	 * Sanitizza una dimensione in pixel.
	 *
	 * @param array  $input   Input grezzo.
	 * @param string $key     Chiave.
	 * @param int    $default Valore di fallback.
	 * @return int
	 */
	private static function sanitize_dimension( $input, $key, $default ) {
		$value = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;

		return self::clamp( $value, 1, 10000, $default );
	}

	/**
	 * Sanitizza un valore appartenente a una lista bianca.
	 *
	 * @param array    $input   Input grezzo.
	 * @param string   $key     Chiave.
	 * @param string[] $allowed Valori ammessi.
	 * @param string   $default Valore di fallback.
	 * @return string
	 */
	private static function sanitize_choice( $input, $key, $allowed, $default ) {
		$value = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : '';

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Limita un intero in un intervallo.
	 *
	 * @param int $value   Valore.
	 * @param int $min     Minimo.
	 * @param int $max     Massimo.
	 * @param int $default Fallback.
	 * @return int
	 */
	private static function clamp( $value, $min, $max, $default ) {
		if ( $value < $min || $value > $max ) {
			return (int) $default;
		}

		return (int) $value;
	}

	/**
	 * Sanitizza l'elenco dei Custom Post Type selezionati.
	 *
	 * @param mixed $post_types Input grezzo.
	 * @return string[]
	 */
	private static function sanitize_post_types( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return array();
		}

		$clean     = array_map( 'sanitize_key', $post_types );
		$registered = get_post_types( array( 'public' => true ), 'names' );
		$clean      = array_values( array_intersect( $clean, (array) $registered ) );

		/**
		 * Filtra l'elenco dei Custom Post Type selezionabili.
		 *
		 * @param string[] $clean Post type sanificati.
		 */
		return apply_filters( 'idim_selected_post_types', $clean );
	}
}
