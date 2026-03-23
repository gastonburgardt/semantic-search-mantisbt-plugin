<?php

access_ensure_project_level( plugin_config_get( 'search_access_level' ) );

$t_bug_id = gpc_get_int( 'bug_id' );
$t_mode = gpc_get_string( 'mode', 'save_policy' );
$t_attachment_ids = gpc_get_int_array( 'attachment_ids', array() );
$t_note_ids = gpc_get_int_array( 'note_ids', array() );
$t_core_indexable = gpc_get_bool( 'core_indexable', false );
$t_similar_limit = gpc_get_int( 'similar_limit', 10 );
$t_similar_min_score = (float)gpc_get_string( 'similar_min_score', '0.3' );

try {
	$t_plugin = plugin_get( 'SemanticSearch' );
	$t_indexer = new IssueIndexer( $t_plugin );
	$t_dashboard = $t_indexer->get_issue_index_dashboard( $t_bug_id );

	$t_note_flags = array();
	if( isset( $t_dashboard['notes'] ) && is_array( $t_dashboard['notes'] ) ) {
		foreach( $t_dashboard['notes'] as $t_note ) {
			$t_note_flags[(int)$t_note['id']] = in_array( (int)$t_note['id'], $t_note_ids, true );
		}
	}

	$t_attachment_flags = array();
	if( isset( $t_dashboard['attachments'] ) && is_array( $t_dashboard['attachments'] ) ) {
		foreach( $t_dashboard['attachments'] as $t_file ) {
			$t_attachment_flags[(int)$t_file['id']] = in_array( (int)$t_file['id'], $t_attachment_ids, true );
		}
	}

	$t_indexer->update_issue_index_policy( $t_bug_id, $t_core_indexable, $t_note_flags, $t_attachment_flags );

	if( $t_mode === 'index_now' ) {
		$t_indexer->index_issue( $t_bug_id, array( 'attachment_mode' => 'all' ) );
	}

	if( $t_mode === 'similar_now' ) {
		if( $t_similar_limit < 1 ) { $t_similar_limit = 10; }
		if( $t_similar_limit > 50 ) { $t_similar_limit = 50; }
		if( $t_similar_min_score < 0 ) { $t_similar_min_score = 0; }
		if( $t_similar_min_score > 1 ) { $t_similar_min_score = 1; }

		require_once( __DIR__ . '/../core/SemanticSearchService.php' );
		$t_service = new SemanticSearchService( $t_plugin );
		$t_bug = bug_get( $t_bug_id, true );
		$t_query = trim( (string)$t_bug->summary . "\n" . (string)$t_bug->description );
		$t_results = $t_service->search( $t_query, $t_similar_limit + 5, $t_similar_min_score, (int)$t_bug->project_id, null );

		$t_lines = array();
		$t_now = date( 'Y-m-d H:i:s' );
		$t_lines[] = 'Similitudes automáticas (' . $t_now . ')';
		$t_lines[] = 'Filtros: cantidad=' . $t_similar_limit . ', score mínimo=' . $t_similar_min_score . ', proyecto=' . (int)$t_bug->project_id . '.';

		$t_count = 0;
		foreach( $t_results as $t_row ) {
			$t_candidate_id = isset( $t_row['issue_id'] ) ? (int)$t_row['issue_id'] : 0;
			if( $t_candidate_id <= 0 || $t_candidate_id === (int)$t_bug_id ) {
				continue;
			}
			$t_count++;
			$t_lines[] = '- #' . $t_candidate_id . ' | score=' . sprintf( '%.4f', (float)$t_row['score'] ) . ' | ' . (string)$t_row['summary'];
			if( $t_count >= $t_similar_limit ) {
				break;
			}
		}

		if( $t_count === 0 ) {
			$t_lines[] = 'No hay incidentes similares a la fecha con esos filtros.';
		} else {
			$t_lines[] = 'Total de incidentes similares encontrados: ' . $t_count . '.';
		}

		bugnote_add( $t_bug_id, implode( "\n", $t_lines ) );
	}
} catch( Throwable $e ) {
	log_event( LOG_PLUGIN, '[SemanticSearch] attachment_index_action failed for issue #' . $t_bug_id . ': ' . $e->getMessage() );
}

print_header_redirect( string_get_bug_view_url( $t_bug_id ) );
