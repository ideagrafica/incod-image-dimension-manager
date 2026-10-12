<?php
/**
 * Interfaccia di amministrazione: menu, Settings API e asset.
 *
 * @package IncodImageDimensionManager
 */

namespace IncodImageDimensionManager\Admin;

use IncodImageDimensionManager\Includes\Idim_Ajax;
use IncodImageDimensionManager\Includes\Idim_Cpt_Manager;
use IncodImageDimensionManager\Includes\Idim_Loader;
use IncodImageDimensionManager\Includes\Idim_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Classe Idim_Admin
 */
final class Idim_Admin {

	/**
	 * Slug della pagina impostazioni.
	 *
	 * @var string
	 */
	const SLUG_SETTINGS = 'idim';

	/**
	 * Slug della pagina bulk.
	 *
	 * @var string
	 */
	const SLUG_BULK = 'idim-bulk';

	/**
	 * Registro degli hooks.
	 *
	 * @var Idim_Loader
	 */
	private $loader;

	/**
	 * Gestore CPT.
	 *
	 * @var Idim_Cpt_Manager
	 */
	private $cpt;

	/**
	 * Hook suffix della pagina impostazioni.
	 *
	 * @var string
	 */
	private $settings_hook = '';

	/**
	 * Hook suffix della pagina bulk.
	 *
	 * @var string
	 */
	private $bulk_hook = '';

