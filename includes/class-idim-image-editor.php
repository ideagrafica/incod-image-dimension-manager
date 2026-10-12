<?php
/**
 * Motore di manipolazione delle immagini.
 *
 * Supporta Letterbox (canvas colorato o trasparente), Hard Crop con punto
 * focale e Proportional Scale. Utilizza wp_get_image_editor() (Imagick o GD)
 * per il ridimensionamento e compone il canvas con Imagick o GD.
 *
 * @package IncodImageDimensionManager
 */

namespace IncodImageDimensionManager\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Classe Idim_Image_Editor
 */
final class Idim_Image_Editor {

	/**
	 * MIME type elaborabili.
	 *
	 * @var string[]
	 */
	const ALLOWED_MIME = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Profondità del guard contro le chiamate ricorsive.
	 *
	 * @var int
	 */
	private static $guard = 0;

	/**
	 * Gestore dei Custom Post Type.
	 *
	 * @var Idim_Cpt_Manager
	 */
	private $cpt;

	/**
	 * Costruttore.
	 */
	public function __construct() {
		$this->cpt = new Idim_Cpt_Manager();
	}

	/**
	 * Indica se il plugin sta attualmente elaborando una meta data.
	 *
	 * @return bool
	 */
	public static function is_guarded() {
		return self::$guard > 0;
	}

	/**
	 * Entra nella sezione protetta da ricorsioni.
	 *
	 * @return void
	 */
	private static function enter_guard() {
		self::$guard++;
	}

	/**
	 * Esce dalla sezione protetta da ricorsioni.
	 *
	 * @return void
	 */
	private static function leave_guard() {
		self::$guard = max( 0, self::$guard - 1 );
	}

	/**
	 * Hook: rigenera i metadata dopo la generazione nativa di WordPress.
	 *
	 * @param array $metadata      Metadati generati da WordPress.
	 * @param int   $attachment_id ID dell'allegato.
	 * @return array
	 */
	public function filter_generated_metadata( $metadata, $attachment_id ) {
		if ( self::is_guarded() ) {
			return $metadata;
		}

		if ( ! $this->should_auto_resize( $attachment_id ) ) {
			return $metadata;
		}

		$result = $this->process_attachment( $attachment_id );

		if ( is_wp_error( $result ) || 'skipped' === $result ) {
			return $metadata;
		}

		$fresh = wp_get_attachment_metadata( $attachment_id );

		return is_array( $fresh ) && ! empty( $fresh['width'] ) ? $fresh : $metadata;
	}

	/**
	 * Determina se l'allegato deve essere ridimensionato al caricamento.
	 *
	 * @param int $attachment_id ID dell'allegato.
	 * @return bool
	 */
	public function should_auto_resize( $attachment_id ) {
		if ( ! Idim_Settings::get( 'auto_resize' ) ) {
			return false;
		}

		return $this->is_supported_image( $attachment_id )
			&& $this->cpt->is_attachment_in_scope( $attachment_id );
	}

	/**
	 * Elabora un allegato completo: backup, ridimensionamento, metadata.
	 *
	 * @param int        $attachment_id ID dell'allegato.
	 * @param array|null $settings      Impostazioni opzionali (usa quelle salvate).
	 * @return true|string|\WP_Error True se elaborato, 'skipped' se omesso.
	 */
	public function process_attachment( $attachment_id, $settings = null ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return new \WP_Error( 'idim_invalid_attachment', __( 'Invalid attachment ID.', 'incod-image-dimension-manager' ) );
		}

		if ( self::is_guarded() ) {
			return 'skipped';
		}

