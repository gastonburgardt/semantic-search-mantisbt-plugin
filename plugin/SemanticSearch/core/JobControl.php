<?php

class SemanticSearchJobControl {
	private function table( $p_name ) {
		$t_prefix = function_exists( 'db_get_prefix' ) ? db_get_prefix() : config_get( 'db_table_prefix', 'mantis_' );
		return $t_prefix . $p_name;
	}

	private function now() {
		return time();
	}

	public function compute_scope( array $p_filters ) {
		$t_project_id = isset( $p_filters['project_id'] ) ? $p_filters['project_id'] : null;
		$t_scope_type = ( $t_project_id !== null && (int)$t_project_id === 0 ) ? 'all' : 'project';
		$t_scope_project_id = ( $t_scope_type === 'project' ) ? (int)$t_project_id : 0;
		return array( $t_scope_type, $t_scope_project_id );
	}

	public function create_run( $p_kind, $p_scope_type, $p_scope_project_id, $p_run_id, array $p_filters, $p_total = 0 ) {
		$t_table = $this->table( 'plugin_semsearch_job_run' );
		$t_now = $this->now();
		$t_filters_json = json_encode( $p_filters );
		db_query(
			"INSERT INTO $t_table (RunId,Kind,ScopeType,ScopeProjectId,Status,Total,Processed,OkCount,SkipCount,FailCount,StartedAt,UpdatedAt,HeartbeatAt,StopRequested,LastId,FiltersJson) VALUES (" . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ",'running'," . db_param() . ',0,0,0,0,' . db_param() . ',' . db_param() . ',' . db_param() . ',0,0,' . db_param() . ')',
			array( (string)$p_run_id, (string)$p_kind, (string)$p_scope_type, (int)$p_scope_project_id, (int)$p_total, (int)$t_now, (int)$t_now, (int)$t_now, (string)$t_filters_json )
		);
	}

	public function acquire_lock( $p_kind, $p_scope_type, $p_scope_project_id, $p_run_id, $p_ttl_seconds = 120 ) {
		$t_table = $this->table( 'plugin_semsearch_job_lock' );
		$t_now = $this->now();
		$t_expires = $t_now + (int)$p_ttl_seconds;
		$t_scope_project_id = (int)$p_scope_project_id;

		$this->unlock_stale_locks( $p_ttl_seconds );

		$t_conflict_sql = "SELECT Id, ScopeType, ScopeProjectId, RunId FROM $t_table WHERE (ScopeType='all' OR (" . db_param() . "='all') OR (ScopeType='project' AND " . db_param() . " > 0 AND ScopeProjectId=" . db_param() . ')) LIMIT 1';
		$t_conflict = db_query( $t_conflict_sql, array( (string)$p_scope_type, $t_scope_project_id, $t_scope_project_id ) );
		if( db_num_rows( $t_conflict ) > 0 ) {
			$r = db_fetch_array( $t_conflict );
			return array( 'ok' => false, 'reason' => 'locked', 'lock_id' => (int)$r['Id'], 'run_id' => (string)$r['RunId'] );
		}

		db_query(
			"INSERT INTO $t_table (Kind,ScopeType,ScopeProjectId,RunId,StartedAt,HeartbeatAt,ExpiresAt) VALUES (" . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ')',
			array( (string)$p_kind, (string)$p_scope_type, $t_scope_project_id, (string)$p_run_id, (int)$t_now, (int)$t_now, (int)$t_expires )
		);
		return array( 'ok' => true );
	}

	public function heartbeat( $p_run_id, $p_ttl_seconds = 120 ) {
		$t_lock = $this->table( 'plugin_semsearch_job_lock' );
		$t_run = $this->table( 'plugin_semsearch_job_run' );
		$t_now = $this->now();
		$t_expires = $t_now + (int)$p_ttl_seconds;
		db_query( "UPDATE $t_lock SET HeartbeatAt=" . db_param() . ', ExpiresAt=' . db_param() . ' WHERE RunId=' . db_param(), array( (int)$t_now, (int)$t_expires, (string)$p_run_id ) );
		db_query( "UPDATE $t_run SET HeartbeatAt=" . db_param() . ', UpdatedAt=' . db_param() . ' WHERE RunId=' . db_param() . " AND Status='running'", array( (int)$t_now, (int)$t_now, (string)$p_run_id ) );
	}

	public function update_progress( $p_run_id, array $p_delta, $p_last_id = 0 ) {
		$t_run = $this->table( 'plugin_semsearch_job_run' );
		$t_now = $this->now();
		$t_ok = isset( $p_delta['ok'] ) ? (int)$p_delta['ok'] : 0;
		$t_skip = isset( $p_delta['skip'] ) ? (int)$p_delta['skip'] : 0;
		$t_fail = isset( $p_delta['fail'] ) ? (int)$p_delta['fail'] : 0;
		$t_seen = isset( $p_delta['seen'] ) ? (int)$p_delta['seen'] : 0;
		db_query(
			"UPDATE $t_run SET Processed=Processed+" . db_param() . ', OkCount=OkCount+' . db_param() . ', SkipCount=SkipCount+' . db_param() . ', FailCount=FailCount+' . db_param() . ', LastId=' . db_param() . ', UpdatedAt=' . db_param() . ', HeartbeatAt=' . db_param() . ' WHERE RunId=' . db_param() . " AND Status='running'",
			array( $t_seen, $t_ok, $t_skip, $t_fail, (int)$p_last_id, (int)$t_now, (int)$t_now, (string)$p_run_id )
		);
	}

