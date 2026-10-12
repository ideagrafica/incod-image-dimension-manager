<?php
/**
 * Endpoint AJAX protetti del plugin.
 *
 * @package IncodImageDimensionManager
 */

namespace IncodImageDimensionManager\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Classe Idim_Ajax
 */
final class Idim_Ajax {

	/**
	 * Azione nonce condivisa.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'idim_admin';

	/**
	 * Massimo numero di immagini per richiesta AJAX.
	 *
	 * @var int
	 */
	const MAX_PER_REQUEST = 50;

	/**
	 * Budget di tempo in secondi per singola richiesta.
	 *
	 * Molti sotto i tipici limiti di nginx/Apache/PHP-FPM (30-60s): il batch
	 * viene interrotto in tempo utile e il resto viene ricalcolato nella
	 * richiesta successiva.
	 *
	 * @var int
	 */
	const TIME_BUDGET = 20;

	/**
	 * Chiave del transient del lock di mutua esclusione.
	 *
	 * @var string
	 */
	const LOCK_KEY = 'idim_bulk_lock';

	/**
	 * Durata del lock in secondi.
	 *
	 * @var int
	 */
	const LOCK_TTL = 60;

	/**
	 * Massimo numero di cartelle backup per richiesta di ripristino.
	 *
	 * @var int
	 */
	const RESTORE_PER_REQUEST = 25;

	/**
	 * Timeout AJAX lato browser in millisecondi (3x il budget di tempo).
	 *
	 * @var int
	 */
	const AJAX_TIMEOUT = 60000;

	/**
	 * Token del lock attualmente detenuto dalla richiesta corrente.
	 *
	 * @var string|null
	 */
	private $lock_token = null;

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
	 * Gestore dello stato bulk.
	 *
	 * @var Idim_Bulk_Process
	 */
	private $bulk;

	/**
	 * Costruttore.
	 */
	public function __construct() {
		$this->editor = new Idim_Image_Editor();
		$this->cpt    = new Idim_Cpt_Manager();
		$this->bulk   = new Idim_Bulk_Process();
	}

