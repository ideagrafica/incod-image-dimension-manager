<?php
/**
 * Stato e statistica del processo di ridimensionamento di massa.
 *
 * @package IncodImageDimensionManager
 */

namespace IncodImageDimensionManager\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Classe Idim_Bulk_Process
 */
final class Idim_Bulk_Process {

	/**
	 * Chiave del transient che memorizza lo stato.
	 *
	 * @var string
	 */
	const STATE_KEY = 'idim_bulk_state';

	/**
	 * Massimo numero di errori conservati nello stato.
	 *
	 * @var int
	 */
	const MAX_ERRORS = 50;

	/**
	 * Durata del transient di stato (1 settimana).
	 *
	 * @var int
	 */
	const EXPIRATION = WEEK_IN_SECONDS;

	/**
	 * Stato di default.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'status'    => 'idle', // idle|running|paused|done|cancelled.
			'total'     => 0,
			'processed' => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'errors'    => array(),
			'started'   => 0,
			'updated'   => 0,
			'finished'  => 0,
		);
	}

	/**
	 * Legge lo stato corrente.
	 *
	 * @return array
	 */
	public function get_state() {
		$state = get_transient( self::STATE_KEY );

		return wp_parse_args( is_array( $state ) ? $state : array(), self::defaults() );
	}

	/**
	 * Salva lo stato.
	 *
	 * @param array $state Stato.
	 * @return array
	 */
	public function save_state( $state ) {
		$state                 = wp_parse_args( is_array( $state ) ? $state : array(), self::defaults() );
		$state['errors']       = array_slice( (array) $state['errors'], 0, self::MAX_ERRORS );
		$state['updated']      = time();

		set_transient( self::STATE_KEY, $state, self::EXPIRATION );

		return $state;
	}

	/**
	 * Avvia una nuova sessione di elaborazione.
	 *
	 * @param int $totale Numero totale di immagini in coda.
	 * @return array
	 */
	public function start( $total ) {
		$state                = self::defaults();
		$state['status']      = 'running';
		$state['total']       = max( 0, absint( $total ) );
		$state['started']     = time();
		$state['updated']     = time();

		return $this->save_state( $state );
	}

	/**
	 * Registra l'esito di un batch.
	 *
	 * @param int   $processed Immagini elaborate.
	 * @param int   $skipped   Immagini saltate.
	 * @param int   $failed    Immagini in errore.
	 * @param array $errors    Dettaglio errori.
	 * @return array
	 */
	public function record_batch( $processed, $skipped, $failed, $errors ) {
		$state = $this->get_state();

		$state['processed'] += max( 0, absint( $processed ) );
		$state['skipped']   += max( 0, absint( $skipped ) );
		$state['failed']    += max( 0, absint( $failed ) );
		$state['errors']     = array_merge( (array) $state['errors'], (array) $errors );

		$done = ( $state['processed'] + $state['skipped'] + $state['failed'] ) >= $state['total'];

		if ( in_array( $state['status'], array( 'running', 'paused' ), true ) && $state['total'] > 0 && $done ) {
			$state['status']   = 'done';
			$state['finished'] = time();
		}

		return $this->save_state( $state );
	}

	/**
	 * Modifica lo stato della sessione.
	 *
	 * @param string $status Nuovo stato.
	 * @return array
	 */
	public function set_status( $status ) {
		$allowed = array( 'idle', 'running', 'paused', 'done', 'cancelled' );
		$status  = in_array( $status, $allowed, true ) ? $status : 'running';

		$state          = $this->get_state();
		$state['status'] = $status;

		if ( 'cancelled' === $status ) {
			$state['finished'] = time();
		}

		return $this->save_state( $state );
	}

	/**
	 * Azzera lo stato.
	 *
	 * @return array
	 */
	public function reset() {
		delete_transient( self::STATE_KEY );

		return self::defaults();
	}

	/**
	 * Calcola i secondi trascorsi dall'avvio.
	 *
	 * @return int
	 */
	public function get_elapsed() {
		$state = $this->get_state();

		if ( empty( $state['started'] ) ) {
			return 0;
		}

		$end = ! empty( $state['finished'] ) ? (int) $state['finished'] : time();

		return max( 0, $end - (int) $state['started'] );
	}
}
