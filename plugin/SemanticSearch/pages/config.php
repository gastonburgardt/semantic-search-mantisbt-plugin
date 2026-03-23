<?php

form_security_validate( 'plugin_SemanticSearch_config' );
access_ensure_global_level( config_get( 'manage_plugin_threshold' ) );

function semantic_config_set_if_needed( $p_name, $p_value ) {
	if( $p_value != plugin_config_get( $p_name ) ) {
		plugin_config_set( $p_name, $p_value );
	}
}

semantic_config_set_if_needed( 'enabled', gpc_get_bool( 'enabled', false ) ? ON : OFF );
semantic_config_set_if_needed( 'qdrant_url', gpc_get_string( 'qdrant_url', plugin_config_get( 'qdrant_url' ) ) );
semantic_config_set_if_needed( 'qdrant_collection', gpc_get_string( 'qdrant_collection', plugin_config_get( 'qdrant_collection' ) ) );
semantic_config_set_if_needed( 'openai_embedding_model', gpc_get_string( 'openai_embedding_model', plugin_config_get( 'openai_embedding_model' ) ) );
semantic_config_set_if_needed( 'top_k', gpc_get_int( 'top_k', plugin_config_get( 'top_k' ) ) );
semantic_config_set_if_needed( 'min_score', (float)gpc_get_string( 'min_score', (string)plugin_config_get( 'min_score' ) ) );
semantic_config_set_if_needed( 'include_notes', gpc_get_bool( 'include_notes', false ) ? ON : OFF );
semantic_config_set_if_needed( 'include_attachments', gpc_get_bool( 'include_attachments', false ) ? ON : OFF );
semantic_config_set_if_needed( 'attachment_extensions', gpc_get_string( 'attachment_extensions', plugin_config_get( 'attachment_extensions' ) ) );
semantic_config_set_if_needed( 'index_statuses', gpc_get_string( 'index_statuses', plugin_config_get( 'index_statuses' ) ) );
semantic_config_set_if_needed( 'remove_on_unresolved', gpc_get_bool( 'remove_on_unresolved', false ) ? ON : OFF );

form_security_purge( 'plugin_SemanticSearch_config' );
print_header_redirect( plugin_page( 'config_page', true ) );
