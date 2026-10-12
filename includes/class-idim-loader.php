<?php
/**
 * Registro centrale di action e filter.
 *
 * @package IncodImageDimensionManager
 */

namespace IncodImageDimensionManager\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Classe Idim_Loader
 */
final class Idim_Loader {

	/**
	 * Hooks di tipo action da registrare.
	 *
	 * @var array[]
	 */
	private $actions = array();

	/**
	 * Hooks di tipo filter da registrare.
	 *
	 * @var array[]
	 */
	private $filters = array();

	/**
	 * Accoda un'action.
	 *
	 * @param string   $hook          Nome dell'hook.
	 * @param callable $component     Callback.
	 * @param int      $priority      Priorità.
	 * @param int      $accepted_args Numero di argomenti accettati.
	 * @return void
	 */
	public function add_action( $hook, $component, $priority = 10, $accepted_args = 1 ) {
		$this->actions[] = $this->build( $hook, $component, $priority, $accepted_args );
	}

	/**
	 * Accoda un filter.
	 *
	 * @param string   $hook          Nome dell'hook.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priorità.
	 * @param int      $accepted_args Numero di argomenti accettati.
	 * @return void
	 */
	public function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters[] = $this->build( $hook, $callback, $priority, $accepted_args );
	}

	/**
	 * Costruisce la struttura di un hook accodato.
	 *
	 * @param string   $hook          Nome dell'hook.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priorità.
	 * @param int      $accepted_args Numero di argomenti accettati.
	 * @return array
	 */
	private function build( $hook, $callback, $priority, $accepted_args ) {
		return array(
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => (int) $priority,
			'accepted_args' => (int) $accepted_args,
		);
	}

	/**
	 * Registra tutti gli hooks accumulati con WordPress.
	 *
	 * @return void
	 */
	public function run() {
		foreach ( $this->filters as $filter ) {
			add_filter( $filter['hook'], $filter['callback'], $filter['priority'], $filter['accepted_args'] );
		}

		foreach ( $this->actions as $action ) {
			add_action( $action['hook'], $action['callback'], $action['priority'], $action['accepted_args'] );
		}
	}
}
