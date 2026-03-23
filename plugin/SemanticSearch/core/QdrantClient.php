<?php

class QdrantClient {
	private $plugin;

	public function __construct( SemanticSearchPlugin $p_plugin ) {
		$this->plugin = $p_plugin;
	}

	public function get_collection_name() {
		return $this->plugin->get_setting( 'qdrant_collection', 'mantis_resolved_issues', 'SEMSEARCH_QDRANT_COLLECTION' );
	}

	public function ensure_collection( $p_vector_size ) {
		$t_collection = $this->get_collection_name();
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
		$t_collection = $this->get_collection_name();
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

	public function search( array $p_query_vector, $p_limit, $p_min_score ) {
		$t_collection = $this->get_collection_name();
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
		if( $t_response['status'] < 200 || $t_response['status'] >= 300 ) {
			throw new RuntimeException( 'Qdrant search failed: HTTP ' . $t_response['status'] . ' - ' . $t_response['body'] );
		}

		$t_json = json_decode( $t_response['body'], true );
		return isset( $t_json['result'] ) && is_array( $t_json['result'] ) ? $t_json['result'] : array();
	}

	public function delete_issue( $p_issue_id ) {
		$t_collection = $this->get_collection_name();
		$t_url = $this->base_url() . '/collections/' . rawurlencode( $t_collection ) . '/points/delete?wait=true';
		$t_request = array(
			'points' => array( (int)$p_issue_id ),
		);
		$t_response = $this->request( $t_url, 'POST', json_encode( $t_request ) );
		if( $t_response['status'] < 200 || $t_response['status'] >= 300 ) {
			throw new RuntimeException( 'Qdrant delete failed: HTTP ' . $t_response['status'] . ' - ' . $t_response['body'] );
		}
	}

	public function update_payload( $p_issue_id, array $p_payload ) {
		$t_collection = $this->get_collection_name();
		$t_url = $this->base_url() . '/collections/' . rawurlencode( $t_collection ) . '/points/payload?wait=true';
		$t_request = array(
			'payload' => $p_payload,
			'points' => array( (int)$p_issue_id ),
		);
		$t_response = $this->request( $t_url, 'POST', json_encode( $t_request ) );
		if( $t_response['status'] < 200 || $t_response['status'] >= 300 ) {
			throw new RuntimeException( 'Qdrant payload update failed: HTTP ' . $t_response['status'] . ' - ' . $t_response['body'] );
		}
	}

	public function get_issue_point( $p_issue_id ) {
		$t_collection = $this->get_collection_name();
		$t_url = $this->base_url() . '/collections/' . rawurlencode( $t_collection ) . '/points/' . (int)$p_issue_id;
		$t_response = $this->request( $t_url, 'GET' );
		if( $t_response['status'] === 404 ) {
			return array( 'found' => false, 'payload' => array() );
		}
		if( $t_response['status'] < 200 || $t_response['status'] >= 300 ) {
			throw new RuntimeException( 'Qdrant point get failed: HTTP ' . $t_response['status'] . ' - ' . $t_response['body'] );
		}
		$t_json = json_decode( $t_response['body'], true );
		if( !isset( $t_json['result'] ) || !$t_json['result'] ) {
			return array( 'found' => false, 'payload' => array() );
		}
		$t_payload = isset( $t_json['result']['payload'] ) && is_array( $t_json['result']['payload'] ) ? $t_json['result']['payload'] : array();
		return array( 'found' => true, 'payload' => $t_payload );
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
}
