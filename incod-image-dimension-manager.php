<?php
/**
 * Plugin Name:       Incod Image Dimension Manager
 * Plugin URI:        https://incod.it/incod-image-dimension-manager/
 * Description:       Ridimensiona, adatta e uniforma tutte le immagini a dimensioni fisse con canvas letterbox o crop, senza limiti e con pieno supporto a tutti i Custom Post Type.
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            inCOD, ideagrafica
 * Author URI:        https://www.studioideagrafica.it
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       incod-image-dimension-manager
 * Domain Path:       /languages
 *
 * @package IncodImageDimensionManager
 */

defined( 'ABSPATH' ) || exit;

define( 'IDIM_VERSION', '1.0.0' );
define( 'IDIM_PLUGIN_FILE', __FILE__ );
define( 'IDIM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'IDIM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'IDIM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'IDIM_TEXT_DOMAIN', 'incod-image-dimension-manager' );

require_once IDIM_PLUGIN_DIR . 'includes/class-idim-autoloader.php';

\IncodImageDimensionManager\Includes\Idim_Autoloader::register();

register_activation_hook( __FILE__, array( \IncodImageDimensionManager\Includes\Idim_Core::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \IncodImageDimensionManager\Includes\Idim_Core::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \IncodImageDimensionManager\Includes\Idim_Core::class, 'boot' ), 5 );
