<?php

form_security_validate( 'plugin_SemanticSearch_attachment_index_action' );
access_ensure_project_level( plugin_config_get( 'search_access_level' ) );

$t_bug_id = gpc_get_int( 'bug_id' );
$t_mode = gpc_get_string( 'mode', 'save_policy' );
$t_attachment_ids = gpc_get_int_array( 'attachment_ids', array() );
$t_note_ids = gpc_get_int_array( 'note_ids', array() );
$t_core_indexable = gpc_get_bool( 'core_indexable', false );
$t_similar_limit = gpc_get_int( 'similar_limit', 10 );
$t_similar_min_score = (float)gpc_get_string( 'similar_min_score', '0.3' );

function semsearch_call_responses_api( $p_api_key, $p_prompt ) {
	if( $p_api_key === '' ) {
		return 'No se pudo generar solución: falta OPENAI_API_KEY.';
	}
	$t_payload = json_encode( array(
		'model' => 'gpt-4.1-mini',
		'input' => $p_prompt,
	), JSON_UNESCAPED_UNICODE );

	$t_ch = curl_init( 'https://api.openai.com/v1/responses' );
	curl_setopt_array( $t_ch, array(
		CURLOPT_POST => true,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => array(
			'Authorization: Bearer ' . $p_api_key,
			'Content-Type: application/json',
		),
		CURLOPT_POSTFIELDS => $t_payload,
		CURLOPT_TIMEOUT => 60,
	));
	$t_raw = curl_exec( $t_ch );
	$t_err = curl_error( $t_ch );
	curl_close( $t_ch );
	if( $t_raw === false ) {
		return 'No se pudo generar solución: error de red (' . $t_err . ').';
	}
	$t_data = json_decode( $t_raw, true );
	if( !is_array( $t_data ) ) {
		return 'No se pudo generar solución: respuesta inválida del modelo.';
	}
	if( isset( $t_data['output_text'] ) && trim( (string)$t_data['output_text'] ) !== '' ) {
		return trim( (string)$t_data['output_text'] );
	}
	if( isset( $t_data['output'] ) && is_array( $t_data['output'] ) ) {
		$t_chunks = array();
		foreach( $t_data['output'] as $t_item ) {
			if( isset( $t_item['content'] ) && is_array( $t_item['content'] ) ) {
				foreach( $t_item['content'] as $t_content ) {
					if( isset( $t_content['text'] ) ) {
						$t_chunks[] = (string)$t_content['text'];
					}
				}
			}
		}
		if( !empty( $t_chunks ) ) {
			return trim( implode( "\n", $t_chunks ) );
		}
	}
	return 'No se pudo generar solución: respuesta sin contenido útil.';
}

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

	if( $t_mode === 'similar_now' || $t_mode === 'solution_now' ) {
		if( $t_similar_limit < 1 ) { $t_similar_limit = 10; }
		if( $t_similar_limit > 50 ) { $t_similar_limit = 50; }
		if( $t_similar_min_score < 0 ) { $t_similar_min_score = 0; }
		if( $t_similar_min_score > 1 ) { $t_similar_min_score = 1; }

		require_once( __DIR__ . '/../core/SemanticSearchService.php' );
		$t_service = new SemanticSearchService( $t_plugin );
		$t_bug = bug_get( $t_bug_id, true );
		$t_context = $t_indexer->build_index_text( $t_bug, array(), $t_dashboard );
		if( trim( $t_context ) === '' ) {
			$t_context = trim( (string)$t_bug->summary . "\n" . (string)$t_bug->description );
		}
		$t_query = $t_mode === 'solution_now' ? ("¿Qué solución tiene este problema?\n\n" . $t_context) : $t_context;
		$t_results = $t_service->search( $t_query, $t_similar_limit + 5, $t_similar_min_score, (int)$t_bug->project_id, null );

		$t_lines = array();
		$t_now = date( 'Y-m-d H:i:s' );
		$t_lines[] = ( $t_mode === 'solution_now' ? 'Posible solución automática' : 'Incidentes similares' ) . ' (' . $t_now . ')';
		$t_lines[] = 'Filtros usados: cantidad=' . $t_similar_limit . ', score mínimo=' . $t_similar_min_score . ', proyecto=' . (int)$t_bug->project_id . '.';

		$t_count = 0;
		$t_similar_lines = array();
		foreach( $t_results as $t_row ) {
			$t_candidate_id = isset( $t_row['issue_id'] ) ? (int)$t_row['issue_id'] : 0;
			if( $t_candidate_id <= 0 || $t_candidate_id === (int)$t_bug_id ) {
				continue;
			}
			$t_count++;
			$t_similar_lines[] = '- #' . $t_candidate_id . ' | score=' . sprintf( '%.4f', (float)$t_row['score'] ) . ' | ' . (string)$t_row['summary'];
			if( $t_count >= $t_similar_limit ) {
				break;
			}
		}

		if( $t_mode === 'solution_now' ) {
			if( $t_count === 0 ) {
				$t_lines[] = 'No se encontró contexto semántico suficiente para resolver con propiedad este incidente.';
				$t_fallback_prompt = "No tengo contexto semántico de incidentes similares para este caso. Problema actual:\n" . $t_context . "\n\nDame una posible solución inicial, aclarando supuestos y riesgos.";
				$t_api_key = (string)$t_plugin->get_setting( 'openai_api_key', '', 'OPENAI_API_KEY' );
				$t_solution = semsearch_call_responses_api( $t_api_key, $t_fallback_prompt );
				$t_lines[] = '';
				$t_lines[] = 'Posible solución sugerida por IA (sin contexto histórico):';
				$t_lines[] = $t_solution;
			} else {
				$t_lines[] = 'Se encontraron ' . $t_count . ' incidentes similares y se usaron como contexto interno para generar la solución.';
				$t_prompt = "Problema actual:\n" . $t_context . "\n\nIncidentes similares:\n" . implode( "\n", $t_similar_lines ) . "\n\nDame una solución concreta para este problema basada en la información anterior.";
				$t_api_key = (string)$t_plugin->get_setting( 'openai_api_key', '', 'OPENAI_API_KEY' );
				$t_solution = semsearch_call_responses_api( $t_api_key, $t_prompt );
				$t_lines[] = '';
				$t_lines[] = 'Posible solución sugerida por IA:';
				$t_lines[] = $t_solution;
			}
			bugnote_add( $t_bug_id, implode( "\n", $t_lines ) );
		} else {
			if( $t_count === 0 ) {
				$t_lines[] = 'No hay incidentes similares a la fecha con esos filtros.';
			} else {
				$t_lines[] = 'Total de incidentes similares encontrados: ' . $t_count . '.';
				$t_lines[] = 'Incidentes similares:';
				foreach( $t_similar_lines as $t_l ) {
					$t_lines[] = $t_l;
				}
			}
			bugnote_add( $t_bug_id, implode( "\n", $t_lines ) );
		}
	}
} catch( Throwable $e ) {
	log_event( LOG_PLUGIN, '[SemanticSearch] attachment_index_action failed for issue #' . $t_bug_id . ': ' . $e->getMessage() );
}

form_security_purge( 'plugin_SemanticSearch_attachment_index_action' );
print_header_redirect( string_get_bug_view_url( $t_bug_id ) );
