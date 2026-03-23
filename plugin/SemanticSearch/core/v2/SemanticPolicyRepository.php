<?php

class SemanticPolicyRepository {
	private function col( array $p_row, $p_name, $p_default = null ) {
		if( array_key_exists( $p_name, $p_row ) ) { return $p_row[$p_name]; }
		$t_lower = strtolower( $p_name );
		foreach( $p_row as $k => $v ) {
			if( strtolower( (string)$k ) === $t_lower ) { return $v; }
		}
		return $p_default;
	}

	private function table( $p_name ) {
		$t_prefix = function_exists( 'db_get_prefix' ) ? db_get_prefix() : config_get( 'db_table_prefix', 'mantis_' );
		return $t_prefix . $p_name;
	}

	private function t_issue() { return $this->table( 'plugin_semsearch_issue' ); }
	private function t_note() { return $this->table( 'plugin_semsearch_issuenote' ); }
	private function t_file() { return $this->table( 'plugin_semsearch_issuenotefile' ); }

	private function bool_to_db( $p_value ) { return $p_value ? 1 : 0; }

	public function sync_inventory( $p_issue_id, array $p_core_item, array $p_note_items, array $p_attachment_items ) {
		$t_issue_id = (int)$p_issue_id;
		$t_now = time();

		$this->ensure_issue_row( $t_issue_id, $t_now );

		foreach( $p_note_items as $t_note ) {
			$t_note_id = (int)$t_note['item_id'];
			$this->ensure_note_row( $t_issue_id, $t_note_id, $t_now );
		}

		foreach( $p_attachment_items as $t_file ) {
			$t_file_id = (int)$t_file['item_id'];
			$t_note_id = isset( $t_file['parent_note_id'] ) ? (int)$t_file['parent_note_id'] : 0;
			$this->ensure_file_row( $t_issue_id, $t_note_id, $t_file_id, $t_now );
		}
	}

	private function ensure_issue_row( $p_issue_id, $p_now ) {
		$t_table = $this->t_issue();
		$t_exists = db_query( "SELECT IssueId FROM $t_table WHERE IssueId=" . db_param(), array( (int)$p_issue_id ) );
		if( db_num_rows( $t_exists ) > 0 ) {
			return;
		}
		db_query(
			"INSERT INTO $t_table (IssueId,CreatedAt,UpdatedAt,IndexedAt,Indexable,Hash,Empty,Indexed,Action,NivelDeRevision) VALUES (" . db_param() . ',' . db_param() . ',' . db_param() . ",NULL,0,'',0,0,'Nothing','NoRevisarNada')",
			array( (int)$p_issue_id, (int)$p_now, (int)$p_now )
		);
	}

	private function ensure_note_row( $p_issue_id, $p_note_id, $p_now ) {
		$t_table = $this->t_note();
		$t_exists = db_query( "SELECT NoteId FROM $t_table WHERE NoteId=" . db_param() . ' AND IssueId=' . db_param(), array( (int)$p_note_id, (int)$p_issue_id ) );
		if( db_num_rows( $t_exists ) > 0 ) {
			return;
		}
		db_query(
			"INSERT INTO $t_table (NoteId,IssueId,CreatedAt,UpdatedAt,IndexedAt,Indexable,Hash,Empty,Indexed,Action,NivelDeRevision) VALUES (" . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ",NULL,0,'',0,0,'Nothing','NoRevisarNada')",
			array( (int)$p_note_id, (int)$p_issue_id, (int)$p_now, (int)$p_now )
		);
	}

	private function ensure_file_row( $p_issue_id, $p_note_id, $p_file_id, $p_now ) {
		$t_table = $this->t_file();
		$t_exists = db_query( "SELECT FileId FROM $t_table WHERE FileId=" . db_param() . ' AND NoteId=' . db_param() . ' AND IssueId=' . db_param(), array( (int)$p_file_id, (int)$p_note_id, (int)$p_issue_id ) );
		if( db_num_rows( $t_exists ) > 0 ) {
			return;
		}
		db_query(
			"INSERT INTO $t_table (FileId,NoteId,IssueId,CreatedAt,UpdatedAt,IndexedAt,Indexable,Hash,Empty,Indexed,Action,NivelDeRevision) VALUES (" . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ",NULL,0,'',0,0,'Nothing','NoRevisarNada')",
			array( (int)$p_file_id, (int)$p_note_id, (int)$p_issue_id, (int)$p_now, (int)$p_now )
		);
	}

	public function by_issue( $p_issue_id ) {
		$t_issue_id = (int)$p_issue_id;
		$t_issue = db_fetch_array( db_query( "SELECT * FROM " . $this->t_issue() . " WHERE IssueId=" . db_param(), array( $t_issue_id ) ) );
		$t_notes = array();
		$t_files = array();

		$t_note_res = db_query( "SELECT * FROM " . $this->t_note() . " WHERE IssueId=" . db_param() . ' ORDER BY NoteId ASC', array( $t_issue_id ) );
		while( $t_row = db_fetch_array( $t_note_res ) ) { $t_notes[(int)$this->col( $t_row, 'NoteId', 0 )] = $t_row; }

		$t_file_res = db_query( "SELECT * FROM " . $this->t_file() . " WHERE IssueId=" . db_param() . ' ORDER BY NoteId ASC, FileId ASC', array( $t_issue_id ) );
		while( $t_row = db_fetch_array( $t_file_res ) ) { $t_files[(int)$this->col( $t_row, 'FileId', 0 )] = $t_row; }

		return array( 'issue' => $t_issue, 'notes' => $t_notes, 'files' => $t_files );
	}

