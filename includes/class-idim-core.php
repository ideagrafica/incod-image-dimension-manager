<?php
/**
 * Classe core: collega tutti i componenti del plugin.
 *
 * @package IncodImageDimensionManager
 */

namespace IncodImageDimensionManager\Includes;

use IncodImageDimensionManager\Admin\Idim_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Classe Idim_Core
 */
final class Idim_Core {

	/**
	 * Istanza unica.
	 *
	 * @var Idim_Core|null
	 */
	private static $instance = null;

	/**
	 * Registro degli hooks.
	 *
	 * @var Idim_Loader
	 */
	private $loader;

	/**
	 * Motore immagini.
	 *
	 * @var Idim_Image_Editor
	 */
	private $editor;

	/**
	 * Gestore CPT.
	 *
	 * @var Idim_Cpt_Manager
	 */
	private $cpt;

	/**
	 * Endpoint AJAX.
	 *
	 * @var Idim_Ajax
	 */
	private $ajax;

	/**
	 * Interfaccia di amministrazione.
	 *
	 * @var Idim_Admin|null
	 */
	private $admin;

	/**
	 * Restituisce (e crea) l'istanza unica.
	 *
	 * @return Idim_Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Avvia il plugin.
	 *
	 * @return void
	 */
	public static function boot() {
		self::instance()->run();
	}

	/**
	 * Costruttore privato (singleton).
	 */
	private function __construct() {
		$this->loader = new Idim_Loader();
		$this->editor = new Idim_Image_Editor();
		$this->cpt    = new Idim_Cpt_Manager();
		$this->ajax   = new Idim_Ajax();

		$this->register_hooks();
	}

	/**
	 * Collega i componenti a WordPress.
	 *
	 * @return void
	 */
	private function register_hooks() {
		// Ridimensionamento automatico al caricamento delle nuove immagini.
		$this->loader->add_filter( 'wp_generate_attachment_metadata', array( $this->editor, 'filter_generated_metadata' ), 20, 2 );

		// Endpoint AJAX (registrati subito: admin-ajax.php li dispatcha dopo init).
		$this->ajax->register();

		// Amministrazione.
		if ( is_admin() ) {
			$this->admin = new Idim_Admin( $this->loader, $this->cpt );
		}

		/**
		 * Azione lanciata dopo l'inizializzazione di tutti i componenti.
		 *
		 * @param Idim_Core $core Istanza del core.
		 */
		do_action( 'idim_loaded', $this );
	}

	/**
	 * Registra gli hooks accodati.
	 *
	 * @return void
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * Accesso al registro degli hooks.
	 *
	 * @return Idim_Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Accesso al motore immagini.
	 *
	 * @return Idim_Image_Editor
	 */
	public function get_editor() {
		return $this->editor;
	}

	/**
	 * Accesso al gestore CPT.
	 *
	 * @return Idim_Cpt_Manager
	 */
	public function get_cpt_manager() {
		return $this->cpt;
	}

	/**
	 * Azioni da eseguire all'attivazione.
	 *
	 * @return void
	 */
	public static function activate() {
		Idim_Settings::seed();

		$editor = new Idim_Image_Editor();

		if ( ! is_dir( $editor->get_backup_dir() ) ) {
			wp_mkdir_p( $editor->get_backup_dir() );
		}

		do_action( 'idim_activated' );
	}

	/**
	 * Azioni da eseguire alla disattivazione.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_transient( Idim_Bulk_Process::STATE_KEY );

		do_action( 'idim_deactivated' );
	}
}
