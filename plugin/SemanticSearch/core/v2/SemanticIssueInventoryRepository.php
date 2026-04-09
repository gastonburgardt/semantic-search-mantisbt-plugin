<?php

class SemanticIssueInventoryRepository {
	public function load_issue( $p_issue_id ) {
		return bug_get( (int)$p_issue_id, true );
	}

	public function load_notes( $p_issue_id ) {
		return bugnote_get_all_bugnotes( (int)$p_issue_id );
	}

	public function load_attachments( $p_issue_id ) {
		$t_raw = file_get_visible_attachments( (int)$p_issue_id );
		$t_db_files = $this->load_attachment_rows_by_issue( (int)$p_issue_id );
		$t_out = array();
		foreach( $t_raw as $t_file ) {
			$t_file_id = isset( $t_file['id'] ) ? (int)$t_file['id'] : 0;
			if( $t_file_id > 0 && isset( $t_db_files[$t_file_id] ) ) {
				$t_file = $t_db_files[$t_file_id] + $t_file;
			}
			$t_file['sem_file_exists'] = $this->attachment_physical_exists( $t_file );
			$t_file['sem_text_content'] = $this->attachment_text_content( $t_file );
			$t_out[] = $t_file;
		}
		return $t_out;
	}

	private function load_attachment_rows_by_issue( $p_issue_id ) {
		$t_file_table = db_get_table( 'bug_file' );
		$t_res = db_query(
			"SELECT id, filename, folder, diskfile, filesize, file_type, content, date_added, bugnote_id FROM $t_file_table WHERE bug_id=" . db_param(),
			array( (int)$p_issue_id )
		);
		$t_rows = array();
		while( $t_row = db_fetch_array( $t_res ) ) {
			$t_file_id = isset( $t_row['id'] ) ? (int)$t_row['id'] : 0;
			if( $t_file_id <= 0 ) {
				continue;
			}
			$t_rows[$t_file_id] = array(
				'id' => $t_file_id,
				'filename' => isset( $t_row['filename'] ) ? $t_row['filename'] : '',
				'folder' => isset( $t_row['folder'] ) ? $t_row['folder'] : '',
				'diskfile' => isset( $t_row['diskfile'] ) ? $t_row['diskfile'] : '',
				'filesize' => isset( $t_row['filesize'] ) ? $t_row['filesize'] : 0,
				'file_type' => isset( $t_row['file_type'] ) ? $t_row['file_type'] : '',
				'content' => isset( $t_row['content'] ) ? $t_row['content'] : null,
				'date_added' => isset( $t_row['date_added'] ) ? $t_row['date_added'] : 0,
				'bugnote_id' => isset( $t_row['bugnote_id'] ) ? $t_row['bugnote_id'] : 0,
			);
		}
		return $t_rows;
	}

	private function attachment_physical_exists( array $p_file ) {
		if( $this->attachment_has_inline_content( $p_file ) ) {
			return true;
		}
		$t_diskfile = isset( $p_file['diskfile'] ) ? trim( (string)$p_file['diskfile'] ) : '';
		if( $t_diskfile === '' ) {
			return true;
		}
		if( $t_diskfile[0] === '/' ) {
			return @file_exists( $t_diskfile );
		}
		$t_folder = isset( $p_file['folder'] ) ? rtrim( (string)$p_file['folder'], '/' ) : '';
		if( $t_folder !== '' ) {
			return @file_exists( $t_folder . '/' . $t_diskfile );
		}
		return false;
	}

	private function attachment_has_inline_content( array $p_file ) {
		if( !array_key_exists( 'content', $p_file ) ) {
			return false;
		}
		$t_content = $p_file['content'];
		if( $t_content === null ) {
			return false;
		}
		return strlen( (string)$t_content ) > 0;
	}

	private function attachment_text_content( array $p_file ) {
		if( !$this->attachment_has_inline_content( $p_file ) ) {
			return '';
		}
		$t_file_type = isset( $p_file['file_type'] ) ? strtolower( trim( (string)$p_file['file_type'] ) ) : '';
		$t_filename = isset( $p_file['filename'] ) ? strtolower( trim( (string)$p_file['filename'] ) ) : '';
		$t_extension = pathinfo( $t_filename, PATHINFO_EXTENSION );
		$t_is_text = strpos( $t_file_type, 'text/' ) === 0 || in_array( $t_extension, array( 'txt', 'md', 'log', 'csv' ), true );
		if( !$t_is_text ) {
			return '';
		}

		$t_content = (string)$p_file['content'];
		$t_content = preg_replace( "/\r\n?/", "\n", $t_content );
		return trim( $t_content );
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

	public function file_physical_exists( $p_issue_id, $p_file_id ) {
		$t_file_table = db_get_table( 'bug_file' );
		$t_res = db_query( "SELECT diskfile, folder, content FROM $t_file_table WHERE id=" . db_param() . ' AND bug_id=' . db_param(), array( (int)$p_file_id, (int)$p_issue_id ) );
		if( db_num_rows( $t_res ) <= 0 ) {
			return false;
		}
		$t_row = db_fetch_array( $t_res );
		if( $this->attachment_has_inline_content( $t_row ) ) {
			return true;
		}
		$t_diskfile = isset( $t_row['diskfile'] ) ? trim( (string)$t_row['diskfile'] ) : '';
		if( $t_diskfile === '' ) {
			return true;
		}
		if( $t_diskfile[0] === '/' ) {
			return @file_exists( $t_diskfile );
		}
		$t_folder = isset( $t_row['folder'] ) ? rtrim( (string)$t_row['folder'], '/' ) : '';
		if( $t_folder !== '' ) {
			return @file_exists( $t_folder . '/' . $t_diskfile );
		}
		return false;
	}
}
