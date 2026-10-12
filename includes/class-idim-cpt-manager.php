<?php
/**
 * Rilevamento dei Custom Post Type e recupero degli allegati immagine.
 *
 * @package IncodImageDimensionManager
 */

namespace IncodImageDimensionManager\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Classe Idim_Cpt_Manager
 */
final class Idim_Cpt_Manager {

	/**
	 * MIME type immagine supportati.
	 *
	 * @var string[]
	 */
	const IMAGE_MIME = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Dimensione dei blocchi utilizzati nelle query.
	 *
	 * @var int
	 */
	const CHUNK = 500;

	/**
	 * Restituisce tutti i Custom Post Type pubblici registrati.
	 *
	 * @return \WP_Post_Type[]
	 */
	public function get_available_post_types() {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		// Gli allegati sono gestiti dall'opzione "tutta la Media Library".
		unset( $post_types['attachment'] );

		/**
		 * Filtra l'elenco dei Custom Post Type mostrati nel pannello.
		 *
		 * @param \WP_Post_Type[] $post_types Post type disponibili.
		 */
		return apply_filters( 'idim_available_post_types', $post_types );
	}

	/**
	 * Restituisce i Custom Post Type attualmente selezionati.
	 *
	 * @return string[]
	 */
	public function get_selected_post_types() {
		$selected = (array) Idim_Settings::get( 'post_types' );
		$selected = array_filter( array_map( 'sanitize_key', $selected ) );

		return array_values( $selected );
	}

	/**
	 * Indica se il plugin deve operare su tutta la Media Library.
	 *
	 * @return bool
	 */
	public function operates_on_media_library() {
		return (bool) Idim_Settings::get( 'all_media' );
	}