	public function finish( $p_run_id, $p_status = 'done', $p_message = '' ) {
		$t_run = $this->table( 'plugin_semsearch_job_run' );
		$t_now = $this->now();
		db_query( "UPDATE $t_run SET Status=" . db_param() . ', Message=' . db_param() . ', UpdatedAt=' . db_param() . ', FinishedAt=' . db_param() . ' WHERE RunId=' . db_param(), array( (string)$p_status, (string)$p_message, (int)$t_now, (int)$t_now, (string)$p_run_id ) );
		$this->release( $p_run_id );
	}

	public function release( $p_run_id ) {
		$t_table = $this->table( 'plugin_semsearch_job_lock' );
		db_query( "DELETE FROM $t_table WHERE RunId=" . db_param(), array( (string)$p_run_id ) );
	}

	public function request_stop( $p_run_id ) {
		$t_run = $this->table( 'plugin_semsearch_job_run' );
		$t_now = $this->now();
		db_query( "UPDATE $t_run SET StopRequested=1, UpdatedAt=" . db_param() . ' WHERE RunId=' . db_param() . " AND Status='running'", array( (int)$t_now, (string)$p_run_id ) );
	}

	public function stop_requested( $p_run_id ) {
		$t_run = $this->table( 'plugin_semsearch_job_run' );
		$res = db_query( "SELECT StopRequested FROM $t_run WHERE RunId=" . db_param() . ' LIMIT 1', array( (string)$p_run_id ) );
		if( db_num_rows( $res ) === 0 ) {
			return false;
		}
		$row = db_fetch_array( $res );
		return (int)$row['StopRequested'] === 1;
	}

	public function get_run( $p_run_id ) {
		$t_run = $this->table( 'plugin_semsearch_job_run' );
		$res = db_query( "SELECT * FROM $t_run WHERE RunId=" . db_param() . ' LIMIT 1', array( (string)$p_run_id ) );
		if( db_num_rows( $res ) === 0 ) {
			return null;
		}
		return db_fetch_array( $res );
	}

	public function get_last_run( $p_kind, $p_scope_type, $p_scope_project_id ) {
		$t_run = $this->table( 'plugin_semsearch_job_run' );
		$res = db_query(
			"SELECT * FROM $t_run WHERE Kind=" . db_param() . ' AND ScopeType=' . db_param() . ' AND ScopeProjectId=' . db_param() . ' ORDER BY Id DESC LIMIT 1',
			array( (string)$p_kind, (string)$p_scope_type, (int)$p_scope_project_id )
		);
		if( db_num_rows( $res ) === 0 ) {
			return null;
		}
		return db_fetch_array( $res );
	}

	public function unlock_stale_locks( $p_heartbeat_timeout = 120 ) {
		$t_lock = $this->table( 'plugin_semsearch_job_lock' );
		$t_run = $this->table( 'plugin_semsearch_job_run' );
		$t_now = $this->now();
		$t_cutoff = $t_now - (int)$p_heartbeat_timeout;
		$res = db_query( "SELECT RunId FROM $t_lock WHERE HeartbeatAt < " . db_param() . ' OR ExpiresAt < ' . db_param(), array( (int)$t_cutoff, (int)$t_now ) );
		while( $row = db_fetch_array( $res ) ) {
			$rid = (string)$row['RunId'];
			db_query( "DELETE FROM $t_lock WHERE RunId=" . db_param(), array( $rid ) );
			db_query( "UPDATE $t_run SET Status='stale', Message='Heartbeat vencido; lock liberado', UpdatedAt=" . db_param() . ', FinishedAt=' . db_param() . " WHERE RunId=" . db_param() . " AND Status='running'", array( (int)$t_now, (int)$t_now, $rid ) );
		}
	}

	public function force_unlock_scope( $p_scope_type, $p_scope_project_id = 0 ) {
		$t_lock = $this->table( 'plugin_semsearch_job_lock' );
		$t_run = $this->table( 'plugin_semsearch_job_run' );
		$t_now = $this->now();
		if( (string)$p_scope_type === 'all' ) {
			$res = db_query( "SELECT RunId FROM $t_lock" );
			while( $row = db_fetch_array( $res ) ) {
				db_query( "UPDATE $t_run SET Status='force_unlocked', Message='Desbloqueado manualmente', UpdatedAt=" . db_param() . ', FinishedAt=' . db_param() . " WHERE RunId=" . db_param() . " AND Status='running'", array( (int)$t_now, (int)$t_now, (string)$row['RunId'] ) );
			}
			db_query( "DELETE FROM $t_lock" );
			return;
		}
		$res = db_query( "SELECT RunId FROM $t_lock WHERE ScopeType='project' AND ScopeProjectId=" . db_param(), array( (int)$p_scope_project_id ) );
		while( $row = db_fetch_array( $res ) ) {
			db_query( "UPDATE $t_run SET Status='force_unlocked', Message='Desbloqueado manualmente', UpdatedAt=" . db_param() . ', FinishedAt=' . db_param() . " WHERE RunId=" . db_param() . " AND Status='running'", array( (int)$t_now, (int)$t_now, (string)$row['RunId'] ) );
		}
		db_query( "DELETE FROM $t_lock WHERE ScopeType='project' AND ScopeProjectId=" . db_param(), array( (int)$p_scope_project_id ) );
	}
}
