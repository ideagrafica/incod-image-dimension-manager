<?php
/**
 * Vista: pagina di elaborazione di massa.
 *
 * @package IncodImageDimensionManager
 */

defined( 'ABSPATH' ) || exit;

$idim_target_size = (int) $settings['target_width'] . ' × ' . (int) $settings['target_height'];
$idim_scope       = ! empty( $settings['all_media'] )
	? __( 'Whole Media Library', 'incod-image-dimension-manager' )
	: implode( ', ', (array) $settings['post_types'] );

// Avviso: Regenerate Thumbnails per visualizzare correttamente le immagini modificate.
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$idim_rt_plugin    = 'regenerate-thumbnails/regenerate-thumbnails.php';
$idim_rt_active    = function_exists( 'is_plugin_active' ) && is_plugin_active( $idim_rt_plugin );
$idim_rt_installed = file_exists( WP_PLUGIN_DIR . '/' . $idim_rt_plugin );
$idim_rt_url       = 'https://wordpress.org/plugins/regenerate-thumbnails/';
?>
<div class="wrap idim-wrap idim-bulk-wrap">

	<h1 class="idim-header">
		<span class="idim-header-icon"></span>
		<?php esc_html_e( 'Bulk Resize', 'incod-image-dimension-manager' ); ?>
		<span class="idim-version"><?php echo esc_html( IDIM_VERSION ); ?></span>
	</h1>

	<div class="idim-intro">
		<p>
			<?php esc_html_e( 'Resize the whole queue in batches. The process runs asynchronously: you can pause, resume or cancel it at any time, without any limit on the number of images.', 'incod-image-dimension-manager' ); ?>
		</p>
	</div>

	<?php if ( ! $idim_rt_active ) : ?>
	<div class="notice notice-info idim-rt-notice">
		<p>
			<strong><?php esc_html_e( 'Recommended: Regenerate Thumbnails', 'incod-image-dimension-manager' ); ?></strong>
		</p>
		<p>
			<?php esc_html_e( 'After the bulk resize, install Regenerate Thumbnails to rebuild every intermediate image size, so the modified images are displayed correctly across the whole site.', 'incod-image-dimension-manager' ); ?>
		</p>
		<p>
			<?php if ( ! $idim_rt_installed && current_user_can( 'install_plugins' ) ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=regenerate-thumbnails' ), 'install-plugin_regenerate-thumbnails' ) ); ?>">
					<?php esc_html_e( 'Install now', 'incod-image-dimension-manager' ); ?>
				</a>
			<?php elseif ( $idim_rt_installed && current_user_can( 'activate_plugins' ) ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $idim_rt_plugin ) ), 'activate-plugin_' . $idim_rt_plugin ) ); ?>">
					<?php esc_html_e( 'Activate', 'incod-image-dimension-manager' ); ?>
				</a>
			<?php endif; ?>
			<a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $idim_rt_url ); ?>">
				<?php esc_html_e( 'Download & view details', 'incod-image-dimension-manager' ); ?>
			</a>
		</p>
	</div>
	<?php endif; ?>

	<div class="idim-cards">
		<div class="idim-card">
			<span class="idim-card-label"><?php esc_html_e( 'Scope', 'incod-image-dimension-manager' ); ?></span>
			<span class="idim-card-value"><?php echo esc_html( $idim_scope ? $idim_scope : __( 'Nothing selected', 'incod-image-dimension-manager' ) ); ?></span>
		</div>
		<div class="idim-card">
			<span class="idim-card-label"><?php esc_html_e( 'Target', 'incod-image-dimension-manager' ); ?></span>
			<span class="idim-card-value"><?php echo esc_html( $idim_target_size ); ?> px</span>
		</div>
		<div class="idim-card">
			<span class="idim-card-label"><?php esc_html_e( 'Mode', 'incod-image-dimension-manager' ); ?></span>
			<span class="idim-card-value"><?php echo esc_html( $mode_label ); ?></span>
		</div>
		<div class="idim-card">
			<span class="idim-card-label"><?php esc_html_e( 'Batch size', 'incod-image-dimension-manager' ); ?></span>
			<span class="idim-card-value"><?php echo esc_html( (string) (int) $settings['batch_size'] ); ?></span>
		</div>
	</div>

	<div class="idim-panel" id="idim-panel">

		<div class="idim-progress-header">
			<div class="idim-status" id="idim-status"><?php esc_html_e( 'Idle', 'incod-image-dimension-manager' ); ?></div>
			<div class="idim-percentage" id="idim-percentage">0%</div>
		</div>

		<div class="idim-progress-track">
			<div class="idim-progress-bar" id="idim-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
		</div>

		<div class="idim-stats">
			<div class="idim-stat">
				<span class="idim-stat-label"><?php esc_html_e( 'Processed', 'incod-image-dimension-manager' ); ?></span>
				<span class="idim-stat-value" id="idim-count-processed">0</span>
			</div>
			<div class="idim-stat">
				<span class="idim-stat-label"><?php esc_html_e( 'Skipped', 'incod-image-dimension-manager' ); ?></span>
				<span class="idim-stat-value" id="idim-count-skipped">0</span>
			</div>
			<div class="idim-stat">
				<span class="idim-stat-label"><?php esc_html_e( 'Errors', 'incod-image-dimension-manager' ); ?></span>
				<span class="idim-stat-value" id="idim-count-failed">0</span>
			</div>
			<div class="idim-stat">
				<span class="idim-stat-label"><?php esc_html_e( 'Total', 'incod-image-dimension-manager' ); ?></span>
				<span class="idim-stat-value" id="idim-count-total">0</span>
			</div>
			<div class="idim-stat">
				<span class="idim-stat-label"><?php esc_html_e( 'Elapsed', 'incod-image-dimension-manager' ); ?></span>
				<span class="idim-stat-value" id="idim-elapsed">0s</span>
			</div>
			<div class="idim-stat">
				<span class="idim-stat-label"><?php esc_html_e( 'Estimated remaining', 'incod-image-dimension-manager' ); ?></span>
				<span class="idim-stat-value" id="idim-eta">—</span>
			</div>
		</div>

		<div class="idim-controls">
			<button type="button" class="button button-primary button-hero" id="idim-start">
				<?php esc_html_e( 'Start bulk resize', 'incod-image-dimension-manager' ); ?>
			</button>
			<button type="button" class="button button-secondary button-hero" id="idim-pause" disabled>
				<?php esc_html_e( 'Pause', 'incod-image-dimension-manager' ); ?>
			</button>
			<button type="button" class="button button-secondary button-hero" id="idim-cancel" disabled>
				<?php esc_html_e( 'Cancel', 'incod-image-dimension-manager' ); ?>
			</button>
			<button type="button" class="button button-link-delete" id="idim-restore">
				<?php esc_html_e( 'Restore originals', 'incod-image-dimension-manager' ); ?>
			</button>
		</div>

		<div class="idim-log-wrap">
			<h3><?php esc_html_e( 'Error log', 'incod-image-dimension-manager' ); ?></h3>
			<ul class="idim-log" id="idim-log">
				<li class="idim-log-empty"><?php esc_html_e( 'No errors detected.', 'incod-image-dimension-manager' ); ?></li>
			</ul>
		</div>

	</div>

	<p class="description idim-footnote">
		<?php esc_html_e( 'The queue is processed a few images at a time to prevent PHP timeouts. You can close this page and come back later: the counters are stored on the server.', 'incod-image-dimension-manager' ); ?>
	</p>

	<div class="idim-shortcuts">
		<a class="button button-secondary" href="<?php echo esc_url( $settings_url ); ?>">
			<?php esc_html_e( 'Settings', 'incod-image-dimension-manager' ); ?>
		</a>
	</div>

</div>