	/**
	 * Costruttore.
	 *
	 * @param Idim_Loader       $loader Registro hooks.
	 * @param Idim_Cpt_Manager  $cpt    Gestore CPT.
	 */
	public function __construct( Idim_Loader $loader, Idim_Cpt_Manager $cpt ) {
		$this->loader = $loader;
		$this->cpt    = $cpt;

		$this->loader->add_action( 'admin_menu', array( $this, 'register_menu' ) );
		$this->loader->add_action( 'admin_init', array( $this, 'register_settings' ) );
		$this->loader->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		$this->loader->add_action( 'admin_notices', array( $this, 'render_notices' ) );
		$this->loader->add_filter( 'plugin_action_links_' . IDIM_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Registra il menu di amministrazione.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon_url = 'dashicons-image-crop';

		$this->settings_hook = add_menu_page(
			__( 'Incod Image Dimension Manager', 'incod-image-dimension-manager' ),
			__( 'Incod Image Dimensions', 'incod-image-dimension-manager' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( $this, 'render_settings_page' ),
			$icon_url,
			80
		);

		add_submenu_page(
			self::SLUG_SETTINGS,
			__( 'Settings', 'incod-image-dimension-manager' ),
			__( 'Settings', 'incod-image-dimension-manager' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( $this, 'render_settings_page' )
		);

		$this->bulk_hook = add_submenu_page(
			self::SLUG_SETTINGS,
			__( 'Bulk Resize', 'incod-image-dimension-manager' ),
			__( 'Bulk Resize', 'incod-image-dimension-manager' ),
			'manage_options',
			self::SLUG_BULK,
			array( $this, 'render_bulk_page' )
		);
	}

	/**
	 * Registra opzioni, sezioni e campi.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'idim_settings_group',
			Idim_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Idim_Settings::class, 'sanitize' ),
				'default'           => Idim_Settings::defaults(),
			)
		);

		// Sezione: dimensioni e modalità.
		add_settings_section(
			'idim_section_sizes',
			__( 'Target size and resize mode', 'incod-image-dimension-manager' ),
			array( $this, 'section_sizes_description' ),
			'idim-settings'
		);

		add_settings_field( 'target_width', __( 'Width (px)', 'incod-image-dimension-manager' ), array( $this, 'field_target_width' ), 'idim-settings', 'idim_section_sizes' );
		add_settings_field( 'target_height', __( 'Height (px)', 'incod-image-dimension-manager' ), array( $this, 'field_target_height' ), 'idim-settings', 'idim_section_sizes' );
		add_settings_field( 'mode', __( 'Resize mode', 'incod-image-dimension-manager' ), array( $this, 'field_mode' ), 'idim-settings', 'idim_section_sizes' );
		add_settings_field( 'crop_focus', __( 'Crop focal point', 'incod-image-dimension-manager' ), array( $this, 'field_crop_focus' ), 'idim-settings', 'idim_section_sizes' );
		add_settings_field( 'background_color', __( 'Canvas color', 'incod-image-dimension-manager' ), array( $this, 'field_background_color' ), 'idim-settings', 'idim_section_sizes' );
		add_settings_field( 'transparent_canvas', __( 'Transparent canvas', 'incod-image-dimension-manager' ), array( $this, 'field_transparent_canvas' ), 'idim-settings', 'idim_section_sizes' );

		// Sezione: ambito.
		add_settings_section(
			'idim_section_scope',
			__( 'Processing scope', 'incod-image-dimension-manager' ),
			array( $this, 'section_scope_description' ),
			'idim-settings'
		);

		add_settings_field( 'all_media', __( 'Images to process', 'incod-image-dimension-manager' ), array( $this, 'field_all_media' ), 'idim-settings', 'idim_section_scope' );
		add_settings_field( 'post_types', __( 'Post types', 'incod-image-dimension-manager' ), array( $this, 'field_post_types' ), 'idim-settings', 'idim_section_scope' );

		// Sezione: automazione.
		add_settings_section(
			'idim_section_automation',
			__( 'Automation and processing', 'incod-image-dimension-manager' ),
			array( $this, 'section_automation_description' ),
			'idim-settings'
		);

		add_settings_field( 'auto_resize', __( 'Auto-resize on upload', 'incod-image-dimension-manager' ), array( $this, 'field_auto_resize' ), 'idim-settings', 'idim_section_automation' );
		add_settings_field( 'skip_sized', __( 'Skip already sized images', 'incod-image-dimension-manager' ), array( $this, 'field_skip_sized' ), 'idim-settings', 'idim_section_automation' );
		add_settings_field( 'allow_upscale', __( 'Allow upscaling', 'incod-image-dimension-manager' ), array( $this, 'field_allow_upscale' ), 'idim-settings', 'idim_section_automation' );
		add_settings_field( 'backup_originals', __( 'Keep a backup of the originals', 'incod-image-dimension-manager' ), array( $this, 'field_backup_originals' ), 'idim-settings', 'idim_section_automation' );
		add_settings_field( 'batch_size', __( 'Batch size', 'incod-image-dimension-manager' ), array( $this, 'field_batch_size' ), 'idim-settings', 'idim_section_automation' );
		add_settings_field( 'jpeg_quality', __( 'JPEG quality', 'incod-image-dimension-manager' ), array( $this, 'field_jpeg_quality' ), 'idim-settings', 'idim_section_automation' );
	}

	/**
	 * Descrizione della sezione dimensioni.
	 *
	 * @return void
	 */
	public function section_sizes_description() {
		echo '<p>' . esc_html__( 'All images are unified to the exact target dimensions. Letterbox pads the empty space with a colored or transparent canvas, Hard Crop cuts the image on the focal point, Proportional Scale only resizes without adding canvas or cutting.', 'incod-image-dimension-manager' ) . '</p>';
	}

	/**
	 * Descrizione della sezione ambito.
	 *
	 * @return void
	 */
	public function section_scope_description() {
		echo '<p>' . esc_html__( 'Choose whether to process the entire Media Library or only the images related to the selected post types: featured images, attached files, WooCommerce product galleries and product variations.', 'incod-image-dimension-manager' ) . '</p>';
	}

	/**
	 * Descrizione della sezione automazione.
	 *
	 * @return void
	 */
	public function section_automation_description() {
		echo '<p>' . esc_html__( 'These options control new uploads, the bulk queue and the safety of your original files.', 'incod-image-dimension-manager' ) . '</p>';
	}

	/**
	 * Campo: larghezza target.
	 *
	 * @return void
	 */
	public function field_target_width() {
		printf(
			'<input type="number" min="1" max="10000" step="1" class="small-text" id="target_width" name="%s" value="%d" />',
			esc_attr( Idim_Settings::OPTION . '[target_width]' ),
			(int) Idim_Settings::get( 'target_width' )
		);
	}

	/**
	 * Campo: altezza target.
	 *
	 * @return void
	 */
	public function field_target_height() {
		printf(
			'<input type="number" min="1" max="10000" step="1" class="small-text" id="target_height" name="%s" value="%d" />',
			esc_attr( Idim_Settings::OPTION . '[target_height]' ),
			(int) Idim_Settings::get( 'target_height' )
		);
	}

	/**
	 * Campo: modalità di ridimensionamento.
	 *
	 * @return void
	 */
	public function field_mode() {
		$current = (string) Idim_Settings::get( 'mode' );

		$modes = array(
			'letterbox' => __( 'Letterbox (colored canvas)', 'incod-image-dimension-manager' ),
			'crop'      => __( 'Hard Crop (cut on focal point)', 'incod-image-dimension-manager' ),
			'scale'     => __( 'Proportional Scale (no canvas, no cut)', 'incod-image-dimension-manager' ),
		);

		foreach ( $modes as $value => $label ) {
			printf(
				'<label class="idim-radio"><input type="radio" name="%s" value="%s" %s /> %s</label>',
				esc_attr( Idim_Settings::OPTION . '[mode]' ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Campo: punto focale.
	 *
	 * @return void
	 */
	public function field_crop_focus() {
		$current = (string) Idim_Settings::get( 'crop_focus' );

		$focus = array(
			'center' => __( 'Center', 'incod-image-dimension-manager' ),
			'top'    => __( 'Top', 'incod-image-dimension-manager' ),
			'bottom' => __( 'Bottom', 'incod-image-dimension-manager' ),
			'left'   => __( 'Left', 'incod-image-dimension-manager' ),
			'right'  => __( 'Right', 'incod-image-dimension-manager' ),
		);

		echo '<select id="crop_focus" name="' . esc_attr( Idim_Settings::OPTION . '[crop_focus]' ) . '">';

		foreach ( $focus as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Only used by the Hard Crop mode.', 'incod-image-dimension-manager' ) . '</p>';
	}

	/**
	 * Campo: colore del canvas.
	 *
	 * @return void
	 */
	public function field_background_color() {
		printf(
			'<input type="text" class="idim-color-picker" id="background_color" name="%s" value="%s" data-default-color="#ffffff" />',
			esc_attr( Idim_Settings::OPTION . '[background_color]' ),
			esc_attr( (string) Idim_Settings::get( 'background_color' ) )
		);
		echo '<p class="description">' . esc_html__( 'Used by the Letterbox mode. Ignored when the canvas is transparent.', 'incod-image-dimension-manager' ) . '</p>';
	}

	/**
	 * Campo: canvas trasparente.
	 *
	 * @return void
	 */
	public function field_transparent_canvas() {
		$checked = (bool) Idim_Settings::get( 'transparent_canvas' );

		printf(
			'<label class="idim-checkbox"><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( Idim_Settings::OPTION . '[transparent_canvas]' ),
			checked( $checked, true, false ),
			esc_html__( 'Use a transparent canvas (PNG and WebP only)', 'incod-image-dimension-manager' )
		);
		echo '<p class="description">' . esc_html__( 'JPEG images fall back to the canvas color, because JPEG does not support transparency.', 'incod-image-dimension-manager' ) . '</p>';
	}

	/**
	 * Campo: scelta fra Media Library intera e soli tipi selezionati.
	 *
	 * @return void
	 */
	public function field_all_media() {
		$value = Idim_Settings::get( 'all_media' ) ? '1' : '0';

		printf(
			'<label class="idim-radio idim-scope-radio"><input type="radio" name="%s" value="1" %s /> %s</label>',
			esc_attr( Idim_Settings::OPTION . '[all_media]' ),
			checked( $value, '1', false ),
			esc_html__( 'Whole Media Library (every image, regardless of the post types below)', 'incod-image-dimension-manager' )
		);

		printf(
			'<label class="idim-radio idim-scope-radio"><input type="radio" name="%s" value="0" %s /> %s</label>',
			esc_attr( Idim_Settings::OPTION . '[all_media]' ),
			checked( $value, '0', false ),
			esc_html__( 'Only images used by the selected post types', 'incod-image-dimension-manager' )
		);
	}

	/**
	 * Campo: selezione multipla dei Custom Post Type.
	 *
	 * @return void
	 */
	public function field_post_types() {
		$post_types = $this->cpt->get_available_post_types();
		$selected   = array_map( 'sanitize_key', (array) Idim_Settings::get( 'post_types' ) );

		if ( empty( $post_types ) ) {
			echo '<p class="description">' . esc_html__( 'No public post type detected.', 'incod-image-dimension-manager' ) . '</p>';

			return;
		}

		echo '<fieldset class="idim-checkbox-group" id="idim-post-types-field">';

		foreach ( $post_types as $post_type ) {
			printf(
				'<label class="idim-checkbox"><input type="checkbox" name="%s[]" value="%s" %s /> <span class="idim-pt-label">%s</span> <code>%s</code></label>',
				esc_attr( Idim_Settings::OPTION . '[post_types]' ),
				esc_attr( $post_type->name ),
				checked( in_array( $post_type->name, $selected, true ), true, false ),
				esc_html( $post_type->labels->name ),
				esc_html( $post_type->name )
			);
		}

		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Used only when images are limited to the selected post types. Includes featured images, attached files, WooCommerce product galleries and product variations.', 'incod-image-dimension-manager' ) . '</p>';
	}

	/**
	 * Campo: auto-resize al caricamento.
	 *
	 * @return void
	 */
	public function field_auto_resize() {
		$checked = (bool) Idim_Settings::get( 'auto_resize' );

		printf(
			'<label class="idim-checkbox"><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( Idim_Settings::OPTION . '[auto_resize]' ),
			checked( $checked, true, false ),
			esc_html__( 'Automatically resize every newly uploaded image', 'incod-image-dimension-manager' )
		);
	}

	/**
	 * Campo: salta immagini già conformi.
	 *
	 * @return void
	 */
	public function field_skip_sized() {
		$checked = (bool) Idim_Settings::get( 'skip_sized' );

		printf(
			'<label class="idim-checkbox"><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( Idim_Settings::OPTION . '[skip_sized]' ),
			checked( $checked, true, false ),
			esc_html__( 'Skip images that already match the target size', 'incod-image-dimension-manager' )
		);
	}

	/**
	 * Campo: consenti ingrandimento.
	 *
	 * @return void
	 */
	public function field_allow_upscale() {
		$checked = (bool) Idim_Settings::get( 'allow_upscale' );

		printf(
			'<label class="idim-checkbox"><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( Idim_Settings::OPTION . '[allow_upscale]' ),
			checked( $checked, true, false ),
			esc_html__( 'Enlarge images smaller than the target size', 'incod-image-dimension-manager' )
		);
	}

	/**
	 * Campo: backup originali.
	 *
	 * @return void
	 */
	public function field_backup_originals() {
		$checked = (bool) Idim_Settings::get( 'backup_originals' );

		printf(
			'<label class="idim-checkbox"><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( Idim_Settings::OPTION . '[backup_originals]' ),
			checked( $checked, true, false ),
			esc_html__( 'Back up original files before overwriting them', 'incod-image-dimension-manager' )
		);
		echo '<p class="description">' . esc_html__( 'Backups are stored inside the uploads folder (idim-backups) and can be restored from the Bulk Resize screen.', 'incod-image-dimension-manager' ) . '</p>';
	}

	/**
	 * Campo: dimensione del batch.
	 *
	 * @return void
	 */
	public function field_batch_size() {
		printf(
			'<input type="number" min="1" max="50" step="1" class="small-text" id="batch_size" name="%s" value="%d" />',
			esc_attr( Idim_Settings::OPTION . '[batch_size]' ),
			(int) Idim_Settings::get( 'batch_size' )
		);
		echo ' <span class="description">' . esc_html__( 'Images sent to the server on every AJAX request (1-50).', 'incod-image-dimension-manager' ) . '</span>';
	}

	/**
	 * Campo: qualità JPEG.
	 *
	 * @return void
	 */
	public function field_jpeg_quality() {
		printf(
			'<input type="number" min="1" max="100" step="1" class="small-text" id="jpeg_quality" name="%s" value="%d" />',
			esc_attr( Idim_Settings::OPTION . '[jpeg_quality]' ),
			(int) Idim_Settings::get( 'jpeg_quality' )
		);
	}

	/**
	 * Enqueue degli asset nelle pagine del plugin.
	 *
	 * @param string $hook Hook suffix della pagina corrente.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( $this->settings_hook, $this->bulk_hook ), true ) ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style(
			'idim-admin',
			IDIM_PLUGIN_URL . 'admin/css/idim-admin.css',
			array( 'wp-color-picker' ),
			IDIM_VERSION
		);

		wp_enqueue_script(
			'idim-admin',
			IDIM_PLUGIN_URL . 'admin/js/idim-admin.js',
			array( 'jquery' ),
			IDIM_VERSION,
			true
		);

		$settings = Idim_Settings::all();

		wp_localize_script(
			'idim-admin',
			'idimAdmin',
			array(
				'ajaxUrl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'nonce'   => wp_create_nonce( Idim_Ajax::NONCE_ACTION ),
				'page'    => $hook === $this->bulk_hook ? 'bulk' : 'settings',
				'options' => array(
					'batchSize'    => max( 1, min( Idim_Ajax::MAX_PER_REQUEST, (int) $settings['batch_size'] ) ),
					'targetWidth'  => (int) $settings['target_width'],
					'targetHeight' => (int) $settings['target_height'],
					'mode'         => (string) $settings['mode'],
					'backup'       => (bool) $settings['backup_originals'],
					'timeBudget'   => Idim_Ajax::TIME_BUDGET,
					'ajaxTimeout'  => Idim_Ajax::AJAX_TIMEOUT,
				),
				'strings'  => array(
					'idle'            => __( 'Idle', 'incod-image-dimension-manager' ),
					'queueTitle'      => __( 'Preparing the queue…', 'incod-image-dimension-manager' ),
					'runningTitle'    => __( 'Processing images…', 'incod-image-dimension-manager' ),
					'pausedTitle'     => __( 'Paused', 'incod-image-dimension-manager' ),
					'doneTitle'       => __( 'Completed', 'incod-image-dimension-manager' ),
					'cancelledTitle'  => __( 'Cancelled', 'incod-image-dimension-manager' ),
					'emptyQueue'      => __( 'No images to process with the current selection.', 'incod-image-dimension-manager' ),
					'processed'       => __( 'Processed', 'incod-image-dimension-manager' ),
					'skipped'         => __( 'Skipped', 'incod-image-dimension-manager' ),
					'failed'          => __( 'Errors', 'incod-image-dimension-manager' ),
					'remaining'       => __( 'Remaining', 'incod-image-dimension-manager' ),
					'elapsed'         => __( 'Elapsed', 'incod-image-dimension-manager' ),
					'estimated'       => __( 'Estimated remaining', 'incod-image-dimension-manager' ),
					'errorsLog'       => __( 'Error log', 'incod-image-dimension-manager' ),
					'noErrors'        => __( 'No errors detected.', 'incod-image-dimension-manager' ),
					'startLabel'      => __( 'Start bulk resize', 'incod-image-dimension-manager' ),
					'pauseLabel'      => __( 'Pause', 'incod-image-dimension-manager' ),
					'resumeLabel'     => __( 'Resume', 'incod-image-dimension-manager' ),
					'cancelLabel'     => __( 'Cancel', 'incod-image-dimension-manager' ),
					'restoreLabel'    => __( 'Restore originals', 'incod-image-dimension-manager' ),
					// translators: %d: number of restored images.
					'restoreDone'     => __( '%d images restored.', 'incod-image-dimension-manager' ),
					'busy'            => __( 'The server is busy. Waiting…', 'incod-image-dimension-manager' ),
					'restoreConfirm'  => __( 'Restore every backed-up original file? The resized versions will be replaced.', 'incod-image-dimension-manager' ),
					'cancelConfirm'   => __( 'Cancel the bulk process? The queue will be discarded.', 'incod-image-dimension-manager' ),
					'requestError'    => __( 'The request failed. Retrying…', 'incod-image-dimension-manager' ),
					'serverError'     => __( 'Server error.', 'incod-image-dimension-manager' ),
					'scopeLabel'      => __( 'Scope', 'incod-image-dimension-manager' ),
					'targetLabel'     => __( 'Target', 'incod-image-dimension-manager' ),
					'backupsDisabled' => __( 'Original backups are disabled in the settings.', 'incod-image-dimension-manager' ),
					'seconds'         => __( 's', 'incod-image-dimension-manager' ),
				),
			)
		);
	}

	/**
	 * Avvisi di amministrazione.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) ) ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Incod Image Dimension Manager cannot work: neither Imagick nor GD is available on this server.', 'incod-image-dimension-manager' );
			echo '</p></div>';
		}
	}

	/**
	 * Link rapidi nella lista dei plugin.
	 *
	 * @param string[] $links Link esistenti.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$settings = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS ) ) . '">' .
			esc_html__( 'Settings', 'incod-image-dimension-manager' ) . '</a>',
		);

		return array_merge( $settings, (array) $links );
	}

	/**
	 * Renderizza la pagina impostazioni.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'incod-image-dimension-manager' ) );
		}

		$bulk_url = admin_url( 'admin.php?page=' . self::SLUG_BULK );

		include IDIM_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Renderizza la pagina di elaborazione di massa.
	 *
	 * @return void
	 */
	public function render_bulk_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'incod-image-dimension-manager' ) );
		}

		$settings = Idim_Settings::all();
		$mode     = isset( $settings['mode'] ) ? $settings['mode'] : 'letterbox';

		$mode_labels = array(
			'letterbox' => __( 'Letterbox', 'incod-image-dimension-manager' ),
			'crop'      => __( 'Hard Crop', 'incod-image-dimension-manager' ),
			'scale'     => __( 'Proportional Scale', 'incod-image-dimension-manager' ),
		);

		$mode_label = isset( $mode_labels[ $mode ] ) ? $mode_labels[ $mode ] : $mode;

		$settings_url = admin_url( 'admin.php?page=' . self::SLUG_SETTINGS );

		include IDIM_PLUGIN_DIR . 'admin/views/bulk-page.php';
	}
}
