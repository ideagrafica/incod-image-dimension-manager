/* global window, document, jQuery */
/**
 * Incod Image Dimension Manager - admin script.
 *
 * Impostazioni (color picker) e Bulk Resize asincrono con coda FIFO,
 * progress bar, pausa/riprendi/annulla e gestione degli errori.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.idimAdmin === 'undefined' ) {
		return;
	}

	var cfg = window.idimAdmin;
	var S = cfg.strings || {};
	var OPTS = cfg.options || {};

	var queue = [];
	var total = 0;
	var running = false;
	var paused = false;
	var startedAt = 0;
	var elapsedOffset = 0;
	var counters = { processed: 0, skipped: 0, failed: 0 };
	var errors = [];
	var loopTimer = null;
	var restoreTimer = null;
	var busyRetries = 0;
	var restoring = false;
	var restoredCount = 0;
	var el = {};

	/**
	 * Inizializzazione.
	 */
	function init() {
		if ( 'settings' === cfg.page ) {
			initSettingsPage();
			return;
		}

		if ( 'bulk' === cfg.page ) {
			initBulkPage();
		}
	}

	/**
	 * Pagina impostazioni: attiva il color picker e il filtro di ambito.
	 */
	function initSettingsPage() {
		if ( $.fn.wpColorPicker ) {
			$( '.idim-color-picker' ).wpColorPicker( {
				change: function ( event, ui ) {
					$( event.target ).val( ui.color.toString() ).trigger( 'change' );
				}
			} );
		}

		var $scope = $( '.idim-scope-radio' );
		var $types = $( '#idim-post-types-field' );

		function toggleScope() {
			// Con la Media Library intera i tipi di contenuto non hanno
			// effetto: vengono oscurati ma NON disabilitati, così i valori
			// selezionati restano intatti al salvataggio.
			var wholeLibrary = '1' === $scope.filter( ':checked' ).val();

			$types.toggleClass( 'is-disabled', wholeLibrary );
		}

		$scope.on( 'change', toggleScope );
		toggleScope();
	}

	/**
	 * Pagina bulk: collega i controlli e carica lo stato salvato.
	 */
	function initBulkPage() {
		el.panel = $( '#idim-panel' );
		el.status = $( '#idim-status' );
		el.percentage = $( '#idim-percentage' );
		el.bar = $( '#idim-progress-bar' );
		el.processed = $( '#idim-count-processed' );
		el.skipped = $( '#idim-count-skipped' );
		el.failed = $( '#idim-count-failed' );
		el.total = $( '#idim-count-total' );
		el.elapsed = $( '#idim-elapsed' );
		el.eta = $( '#idim-eta' );
		el.log = $( '#idim-log' );
		el.start = $( '#idim-start' );
		el.pause = $( '#idim-pause' );
		el.cancel = $( '#idim-cancel' );
		el.restore = $( '#idim-restore' );

		el.start.on( 'click', start );
		el.pause.on( 'click', togglePause );
		el.cancel.on( 'click', cancel );
		el.restore.on( 'click', restore );

		setStatus( S.idle || 'Idle' );
		loadStatus();
	}

	/**
	 * Richiesta AJAX generica protetta da nonce e con timeout.
	 *
	 * Il timeout (3x il budget di tempo del server) evita che una richiesta
	 * appesa blocchi il loop per sempre.
	 *
	 * @param {string} action Azione WordPress.
	 * @param {Object} data   Dati aggiuntivi.
	 * @return {jQuery.Promise}
	 */
	function request( action, data ) {
		var payload = data || {};
		payload.action = action;
		payload.nonce = cfg.nonce;

		return $.ajax( {
			url: cfg.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			timeout: parseInt( OPTS.ajaxTimeout, 10 ) || 60000,
			data: payload
		} );
	}

	/**
	 * Carica lo stato dell'ultima sessione.
	 */
	function loadStatus() {
		request( 'idim_status' )
			.done( function ( response ) {
				if ( ! response || ! response.success || ! response.data || ! response.data.state ) {
					return;
				}

				var state = response.data.state;

				if ( 'idle' === state.status ) {
					return;
				}

				total = parseInt( state.total, 10 ) || 0;
				counters.processed = parseInt( state.processed, 10 ) || 0;
				counters.skipped = parseInt( state.skipped, 10 ) || 0;
				counters.failed = parseInt( state.failed, 10 ) || 0;
				elapsedOffset = parseInt( response.data.elapsed, 10 ) || 0;

				if ( Array.isArray( state.errors ) ) {
					errors = state.errors;
					renderLog();
				}

				setStatus( statusLabel( state.status ) );
				render();

				if ( 'running' === state.status || 'paused' === state.status ) {
					el.pause.prop( 'disabled', false );
					el.cancel.prop( 'disabled', false );
				}
			} );
	}

	/**
	 * Avvia una nuova elaborazione.
	 */
	function start() {
		if ( running ) {
			return;
		}

		resetLocal();
		setStatus( S.queueTitle || 'Preparing the queue…' );
		el.start.prop( 'disabled', true );
		el.restore.prop( 'disabled', true );
		render();

		request( 'idim_get_queue' )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					fatal( response && response.data && response.data.message ? response.data.message : S.serverError );
					return;
				}

				queue = Array.isArray( response.data.ids ) ? response.data.ids : [];
				total = parseInt( response.data.total, 10 ) || 0;

				if ( response.data.batch_size ) {
					OPTS.batchSize = parseInt( response.data.batch_size, 10 );
				}

				if ( ! total ) {
					setStatus( S.emptyQueue );
					el.start.prop( 'disabled', false );
					el.restore.prop( 'disabled', false );
					render();
					return;
				}

				running = true;
				paused = false;
				startedAt = Date.now();
				elapsedOffset = 0;
				el.pause.prop( 'disabled', false ).text( S.pauseLabel );
				el.cancel.prop( 'disabled', false );
				setStatus( S.runningTitle );
				render();
				run();
			} )
			.fail( function () {
				fatal( S.serverError );
			} );
	}

	/**
	 * Loop ricorsivo: stacca un batch dalla testa della coda FIFO.
	 */
	function run() {
		if ( ! running || paused ) {
			return;
		}

		if ( ! queue.length ) {
			finish();
			return;
		}

		var batchSize = parseInt( OPTS.batchSize, 10 ) || 10;
		var batch = queue.splice( 0, batchSize );

		sendBatch( batch, 1 );
	}

	/**
	 * Invia un batch con tentativi di ripetizione.
	 *
	 * @param {Array}  ids     ID del batch.
	 * @param {number} attempt Tentativo corrente.
	 */
	function sendBatch( ids, attempt ) {
		request( 'idim_process_batch', { ids: ids } )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					retryBatch( ids, attempt, response && response.data ? response.data.message : null );
					return;
				}

				var data = response.data;

				busyRetries = 0;

				counters.processed += parseInt( data.processed, 10 ) || 0;
				counters.skipped += parseInt( data.skipped, 10 ) || 0;
				counters.failed += parseInt( data.failed, 10 ) || 0;

				if ( Array.isArray( data.errors ) && data.errors.length ) {
					errors = errors.concat( data.errors );
					renderLog();
				}

				// Batch parziale: il server ha esaurito il budget di tempo.
				// Gli ID non ancora provati tornano in testa alla coda.
				if ( Array.isArray( data.remaining ) && data.remaining.length ) {
					queue = data.remaining.concat( queue );
				}

				render();

				if ( data.stopped ) {
					stopLoop();
					return;
				}

				schedule( throttleFor( data ) );
			} )
			.fail( function ( xhr ) {
				// Lock occupato: il server è già impegnato, si riprova più tardi.
				if ( xhr && 429 === xhr.status ) {
					waitAndRetryBusy( ids, attempt, xhr );
					return;
				}

				var message = null;

				if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
					message = xhr.responseJSON.data.message;
				}

				retryBatch( ids, attempt, message );
			} );
	}

	/**
	 * Throttle adattivo calcolato dalla risposta del server.
	 *
	 * @param {Object} data Risposta.
	 * @return {number} Millisecondi di attesa.
	 */
	function throttleFor( data ) {
		if ( data && data.throttle ) {
			return parseInt( data.throttle, 10 ) || 300;
		}

		var duration = data && data.duration ? parseFloat( data.duration ) : 0;
		var ms = Math.round( duration * 500 );

		return Math.max( 300, Math.min( 3000, ms ) );
	}

	/**
	 * Attende e rinvia lo stesso batch quando il server è occupato (429).
	 *
	 * @param {Array}  ids     ID del batch.
	 * @param {number} attempt Tentativo corrente.
	 * @param {Object} xhr     Risposta jQuery.
	 */
	function waitAndRetryBusy( ids, attempt, xhr ) {
		if ( ! running || paused ) {
			return;
		}

		if ( busyRetries >= 10 ) {
			busyRetries = 0;
			retryBatch( ids, attempt, S.busy );
			return;
		}

		busyRetries++;

		var retryAfter = 2000;

		if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.retry_after ) {
			retryAfter = ( parseInt( xhr.responseJSON.data.retry_after, 10 ) || 2 ) * 1000;
		}

		setStatus( S.busy );

		loopTimer = window.setTimeout( function () {
			if ( ! running || paused ) {
				return;
			}

			setStatus( S.runningTitle );
			sendBatch( ids, attempt );
		}, retryAfter );
	}

	/**
	 * Riprova un batch o lo registra come errore.
	 *
	 * @param {Array}   ids     ID del batch.
	 * @param {number}  attempt Tentativo corrente.
	 * @param {?string} message Messaggio opzionale.
	 */
	function retryBatch( ids, attempt, message ) {
		if ( attempt < 3 && running && ! paused ) {
			loopTimer = window.setTimeout( function () {
				sendBatch( ids, attempt + 1 );
			}, 1000 * attempt );
			return;
		}

		counters.failed += ids.length;

		$.each( ids, function ( index, id ) {
			errors.push( {
				id: id,
				title: '#' + id,
				message: message || S.serverError
			} );
		} );

		renderLog();
		render();
		schedule();
	}

	/**
	 * Pianifica il passo successivo del loop con throttle adattivo.
	 *
	 * @param {number} delay Millisecondi di attesa (default 300).
	 */
	function schedule( delay ) {
		if ( ! running || paused ) {
			return;
		}

		var wait = parseInt( delay, 10 );

		if ( isNaN( wait ) ) {
			wait = 300;
		}

		loopTimer = window.setTimeout( run, Math.max( 120, wait ) );
	}

	/**
	 * Ferma il loop su richiesta del server (sessione annullata).
	 */
	function stopLoop() {
		window.clearTimeout( loopTimer );
		running = false;
		paused = false;
		queue = [];
		setStatus( S.cancelledTitle );
		el.pause.prop( 'disabled', true ).text( S.pauseLabel );
		el.cancel.prop( 'disabled', true );
		el.start.prop( 'disabled', false );
		el.restore.prop( 'disabled', false );
		render();
	}

	/**
	 * Mette in pausa o riprende l'elaborazione.
	 */
	function togglePause() {
		if ( ! running ) {
			return;
		}

		if ( ! paused ) {
			paused = true;
			window.clearTimeout( loopTimer );
			elapsedOffset = elapsedSeconds();
			startedAt = 0;
			setStatus( S.pausedTitle );
			el.pause.text( S.resumeLabel );
			sendControl( 'pause' );
		} else {
			paused = false;
			startedAt = Date.now();
			setStatus( S.runningTitle );
			el.pause.text( S.pauseLabel );
			sendControl( 'resume' );
			run();
		}

		render();
	}

	/**
	 * Annulla l'elaborazione corrente.
	 */
	function cancel() {
		if ( ! window.confirm( S.cancelConfirm ) ) {
			return;
		}

		window.clearTimeout( loopTimer );
		running = false;
		paused = false;
		queue = [];
		sendControl( 'cancel' );
		resetLocal();
		setStatus( S.cancelledTitle );
		render();
	}

	/**
	 * Ripristina i file originali dai backup, un lotto per richiesta.
	 */
	function restore() {
		if ( restoring || ! window.confirm( S.restoreConfirm ) ) {
			return;
		}

		restoring = true;
		restoredCount = 0;
		el.restore.prop( 'disabled', true );
		restoreStep( 1 );
	}

	/**
	 * Esegue un lotto di ripristino e ripianifica il successivo.
	 *
	 * Il server elabora al massimo RESTORE_PER_REQUEST cartelle o TIME_BUDGET
	 * secondi per chiamata: nessuna richiesta singola può andare in timeout.
	 *
	 * @param {number} attempt Tentativo corrente.
	 */
	function restoreStep( attempt ) {
		request( 'idim_restore' )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					restoreFailed( response && response.data && response.data.message ? response.data.message : null );
					return;
				}

				var data = response.data;

				restoredCount += parseInt( data.restored, 10 ) || 0;
				el.restore.text( S.restoreLabel + ' (' + restoredCount + ')' );

				if ( data.done || ! ( parseInt( data.remaining, 10 ) || 0 ) ) {
					restoreFinished();
					return;
				}

				restoreTimer = window.setTimeout( function () {
					restoreStep( 1 );
				}, Math.max( 300, parseInt( data.throttle, 10 ) || 800 ) );
			} )
			.fail( function ( xhr ) {
				if ( xhr && 429 === xhr.status && attempt < 10 ) {
					var retryAfter = 2000;

					if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.retry_after ) {
						retryAfter = ( parseInt( xhr.responseJSON.data.retry_after, 10 ) || 2 ) * 1000;
					}

					restoreTimer = window.setTimeout( function () {
						restoreStep( attempt + 1 );
					}, retryAfter );
					return;
				}

				restoreFailed( xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : null );
			} );
	}

	/**
	 * Ripristino completato.
	 */
	function restoreFinished() {
		window.clearTimeout( restoreTimer );
		restoring = false;
		el.restore.prop( 'disabled', false ).text( S.restoreLabel );

		if ( restoredCount > 0 ) {
			window.alert( String( S.restoreDone || '%d images restored.' ).replace( '%d', restoredCount ) );
		}
	}

	/**
	 * Ripristino interrotto da un errore.
	 *
	 * @param {?string} message Messaggio opzionale.
	 */
	function restoreFailed( message ) {
		window.clearTimeout( restoreTimer );
		restoring = false;
		el.restore.prop( 'disabled', false ).text( S.restoreLabel );
		window.alert( message || S.serverError );
	}

	/**
	 * Invia un comando di controllo allo server.
	 *
	 * @param {string} controlAction Azione.
	 */
	function sendControl( controlAction ) {
		request( 'idim_control', { control_action: controlAction } );
	}

	/**
	 * Elaborazione terminata con successo.
	 */
	function finish() {
		running = false;
		paused = false;
		elapsedOffset = elapsedSeconds();
		startedAt = 0;
		setStatus( S.doneTitle );
		el.pause.prop( 'disabled', true ).text( S.pauseLabel );
		el.cancel.prop( 'disabled', true );
		el.start.prop( 'disabled', false );
		el.restore.prop( 'disabled', false );
		render();
	}

	/**
	 * Errore fatale: riattiva i controlli.
	 *
	 * @param {string} message Messaggio.
	 */
	function fatal( message ) {
		running = false;
		paused = false;
		setStatus( message || S.serverError );
		el.start.prop( 'disabled', false );
		el.pause.prop( 'disabled', true );
		el.cancel.prop( 'disabled', true );
		el.restore.prop( 'disabled', false );
	}

	/**
	 * Azzera i controlli locali.
	 */
	function resetLocal() {
		queue = [];
		total = 0;
		counters = { processed: 0, skipped: 0, failed: 0 };
		errors = [];
		elapsedOffset = 0;
		startedAt = 0;
		renderLog();
	}

	/**
	 * Secondi totali trascorsi.
	 *
	 * @return {number}
	 */
	function elapsedSeconds() {
		var base = elapsedOffset;

		if ( running && ! paused && startedAt ) {
			base += ( Date.now() - startedAt ) / 1000;
		}

		return Math.max( 0, Math.floor( base ) );
	}

	/**
	 * Aggiorna la UI.
	 */
	function render() {
		var done = counters.processed + counters.skipped + counters.failed;
		var percent = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		var elapsed = elapsedSeconds();

		el.bar.css( 'width', percent + '%' ).attr( 'aria-valuenow', percent );
		el.percentage.text( percent + '%' );
		el.processed.text( counters.processed );
		el.skipped.text( counters.skipped );
		el.failed.text( counters.failed );
		el.total.text( total );
		el.elapsed.text( formatDuration( elapsed ) );
		el.eta.text( estimateRemaining( done, elapsed ) );
	}

	/**
	 * Formatta una durata in secondi.
	 *
	 * @param {number} seconds Secondi.
	 * @return {string}
	 */
	function formatDuration( seconds ) {
		seconds = Math.max( 0, parseInt( seconds, 10 ) || 0 );

		if ( seconds < 60 ) {
			return seconds + 's';
		}

		var minutes = Math.floor( seconds / 60 );
		var rest = seconds % 60;

		if ( minutes < 60 ) {
			return minutes + 'm ' + rest + 's';
		}

		return Math.floor( minutes / 60 ) + 'h ' + ( minutes % 60 ) + 'm';
	}

	/**
	 * Stima il tempo residuo.
	 *
	 * @param {number} done    Elementi già gestiti.
	 * @param {number} elapsed Secondi trascorsi.
	 * @return {string}
	 */
	function estimateRemaining( done, elapsed ) {
		if ( ! running || paused || done < 1 || elapsed < 1 || total <= done ) {
			return '—';
		}

		var remaining = total - done;
		var rate = done / elapsed;

		if ( rate <= 0 ) {
			return '—';
		}

		return formatDuration( Math.ceil( remaining / rate ) );
	}

	/**
	 * Aggiorna l'etichetta di stato.
	 *
	 * @param {string} text Testo.
	 */
	function setStatus( text ) {
		el.status.text( text );
	}

	/**
	 * Etichetta di stato tradotta.
	 *
	 * @param {string} status Stato.
	 * @return {string}
	 */
	function statusLabel( status ) {
		var map = {
			idle: S.idle || 'Idle',
			running: S.runningTitle,
			paused: S.pausedTitle,
			done: S.doneTitle,
			cancelled: S.cancelledTitle
		};

		return map[ status ] || status;
	}

	/**
	 * Render della lista degli errori.
	 */
	function renderLog() {
		if ( ! el.log ) {
			return;
		}

		el.log.empty();

		if ( ! errors.length ) {
			$( '<li/>', { 'class': 'idim-log-empty', text: S.noErrors } ).appendTo( el.log );
			return;
		}

		$.each( errors.slice( 0, 50 ), function ( index, error ) {
			$( '<li/>', {
				'class': 'idim-log-item',
				text: ( error.title || ( '#' + error.id ) ) + ' — ' + ( error.message || S.serverError )
			} ).appendTo( el.log );
		} );
	}

	$( init );
}( jQuery ) );
