<?php

$t_mode = gpc_get_string( 'mode', 'single' );
$t_ajax = gpc_get_bool( 'ajax', false );

try {
	access_ensure_global_level( plugin_config_get( 'admin_access_level' ) );
	require_once __DIR__ . '/../SemanticSearch.php';
	require_once __DIR__ . '/../core/OpenAIEmbeddingClient.php';
	require_once __DIR__ . '/../core/QdrantClient.php';
	require_once __DIR__ . '/../core/IssueIndexer.php';
	require_once __DIR__ . '/../core/JobControl.php';
	$t_plugin = new SemanticSearchPlugin( 'SemanticSearch' );
	$t_plugin->init();
	$t_indexer = new IssueIndexer( $t_plugin );
	$t_jobs = new SemanticSearchJobControl();
} catch( Throwable $e ) {
	if( $t_ajax ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		echo json_encode( array( 'ok' => false, 'error' => 'bootstrap: ' . $e->getMessage() ) );
		return;
	}
	throw $e;
}

function semsearch_parse_date( $p_value, $p_end_of_day = false ) {
	$t_value = trim( (string)$p_value );
	if( $t_value === '' ) {
		return 0;
	}
	$t_ts = strtotime( $t_value . ( $p_end_of_day ? ' 23:59:59' : ' 00:00:00' ) );
	return $t_ts ? (int)$t_ts : 0;
}

function semsearch_get_filters() {
	$t_project_raw = gpc_get_string( 'project_id', '' );
	$t_project_id = null;
	if( $t_project_raw !== '' ) {
		$t_project_id = (int)$t_project_raw;
	}

	return array(
		'project_id' => $t_project_id,
		'issue_id' => gpc_get_int( 'issue_id', 0 ),
		'max_issues' => gpc_get_int( 'max_issues', 0 ),
		'created_from_ts' => semsearch_parse_date( gpc_get_string( 'created_from', '' ), false ),
		'created_to_ts' => semsearch_parse_date( gpc_get_string( 'created_to', '' ), true ),
		'pending_only' => gpc_get_bool( 'pending_only', true ),
		'force_revectorize' => gpc_get_bool( 'force_revectorize', false ),
	);
}

function semsearch_spawn_background_worker( $p_run_id, $p_kind, $p_batch_size ) {
	$t_php = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
	$t_worker = __DIR__ . '/reindex_worker.php';
	$t_log = sys_get_temp_dir() . '/semsearch_' . preg_replace( '/[^a-zA-Z0-9_-]/', '', $p_run_id ) . '.log';
	$t_cmd = sprintf(
		"nohup %s %s --run_id=%s --kind=%s --batch_size=%d > %s 2>&1 & echo $!",
		escapeshellarg( $t_php ),
		escapeshellarg( $t_worker ),
		escapeshellarg( $p_run_id ),
		escapeshellarg( $p_kind ),
		(int)$p_batch_size,
		escapeshellarg( $t_log )
	);
	$t_out = array();
	$t_code = 1;
	@exec( $t_cmd, $t_out, $t_code );
	if( $t_code !== 0 ) {
		throw new RuntimeException( 'No se pudo iniciar worker background.' );
	}
	$t_pid = isset( $t_out[0] ) ? (int)trim( $t_out[0] ) : 0;
	return array( 'pid' => $t_pid, 'log' => $t_log );
}

