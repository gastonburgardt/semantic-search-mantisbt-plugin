<?php

class SemanticSearchV2Schema {
	private function table( $p_name ) {
		$t_prefix = function_exists( 'db_get_prefix' ) ? db_get_prefix() : config_get( 'db_table_prefix', 'mantis_' );
		return $t_prefix . $p_name;
	}

	public function ensure() {
		$this->ensure_issue();
		$this->ensure_note();
		$this->ensure_file();
		$this->ensure_job_lock();
		$this->ensure_job_run();
	}

	private function ensure_issue() {
		$t_table = $this->table( 'plugin_semsearch_issue' );
		$t_sql = "CREATE TABLE IF NOT EXISTS $t_table (
			IssueId INT NOT NULL,
			CreatedAt INT NOT NULL DEFAULT 0,
			UpdatedAt INT NOT NULL DEFAULT 0,
			IndexedAt INT NULL DEFAULT NULL,
			Deleted TINYINT NOT NULL DEFAULT 0,
			DeletedAt INT NULL DEFAULT NULL,
			Indexable TINYINT NOT NULL DEFAULT 0,
			Hash VARCHAR(64) NOT NULL DEFAULT '',
			Empty TINYINT NOT NULL DEFAULT 0,
			Indexed TINYINT NOT NULL DEFAULT 0,
			Action VARCHAR(16) NOT NULL DEFAULT 'Nothing',
			NivelDeRevision VARCHAR(24) NOT NULL DEFAULT 'NoRevisarNada',
			PRIMARY KEY (IssueId)
		)";
		db_query( $t_sql );
		$this->ensure_column( $t_table, 'CreatedAt', 'INT NOT NULL DEFAULT 0' );
		$this->ensure_column( $t_table, 'UpdatedAt', 'INT NOT NULL DEFAULT 0' );
		$this->ensure_column( $t_table, 'IndexedAt', 'INT NULL DEFAULT NULL' );
		$this->ensure_column( $t_table, 'Deleted', 'TINYINT NOT NULL DEFAULT 0' );
		$this->ensure_column( $t_table, 'DeletedAt', 'INT NULL DEFAULT NULL' );
		$this->drop_column_if_exists( $t_table, 'Date' );
	}

	private function ensure_note() {
		$t_table = $this->table( 'plugin_semsearch_issuenote' );
		$t_sql = "CREATE TABLE IF NOT EXISTS $t_table (
			NoteId INT NOT NULL,
			IssueId INT NOT NULL,
			CreatedAt INT NOT NULL DEFAULT 0,
			UpdatedAt INT NOT NULL DEFAULT 0,
			IndexedAt INT NULL DEFAULT NULL,
			Deleted TINYINT NOT NULL DEFAULT 0,
			DeletedAt INT NULL DEFAULT NULL,
			Indexable TINYINT NOT NULL DEFAULT 0,
			Hash VARCHAR(64) NOT NULL DEFAULT '',
			Empty TINYINT NOT NULL DEFAULT 0,
			Indexed TINYINT NOT NULL DEFAULT 0,
			Action VARCHAR(16) NOT NULL DEFAULT 'Nothing',
			NivelDeRevision VARCHAR(24) NOT NULL DEFAULT 'NoRevisarNada',
			PRIMARY KEY (NoteId, IssueId)
		)";
		db_query( $t_sql );
		$this->ensure_column( $t_table, 'CreatedAt', 'INT NOT NULL DEFAULT 0' );
		$this->ensure_column( $t_table, 'UpdatedAt', 'INT NOT NULL DEFAULT 0' );
		$this->ensure_column( $t_table, 'IndexedAt', 'INT NULL DEFAULT NULL' );
		$this->ensure_column( $t_table, 'Deleted', 'TINYINT NOT NULL DEFAULT 0' );
		$this->ensure_column( $t_table, 'DeletedAt', 'INT NULL DEFAULT NULL' );
		$this->drop_column_if_exists( $t_table, 'Date' );
	}

	private function ensure_file() {
		$t_table = $this->table( 'plugin_semsearch_issuenotefile' );
		$t_sql = "CREATE TABLE IF NOT EXISTS $t_table (
			FileId INT NOT NULL,
			NoteId INT NOT NULL DEFAULT 0,
			IssueId INT NOT NULL,
			CreatedAt INT NOT NULL DEFAULT 0,
			UpdatedAt INT NOT NULL DEFAULT 0,
			IndexedAt INT NULL DEFAULT NULL,
			Deleted TINYINT NOT NULL DEFAULT 0,
			DeletedAt INT NULL DEFAULT NULL,
			Indexable TINYINT NOT NULL DEFAULT 0,
			Hash VARCHAR(64) NOT NULL DEFAULT '',
			Empty TINYINT NOT NULL DEFAULT 0,
			Indexed TINYINT NOT NULL DEFAULT 0,
			Action VARCHAR(16) NOT NULL DEFAULT 'Nothing',
			NivelDeRevision VARCHAR(24) NOT NULL DEFAULT 'NoRevisarNada',
			PRIMARY KEY (FileId, NoteId, IssueId)
		)";
		db_query( $t_sql );
		$this->ensure_column( $t_table, 'CreatedAt', 'INT NOT NULL DEFAULT 0' );
		$this->ensure_column( $t_table, 'UpdatedAt', 'INT NOT NULL DEFAULT 0' );
		$this->ensure_column( $t_table, 'IndexedAt', 'INT NULL DEFAULT NULL' );
		$this->ensure_column( $t_table, 'Deleted', 'TINYINT NOT NULL DEFAULT 0' );
		$this->ensure_column( $t_table, 'DeletedAt', 'INT NULL DEFAULT NULL' );
		$this->drop_column_if_exists( $t_table, 'Date' );
	}

	private function ensure_column( $p_table, $p_column, $p_definition ) {
		if( !$this->column_exists( $p_table, $p_column ) ) {
			db_query( "ALTER TABLE $p_table ADD COLUMN $p_column $p_definition" );
		}
	}

	private function drop_column_if_exists( $p_table, $p_column ) {
		if( $this->column_exists( $p_table, $p_column ) ) {
			db_query( "ALTER TABLE $p_table DROP COLUMN $p_column" );
		}
	}

	private function ensure_job_lock() {
		$t_table = $this->table( 'plugin_semsearch_job_lock' );
		$t_sql = "CREATE TABLE IF NOT EXISTS $t_table (
			Id INT NOT NULL AUTO_INCREMENT,
			Kind VARCHAR(24) NOT NULL DEFAULT 'vectorize',
			ScopeType VARCHAR(16) NOT NULL DEFAULT 'project',
			ScopeProjectId INT NOT NULL DEFAULT 0,
			RunId VARCHAR(64) NOT NULL,
			StartedAt INT NOT NULL DEFAULT 0,
			HeartbeatAt INT NOT NULL DEFAULT 0,
			ExpiresAt INT NOT NULL DEFAULT 0,
			PRIMARY KEY (Id),
			UNIQUE KEY uniq_run (RunId),
			KEY idx_scope (ScopeType, ScopeProjectId)
		)";
		db_query( $t_sql );
	}

	private function ensure_job_run() {
		$t_table = $this->table( 'plugin_semsearch_job_run' );
		$t_sql = "CREATE TABLE IF NOT EXISTS $t_table (
			Id INT NOT NULL AUTO_INCREMENT,
			RunId VARCHAR(64) NOT NULL,
			Kind VARCHAR(24) NOT NULL,
			ScopeType VARCHAR(16) NOT NULL,
			ScopeProjectId INT NOT NULL DEFAULT 0,
			Status VARCHAR(16) NOT NULL DEFAULT 'running',
			Total INT NOT NULL DEFAULT 0,
			Processed INT NOT NULL DEFAULT 0,
			OkCount INT NOT NULL DEFAULT 0,
			SkipCount INT NOT NULL DEFAULT 0,
			FailCount INT NOT NULL DEFAULT 0,
			StartedAt INT NOT NULL DEFAULT 0,
			UpdatedAt INT NOT NULL DEFAULT 0,
			FinishedAt INT NULL DEFAULT NULL,
			Message TEXT NULL,
			PRIMARY KEY (Id),
			UNIQUE KEY uniq_run (RunId),
			KEY idx_scope (ScopeType, ScopeProjectId)
		)";
		db_query( $t_sql );
	}

	private function column_exists( $p_table, $p_column ) {
		$t_col = db_escape_string( $p_column );
		$t_res = db_query( "SHOW COLUMNS FROM $p_table LIKE '$t_col'" );
		return db_num_rows( $t_res ) > 0;
	}
}
