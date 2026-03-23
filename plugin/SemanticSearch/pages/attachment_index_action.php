<?php

access_ensure_project_level( plugin_config_get( 'search_access_level' ) );

$t_bug_id = gpc_get_int( 'bug_id' );
$t_mode = gpc_get_string( 'mode', 'save_policy' );
$t_attachment_ids = gpc_get_int_array( 'attachment_ids', array() );
$t_note_ids = gpc_get_int_array( 'note_ids', array() );
$t_core_indexable = gpc_get_bool( 'core_indexable', false );

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
} catch( Throwable $e ) {
	log_event( LOG_PLUGIN, '[SemanticSearch] attachment_index_action failed for issue #' . $t_bug_id . ': ' . $e->getMessage() );
}

print_header_redirect( string_get_bug_view_url( $t_bug_id ) );
