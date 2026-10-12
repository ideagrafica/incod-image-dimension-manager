<?php
/**
 * Vista: pagina impostazioni.
 *
 * @package IncodImageDimensionManager
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap idim-wrap">

	<h1 class="idim-header">
		<span class="idim-header-icon"></span>
		<?php esc_html_e( 'Incod Image Dimension Manager', 'incod-image-dimension-manager' ); ?>
		<span class="idim-version"><?php echo esc_html( IDIM_VERSION ); ?></span>
	</h1>

	<div class="idim-intro">
		<p>
			<?php esc_html_e( 'Unify every image of your website to a fixed size. Choose the target dimensions, the resize mode and the images to process: the plugin handles the whole Media Library or only the images related to the post types you select.', 'incod-image-dimension-manager' ); ?>
		</p>
	</div>

	<?php settings_errors(); ?>

	<form method="post" action="options.php" class="idim-settings-form">
		<?php
		settings_fields( 'idim_settings_group' );
		do_settings_sections( 'idim-settings' );
		submit_button( __( 'Save Settings', 'incod-image-dimension-manager' ), 'primary large' );
		?>
	</form>

	<div class="idim-shortcuts">
		<a class="button button-secondary" href="<?php echo esc_url( $bulk_url ); ?>">
			<?php esc_html_e( 'Open Bulk Resize', 'incod-image-dimension-manager' ); ?>
		</a>
	</div>

</div>