	/**
	 * Restituisce tutti gli ID delle immagini da elaborare.
	 *
	 * Quando "tutta la Media Library" è attivo restituisce l'intera libreria,
	 * altrimenti ricostruisce l'insieme degli allegati legati ai CPT selezionati
	 * (immagine in evidenza, allegati figli, galleria prodotti WooCommerce e
	 * immagini delle variazioni di prodotto).
	 *
	 * @return int[]
	 */
	public function get_attachment_ids() {
		if ( $this->operates_on_media_library() ) {
			return $this->query_image_attachments( array() );
		}

		$post_types = $this->get_selected_post_types();

		if ( empty( $post_types ) ) {
			return array();
		}

		$post_ids = $this->get_post_ids( $post_types );

		if ( empty( $post_ids ) ) {
			return array();
		}

		$attachment_ids = array();

		// 1. Immagini in evidenza (_thumbnail_id).
		$attachment_ids = array_merge( $attachment_ids, $this->get_meta_values( '_thumbnail_id', $post_ids ) );

		// 2. Galleria prodotti WooCommerce (_product_image_gallery: "1,2,3").
		foreach ( $this->get_meta_values( '_product_image_gallery', $post_ids ) as $gallery ) {
			foreach ( explode( ',', (string) $gallery ) as $image_id ) {
				$attachment_ids[] = absint( $image_id );
			}
		}

		// 3. Allegati figli diretti (post_parent).
		$attachment_ids = array_merge( $attachment_ids, $this->get_child_attachment_ids( $post_ids ) );

		// 4. Immagini in evidenza delle variazioni WooCommerce.
		if ( in_array( 'product', $post_types, true ) ) {
			$variation_ids = $this->get_child_post_ids( 'product_variation', $post_ids );

			if ( ! empty( $variation_ids ) ) {
				$attachment_ids = array_merge( $attachment_ids, $this->get_meta_values( '_thumbnail_id', $variation_ids ) );
			}
		}

		$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) );

		if ( empty( $attachment_ids ) ) {
			return array();
		}

		return $this->query_image_attachments( $attachment_ids );
	}

	/**
	 * Indica se un'allegato rientra nell'ambito selezionato.
	 *
	 * L'allegato è in ambito quando è figlio diretto di un post selezionato
	 * (le variazioni WooCommerce contano come prodotti) oppure quando è
	 * usato come immagine in evidenza o come galleria di un post selezionato,
	 * anche se non risulta allegato ad alcuna pagina.
	 *
	 * @param int $attachment_id ID dell'allegato.
	 * @return bool
	 */
	public function is_attachment_in_scope( $attachment_id ) {
		if ( $this->operates_on_media_library() ) {
			return true;
		}

		$selected = $this->get_selected_post_types();

		if ( empty( $selected ) ) {
			return false;
		}

		$parent_id = (int) wp_get_post_parent_id( $attachment_id );

		if ( $parent_id ) {
			$post_type = get_post_type( $parent_id );

			// Le variazioni di prodotto ereditano l'ambito del prodotto.
			if ( 'product_variation' === $post_type ) {
				$post_type = 'product';
			}

			if ( $post_type && in_array( $post_type, $selected, true ) ) {
				return true;
			}
		}

		return $this->is_referenced_by_selected_posts( $attachment_id, $selected );
	}

	/**
	 * Verifica se un allegato è usato da un post dei tipi indicati.
	 *
	 * Controlla in parallelo l'immagine in evidenza (_thumbnail_id) e la
	 * galleria prodotti WooCommerce (_product_image_gallery).
	 *
	 * @param int      $attachment_id ID dell'allegato.
	 * @param string[] $post_types    Tipi di post selezionati.
	 * @return bool
	 */
	private function is_referenced_by_selected_posts( $attachment_id, $post_types ) {
		global $wpdb;

		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || empty( $post_types ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// Immagine in evidenza di un post selezionato.
		$featured = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %d AND p.post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			array_merge( array( $attachment_id ), $post_types )
		);

		if ( $wpdb->get_var( $featured ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return true;
		}

		// Elemento della galleria prodotti WooCommerce.
		$gallery = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key = '_product_image_gallery' AND FIND_IN_SET( %d, pm.meta_value ) AND p.post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			array_merge( array( $attachment_id ), $post_types )
		);

		if ( $wpdb->get_var( $gallery ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return true;
		}

		return false;
	}

	/**
	 * Recupera gli ID dei post dei tipi indicati, processati a blocchi.
	 *
	 * @param string[] $post_types Post type da interrogare.
	 * @return int[]
	 */
	private function get_post_ids( $post_types ) {
		$ids   = array();
		$paged = 1;

		do {
			$query = new \WP_Query(
				array(
					'post_type'           => $post_types,
					'post_status'         => 'any',
					'fields'              => 'ids',
					'posts_per_page'      => self::CHUNK,
					'paged'               => $paged,
					'orderby'             => 'ID',
					'order'               => 'ASC',
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					'suppress_filters'    => false,
				)
			);

			$ids   = array_merge( $ids, array_map( 'absint', (array) $query->posts ) );
			$paged++;
			$query->reset();
		} while ( ! empty( $query->posts ) );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Recupera tutti i valori di una meta key per un elenco di post.
	 *
	 * @param string $meta_key  Chiave meta.
	 * @param int[]  $post_ids   ID dei post.
	 * @return string[]
	 */
	private function get_meta_values( $meta_key, $post_ids ) {
		global $wpdb;

		$values = array();

		foreach ( array_chunk( $post_ids, self::CHUNK ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$query        = $wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( $meta_key ), $chunk )
			);

			$found = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! empty( $found ) ) {
				$values = array_merge( $values, $found );
			}
		}

		return $values;
	}

	/**
	 * Recupera gli allegati figli diretti di un elenco di post.
	 *
	 * @param int[] $post_ids ID dei post.
	 * @return int[]
	 */
	private function get_child_attachment_ids( $post_ids ) {
		return $this->get_child_post_ids( 'attachment', $post_ids, 'inherit' );
	}

	/**
	 * Recupera gli ID dei post figli diretti di un elenco di post.
	 *
	 * @param string $post_type   Tipo di post figlio.
	 * @param int[]  $post_ids    ID dei post padre.
	 * @param string $post_status Stato dei figli (default: any).
	 * @return int[]
	 */
	private function get_child_post_ids( $post_type, $post_ids, $post_status = 'any' ) {
		$ids = array();

		foreach ( array_chunk( $post_ids, self::CHUNK ) as $chunk ) {
			$query = new \WP_Query(
				array(
					'post_type'           => $post_type,
					'post_status'         => $post_status,
					'post_parent__in'     => $chunk,
					'fields'              => 'ids',
					'posts_per_page'      => -1,
					'orderby'             => 'ID',
					'order'               => 'ASC',
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					'suppress_filters'    => false,
				)
			);

			$ids = array_merge( $ids, array_map( 'absint', (array) $query->posts ) );
			$query->reset();
		}

		return $ids;
	}

	/**
	 * Interroga la Media Library restituendo solo le immagini supportate.
	 *
	 * @param int[] $restrict_to ID fra i quali limitare la ricerca (vuoto = tutti).
	 * @return int[]
	 */
	private function query_image_attachments( $restrict_to ) {
		$args = array(
			'post_type'           => 'attachment',
			'post_status'         => 'inherit',
			'post_mime_type'      => self::IMAGE_MIME,
			'fields'              => 'ids',
			'orderby'             => 'ID',
			'order'               => 'ASC',
			'posts_per_page'      => -1,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'suppress_filters'    => false,
		);

		if ( empty( $restrict_to ) ) {
			$query = new \WP_Query( $args );
			$ids   = array_map( 'absint', (array) $query->posts );
			$query->reset();

			return array_values( array_unique( $ids ) );
		}

		$ids = array();

		foreach ( array_chunk( $restrict_to, self::CHUNK ) as $chunk ) {
			$args['post__in'] = $chunk;

			$query   = new \WP_Query( $args );
			$ids     = array_merge( $ids, array_map( 'absint', (array) $query->posts ) );
			$query->reset();
		}

		return array_values( array_unique( $ids ) );
	}
}