	/**
	 * Registra gli endpoint AJAX.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_idim_get_queue', array( $this, 'get_queue' ) );
		add_action( 'wp_ajax_idim_process_batch', array( $this, 'process_batch' ) );
		add_action( 'wp_ajax_idim_control', array( $this, 'control' ) );
		add_action( 'wp_ajax_idim_status', array( $this, 'status' ) );
		add_action( 'wp_ajax_idim_restore', array( $this, 'restore' ) );
	}

	/**
	 * Verifica nonce e permessi per ogni richiesta AJAX.
	 *
	 * @param bool $heavy True solo per gli endpoint che elaborano dati
	 *                    (coda, batch, ripristino): i limiti PHP vengono
	 *                    alzati esclusivamente per queste richieste pesanti
	 *                    e mai per quelle leggere (stato e controllo).
	 * @return void
	 */
	private function verify_request( $heavy = false ) {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to perform this action.', 'incod-image-dimension-manager' ),
				),
				403
			);
		}

		if ( $heavy && ! Idim_Image_Editor::is_guarded() ) {
			// Evita timeout PHP sulle elaborazioni lunghe, solo sulle
			// richieste che richiedono davvero tempo di esecuzione.
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			wp_raise_memory_limit( 'admin' );
		}
	}

	/**
	 * Acquisisce il lock di mutua esclusione della sessione bulk.
	 *
	 * Impedisce che due tab o due amministratori elaborino e contino due volte
	 * la stessa coda, saturando il server.
	 *
	 * @return bool True se il lock è stato ottenuto.
	 */
	private function acquire_lock() {
		$lock = get_transient( self::LOCK_KEY );

		if (
			is_array( $lock )
			&& ! empty( $lock['token'] )
			&& ! empty( $lock['expires'] )
			&& (int) $lock['expires'] > time()
		) {
			return false;
		}

		$lock = array(
			'token'   => function_exists( 'wp_generate_password' )
				? wp_generate_password( 32, false, false )
				: uniqid( 'idim', true ),
			'expires' => time() + self::LOCK_TTL,
		);

		set_transient( self::LOCK_KEY, $lock, self::LOCK_TTL );

		$this->lock_token = $lock['token'];

		return true;
	}

	/**
	 * Rilascia il lock solo se ancora proprietario della richiesta corrente.
	 *
	 * @return void
	 */
	private function release_lock() {
		if ( ! $this->lock_token ) {
			return;
		}

		$lock = get_transient( self::LOCK_KEY );

		if (
			is_array( $lock )
			&& ! empty( $lock['token'] )
			&& hash_equals( (string) $lock['token'], (string) $this->lock_token )
		) {
			delete_transient( self::LOCK_KEY );
		}

		$this->lock_token = null;
	}

	/**
	 * Calcola la pausa consigliata prima del batch successivo.
	 *
	 * Throttle adattivo: più è stato impegnativo il batch elaborato, più a lungo
	 * il server può riposare (clamp 300-3000ms).
	 *
	 * @param float $duration Durata del batch in secondi.
	 * @return int Millisecondi di attesa.
	 */
	private function suggest_throttle( $duration ) {
		$duration = max( 0, (float) $duration );
		$ms       = (int) round( $duration * 500 );

		return max( 300, min( 3000, $ms ) );
	}

	/**
	 * Interrompe la richiesta con un errore "busy" (429).
	 *
	 * @param string $message Messaggio da restituire.
	 * @return void
	 */
	private function send_busy( $message ) {
		wp_send_json_error(
			array(
				'code'        => 'busy',
				'message'     => $message,
				'retry_after' => 2,
			),
			429
		);
	}

	/**
	 * Restituisce la coda completa delle immagini da elaborare.
	 *
	 * @return void
	 */
	public function get_queue() {
		$this->verify_request( true );

		$ids   = $this->cpt->get_attachment_ids();
		$total = count( $ids );
		$state = $this->bulk->start( $total );

		wp_send_json_success(
			array(
				'total'      => $total,
				'ids'        => array_values( $ids ),
				'batch_size' => max( 1, min( self::MAX_PER_REQUEST, (int) Idim_Settings::get( 'batch_size' ) ) ),
				'state'      => $state,
				'scope'      => $this->cpt->operates_on_media_library()
					? __( 'Whole Media Library', 'incod-image-dimension-manager' )
					: implode( ', ', $this->cpt->get_selected_post_types() ),
				'message'    => $total
					? __( 'Queue created.', 'incod-image-dimension-manager' )
					: __( 'No images match the current selection.', 'incod-image-dimension-manager' ),
			)
		);
	}

	/**
	 * Elabora un singolo batch di immagini con budget di tempo.
	 *
	 * Il batch viene interrotto al raggiungimento di TIME_BUDGET (compiendo
	 * sempre almeno un'elaborazione): gli ID non ancora provati vengono
	 * restituiti in "remaining" e ricalcolati dal client, così una richiesta
	 * lenta non genera mai errori né immagini perse.
	 *
	 * @return void
	 */
	public function process_batch() {
		$this->verify_request( true );

		$state = $this->bulk->get_state();

		if ( 'cancelled' === $state['status'] ) {
			wp_send_json_success(
				array(
					'stopped'   => true,
					'remaining' => array(),
					'state'     => $state,
					'message'   => __( 'The bulk process has been cancelled.', 'incod-image-dimension-manager' ),
				)
			);
		}

		$raw_ids = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ids     = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );

		if ( empty( $ids ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid image batch.', 'incod-image-dimension-manager' ),
				),
				400
			);
		}

		if ( ! $this->acquire_lock() ) {
			$this->send_busy( __( 'Another resize process is already running.', 'incod-image-dimension-manager' ) );
		}

		// La risposta può essere persa senza interrompere l'elaborazione:
		// i conteggi restano coerenti con lo stato sul server.
		ignore_user_abort( true );

		$settings   = Idim_Settings::all();
		$processed  = 0;
		$skipped    = 0;
		$failed     = 0;
		$errors     = array();
		$handled    = 0;
		$remaining  = array();
		$started    = microtime( true );

		try {
			foreach ( $ids as $index => $attachment_id ) {
				if (
					( $handled > 0 && ( microtime( true ) - $started ) >= self::TIME_BUDGET )
					|| $handled >= self::MAX_PER_REQUEST
				) {
					$remaining = array_slice( $ids, $index );
					break;
				}

				$handled++;
				$result = $this->editor->process_attachment( $attachment_id, $settings );

				if ( is_wp_error( $result ) ) {
					$failed++;
					$errors[] = array(
						'id'      => $attachment_id,
						'title'   => $this->get_attachment_title( $attachment_id ),
						'message' => $result->get_error_message(),
					);
					continue;
				}

				if ( 'skipped' === $result ) {
					$skipped++;
					continue;
				}

				$processed++;
			}

			$state = $this->bulk->record_batch( $processed, $skipped, $failed, $errors );
		} finally {
			$this->release_lock();
		}

		$duration = microtime( true ) - $started;
		$stopped  = in_array( $state['status'], array( 'cancelled' ), true );

		wp_send_json_success(
			array(
				'processed'  => $processed,
				'skipped'    => $skipped,
				'failed'     => $failed,
				'errors'     => $errors,
				'count'      => $handled,
				'remaining'  => $remaining,
				'duration'   => round( $duration, 3 ),
				'throttle'   => $this->suggest_throttle( $duration ),
				'stopped'    => $stopped,
				'state'      => $state,
				'elapsed'    => $this->bulk->get_elapsed(),
			)
		);
	}

	/**
	 * Gestisce pausa, ripresa, annullamento e azioni correlate.
	 *
	 * @return void
	 */
	public function control() {
		$this->verify_request();

		$action = isset( $_POST['control_action'] ) ? sanitize_key( wp_unslash( $_POST['control_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		switch ( $action ) {
			case 'pause':
				$state = $this->bulk->set_status( 'paused' );
				break;

			case 'resume':
				$state = $this->bulk->set_status( 'running' );
				break;

			case 'cancel':
				$state = $this->bulk->set_status( 'cancelled' );
				break;

			case 'reset':
				$state = $this->bulk->reset();
				break;

			default:
				wp_send_json_error(
					array(
						'message' => __( 'Unknown action.', 'incod-image-dimension-manager' ),
					),
					400
				);
		}

		wp_send_json_success( array( 'state' => $state ) );
	}

	/**
	 * Restituisce lo stato dell'ultima sessione.
	 *
	 * @return void
	 */
	public function status() {
		$this->verify_request();

		wp_send_json_success(
			array(
				'state'   => $this->bulk->get_state(),
				'elapsed' => $this->bulk->get_elapsed(),
			)
		);
	}

	/**
	 * Ripristina i file originali dai backup, un lotto per richiesta.
	 *
	 * Il client ripete la chiamata finché "done" è falso: nessuna richiesta
	 * singola può superare il budget di tempo.
	 *
	 * @return void
	 */
	public function restore() {
		$this->verify_request( true );

		if ( ! $this->acquire_lock() ) {
			$this->send_busy( __( 'Another resize process is already running.', 'incod-image-dimension-manager' ) );
		}

		ignore_user_abort( true );

		try {
			$result = $this->editor->restore_backups( self::RESTORE_PER_REQUEST, self::TIME_BUDGET );
		} finally {
			$this->release_lock();
		}

		$restored = (int) $result['restored'];

		wp_send_json_success(
			array(
				'restored'  => $restored,
				'failed'    => (int) $result['failed'],
				'remaining' => (int) $result['remaining'],
				'done'      => (bool) $result['done'],
				'duration'  => (float) $result['duration'],
				'throttle'  => $this->suggest_throttle( (float) $result['duration'] ),
				'message'   => sprintf(
					/* translators: %d: number of restored images. */
					_n( '%d image restored.', '%d images restored.', $restored, 'incod-image-dimension-manager' ),
					$restored
				),
			)
		);
	}

	/**
	 * Recupera un titolo leggibile per l'allegato.
	 *
	 * @param int $attachment_id ID allegato.
	 * @return string
	 */
	private function get_attachment_title( $attachment_id ) {
		$title = get_the_title( $attachment_id );

		return $title ? $title : '#' . absint( $attachment_id );
	}
}
