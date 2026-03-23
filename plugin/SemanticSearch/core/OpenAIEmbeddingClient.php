<?php

class OpenAIEmbeddingClient {
	private $plugin;

	public function __construct( SemanticSearchPlugin $p_plugin ) {
		$this->plugin = $p_plugin;
	}

	public function embed( $p_text ) {
		$t_api_key = $this->plugin->get_openai_api_key();
		if( empty( $t_api_key ) ) {
			throw new RuntimeException( 'OPENAI_API_KEY is not configured' );
		}

		$t_model = $this->plugin->get_setting( 'openai_embedding_model', 'text-embedding-3-large', 'OPENAI_EMBEDDING_MODEL' );
		$t_payload = json_encode( array(
			'model' => $t_model,
			'input' => $p_text,
		) );

		$t_response = $this->request( 'https://api.openai.com/v1/embeddings', 'POST', $t_payload, array(
			'Authorization: Bearer ' . $t_api_key,
			'Content-Type: application/json',
		) );

		$t_json = json_decode( $t_response, true );
		if( !is_array( $t_json ) || empty( $t_json['data'][0]['embedding'] ) ) {
			throw new RuntimeException( 'Invalid OpenAI embedding response' );
		}

		return $t_json['data'][0]['embedding'];
	}

	private function request( $p_url, $p_method = 'GET', $p_body = null, $p_headers = array() ) {
		$t_ch = curl_init( $p_url );
		curl_setopt( $t_ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $t_ch, CURLOPT_TIMEOUT, 30 );
		curl_setopt( $t_ch, CURLOPT_CUSTOMREQUEST, $p_method );
		if( $p_body !== null ) {
			curl_setopt( $t_ch, CURLOPT_POSTFIELDS, $p_body );
		}
		if( !empty( $p_headers ) ) {
			curl_setopt( $t_ch, CURLOPT_HTTPHEADER, $p_headers );
		}

		$t_result = curl_exec( $t_ch );
		$t_error = curl_error( $t_ch );
		$t_http_code = (int)curl_getinfo( $t_ch, CURLINFO_RESPONSE_CODE );
		curl_close( $t_ch );

		if( $t_result === false ) {
			throw new RuntimeException( 'OpenAI request failed: ' . $t_error );
		}
		if( $t_http_code < 200 || $t_http_code >= 300 ) {
			throw new RuntimeException( 'OpenAI request failed with status ' . $t_http_code . ': ' . $t_result );
		}

		return $t_result;
	}
}
