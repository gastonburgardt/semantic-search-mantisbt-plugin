<?php

class SemanticSearchService {
	private $plugin;
	private $openai;
	private $qdrant;

	public function __construct( SemanticSearchPlugin $p_plugin ) {
		$this->plugin = $p_plugin;
		$this->openai = new OpenAIEmbeddingClient( $p_plugin );
		$this->qdrant = new QdrantClient( $p_plugin );
	}

	public function search( $p_query, $p_limit = null, $p_min_score = null, $p_project_id = null, $p_issue_id = null ) {
		$t_query = trim( (string)$p_query );
		if( $t_query === '' ) {
			return array();
		}

		$t_limit = $p_limit !== null ? (int)$p_limit : (int)$this->plugin->get_setting( 'top_k', 10, 'SEMSEARCH_TOP_K' );
		if( $t_limit < 1 ) {
			$t_limit = 10;
		}

		$t_min_score = $p_min_score !== null ? (float)$p_min_score : (float)$this->plugin->get_setting( 'min_score', 0, 'SEMSEARCH_MIN_SCORE' );
		$t_project_id = $p_project_id === null ? null : (int)$p_project_id;
		$t_issue_id = $p_issue_id === null ? 0 : (int)$p_issue_id;
		$t_project_name_filter = null;
		if( $t_project_id !== null && $t_project_id > 0 ) {
			$t_project_name_filter = project_get_name( $t_project_id );
		}

		$t_query_vector = $this->openai->embed( $t_query );
		$t_fetch_limit = max( $t_limit, 50 );
		$t_results = $this->qdrant->search( $t_query_vector, $t_fetch_limit, $t_min_score, $t_project_id, $t_project_name_filter );

		$t_response = array();
		foreach( $t_results as $t_row ) {
			$t_payload = isset( $t_row['payload'] ) && is_array( $t_row['payload'] ) ? $t_row['payload'] : array();
			$t_issue_id_row = isset( $t_payload['issue_id'] ) ? (int)$t_payload['issue_id'] : (int)$t_row['id'];
			if( $t_issue_id > 0 && $t_issue_id_row !== $t_issue_id ) {
				continue;
			}
			$t_project_name_row = isset( $t_payload['project_name'] ) ? (string)$t_payload['project_name'] : '';
			$t_project_id_row = isset( $t_payload['project_id'] ) ? (int)$t_payload['project_id'] : 0;
			if( $t_project_name_filter !== null ) {
				if( $t_project_id > 0 && $t_project_id_row > 0 && $t_project_id_row !== $t_project_id ) {
					continue;
				}
				if( $t_project_id_row <= 0 && $t_project_name_row !== '' && $t_project_name_row !== $t_project_name_filter ) {
					continue;
				}
			}

			$t_response[] = array(
				'issue_id' => $t_issue_id_row,
				'issue_number' => isset( $t_payload['issue_number'] ) ? $t_payload['issue_number'] : bug_format_id( $t_issue_id_row ),
				'summary' => isset( $t_payload['summary'] ) ? $t_payload['summary'] : '',
				'project_name' => $t_project_name_row,
				'category' => isset( $t_payload['category'] ) ? $t_payload['category'] : '',
				'status' => isset( $t_payload['status'] ) ? $t_payload['status'] : '',
				'score' => isset( $t_row['score'] ) ? (float)$t_row['score'] : 0,
				'url' => string_get_bug_view_url( $t_issue_id_row ),
			);
			if( count( $t_response ) >= $t_limit ) {
				break;
			}
		}

		return $t_response;
	}
}
