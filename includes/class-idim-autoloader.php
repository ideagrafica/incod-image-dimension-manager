<?php
/**
 * Autoloader PSR-0/wP-style del plugin.
 *
 * @package IncodImageDimensionManager
 */

namespace IncodImageDimensionManager\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Classe Idim_Autoloader
 *
 * Mappa i namespace del plugin sui file seguendo la convenzione WordPress:
 * IncodImageDimensionManager\Includes\Idim_Image_Editor -> includes/class-idim-image-editor.php
 * IncodImageDimensionManager\Admin\Idim_Admin            -> admin/class-idim-admin.php
 */
final class Idim_Autoloader {

	/**
	 * Prefisso dei namespace del plugin.
	 *
	 * @var string
	 */
	const PREFIX = 'IncodImageDimensionManager\\';

	/**
	 * Registra l'autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Carica il file di classe richiesto.
	 *
	 * @param string $class Nome completo della classe.
	 * @return void
	 */
	public static function load( $class ) {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$parts    = explode( '\\', $relative );
		$basename = array_pop( $parts );

		if ( '' === $basename || '' === $relative ) {
			return;
		}

		$directories = array();
		foreach ( $parts as $part ) {
			$directories[] = strtolower( str_replace( '_', '-', $part ) );
		}

		$file = 'class-' . strtolower( str_replace( '_', '-', $basename ) ) . '.php';
		$path = IDIM_PLUGIN_DIR . ( $directories ? implode( '/', $directories ) . '/' : '' ) . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
