<?php
/**
 * Incod Image Dimension Manager - Disinstallazione pulita.
 *
 * Rimuove opzioni, transients e file di backup creati dal plugin.
 *
 * @package IncodImageDimensionManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// 1. Opzioni del plugin.
delete_option( 'idim_settings' );
delete_option( 'idim_bulk_state' );

// 2. Transients del plugin.
global $wpdb;

if ( isset( $wpdb->options ) ) {
	$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_idim_%' OR option_name LIKE '_transient_timeout_idim_%'"
	);
}

// 3. Cartella dei backup degli originali (uploads/idim-backups).
//    L'eliminazione resta sempre vincolata a questa cartella: i collegamenti
//    simbolici non vengono mai seguiti e nessun percorso esterno viene toccato.
if ( function_exists( 'wp_upload_dir' ) ) {
	$idim_uploads = wp_upload_dir();

	if ( empty( $idim_uploads['error'] ) && ! empty( $idim_uploads['basedir'] ) ) {
		$idim_backup_dir = $idim_uploads['basedir'] . '/idim-backups';

		if ( is_link( $idim_backup_dir ) ) {
			// La cartella backup è un link: viene rimosso solo il link.
			@unlink( $idim_backup_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		} elseif ( is_dir( $idim_backup_dir ) && false !== ( $idim_backup_root = realpath( $idim_backup_dir ) ) ) {
			$idim_remove_directory = function ( $directory ) use ( &$idim_remove_directory, $idim_backup_root ) {
				$items = scandir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions

				if ( ! $items ) {
					return;
				}

				foreach ( $items as $item ) {
					if ( '.' === $item || '..' === $item ) {
						continue;
					}

					$path = $directory . '/' . $item;

					// Un link simbolico viene staccato senza essere percorso.
					if ( is_link( $path ) ) {
						@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
						continue;
					}

					$real = realpath( $path );

					// Ignora tutto ciò che, risolto, non è più dentro la cartella backup.
					if ( false === $real || 0 !== strpos( $real, $idim_backup_root . '/' ) ) {
						continue;
					}

					if ( is_dir( $real ) ) {
						$idim_remove_directory( $real );
					} else {
						@unlink( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					}
				}

				@rmdir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			};

			$idim_remove_directory( $idim_backup_root );
		}
	}
}
