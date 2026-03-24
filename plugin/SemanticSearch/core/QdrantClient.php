<?php

class QdrantClient {
	private $plugin;

	public function __construct( SemanticSearchPlugin $p_plugin ) {
		$this->plugin = $p_plugin;
	}

	public function get_collection_name() {
		return $this->plugin->get_setting( 'qdrant_collection', 'mantis_resolved_issues', 'SEMSEARCH_QDRANT_COLLECTION' );
	}

	public function collection_name_for_project( $p_project_id, $p_project_name = '' ) {
		$t_project_id = (int)$p_project_id;
		if( $t_project_id <= 0 ) {
			return $this->get_collection_name();
		}
		$t_name = trim( (string)$p_project_name );
		if( $t_name === '' ) {
			$t_name = project_get_name( $t_project_id );
		}
		$t_slug = $this->slugify( $t_name );
		if( $t_slug === '' ) {
			$t_slug = 'project';
		}
		return $t_project_id . '_' . $t_slug;
	}

	public function ensure_collection( $p_vector_size, $p_project_id = 0, $p_project_name = '' ) {
		$t_collection = $this->collection_name_for_project( $p_project_id, $p_project_name );
		$t_url = $this->collection_url( $t_collection );

		$t_exists_response = $this->request( $t_url, 'GET' );
		if( $t_exists_response['status'] === 200 ) {
			return;
		}

		if( $t_exists_response['status'] !== 404 ) {
			throw new RuntimeException( 'Qdrant collection check failed: HTTP ' . $t_exists_response['status'] . ' - ' . $t_exists_response['body'] );
		}

		$t_payload = json_encode( array(
			'vectors' => array(
				'size' => (int)$p_vector_size,
				'distance' => 'Cosine',
			),
		) );
		$t_create_response = $this->request( $t_url, 'PUT', $t_payload );
		if( $t_create_response['status'] < 200 || $t_create_response['status'] >= 300 ) {
			throw new RuntimeException( 'Qdrant collection create failed: HTTP ' . $t_create_response['status'] . ' - ' . $t_create_response['body'] );
		}
	}

	public function upsert_issue( $p_issue_id, array $p_vector, array $p_payload ) {
		$t_project_id = isset( $p_payload['project_id'] ) ? (int)$p_payload['project_id'] : 0;
		$t_project_name = isset( $p_payload['project_name'] ) ? (string)$p_payload['project_name'] : '';
		$t_collection = $this->collection_name_for_project( $t_project_id, $t_project_name );
		$t_url = $this->base_url() . '/collections/' . rawurlencode( $t_collection ) . '/points?wait=true';
		$t_request = array(
			'points' => array(
				array(
					'id' => (int)$p_issue_id,
					'vector' => $p_vector,
					'payload' => $p_payload,
				),
			),
		);
		$t_response = $this->request( $t_url, 'PUT', json_encode( $t_request ) );
		if( $t_response['status'] < 200 || $t_response['status'] >= 300 ) {
			throw new RuntimeException( 'Qdrant upsert failed: HTTP ' . $t_response['status'] . ' - ' . $t_response['body'] );
		}
	}

	public function search( array $p_query_vector, $p_limit, $p_min_score, $p_project_id = null, $p_project_name = '' ) {
		$t_project_id = $p_project_id === null ? 0 : (int)$p_project_id;
		$t_collection = $this->collection_name_for_project( $t_project_id, $p_project_name );
		$t_url = $this->base_url() . '/collections/' . rawurlencode( $t_collection ) . '/points/search';
		$t_request = array(
			'vector' => $p_query_vector,
			'limit' => (int)$p_limit,
			'with_payload' => true,
		);
		if( $p_min_score > 0 ) {
			$t_request['score_threshold'] = (float)$p_min_score;
		}

		$t_response = $this->request( $t_url, 'POST', json_encode( $t_request ) );
		if( $t_response['status'] === 404 ) {
			return array();
		}
		if( $t_response['status'] < 200 || $t_response['status'] >= 300 ) {
			throw new RuntimeException( 'Qdrant search failed: HTTP ' . $t_response['status'] . ' - ' . $t_response['body'] );
		}

		$t_json = json_decode( $t_response['body'], true );
		return isset( $t_json['result'] ) && is_array( $t_json['result'] ) ? $t_json['result'] : array();
	}

	public function delete_issue( $p_issue_id, $p_project_id = 0, $p_project_name = '' ) {
		$t_collection = $this->collection_name_for_project( $p_project_id, $p_project_name );
		$t_url = $this->base_url() . '/collections/' . rawurlencode( $t_collection ) . '/points/delete?wait=true';
		$t_request = array(
			'points' => array( (int)$p_issue_id ),
		);
		$t_response = $this->request( $t_url, 'POST', json_encode( $t_request ) );
		if( $t_response['status'] === 404 ) {
			return;
		}
		if( $t_response['status'] < 200 || $t_response['status'] >= 300 ) {
			throw new RuntimeException( 'Qdrant delete failed: HTTP ' . $t_response['status'] . ' - ' . $t_response['body'] );
		}
	}

	public function update_payload( $p_issue_id, array $p_payload, $p_project_id = 0, $p_project_name = '' ) {
		$t_collection = $this->collection_name_for_project( $p_project_id, $p_project_name );
		$t_url = $this->base_url() . '/collections/' . rawurlencode( $t_collection ) . '/points/payload?wait=true';
		$t_request = array(
			'payload' => $p_payload,
			'points' => array( (int)$p_issue_id ),
		);
		$t_response = $this->request( $t_url, 'POST', json_encode( $t_request ) );
		if( $t_response['status'] === 404 ) {
			return;
		}
		if( $t_response['status'] < 200 || $t_response['status'] >= 300 ) {
			throw new RuntimeException( 'Qdrant payload update failed: HTTP ' . $t_response['status'] . ' - ' . $t_response['body'] );
		}
	}

	private function collection_url( $p_collection_name ) {
		return $this->base_url() . '/collections/' . rawurlencode( $p_collection_name );
	}

	private function base_url() {
		return rtrim( $this->plugin->get_setting( 'qdrant_url', 'http://qdrant:6333', 'SEMSEARCH_QDRANT_URL' ), '/' );
	}

	private function request( $p_url, $p_method, $p_body = null ) {
		$t_ch = curl_init( $p_url );
		curl_setopt( $t_ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $t_ch, CURLOPT_TIMEOUT, 30 );
		curl_setopt( $t_ch, CURLOPT_CUSTOMREQUEST, $p_method );
		curl_setopt( $t_ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' ) );
		if( $p_body !== null ) {
			curl_setopt( $t_ch, CURLOPT_POSTFIELDS, $p_body );
		}
		$t_body = curl_exec( $t_ch );
		$t_error = curl_error( $t_ch );
		$t_status = (int)curl_getinfo( $t_ch, CURLINFO_RESPONSE_CODE );
		curl_close( $t_ch );

		if( $t_body === false ) {
			throw new RuntimeException( 'Qdrant request failed: ' . $t_error );
		}
		return array( 'status' => $t_status, 'body' => $t_body );
	}

	private function slugify( $p_text ) {
		$t = mb_strtolower( trim( (string)$p_text ), 'UTF-8' );
		$t = iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $t );
		$t = strtolower( (string)$t );
		$t = preg_replace( '/[^a-z0-9]+/', '_', $t );
		$t = trim( (string)$t, '_' );
		return $t;
	}
}