if( $t_ajax ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	$t_filters = semsearch_get_filters();
	$t_jobs->unlock_stale_locks( 120 );

	try {
		if( $t_mode === 'estimate' ) {
			if( ( !isset( $t_filters['project_id'] ) || $t_filters['project_id'] === null ) && ( !isset( $t_filters['issue_id'] ) || (int)$t_filters['issue_id'] <= 0 ) ) {
				echo json_encode( array( 'ok' => false, 'error' => 'Debe indicar Proyecto o Issue ID.' ) );
				return;
			}
			$t_stats = $t_indexer->collect_reindex_stats_filtered( $t_filters );
			echo json_encode( array( 'ok' => true ) + $t_stats );
			return;
		}

		if( $t_mode === 'start_policy' || $t_mode === 'start_vector' ) {
			if( ( !isset( $t_filters['project_id'] ) || $t_filters['project_id'] === null ) && ( !isset( $t_filters['issue_id'] ) || (int)$t_filters['issue_id'] <= 0 ) ) {
				echo json_encode( array( 'ok' => false, 'error' => 'Debe indicar Proyecto o Issue ID.' ) );
				return;
			}
			list( $scope_type, $scope_project_id ) = $t_jobs->compute_scope( $t_filters );
			$t_kind = $t_mode === 'start_policy' ? 'policy' : 'vectorize';
			$t_run_id = $t_kind . '_' . date( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 3 ) );
			$t_batch_size = max( 1, gpc_get_int( 'batch_size', 25 ) );
			$t_total = (int)$t_indexer->count_reindex_candidates_filtered( $t_filters );
			$t_lock = $t_jobs->acquire_lock( $t_kind, $scope_type, $scope_project_id, $t_run_id );
			if( empty( $t_lock['ok'] ) ) {
				echo json_encode( array( 'ok' => false, 'error' => 'Hay otro proceso ejecutándose para este alcance.', 'run_id' => isset( $t_lock['run_id'] ) ? $t_lock['run_id'] : '' ) );
				return;
			}
			$t_jobs->create_run( $t_kind, $scope_type, $scope_project_id, $t_run_id, $t_filters, $t_total );
			$t_spawn = semsearch_spawn_background_worker( $t_run_id, $t_kind, $t_batch_size );
			echo json_encode( array( 'ok' => true, 'run_id' => $t_run_id, 'total' => $t_total, 'pid' => $t_spawn['pid'] ) );
			return;
		}

		if( $t_mode === 'status' ) {
			$t_run_id = gpc_get_string( 'run_id', '' );
			if( $t_run_id !== '' ) {
				$t_run = $t_jobs->get_run( $t_run_id );
				echo json_encode( array( 'ok' => true, 'run' => $t_run ) );
				return;
			}
			$t_kind = gpc_get_string( 'kind', 'vectorize' );
			list( $scope_type, $scope_project_id ) = $t_jobs->compute_scope( $t_filters );
			$t_run = $t_jobs->get_last_run( $t_kind, $scope_type, $scope_project_id );
			echo json_encode( array( 'ok' => true, 'run' => $t_run ) );
			return;
		}

		if( $t_mode === 'stop' ) {
			$t_run_id = gpc_get_string( 'run_id', '' );
			if( $t_run_id === '' ) {
				echo json_encode( array( 'ok' => false, 'error' => 'run_id requerido' ) );
				return;
			}
			$t_jobs->request_stop( $t_run_id );
			echo json_encode( array( 'ok' => true ) );
			return;
		}

		if( $t_mode === 'force_unlock' ) {
			$t_scope_type = gpc_get_string( 'scope_type', 'all' );
			$t_scope_project_id = gpc_get_int( 'scope_project_id', 0 );
			$t_jobs->force_unlock_scope( $t_scope_type, $t_scope_project_id );
			echo json_encode( array( 'ok' => true ) );
			return;
		}
	} catch( Throwable $e ) {
		log_event( LOG_PLUGIN, '[SemanticSearch] AJAX reindex failed: ' . $e->getMessage() );
		echo json_encode( array( 'ok' => false, 'error' => $e->getMessage() ) );
		return;
	}

	echo json_encode( array( 'ok' => false, 'error' => 'Invalid mode' ) );
	return;
}

form_security_validate( 'plugin_SemanticSearch_reindex' );
try {
	if( $t_mode === 'single' ) {
		$t_issue_id = gpc_get_int( 'issue_id' );
		$t_indexer->index_issue( $t_issue_id );
		log_event( LOG_PLUGIN, '[SemanticSearch] Manual reindex completed for issue #' . $t_issue_id );
	}
} catch( Throwable $e ) {
	log_event( LOG_PLUGIN, '[SemanticSearch] Reindex action failed: ' . $e->getMessage() );
}
form_security_purge( 'plugin_SemanticSearch_reindex' );
print_header_redirect( plugin_page( 'reindex', true ) );
