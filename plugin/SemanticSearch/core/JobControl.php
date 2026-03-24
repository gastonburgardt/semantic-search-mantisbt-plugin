<?php

class SemanticSearchJobControl {
	private function table( $p_name ) {
		$t_prefix = function_exists( 'db_get_prefix' ) ? db_get_prefix() : config_get( 'db_table_prefix', 'mantis_' );
		return $t_prefix . $p_name;
	}

	public function acquire_lock( $p_kind, $p_scope_type, $p_scope_project_id, $p_run_id, $p_ttl_seconds = 900 ) {
		$t_table = $this->table( 'plugin_semsearch_job_lock' );
		$t_now = time();
		$t_expires = $t_now + (int)$p_ttl_seconds;
		$t_scope_project_id = (int)$p_scope_project_id;

		db_query( "DELETE FROM $t_table WHERE ExpiresAt > 0 AND ExpiresAt < " . db_param(), array( (int)$t_now ) );

		$t_conflict_sql = "SELECT Id, ScopeType, ScopeProjectId FROM $t_table WHERE (ScopeType='all' OR (" . db_param() . "='all') OR (ScopeType='project' AND " . db_param() . " > 0 AND ScopeProjectId=" . db_param() . ')) LIMIT 1';
		$t_conflict = db_query( $t_conflict_sql, array( (string)$p_scope_type, $t_scope_project_id, $t_scope_project_id ) );
		if( db_num_rows( $t_conflict ) > 0 ) {
			$r = db_fetch_array( $t_conflict );
			return array( 'ok' => false, 'reason' => 'locked', 'lock_id' => (int)$r['Id'] );
		}

		db_query(
			"INSERT INTO $t_table (Kind,ScopeType,ScopeProjectId,RunId,StartedAt,HeartbeatAt,ExpiresAt) VALUES (" . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ')',
			array( (string)$p_kind, (string)$p_scope_type, $t_scope_project_id, (string)$p_run_id, (int)$t_now, (int)$t_now, (int)$t_expires )
		);
		return array( 'ok' => true );
	}

	public function heartbeat( $p_run_id, $p_ttl_seconds = 900 ) {
		$t_table = $this->table( 'plugin_semsearch_job_lock' );
		$t_now = time();
		$t_expires = $t_now + (int)$p_ttl_seconds;
		db_query( "UPDATE $t_table SET HeartbeatAt=" . db_param() . ', ExpiresAt=' . db_param() . ' WHERE RunId=' . db_param(), array( (int)$t_now, (int)$t_expires, (string)$p_run_id ) );
	}

	public function release( $p_run_id ) {
		$t_table = $this->table( 'plugin_semsearch_job_lock' );
		db_query( "DELETE FROM $t_table WHERE RunId=" . db_param(), array( (string)$p_run_id ) );
	}

	public function is_locked_for_project( $p_project_id ) {
		$t_table = $this->table( 'plugin_semsearch_job_lock' );
		$t_now = time();
		db_query( "DELETE FROM $t_table WHERE ExpiresAt > 0 AND ExpiresAt < " . db_param(), array( (int)$t_now ) );
		$t_project_id = (int)$p_project_id;
		$t_sql = "SELECT Id FROM $t_table WHERE ScopeType='all' OR (ScopeType='project' AND ScopeProjectId=" . db_param() . ') LIMIT 1';
		$t_res = db_query( $t_sql, array( $t_project_id ) );
		return db_num_rows( $t_res ) > 0;
	}

	public function force_unlock_scope( $p_scope_type, $p_scope_project_id = 0 ) {
		$t_table = $this->table( 'plugin_semsearch_job_lock' );
		if( (string)$p_scope_type === 'all' ) {
			db_query( "DELETE FROM $t_table" );
			return;
		}
		db_query( "DELETE FROM $t_table WHERE ScopeType='project' AND ScopeProjectId=" . db_param(), array( (int)$p_scope_project_id ) );
	}
}