		$settings = is_array( $settings )
			? wp_parse_args( $settings, Idim_Settings::defaults() )
			: Idim_Settings::all();

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! is_file( $file ) ) {
			return new \WP_Error(
				'idim_missing_file',
				__( 'The original file could not be found.', 'incod-image-dimension-manager' )
			);
		}

		$mime = get_post_mime_type( $attachment_id );

		if ( ! in_array( $mime, self::ALLOWED_MIME, true ) ) {
			return new \WP_Error(
				'idim_unsupported_mime',
				sprintf(
					/* translators: %s: MIME type. */
					__( 'Unsupported media type: %s.', 'incod-image-dimension-manager' ),
					$mime ? $mime : '-'
				)
			);
		}

		$dimensions = $this->get_image_dimensions( $attachment_id, $file );

		if ( $dimensions && $this->is_already_correct( $dimensions[0], $dimensions[1], $settings ) ) {
			return 'skipped';
		}

		if ( ! empty( $settings['backup_originals'] ) ) {
			$this->backup_file( $attachment_id, $file );
		}

		self::enter_guard();

		try {
			$result = $this->process_file( $file, $settings );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$this->regenerate_metadata( $attachment_id, $file );
		} finally {
			self::leave_guard();
		}

		return true;
	}

	/**
	 * Elabora un singolo file immagine su disco.
	 *
	 * @param string $file     Percorso assoluto del file.
	 * @param array  $settings Impostazioni.
	 * @return true|\WP_Error
	 */
	public function process_file( $file, $settings ) {
		$editor = wp_get_image_editor( $file );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		if ( method_exists( $editor, 'set_quality' ) ) {
			$editor->set_quality( (int) $settings['jpeg_quality'] );
		}

		$size = $editor->get_size();

		if ( is_wp_error( $size ) || empty( $size['width'] ) || empty( $size['height'] ) ) {
			return new \WP_Error( 'idim_invalid_dimensions', __( 'Unable to read the image dimensions.', 'incod-image-dimension-manager' ) );
		}

		$geometry = $this->compute_geometry(
			(int) $size['width'],
			(int) $size['height'],
			(int) $settings['target_width'],
			(int) $settings['target_height'],
			(string) $settings['mode'],
			(string) $settings['crop_focus'],
			! empty( $settings['allow_upscale'] )
		);

		if ( 'crop' === $settings['mode'] ) {
			$resized = $editor->crop(
				$geometry['crop_x'],
				$geometry['crop_y'],
				$geometry['crop_w'],
				$geometry['crop_h'],
				$geometry['out_w'],
				$geometry['out_h']
			);

			if ( is_wp_error( $resized ) ) {
				return $resized;
			}

			return $this->save_editor( $editor, $file );
		}

		$target_w = 'scale' === $settings['mode'] ? $geometry['out_w'] : $geometry['fit_w'];
		$target_h = 'scale' === $settings['mode'] ? $geometry['out_h'] : $geometry['fit_h'];

		$source_w = (int) $size['width'];
		$source_h = (int) $size['height'];

		// Niente da fare: le dimensioni calcolate coincidono con la sorgente
		// (o ne distano entro 1px). image_resize_dimensions() rifiuta in questi
		// casi e genererebbe l'errore "Could not calculate resized image
		// dimensions": il resize viene quindi saltato del tutto.
		if ( abs( $target_w - $source_w ) <= 1 && abs( $target_h - $source_h ) <= 1 ) {
			if ( 'scale' === $settings['mode'] ) {
				return true;
			}

			return $this->apply_letterbox( $editor, $file, $settings );
		}

		// Ingrandimento oltre la sorgente: il core si rifiuta di scalare,
		// quindi si usa un ridimensionamento diretto (Imagick o GD).
		if ( $target_w > $source_w && $target_h > $source_h ) {
			return $this->upscale_file( $file, $geometry, $settings );
		}

		$resized = $editor->resize( $target_w, $target_h, false );

		if ( is_wp_error( $resized ) ) {
			return $resized;
		}

		if ( 'scale' === $settings['mode'] ) {
			return $this->save_editor( $editor, $file );
		}

		return $this->apply_letterbox( $editor, $file, $settings );
	}

	/**
	 * Esegue un ingrandimento diretto bypassando i limiti del core.
	 *
	 * image_resize_dimensions() si rifiuta di scalare oltre la sorgente:
	 * il file viene ridimensionato su un temporaneo (Imagick o GD) e poi
	 * riscritto. In Letterbox viene composto anche il canvas target.
	 *
	 * @param string $file     Percorso del file.
	 * @param array  $geometry Geometria calcolata.
	 * @param array  $settings Impostazioni.
	 * @return true|\WP_Error
	 */
	private function upscale_file( $file, $geometry, $settings ) {
		$is_scale = 'scale' === $settings['mode'];
		$resize_w = $is_scale ? (int) $geometry['out_w'] : (int) $geometry['fit_w'];
		$resize_h = $is_scale ? (int) $geometry['out_h'] : (int) $geometry['fit_h'];
		$temp     = $this->create_temp_path( $file );

		if ( ! $temp ) {
			return new \WP_Error( 'idim_temp_failed', __( 'Unable to create a temporary file.', 'incod-image-dimension-manager' ) );
		}

		try {
			$scaled = $this->scale_file( $file, $temp, $resize_w, $resize_h, (int) $settings['jpeg_quality'] );

			if ( is_wp_error( $scaled ) ) {
				return $scaled;
			}

			if ( $is_scale ) {
				if ( ! copy( $temp, $file ) ) {
					return new \WP_Error( 'idim_upscale_copy_failed', __( 'The resized image could not replace the original file.', 'incod-image-dimension-manager' ) );
				}

				return true;
			}

			return $this->composite_canvas(
				$temp,
				$file,
				(int) $settings['target_width'],
				(int) $settings['target_height'],
				$settings
			);
		} finally {
			if ( is_file( $temp ) ) {
				wp_delete_file( $temp );
			}
		}
	}

	/**
	 * Applica il canvas Letterbox attorno all'immagine ridimensionata.
	 *
	 * @param \WP_Image_Editor $editor   Editor con l'immagine già ridimensionata.
	 * @param string           $file     Percorso di destinazione.
	 * @param array            $settings Impostazioni.
	 * @return true|\WP_Error
	 */
	private function apply_letterbox( $editor, $file, $settings ) {
		$temp = $this->create_temp_path( $file );

		if ( ! $temp ) {
			return new \WP_Error( 'idim_temp_failed', __( 'Unable to create a temporary file.', 'incod-image-dimension-manager' ) );
		}

		try {
			$saved = $editor->save( $temp );

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$source = isset( $saved['path'] ) ? $saved['path'] : $temp;

			return $this->composite_canvas(
				$source,
				$file,
				(int) $settings['target_width'],
				(int) $settings['target_height'],
				$settings
			);
		} finally {
			if ( is_file( $temp ) ) {
				wp_delete_file( $temp );
			}

			if ( isset( $saved['path'] ) && ! empty( $saved['path'] ) && is_file( $saved['path'] ) ) {
				wp_delete_file( $saved['path'] );
			}
		}
	}

	/**
	 * Salva l'editor sul file di destinazione.
	 *
	 * @param \WP_Image_Editor $editor Editor.
	 * @param string           $file   Percorso di destinazione.
	 * @return true|\WP_Error
	 */
	private function save_editor( $editor, $file ) {
		$saved = $editor->save( $file );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( empty( $saved['path'] ) ) {
			return new \WP_Error( 'idim_save_failed', __( 'The image could not be saved.', 'incod-image-dimension-manager' ) );
		}

		// Sicurezza: riporta il risultato sul file originale se WP ha scritto altrove.
		if ( $saved['path'] !== $file ) {
			if ( ! copy( $saved['path'], $file ) ) {
				return new \WP_Error( 'idim_save_move_failed', __( 'The resized image could not replace the original file.', 'incod-image-dimension-manager' ) );
			}

			wp_delete_file( $saved['path'] );
		}

		return true;
	}

	/**
	 * Calcola la geometria di lavorazione in base alla modalità scelta.
	 *
	 * @param int    $src_w        Larghezza sorgente.
	 * @param int    $src_h        Altezza sorgente.
	 * @param int    $target_w     Larghezza target.
	 * @param int    $target_h     Altezza target.
	 * @param string $mode         Modalità (letterbox|crop|scale).
	 * @param string $focus        Punto focale per l'hard crop.
	 * @param bool   $allow_upscale Consenti ingrandimento.
	 * @return array
	 */
	public function compute_geometry( $src_w, $src_h, $target_w, $target_h, $mode, $focus, $allow_upscale = false ) {
		$src_w     = max( 1, (int) $src_w );
		$src_h     = max( 1, (int) $src_h );
		$target_w  = max( 1, (int) $target_w );
		$target_h  = max( 1, (int) $target_h );
		$geometry  = array(
			'fit_w'   => $src_w,
			'fit_h'   => $src_h,
			'out_w'   => $src_w,
			'out_h'   => $src_h,
			'offset_x' => 0,
			'offset_y' => 0,
			'crop_x'  => 0,
			'crop_y'  => 0,
			'crop_w'  => $src_w,
			'crop_h'  => $src_h,
		);

		if ( 'crop' === $mode ) {
			$src_ratio = $src_w / $src_h;
			$dst_ratio = $target_w / $target_h;

			if ( $src_ratio > $dst_ratio ) {
				$crop_h = $src_h;
				$crop_w = (int) round( $src_h * $dst_ratio );
			} else {
				$crop_w = $src_w;
				$crop_h = (int) round( $src_w / $dst_ratio );
			}

			$crop_w = max( 1, min( $crop_w, $src_w ) );
			$crop_h = max( 1, min( $crop_h, $src_h ) );

			$crop_x = (int) floor( ( $src_w - $crop_w ) / 2 );
			$crop_y = (int) floor( ( $src_h - $crop_h ) / 2 );

			switch ( $focus ) {
				case 'left':
					$crop_x = 0;
					break;
				case 'right':
					$crop_x = $src_w - $crop_w;
					break;
				case 'top':
					$crop_y = 0;
					break;
				case 'bottom':
					$crop_y = $src_h - $crop_h;
					break;
				default:
					// Center: valori già calcolati.
					break;
			}

			// Senza ingrandimento l'output non supera mai la regione ritagliata.
			$geometry['out_w']  = $allow_upscale ? $target_w : min( $target_w, $crop_w );
			$geometry['out_h']  = $allow_upscale ? $target_h : min( $target_h, $crop_h );
			$geometry['crop_x'] = max( 0, $crop_x );
			$geometry['crop_y'] = max( 0, $crop_y );
			$geometry['crop_w'] = $crop_w;
			$geometry['crop_h'] = $crop_h;

			return $geometry;
		}

		// Letterbox e Scale: adattamento proporzionale dentro il box target.
		$ratio = min( $target_w / $src_w, $target_h / $src_h );

		if ( ! $allow_upscale ) {
			$ratio = min( 1, $ratio );
		}

		$fit_w = (int) round( $src_w * $ratio );
		$fit_h = (int) round( $src_h * $ratio );

		if ( $fit_w > $target_w ) {
			$fit_w = $target_w;
			$fit_h = max( 1, (int) round( $fit_w * $src_h / $src_w ) );
		}

		if ( $fit_h > $target_h ) {
			$fit_h = $target_h;
			$fit_w = max( 1, (int) round( $fit_h * $src_w / $src_h ) );
		}

		$fit_w = max( 1, min( $fit_w, $target_w ) );
		$fit_h = max( 1, min( $fit_h, $target_h ) );

		$geometry['fit_w']    = $fit_w;
		$geometry['fit_h']    = $fit_h;
		$geometry['offset_x'] = (int) floor( ( $target_w - $fit_w ) / 2 );
		$geometry['offset_y'] = (int) floor( ( $target_h - $fit_h ) / 2 );

		if ( 'scale' === $mode ) {
			$geometry['out_w'] = $fit_w;
			$geometry['out_h'] = $fit_h;
		} else {
			$geometry['out_w'] = $target_w;
			$geometry['out_h'] = $target_h;
		}

		return $geometry;
	}

	/**
	 * Compone il canvas Letterbox attorno all'immagine ridimensionata.
	 *
	 * @param string $source    File temporaneo ridimensionato.
	 * @param string $destination File di destinazione.
	 * @param int    $target_w  Larghezza canvas.
	 * @param int    $target_h  Altezza canvas.
	 * @param array  $settings  Impostazioni.
	 * @return true|\WP_Error
	 */
	private function composite_canvas( $source, $destination, $target_w, $target_h, $settings ) {
		if ( ! is_file( $source ) ) {
			return new \WP_Error( 'idim_source_missing', __( 'The resized image could not be generated.', 'incod-image-dimension-manager' ) );
		}

		$source_size = $this->read_file_dimensions( $source );

		if ( ! $source_size ) {
			return new \WP_Error( 'idim_source_invalid', __( 'The resized image is not readable.', 'incod-image-dimension-manager' ) );
		}

		$format   = $this->format_from_path( $destination );
		$alpha    = in_array( $format, array( 'png', 'webp' ), true );
		$transparent = ! empty( $settings['transparent_canvas'] ) && $alpha;
		$color      = $transparent ? '' : $this->normalize_color( $settings['background_color'] );

		$offset_x = (int) floor( ( $target_w - $source_size[0] ) / 2 );
		$offset_y = (int) floor( ( $target_h - $source_size[1] ) / 2 );

		if ( class_exists( '\Imagick' ) ) {
			$result = $this->composite_with_imagick(
				$source,
				$destination,
				$target_w,
				$target_h,
				$offset_x,
				$offset_y,
				$format,
				$transparent,
				$color,
				(int) $settings['jpeg_quality']
			);

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $this->composite_with_gd(
			$source,
			$destination,
			$target_w,
			$target_h,
			$offset_x,
			$offset_y,
			$format,
			$transparent,
			$color,
			(int) $settings['jpeg_quality']
		);
	}

	/**
	 * Compone il canvas usando Imagick.
	 *
	 * @return true|\WP_Error
	 */
	private function composite_with_imagick( $source, $destination, $target_w, $target_h, $offset_x, $offset_y, $format, $transparent, $color, $quality ) {
		try {
			$canvas = new \Imagick();

			if ( $transparent ) {
				$canvas->newImage( $target_w, $target_h, new \ImagickPixel( 'transparent' ) );
			} else {
				$canvas->newImage( $target_w, $target_h, new \ImagickPixel( $color ) );
			}

			$image = new \Imagick( $source );
			$image->setBackgroundColor( new \ImagickPixel( $transparent ? 'transparent' : $color ) );

			$canvas->compositeImage( $image, \Imagick::COMPOSITE_OVER, (int) $offset_x, (int) $offset_y );

			$canvas->setImageFormat( $format );

			if ( 'jpeg' === $format ) {
				$canvas->setImageCompression( \Imagick::COMPRESSION_JPEG );
				$canvas->setImageCompressionQuality( $quality );
			}

			$canvas->setImagePage( $target_w, $target_h, 0, 0 );

			$written = $canvas->writeImage( $destination );

			$image->clear();
			$canvas->clear();

			if ( ! $written ) {
				return new \WP_Error( 'idim_imagick_write', __( 'Imagick could not write the canvas file.', 'incod-image-dimension-manager' ) );
			}

			return true;
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return new \WP_Error( 'idim_imagick_failed', $e->getMessage() );
		}
	}

	/**
	 * Compone il canvas usando GD (fallback).
	 *
	 * @return true|\WP_Error
	 */
	private function composite_with_gd( $source, $destination, $target_w, $target_h, $offset_x, $offset_y, $format, $transparent, $color, $quality ) {
		$image = $this->gd_load( $source );

		if ( ! $image ) {
			return new \WP_Error( 'idim_gd_load', __( 'GD could not load the resized image.', 'incod-image-dimension-manager' ) );
		}

		$source_w = imagesx( $image );
		$source_h = imagesy( $image );
		$canvas   = imagecreatetruecolor( $target_w, $target_h );

		if ( ! $canvas ) {
			imagedestroy( $image );

			return new \WP_Error( 'idim_gd_canvas', __( 'GD could not allocate the canvas.', 'incod-image-dimension-manager' ) );
		}

		if ( $transparent ) {
			imagealphablending( $canvas, false );
			imagesavealpha( $canvas, true );
			$fill = imagecolorallocatealpha( $canvas, 0, 0, 0, 127 );
			imagefilledrectangle( $canvas, 0, 0, $target_w, $target_h, $fill );
			imagealphablending( $canvas, true );
		} else {
			list( $r, $g, $b ) = $this->hex_to_rgb( $color );
			$fill              = imagecolorallocate( $canvas, $r, $g, $b );
			imagefilledrectangle( $canvas, 0, 0, $target_w, $target_h, $fill );
		}

		imagecopyresampled(
			$canvas,
			$image,
			(int) $offset_x,
			(int) $offset_y,
			0,
			0,
			$source_w,
			$source_h,
			$source_w,
			$source_h
		);

		$written = $this->gd_save( $canvas, $destination, $format, $quality );

		imagedestroy( $canvas );
		imagedestroy( $image );

		if ( ! $written ) {
			return new \WP_Error( 'idim_gd_write', __( 'GD could not write the canvas file.', 'incod-image-dimension-manager' ) );
		}

		return true;
	}

	/**
	 * Ridimensiona un file a dimensioni fisse senza i limiti del core.
	 *
	 * Usato per gli ingrandimenti, che WP_Image_Editor rifiuta. Prova con
	 * Imagick e in caso di errore o assenza cade su GD.
	 *
	 * @param string $source      File sorgente.
	 * @param string $destination File destinazione.
	 * @param int    $width       Larghezza finale.
	 * @param int    $height      Altezza finale.
	 * @param int    $quality     Qualità JPEG/WebP.
	 * @return true|\WP_Error
	 */
	private function scale_file( $source, $destination, $width, $height, $quality ) {
		$width  = max( 1, (int) $width );
		$height = max( 1, (int) $height );
		$format = $this->format_from_path( $destination );

		if ( class_exists( '\Imagick' ) ) {
			try {
				$image = new \Imagick( $source );
				$image->resizeImage( $width, $height, \Imagick::FILTER_LANCZOS, 1 );
				$image->setImageFormat( $format );
				$image->setImageCompressionQuality( $quality );

				if ( 'jpeg' === $format ) {
					$image->setImageCompression( \Imagick::COMPRESSION_JPEG );
				}

				$image->setImagePage( $width, $height, 0, 0 );
				$written = $image->writeImage( $destination );
				$image->clear();

				if ( $written ) {
					return true;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Passa al fallback GD.
			}
		}

		return $this->scale_file_gd( $source, $destination, $width, $height, $quality );
	}

	/**
	 * Ridimensiona un file con GD (fallback).
	 *
	 * @param string $source      File sorgente.
	 * @param string $destination File destinazione.
	 * @param int    $width       Larghezza finale.
	 * @param int    $height      Altezza finale.
	 * @param int    $quality     Qualità.
	 * @return true|\WP_Error
	 */
	private function scale_file_gd( $source, $destination, $width, $height, $quality ) {
		$image = $this->gd_load( $source );

		if ( ! $image ) {
			return new \WP_Error( 'idim_gd_load', __( 'GD could not load the resized image.', 'incod-image-dimension-manager' ) );
		}

		$canvas = imagecreatetruecolor( $width, $height );

		if ( ! $canvas ) {
			imagedestroy( $image );

			return new \WP_Error( 'idim_gd_canvas', __( 'GD could not allocate the canvas.', 'incod-image-dimension-manager' ) );
		}

		$format = $this->format_from_path( $destination );
		$alpha  = in_array( $format, array( 'png', 'webp' ), true );

		if ( $alpha ) {
			imagealphablending( $canvas, false );
			imagesavealpha( $canvas, true );
			$fill = imagecolorallocatealpha( $canvas, 0, 0, 0, 127 );
			imagefilledrectangle( $canvas, 0, 0, $width, $height, $fill );
			imagealphablending( $canvas, true );
		}

		imagecopyresampled(
			$canvas,
			$image,
			0,
			0,
			0,
			0,
			$width,
			$height,
			imagesx( $image ),
			imagesy( $image )
		);

		$written = $this->gd_save( $canvas, $destination, $format, $quality );

		imagedestroy( $canvas );
		imagedestroy( $image );

		if ( ! $written ) {
			return new \WP_Error( 'idim_gd_write', __( 'GD could not write the canvas file.', 'incod-image-dimension-manager' ) );
		}

		return true;
	}

	/**
	 * Carica un'immagine con GD.
	 *
	 * @param string $path Percorso file.
	 * @return resource|\GdImage|false
	 */
	private function gd_load( $path ) {
		$format = $this->format_from_path( $path );

		if ( 'jpeg' === $format && function_exists( 'imagecreatefromjpeg' ) ) {
			return imagecreatefromjpeg( $path );
		}

		if ( 'png' === $format && function_exists( 'imagecreatefrompng' ) ) {
			return imagecreatefrompng( $path );
		}

		if ( 'webp' === $format && function_exists( 'imagecreatefromwebp' ) ) {
			return imagecreatefromwebp( $path );
		}

		// Ultimo tentativo: prova in ordine.
		if ( function_exists( 'imagecreatefromstring' ) ) {
			$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( $data ) {
				$image = imagecreatefromstring( $data );

				if ( $image && function_exists( 'imagepalettetotruecolor' ) && ! imageistruecolor( $image ) ) {
					imagepalettetotruecolor( $image );
				}

				return $image ? $image : false;
			}
		}

		return false;
	}

	/**
	 * Salva un'immagine GD nel formato richiesto.
	 *
	 * @param resource|\GdImage $image     Immagine.
	 * @param string            $path      Percorso destinazione.
	 * @param string            $format    Formato.
	 * @param int               $quality   Qualità.
	 * @return bool
	 */
	private function gd_save( $image, $path, $format, $quality ) {
		if ( 'jpeg' === $format && function_exists( 'imagejpeg' ) ) {
			return imagejpeg( $image, $path, $quality );
		}

		if ( 'png' === $format && function_exists( 'imagepng' ) ) {
			$compression = (int) round( ( 100 - $quality ) / 100 * 9 );

			return imagepng( $image, $path, $compression );
		}

		if ( 'webp' === $format && function_exists( 'imagewebp' ) ) {
			return imagewebp( $image, $path, $quality );
		}

		if ( function_exists( 'imagejpeg' ) ) {
			return imagejpeg( $image, $path, $quality );
		}

		return false;
	}

	/**
	 * Rigenera i metadati dell'allegato a partire dal file aggiornato.
	 *
	 * Elimina le taglie intermedie ormai obsolete prima di ricalcolarle.
	 *
	 * @param int    $attachment_id ID allegato.
	 * @param string $file          Percorso del file principale.
	 * @return array|\WP_Error
	 */
	public function regenerate_metadata( $attachment_id, $file ) {
		$old = wp_get_attachment_metadata( $attachment_id );
		$old = is_array( $old ) ? $old : array();

		if ( ! empty( $old['sizes'] ) && is_array( $old['sizes'] ) ) {
			$this->delete_intermediate_files( $file, $old['sizes'] );
		}

		self::enter_guard();

		try {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file );

			if ( is_wp_error( $metadata ) ) {
				return $metadata;
			}

			// Conserva il riferimento all'originale a risoluzione piena.
			if ( ! empty( $old['original_image'] ) ) {
				$metadata['original_image'] = $old['original_image'];
			}

			wp_update_attachment_metadata( $attachment_id, $metadata );
		} finally {
			self::leave_guard();
		}

		return $metadata;
	}

	/**
	 * Elimina i file delle taglie intermedie generate in precedenza.
	 *
	 * @param string $file  File principale.
	 * @param array  $sizes Taglie registrate nei metadati.
	 * @return void
	 */
	private function delete_intermediate_files( $file, $sizes ) {
		$directory = dirname( $file );

		foreach ( $sizes as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}

			$path = trailingslashit( $directory ) . $size['file'];

			if ( is_file( $path ) && $path !== $file ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Crea un backup del file prima della sovrascrittura.
	 *
	 * @param int    $attachment_id ID allegato.
	 * @param string $file          File da salvare.
	 * @return bool
	 */
	public function backup_file( $attachment_id, $file ) {
		$directory = trailingslashit( $this->get_backup_dir() ) . absint( $attachment_id );

		if ( ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		$destination = trailingslashit( $directory ) . basename( $file );

		if ( is_file( $destination ) ) {
			return true;
		}

		return copy( $file, $destination );
	}

	/**
	 * Ripristina i backup degli originali, in lotti limitati da numero e tempo.
	 *
	 * Ogni cartella elaborata viene consumata nell'istante corrente, così la
	 * chiamata successiva riprende esattamente da dove si è interrotta senza
	 * mai elaborare due volte lo stesso allegato.
	 *
	 * @param int $limit       Massimo numero di cartelle per richiesta (0 = illimitato).
	 * @param int $time_budget Massimo tempo in secondi (0 = illimitato).
	 * @return array {
	 *     @type int  $restored  Allegati ripristinati.
	 *     @type int  $failed    Allegati non ripristinati.
	 *     @type int  $remaining Cartelle ancora da elaborare.
	 *     @type bool $done      True quando non resta nulla da fare.
	 *     @type float $duration Durata in secondi.
	 * }
	 */
	public function restore_backups( $limit = 0, $time_budget = 0 ) {
		$started = microtime( true );
		$result  = array(
			'restored'  => 0,
			'failed'    => 0,
			'remaining' => 0,
			'done'      => true,
			'duration'  => 0.0,
		);

		$base = $this->get_backup_dir();

		if ( ! is_dir( $base ) ) {
			return $result;
		}

		$folders = glob( trailingslashit( $base ) . '*', GLOB_ONLYDIR );
		$queue   = array();

		if ( $folders ) {
			foreach ( $folders as $folder ) {
				$attachment_id = absint( basename( $folder ) );

				if ( ! $attachment_id ) {
					// Cartelle non riconosciute: rifiuti del plugin, eliminati.
					$this->delete_directory( $folder );
					continue;
				}

				$queue[] = array(
					'folder' => $folder,
					'id'     => $attachment_id,
				);
			}
		}

		$total = count( $queue );
		$index = 0;

		foreach ( $queue as $entry ) {
			if ( $limit > 0 && $index >= $limit ) {
				break;
			}

			if ( $time_budget > 0 && $index > 0 && ( microtime( true ) - $started ) >= $time_budget ) {
				break;
			}

			$index++;
			$folder         = $entry['folder'];
			$attachment_id  = $entry['id'];
			$files          = glob( trailingslashit( $folder ) . '*' );
			$target         = get_attached_file( $attachment_id );

			if ( empty( $files ) ) {
				$this->delete_directory( $folder );
				continue;
			}

			if ( ! $target ) {
				$result['failed']++;
				continue;
			}

			$this->enter_guard();

			try {
				// Elimina le taglie generate dall'immagine modificata.
				$old = wp_get_attachment_metadata( $attachment_id );

				if ( is_array( $old ) && ! empty( $old['sizes'] ) ) {
					$this->delete_intermediate_files( $target, $old['sizes'] );
				}

				if ( ! copy( $files[0], $target ) ) {
					$result['failed']++;
					continue;
				}

				$metadata = wp_generate_attachment_metadata( $attachment_id, $target );

				if ( is_wp_error( $metadata ) ) {
					$result['failed']++;
					continue;
				}

				wp_update_attachment_metadata( $attachment_id, $metadata );
				$result['restored']++;
			} finally {
				self::leave_guard();
			}

			$this->delete_directory( $folder );
		}

		$result['remaining'] = max( 0, $total - $index );
		$result['done']      = ( 0 === $result['remaining'] );
		$result['duration']  = round( microtime( true ) - $started, 3 );

		if ( $result['done'] && is_dir( $base ) && ! glob( trailingslashit( $base ) . '*' ) ) {
			$this->delete_directory( $base );
		}

		return $result;
	}

	/**
	 * Restituisce la cartella dei backup.
	 *
	 * @return string
	 */
	public function get_backup_dir() {
		$uploads = wp_get_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . 'idim-backups';
	}

	/**
	 * Elimina ricorsivamente una cartella.
	 *
	 * @param string $directory Cartella.
	 * @return bool
	 */
	private function delete_directory( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return false;
		}

		$uploads = wp_get_upload_dir();
		$base    = realpath( $uploads['basedir'] );
		$real    = realpath( $directory );

		if ( ! $base || ! $real || 0 !== strpos( $real, $base ) ) {
			return false;
		}

		$items = scandir( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = trailingslashit( $real ) . $item;

			if ( is_dir( $path ) ) {
				$this->delete_directory( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		return rmdir( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Verifica se l'allegato è un'immagine supportata.
	 *
	 * @param int $attachment_id ID allegato.
	 * @return bool
	 */
	private function is_supported_image( $attachment_id ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return false;
		}

		$mime = get_post_mime_type( $attachment_id );

		return in_array( $mime, self::ALLOWED_MIME, true );
	}

	/**
	 * Determina se l'immagine ha già le dimensioni richieste.
	 *
	 * @param int   $width    Larghezza.
	 * @param int   $height   Altezza.
	 * @param array $settings Impostazioni.
	 * @return bool
	 */
	private function is_already_correct( $width, $height, $settings ) {
		if ( empty( $settings['skip_sized'] ) ) {
			return false;
		}

		$geometry = $this->compute_geometry(
			(int) $width,
			(int) $height,
			(int) $settings['target_width'],
			(int) $settings['target_height'],
			(string) $settings['mode'],
			(string) $settings['crop_focus'],
			! empty( $settings['allow_upscale'] )
		);

		return (int) $width === (int) $geometry['out_w'] && (int) $height === (int) $geometry['out_h'];
	}

	/**
	 * Legge le dimensioni dell'allegato.
	 *
	 * @param int    $attachment_id ID allegato.
	 * @param string $file          Percorso file.
	 * @return array|null
	 */
	private function get_image_dimensions( $attachment_id, $file ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( is_array( $metadata ) && ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
			return array( (int) $metadata['width'], (int) $metadata['height'] );
		}

		return $this->read_file_dimensions( $file );
	}

	/**
	 * Legge le dimensioni di un file immagine.
	 *
	 * @param string $file Percorso.
	 * @return array|null
	 */
	private function read_file_dimensions( $file ) {
		if ( ! is_file( $file ) ) {
			return null;
		}

		if ( function_exists( 'wp_getimagesize' ) ) {
			$size = wp_getimagesize( $file );
		} else {
			$size = getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		if ( empty( $size[0] ) || empty( $size[1] ) ) {
			return null;
		}

		return array( (int) $size[0], (int) $size[1] );
	}

	/**
	 * Crea un percorso temporaneo accanto al file di destinazione.
	 *
	 * @param string $file File di riferimento.
	 * @return string|false
	 */
	private function create_temp_path( $file ) {
		$directory = dirname( $file );

		if ( ! is_dir( $directory ) || ! wp_is_writable( $directory ) ) {
			return false;
		}

		$extension = pathinfo( $file, PATHINFO_EXTENSION );
		$name      = 'idim-tmp-' . wp_generate_password( 12, false, false ) . ( $extension ? '.' . strtolower( $extension ) : '' );
		$path      = trailingslashit( $directory ) . $name;

		return @touch( $path ) ? $path : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch
	}

	/**
	 * Determina il formato in base all'estensione.
	 *
	 * @param string $path Percorso file.
	 * @return string jpeg|png|webp
	 */
	private function format_from_path( $path ) {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( 'png' === $extension ) {
			return 'png';
		}

		if ( 'webp' === $extension ) {
			return 'webp';
		}

		return 'jpeg';
	}

	/**
	 * Normalizza un colore HEX.
	 *
	 * @param string $color Colore.
	 * @return string
	 */
	private function normalize_color( $color ) {
		$color = sanitize_hex_color( $color );

		return $color ? $color : '#ffffff';
	}

	/**
	 * Converte un colore HEX in RGB.
	 *
	 * @param string $color Colore #rrggbb.
	 * @return int[]
	 */
	private function hex_to_rgb( $color ) {
		$color = ltrim( (string) $color, '#' );

		if ( 3 === strlen( $color ) ) {
			$color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
		}

		$rgb = array( 255, 255, 255 );

		if ( 6 === strlen( $color ) && ctype_xdigit( $color ) ) {
			$rgb = array(
				hexdec( substr( $color, 0, 2 ) ),
				hexdec( substr( $color, 2, 2 ) ),
				hexdec( substr( $color, 4, 2 ) ),
			);
		}

		return $rgb;
	}
}
