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

/** @return int unix ts start/end day */
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

if( $t_ajax ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	$t_filters = semsearch_get_filters();
	if( ( !isset( $t_filters['project_id'] ) || $t_filters['project_id'] === null ) && ( !isset( $t_filters['issue_id'] ) || (int)$t_filters['issue_id'] <= 0 ) ) {
		echo json_encode( array( 'ok' => false, 'error' => 'Debe indicar Proyecto o Issue ID.' ) );
		return;
	}

	try {
		if( $t_mode === 'estimate' ) {
			$t_stats = $t_indexer->collect_reindex_stats_filtered( $t_filters );
			echo json_encode( array( 'ok' => true ) + $t_stats );
			return;
		}

		if( $t_mode === 'force_unlock' ) {
			$t_scope_type = gpc_get_string( 'scope_type', 'all' );
			$t_scope_project_id = gpc_get_int( 'scope_project_id', 0 );
			$t_jobs->force_unlock_scope( $t_scope_type, $t_scope_project_id );
			echo json_encode( array( 'ok' => true ) );
			return;
		}

		if( $t_mode === 'policy_batch' || $t_mode === 'batch' ) {
			$t_last_id = gpc_get_int( 'last_id', 0 );
			$t_processed = gpc_get_int( 'processed', 0 );
			$t_batch_size = gpc_get_int( 'batch_size', 25 );
			$t_run_id = gpc_get_string( 'run_id', '' );
			if( $t_run_id === '' ) {
				echo json_encode( array( 'ok' => false, 'error' => 'run_id requerido' ) );
				return;
			}
			$t_scope_type = ( isset( $t_filters['project_id'] ) && (int)$t_filters['project_id'] === 0 ) ? 'all' : 'project';
			$t_scope_project_id = ( $t_scope_type === 'project' ) ? (int)$t_filters['project_id'] : 0;
			if( $t_last_id === 0 && $t_processed === 0 ) {
				$t_lock = $t_jobs->acquire_lock( $t_mode === 'policy_batch' ? 'policy' : 'vectorize', $t_scope_type, $t_scope_project_id, $t_run_id );
				if( empty( $t_lock['ok'] ) ) {
					echo json_encode( array( 'ok' => false, 'error' => 'Hay otro proceso ejecutándose para este alcance.' ) );
					return;
				}
			}
			$t_jobs->heartbeat( $t_run_id );
			$t_state = $t_mode === 'policy_batch'
				? $t_indexer->process_policy_batch_filtered( $t_filters, $t_last_id, $t_batch_size, $t_processed )
				: $t_indexer->reindex_batch_filtered( $t_filters, $t_last_id, $t_batch_size, $t_processed );
			if( !empty( $t_state['done'] ) ) {
				$t_jobs->release( $t_run_id );
			}
			echo json_encode( array( 'ok' => true ) + $t_state );
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

# backward compatible manual action
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