	public function set_indexable( $p_issue_id, $p_item_type, $p_item_id, $p_indexable ) {
		$t_now = time();
		$t_db = $this->bool_to_db( (bool)$p_indexable );
		if( $p_item_type === SemanticEntityType::ISSUE ) {
			db_query( "UPDATE " . $this->t_issue() . " SET Indexable=" . db_param() . ', UpdatedAt=' . db_param() . " WHERE IssueId=" . db_param(), array( $t_db, $t_now, (int)$p_issue_id ) );
			return;
		}
		if( $p_item_type === SemanticEntityType::ISSUENOTE ) {
			db_query( "UPDATE " . $this->t_note() . " SET Indexable=" . db_param() . ', UpdatedAt=' . db_param() . " WHERE IssueId=" . db_param() . ' AND NoteId=' . db_param(), array( $t_db, $t_now, (int)$p_issue_id, (int)$p_item_id ) );
			return;
		}
		db_query( "UPDATE " . $this->t_file() . " SET Indexable=" . db_param() . ', UpdatedAt=' . db_param() . " WHERE IssueId=" . db_param() . ' AND FileId=' . db_param(), array( $t_db, $t_now, (int)$p_issue_id, (int)$p_item_id ) );
	}

	public function save_issue_state( $p_issue_id, array $p_values ) {
		$t_now = time();
		db_query(
			"UPDATE " . $this->t_issue() . " SET Hash=" . db_param() . ', Empty=' . db_param() . ', Indexed=' . db_param() . ', Action=' . db_param() . ', NivelDeRevision=' . db_param() . ', UpdatedAt=' . db_param() . ', IndexedAt=' . db_param() . " WHERE IssueId=" . db_param(),
			array( (string)$p_values['Hash'], $this->bool_to_db( !empty( $p_values['Empty'] ) ), $this->bool_to_db( !empty( $p_values['Indexed'] ) ), (string)$p_values['Action'], (string)$p_values['NivelDeRevision'], $t_now, $this->indexed_at_param( $p_values, $t_now ), (int)$p_issue_id )
		);
	}

	public function save_note_state( $p_issue_id, $p_note_id, array $p_values ) {
		$t_now = time();
		db_query(
			"UPDATE " . $this->t_note() . " SET Hash=" . db_param() . ', Empty=' . db_param() . ', Indexed=' . db_param() . ', Action=' . db_param() . ', NivelDeRevision=' . db_param() . ', UpdatedAt=' . db_param() . ', IndexedAt=' . db_param() . " WHERE IssueId=" . db_param() . ' AND NoteId=' . db_param(),
			array( (string)$p_values['Hash'], $this->bool_to_db( !empty( $p_values['Empty'] ) ), $this->bool_to_db( !empty( $p_values['Indexed'] ) ), (string)$p_values['Action'], (string)$p_values['NivelDeRevision'], $t_now, $this->indexed_at_param( $p_values, $t_now ), (int)$p_issue_id, (int)$p_note_id )
		);
	}

	public function save_file_state( $p_issue_id, $p_note_id, $p_file_id, array $p_values ) {
		$t_now = time();
		db_query(
			"UPDATE " . $this->t_file() . " SET NoteId=" . db_param() . ', Hash=' . db_param() . ', Empty=' . db_param() . ', Indexed=' . db_param() . ', Action=' . db_param() . ', NivelDeRevision=' . db_param() . ', UpdatedAt=' . db_param() . ', IndexedAt=' . db_param() . " WHERE IssueId=" . db_param() . ' AND FileId=' . db_param(),
			array( (int)$p_note_id, (string)$p_values['Hash'], $this->bool_to_db( !empty( $p_values['Empty'] ) ), $this->bool_to_db( !empty( $p_values['Indexed'] ) ), (string)$p_values['Action'], (string)$p_values['NivelDeRevision'], $t_now, $this->indexed_at_param( $p_values, $t_now ), (int)$p_issue_id, (int)$p_file_id )
		);
	}

	private function indexed_at_param( array $p_values, $p_now ) {
		if( array_key_exists( 'IndexedAt', $p_values ) ) {
			return $p_values['IndexedAt'];
		}
		if( !empty( $p_values['Indexed'] ) ) {
			return (int)$p_now;
		}
		return null;
	}

	public function all_plugin_rows() {
		$t_rows = array( 'issues' => array(), 'notes' => array(), 'files' => array() );
		$r1 = db_query( "SELECT * FROM " . $this->t_issue() );
		while( $r = db_fetch_array( $r1 ) ) { $t_rows['issues'][] = $r; }
		$r2 = db_query( "SELECT * FROM " . $this->t_note() );
		while( $r = db_fetch_array( $r2 ) ) { $t_rows['notes'][] = $r; }
		$r3 = db_query( "SELECT * FROM " . $this->t_file() );
		while( $r = db_fetch_array( $r3 ) ) { $t_rows['files'][] = $r; }
		return $t_rows;
	}
}
