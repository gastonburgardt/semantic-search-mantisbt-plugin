<?php

class SemanticIssueInventoryRepository {
	public function load_issue( $p_issue_id ) {
		return bug_get( (int)$p_issue_id, true );
	}

	public function load_notes( $p_issue_id ) {
		return bugnote_get_all_bugnotes( (int)$p_issue_id );
	}

	public function load_attachments( $p_issue_id ) {
		return file_get_visible_attachments( (int)$p_issue_id );
	}

	public function issue_source_hash( $p_bug ) {
		return sha1( trim( (string)$p_bug->summary ) . '|' . trim( (string)$p_bug->description ) );
	}

	public function issue_source_updated_at( $p_bug ) {
		return isset( $p_bug->last_updated ) ? (int)$p_bug->last_updated : 0;
	}

	public function note_source_hash( $p_note ) {
		$t_text = trim( (string)$p_note->note );
		return $t_text === '' ? '' : sha1( $t_text );
	}

	public function note_source_updated_at( $p_note ) {
		if( isset( $p_note->last_modified ) ) {
			return (int)$p_note->last_modified;
		}
		return isset( $p_note->date_submitted ) ? (int)$p_note->date_submitted : 0;
	}

	public function file_source_hash( array $p_file ) {
		$t_name = isset( $p_file['display_name'] ) ? (string)$p_file['display_name'] : (string)$p_file['filename'];
		return sha1( $t_name . '|' . (int)$p_file['size'] . '|' . (int)$p_file['date_added'] );
	}

	public function file_source_updated_at( array $p_file ) {
		return isset( $p_file['date_added'] ) ? (int)$p_file['date_added'] : 0;
	}

	public function issue_exists( $p_issue_id ) {
		$t_bug_table = db_get_table( 'bug' );
		$t_res = db_query( "SELECT id FROM $t_bug_table WHERE id=" . db_param(), array( (int)$p_issue_id ) );
		return db_num_rows( $t_res ) > 0;
	}

	public function note_exists( $p_issue_id, $p_note_id ) {
		$t_note_table = db_get_table( 'bugnote' );
		$t_res = db_query( "SELECT id FROM $t_note_table WHERE id=" . db_param() . ' AND bug_id=' . db_param(), array( (int)$p_note_id, (int)$p_issue_id ) );
		return db_num_rows( $t_res ) > 0;
	}

	public function file_exists( $p_issue_id, $p_file_id ) {
		$t_file_table = db_get_table( 'bug_file' );
		$t_res = db_query( "SELECT id FROM $t_file_table WHERE id=" . db_param() . ' AND bug_id=' . db_param(), array( (int)$p_file_id, (int)$p_issue_id ) );
		return db_num_rows( $t_res ) > 0;
	}
}
