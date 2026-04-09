<?php

require_once __DIR__ . '/SemanticDomain.php';
require_once __DIR__ . '/SemanticPolicyRepository.php';
require_once __DIR__ . '/SemanticIssueInventoryRepository.php';

class SemanticV2Engine {
	private $plugin;

	private function rowv( array $r, $name, $default = null ) {
		if( array_key_exists( $name, $r ) ) { return $r[$name]; }
		$ln = strtolower( $name );
		foreach( $r as $k => $v ) {
			if( strtolower( (string)$k ) === $ln ) { return $v; }
		}
		return $default;
	}
	private $openai;
	private $qdrant;
	private $policy_repo;
	private $inventory_repo;

	public function __construct( $p_plugin ) {
		$this->plugin = $p_plugin;
		$this->openai = new OpenAIEmbeddingClient( $p_plugin );
		$this->qdrant = new QdrantClient( $p_plugin );
		$this->policy_repo = new SemanticPolicyRepository();
		$this->inventory_repo = new SemanticIssueInventoryRepository();
	}

	public function review_issue_policy( $p_issue_id ) {
		$t_issue_id = (int)$p_issue_id;
		$t_bug = $this->inventory_repo->load_issue( $t_issue_id );
		$t_notes = $this->inventory_repo->load_notes( $t_issue_id );
		$t_files = $this->inventory_repo->load_attachments( $t_issue_id );
		$t_now = time();

		$t_note_items = array();
		foreach( $t_notes as $t_note ) {
			$t_note_items[] = array( 'item_id' => (int)$t_note->id );
		}
		$t_file_items = array();
		foreach( $t_files as $t_file ) {
			$t_file_items[] = array( 'item_id' => (int)$t_file['id'], 'parent_note_id' => isset( $t_file['bugnote_id'] ) ? (int)$t_file['bugnote_id'] : 0 );
		}
		$this->policy_repo->sync_inventory( $t_issue_id, array(), $t_note_items, $t_file_items );
		$t_tree = $this->policy_repo->by_issue( $t_issue_id );
		$t_issue_row = $t_tree['issue'];
		$t_note_rows = $t_tree['notes'];
		$t_file_rows = $t_tree['files'];

		$t_file_by_note = array();
		foreach( $t_file_rows as $t_fid => $t_file_row ) {
			$t_note_id = (int)$this->rowv( $t_file_row, 'NoteId', 0 );
			if( !isset( $t_file_by_note[$t_note_id] ) ) { $t_file_by_note[$t_note_id] = array(); }
			$t_file_by_note[$t_note_id][] = $t_fid;
		}

		$notes_with_children = array();
		foreach( $t_notes as $t_note ) {
			$t_note_id = (int)$t_note->id;
			$t_note_row = isset( $t_note_rows[$t_note_id] ) ? $t_note_rows[$t_note_id] : null;
			$t_note_indexable = $t_note_row !== null && (int)$this->rowv( $t_note_row, 'Indexable', 0 ) === 1;
			$t_has_children = false;
			if( isset( $t_file_by_note[$t_note_id] ) ) {
				foreach( $t_file_by_note[$t_note_id] as $t_fid ) {
					$t_row = $t_file_rows[$t_fid];
					$t_file_src = $this->find_file( $t_files, $t_fid );
					$t_source_hash = $this->inventory_repo->file_source_hash( $t_file_src );
					$t_source_updated_at = $this->inventory_repo->file_source_updated_at( $t_file_src );
					$t_file_empty = (int)( isset( $t_file_src['size'] ) ? $t_file_src['size'] : 0 ) <= 0;
					if( !$t_note_indexable ) {
						$t_indexed = (int)$this->rowv( $t_row, 'Indexed', 0 ) === 1;
						$t_action = $t_indexed ? SemanticPolicyAction::DELETE_INDEX : SemanticPolicyAction::NOTHING;
						$t_eval = array( 'Action' => $t_action, 'StoreHash' => $t_source_hash );
					} else {
						$t_eval = $this->evaluate_action( (int)$this->rowv( $t_row, 'Indexable', 0 ) === 1, $t_file_empty, (int)$this->rowv( $t_row, 'Indexed', 0 ) === 1, $t_source_hash, (string)$this->rowv( $t_row, 'Hash', '' ), $t_source_updated_at, $this->rowv( $t_row, 'IndexedAt', null ) );
					}
					$t_level = $t_eval['Action'] === SemanticPolicyAction::NOTHING ? SemanticReviewLevel::NONE : SemanticReviewLevel::ONLY_ME;
					if( $t_eval['Action'] !== SemanticPolicyAction::NOTHING ) { $t_has_children = true; }
					$this->policy_repo->save_file_state( $t_issue_id, $t_note_id, $t_fid, array(
						'Hash' => $t_eval['StoreHash'],
						'Empty' => $t_file_empty,
						'Indexed' => (int)$this->rowv( $t_row, 'Indexed', 0 ) === 1,
						'IndexedAt' => $this->rowv( $t_row, 'IndexedAt', null ),
						'Action' => $t_eval['Action'],
						'NivelDeRevision' => $t_level,
					) );
				}
			}

			if( $t_note_row === null ) { continue; }
			$t_note_hash = $this->inventory_repo->note_source_hash( $t_note );
			$t_note_updated_at = $this->inventory_repo->note_source_updated_at( $t_note );
			$t_note_empty = trim( (string)$t_note->note ) === '';
			$t_eval_note = $this->evaluate_action( (int)$this->rowv( $t_note_row, 'Indexable', 0 ) === 1, $t_note_empty, (int)$this->rowv( $t_note_row, 'Indexed', 0 ) === 1, $t_note_hash, (string)$this->rowv( $t_note_row, 'Hash', '' ), $t_note_updated_at, $this->rowv( $t_note_row, 'IndexedAt', null ) );
			$t_self = $t_eval_note['Action'] !== SemanticPolicyAction::NOTHING;
			$t_level_note = $this->derive_level( $t_self, $t_has_children, false );
			$this->policy_repo->save_note_state( $t_issue_id, $t_note_id, array(
				'Hash' => $t_eval_note['StoreHash'],
				'Empty' => $t_note_empty,
				'Indexed' => (int)$this->rowv( $t_note_row, 'Indexed', 0 ) === 1,
				'IndexedAt' => $this->rowv( $t_note_row, 'IndexedAt', null ),
				'Action' => $t_eval_note['Action'],
				'NivelDeRevision' => $t_level_note,
			) );
			$notes_with_children[$t_note_id] = ( $t_self || $t_has_children );
		}

		$t_issue_hash = $this->inventory_repo->issue_source_hash( $t_bug );
		$t_issue_updated_at = $this->inventory_repo->issue_source_updated_at( $t_bug );
		$t_issue_empty = trim( (string)$t_bug->summary ) === '' && trim( (string)$t_bug->description ) === '';
		$t_eval_issue = $this->evaluate_action( (int)$this->rowv( $t_issue_row, 'Indexable', 0 ) === 1, $t_issue_empty, (int)$this->rowv( $t_issue_row, 'Indexed', 0 ) === 1, $t_issue_hash, (string)$this->rowv( $t_issue_row, 'Hash', '' ), $t_issue_updated_at, $this->rowv( $t_issue_row, 'IndexedAt', null ) );
		$t_has_children_issue = false;
		foreach( $notes_with_children as $t_pending ) { if( $t_pending ) { $t_has_children_issue = true; break; } }
		$t_self_issue = $t_eval_issue['Action'] !== SemanticPolicyAction::NOTHING;
		$t_level_issue = $this->derive_level( $t_self_issue, $t_has_children_issue, false );
		$this->policy_repo->save_issue_state( $t_issue_id, array(
			'Hash' => $t_eval_issue['StoreHash'],
			'Empty' => $t_issue_empty,
			'Indexed' => (int)$this->rowv( $t_issue_row, 'Indexed', 0 ) === 1,
			'IndexedAt' => $this->rowv( $t_issue_row, 'IndexedAt', null ),
			'Action' => $t_eval_issue['Action'],
			'NivelDeRevision' => $t_level_issue,
		) );

		if( (int)$this->rowv( $t_issue_row, 'Indexable', 0 ) !== 1 ) {
			$this->apply_core_blocking( $t_issue_id );
		}

		$this->second_pass_for_deleted_entities();
		$this->reconcile_parent_review_levels( $t_issue_id );
		return $this->get_issue_index_dashboard( $t_issue_id, true );
	}

	private function evaluate_action( $p_indexable, $p_empty, $p_indexed, $p_source_hash, $p_stored_hash, $p_source_updated_at, $p_indexed_at ) {
		$t_hash = (string)$p_source_hash;
		$t_stored_hash = (string)$p_stored_hash;
		$t_source_updated_at = (int)$p_source_updated_at;
		$t_indexed_at = ( $p_indexed_at === null || $p_indexed_at === '' ) ? 0 : (int)$p_indexed_at;
		if( !$p_indexable ) {
			return array( 'Action' => $p_indexed ? SemanticPolicyAction::DELETE_INDEX : SemanticPolicyAction::NOTHING, 'StoreHash' => $t_hash );
		}
		if( $p_empty ) {
			return array( 'Action' => SemanticPolicyAction::NOTHING, 'StoreHash' => $t_hash );
		}
		if( !$p_indexed ) {
			return array( 'Action' => SemanticPolicyAction::CREATE_INDEX, 'StoreHash' => $t_hash );
		}
		if( $t_stored_hash !== '' && $t_stored_hash === $t_hash ) {
			return array( 'Action' => SemanticPolicyAction::NOTHING, 'StoreHash' => $t_hash );
		}
		if( $t_indexed_at <= 0 ) {
			return array( 'Action' => SemanticPolicyAction::UPDATE_INDEX, 'StoreHash' => $t_hash );
		}
		if( $t_source_updated_at > $t_indexed_at ) {
			return array( 'Action' => SemanticPolicyAction::UPDATE_INDEX, 'StoreHash' => $t_hash );
		}
		return array( 'Action' => SemanticPolicyAction::NOTHING, 'StoreHash' => $t_hash );
	}

	private function derive_level( $p_self, $p_children, $p_is_file ) {
		if( $p_is_file ) {
			return $p_self ? SemanticReviewLevel::ONLY_ME : SemanticReviewLevel::NONE;
		}
		if( $p_self && $p_children ) { return SemanticReviewLevel::ME_AND_CHILDREN; }
		if( $p_self ) { return SemanticReviewLevel::ONLY_ME; }
		if( $p_children ) { return SemanticReviewLevel::ONLY_CHILDREN; }
		return SemanticReviewLevel::NONE;
	}

	private function apply_core_blocking( $p_issue_id ) {
		$t_tree = $this->policy_repo->by_issue( (int)$p_issue_id );
		$t_notes = $t_tree['notes'];
		$t_files = $t_tree['files'];
		$t_files_by_note = array();
		foreach( $t_files as $t_file ) {
			$t_note_id = (int)$this->rowv( $t_file, 'NoteId', 0 );
			if( !isset( $t_files_by_note[$t_note_id] ) ) { $t_files_by_note[$t_note_id] = array(); }
			$t_files_by_note[$t_note_id][] = $t_file;
		}

		foreach( $t_notes as $t_note_id => $t_note_row ) {
			$t_has_children_action = false;
			if( isset( $t_files_by_note[(int)$t_note_id] ) ) {
				foreach( $t_files_by_note[(int)$t_note_id] as $t_file_row ) {
					$t_file_indexed = (int)$this->rowv( $t_file_row, 'Indexed', 0 ) === 1;
					$t_file_action = $t_file_indexed ? SemanticPolicyAction::DELETE_INDEX : SemanticPolicyAction::NOTHING;
					if( $t_file_action !== SemanticPolicyAction::NOTHING ) { $t_has_children_action = true; }
					$this->policy_repo->save_file_state( (int)$p_issue_id, (int)$t_note_id, (int)$this->rowv( $t_file_row, 'FileId', 0 ), array(
						'Hash' => (string)$this->rowv( $t_file_row, 'Hash', '' ),
						'Empty' => (int)$this->rowv( $t_file_row, 'Empty', 0 ) === 1,
						'Indexed' => $t_file_indexed,
						'IndexedAt' => $this->rowv( $t_file_row, 'IndexedAt', null ),
						'Action' => $t_file_action,
						'NivelDeRevision' => $t_file_action === SemanticPolicyAction::NOTHING ? SemanticReviewLevel::NONE : SemanticReviewLevel::ONLY_ME,
					) );
				}
			}

			$t_note_indexed = (int)$this->rowv( $t_note_row, 'Indexed', 0 ) === 1;
			$t_note_action = $t_note_indexed ? SemanticPolicyAction::DELETE_INDEX : SemanticPolicyAction::NOTHING;
			$t_note_level = $this->derive_level( $t_note_action !== SemanticPolicyAction::NOTHING, $t_has_children_action, false );
			$this->policy_repo->save_note_state( (int)$p_issue_id, (int)$t_note_id, array(
				'Hash' => (string)$this->rowv( $t_note_row, 'Hash', '' ),
				'Empty' => (int)$this->rowv( $t_note_row, 'Empty', 0 ) === 1,
				'Indexed' => $t_note_indexed,
				'IndexedAt' => $this->rowv( $t_note_row, 'IndexedAt', null ),
				'Action' => $t_note_action,
				'NivelDeRevision' => $t_note_level,
			) );
		}
	}

	private function reconcile_parent_review_levels( $p_issue_id ) {
		$t_issue_id = (int)$p_issue_id;
		$t_tree = $this->policy_repo->by_issue( $t_issue_id );
		$t_issue = $t_tree['issue'];
		$t_notes = $t_tree['notes'];
		$t_files = $t_tree['files'];
		$t_files_by_note = array();
		foreach( $t_files as $t_file ) {
			$t_note_id = (int)$this->rowv( $t_file, 'NoteId', 0 );
			if( !isset( $t_files_by_note[$t_note_id] ) ) { $t_files_by_note[$t_note_id] = array(); }
			$t_files_by_note[$t_note_id][] = $t_file;
		}

		$t_issue_has_children = false;
		foreach( $t_notes as $t_note_id => $t_note ) {
			$t_note_self = (string)$this->rowv( $t_note, 'Action', SemanticPolicyAction::NOTHING ) !== SemanticPolicyAction::NOTHING;
			$t_note_children = false;
			if( isset( $t_files_by_note[(int)$t_note_id] ) ) {
				foreach( $t_files_by_note[(int)$t_note_id] as $t_file ) {
					if( (string)$this->rowv( $t_file, 'Action', SemanticPolicyAction::NOTHING ) !== SemanticPolicyAction::NOTHING ) {
						$t_note_children = true;
						break;
					}
				}
			}
			$t_note_level = $this->derive_level( $t_note_self, $t_note_children, false );
			if( $t_note_self || $t_note_children ) { $t_issue_has_children = true; }
			$this->policy_repo->save_note_state( $t_issue_id, (int)$t_note_id, array(
				'Hash' => (string)$this->rowv( $t_note, 'Hash', '' ),
				'Empty' => (int)$this->rowv( $t_note, 'Empty', 0 ) === 1,
				'Indexed' => (int)$this->rowv( $t_note, 'Indexed', 0 ) === 1,
				'IndexedAt' => $this->rowv( $t_note, 'IndexedAt', null ),
				'Action' => (string)$this->rowv( $t_note, 'Action', SemanticPolicyAction::NOTHING ),
				'NivelDeRevision' => $t_note_level,
			) );
		}

		$t_issue_self = (string)$this->rowv( $t_issue, 'Action', SemanticPolicyAction::NOTHING ) !== SemanticPolicyAction::NOTHING;
		$t_issue_level = $this->derive_level( $t_issue_self, $t_issue_has_children, false );
		$this->policy_repo->save_issue_state( $t_issue_id, array(
			'Hash' => (string)$this->rowv( $t_issue, 'Hash', '' ),
			'Empty' => (int)$this->rowv( $t_issue, 'Empty', 0 ) === 1,
			'Indexed' => (int)$this->rowv( $t_issue, 'Indexed', 0 ) === 1,
			'IndexedAt' => $this->rowv( $t_issue, 'IndexedAt', null ),
			'Action' => (string)$this->rowv( $t_issue, 'Action', SemanticPolicyAction::NOTHING ),
			'NivelDeRevision' => $t_issue_level,
		) );
	}

	private function find_file( array $p_files, $p_file_id ) {
		foreach( $p_files as $t_file ) {
			if( (int)$t_file['id'] === (int)$p_file_id ) { return $t_file; }
		}
		return array( 'display_name' => '', 'filename' => '', 'size' => 0, 'date_added' => 0 );
	}

	private function second_pass_for_deleted_entities() {
		$t_rows = $this->policy_repo->all_plugin_rows();
		foreach( $t_rows['files'] as $t_row ) {
			$t_issue_id = (int)$this->rowv( $t_row, 'IssueId', 0 );
			$t_note_id = (int)$this->rowv( $t_row, 'NoteId', 0 );
			$t_file_id = (int)$this->rowv( $t_row, 'FileId', 0 );
			$t_exists = $this->inventory_repo->file_exists( $t_issue_id, $t_file_id );
			$t_physical_exists = $t_exists ? $this->inventory_repo->file_physical_exists( $t_issue_id, $t_file_id ) : false;
			if( !$t_exists || !$t_physical_exists ) {
				$t_indexed = (int)$this->rowv( $t_row, 'Indexed', 0 ) === 1;
				$this->policy_repo->save_file_state( $t_issue_id, $t_note_id, $t_file_id, array(
					'Hash' => (string)$this->rowv( $t_row, 'Hash', '' ), 'Empty' => (int)$this->rowv( $t_row, 'Empty', 0 ) === 1, 'Indexed' => $t_indexed,
					'IndexedAt' => $this->rowv( $t_row, 'IndexedAt', null ),
					'Action' => $t_indexed ? SemanticPolicyAction::DELETE_INDEX : SemanticPolicyAction::NOTHING,
					'Deleted' => true,
					'DeletedAt' => time(),
					'NivelDeRevision' => $t_indexed ? SemanticReviewLevel::ONLY_ME : SemanticReviewLevel::NONE,
				) );
			}
		}
		foreach( $t_rows['notes'] as $t_row ) {
			$t_issue_id = (int)$this->rowv( $t_row, 'IssueId', 0 );
			$t_note_id = (int)$this->rowv( $t_row, 'NoteId', 0 );
			$t_exists = $this->inventory_repo->note_exists( $t_issue_id, $t_note_id );
			if( !$t_exists ) {
				$t_indexed = (int)$this->rowv( $t_row, 'Indexed', 0 ) === 1;
				$this->policy_repo->save_note_state( $t_issue_id, $t_note_id, array(
					'Hash' => (string)$this->rowv( $t_row, 'Hash', '' ), 'Empty' => (int)$this->rowv( $t_row, 'Empty', 0 ) === 1, 'Indexed' => $t_indexed,
					'IndexedAt' => $this->rowv( $t_row, 'IndexedAt', null ),
					'Action' => $t_indexed ? SemanticPolicyAction::DELETE_INDEX : SemanticPolicyAction::NOTHING,
					'Deleted' => true,
					'DeletedAt' => time(),
					'NivelDeRevision' => $t_indexed ? SemanticReviewLevel::ONLY_ME : SemanticReviewLevel::NONE,
				) );
			}
		}
		foreach( $t_rows['issues'] as $t_row ) {
			$t_issue_id = (int)$this->rowv( $t_row, 'IssueId', 0 );
			$t_exists = $this->inventory_repo->issue_exists( $t_issue_id );
			if( !$t_exists ) {
				$t_indexed = (int)$this->rowv( $t_row, 'Indexed', 0 ) === 1;
				$this->policy_repo->save_issue_state( $t_issue_id, array(
					'Hash' => (string)$this->rowv( $t_row, 'Hash', '' ), 'Empty' => (int)$this->rowv( $t_row, 'Empty', 0 ) === 1, 'Indexed' => $t_indexed,
					'IndexedAt' => $this->rowv( $t_row, 'IndexedAt', null ),
					'Action' => $t_indexed ? SemanticPolicyAction::DELETE_INDEX : SemanticPolicyAction::NOTHING,
					'Deleted' => true,
					'DeletedAt' => time(),
					'NivelDeRevision' => $t_indexed ? SemanticReviewLevel::ONLY_ME : SemanticReviewLevel::NONE,
				) );
			}
		}
	}

	public function update_issue_index_policy( $p_issue_id, $p_core_indexable, array $p_note_flags, array $p_attachment_flags ) {
		$t_issue_id = (int)$p_issue_id;
		$this->policy_repo->set_indexable( $t_issue_id, SemanticEntityType::ISSUE, 0, (bool)$p_core_indexable );
		foreach( $p_note_flags as $t_note_id => $t_flag ) {
			$this->policy_repo->set_indexable( $t_issue_id, SemanticEntityType::ISSUENOTE, (int)$t_note_id, (bool)$t_flag );
		}
		foreach( $p_attachment_flags as $t_file_id => $t_flag ) {
			$this->policy_repo->set_indexable( $t_issue_id, SemanticEntityType::ISSUENOTEFILE, (int)$t_file_id, (bool)$t_flag );
		}
		return $this->review_issue_policy( $t_issue_id );
	}

	public function get_issue_index_dashboard( $p_issue_id, $p_skip_reconcile = false ) {
		if( !$p_skip_reconcile ) {
			$this->review_issue_policy( (int)$p_issue_id );
		}
		$t_tree = $this->policy_repo->by_issue( (int)$p_issue_id );
		$t_notes = array();
		$t_files = array();
		$t_pending = 0;
		$t_create = 0; $t_update = 0; $t_delete = 0; $t_nothing = 0;
		$t_review_solo = 0; $t_review_hijos = 0; $t_review_mixed = 0; $t_review_none = 0;

		foreach( $t_tree['notes'] as $t_note ) {
			$t_row = $this->to_dashboard_row_note( $t_note );
			$t_notes[] = $t_row;
			$this->add_counts( $t_row, $t_pending, $t_create, $t_update, $t_delete, $t_nothing, $t_review_solo, $t_review_hijos, $t_review_mixed, $t_review_none );
		}
		foreach( $t_tree['files'] as $t_file ) {
			$t_row = $this->to_dashboard_row_file( $t_file );
			$t_files[] = $t_row;
			$this->add_counts( $t_row, $t_pending, $t_create, $t_update, $t_delete, $t_nothing, $t_review_solo, $t_review_hijos, $t_review_mixed, $t_review_none );
		}

		$t_core = $this->to_dashboard_row_issue( $t_tree['issue'] );
		$this->add_counts( $t_core, $t_pending, $t_create, $t_update, $t_delete, $t_nothing, $t_review_solo, $t_review_hijos, $t_review_mixed, $t_review_none );

		$t_core_blocked = empty( $t_core['indexable'] );
		$t_note_indexable = array();
		foreach( $t_notes as &$t_note_row ) {
			$t_note_row['blocked_by_core'] = $t_core_blocked;
			$t_note_row['effective_indexable'] = !empty( $t_note_row['indexable'] ) && !$t_core_blocked;
			$t_note_indexable[(int)$t_note_row['id']] = !empty( $t_note_row['indexable'] );
		}
		unset( $t_note_row );
		foreach( $t_files as &$t_file_row ) {
			$t_note_id = (int)$t_file_row['note_id'];
			$t_blocked_by_note = isset( $t_note_indexable[$t_note_id] ) ? !$t_note_indexable[$t_note_id] : false;
			$t_file_row['blocked_by_note'] = $t_blocked_by_note;
			$t_file_row['blocked_by_core'] = $t_core_blocked;
			$t_file_row['effective_indexable'] = !empty( $t_file_row['indexable'] ) && !$t_blocked_by_note && !$t_core_blocked;
		}
		unset( $t_file_row );

		return array(
			'core' => $t_core,
			'notes' => $t_notes,
			'attachments' => $t_files,
			'pending_review_count' => $t_pending,
			'has_pending_review' => $t_pending > 0,
			'effective_to_index' => $t_create + $t_update,
			'effective_to_delete' => $t_delete,
			'create_count' => $t_create,
			'update_count' => $t_update,
			'delete_count' => $t_delete,
			'nothing_count' => $t_nothing,
			'review_solo_mi' => $t_review_solo,
			'review_solo_hijos' => $t_review_hijos,
			'review_mi_hijos' => $t_review_mixed,
			'review_none' => $t_review_none,
		);
	}

	private function add_counts( array $r, &$pending, &$create, &$update, &$delete, &$nothing, &$solo, &$hijos, &$mix, &$none ) {
		$a = (string)$r['target_status'];
		if( $a !== SemanticPolicyAction::NOTHING ) { $pending++; }
		if( $a === SemanticPolicyAction::CREATE_INDEX ) { $create++; }
		elseif( $a === SemanticPolicyAction::UPDATE_INDEX ) { $update++; }
		elseif( $a === SemanticPolicyAction::DELETE_INDEX ) { $delete++; }
		else { $nothing++; }
		$l = (string)$r['review_level'];
		if( $l === SemanticReviewLevel::ONLY_ME ) { $solo++; }
		elseif( $l === SemanticReviewLevel::ONLY_CHILDREN ) { $hijos++; }
		elseif( $l === SemanticReviewLevel::ME_AND_CHILDREN ) { $mix++; }
		else { $none++; }
	}

	private function to_dashboard_row_issue( array $r ) {
		$t_action = (string)$this->rowv( $r, 'Action', SemanticPolicyAction::NOTHING );
		$t_level = (string)$this->rowv( $r, 'NivelDeRevision', SemanticReviewLevel::NONE );
		$t_hash = (string)$this->rowv( $r, 'Hash', '' );
		return array(
			'indexable' => (int)$this->rowv( $r, 'Indexable', 0 ) === 1,
			'effective_indexable' => (int)$this->rowv( $r, 'Indexable', 0 ) === 1,
			'empty' => (int)$this->rowv( $r, 'Empty', 0 ) === 1,
			'deleted' => (int)$this->rowv( $r, 'Deleted', 0 ) === 1,
			'deleted_at' => (int)$this->rowv( $r, 'DeletedAt', 0 ),
			'indexed' => (int)$this->rowv( $r, 'Indexed', 0 ) === 1,
			'status' => $t_action,
			'target_status' => $t_action,
			'source_hash' => $t_hash,
			'last_indexed_hash' => $t_hash,
			'created_at' => (int)$this->rowv( $r, 'CreatedAt', 0 ),
			'updated_at' => (int)$this->rowv( $r, 'UpdatedAt', 0 ),
			'indexed_at' => (int)$this->rowv( $r, 'IndexedAt', 0 ),
			'child_pending_review' => in_array( $t_level, array( SemanticReviewLevel::ONLY_CHILDREN, SemanticReviewLevel::ME_AND_CHILDREN ), true ),
			'needs_review' => $t_action !== SemanticPolicyAction::NOTHING,
			'review_level' => $t_level,
		);
	}

	private function to_dashboard_row_note( array $r ) {
		$t_action = (string)$this->rowv( $r, 'Action', SemanticPolicyAction::NOTHING );
		$t_level = (string)$this->rowv( $r, 'NivelDeRevision', SemanticReviewLevel::NONE );
		$t_hash = (string)$this->rowv( $r, 'Hash', '' );
		return array(
			'id' => (int)$this->rowv( $r, 'NoteId', 0 ),
			'text' => '',
			'indexable' => (int)$this->rowv( $r, 'Indexable', 0 ) === 1,
			'effective_indexable' => (int)$this->rowv( $r, 'Indexable', 0 ) === 1,
			'empty' => (int)$this->rowv( $r, 'Empty', 0 ) === 1,
			'deleted' => (int)$this->rowv( $r, 'Deleted', 0 ) === 1,
			'deleted_at' => (int)$this->rowv( $r, 'DeletedAt', 0 ),
			'indexed' => (int)$this->rowv( $r, 'Indexed', 0 ) === 1,
			'blocked_by_core' => false,
			'status' => $t_action,
			'target_status' => $t_action,
			'source_hash' => $t_hash,
			'last_indexed_hash' => $t_hash,
			'created_at' => (int)$this->rowv( $r, 'CreatedAt', 0 ),
			'updated_at' => (int)$this->rowv( $r, 'UpdatedAt', 0 ),
			'indexed_at' => (int)$this->rowv( $r, 'IndexedAt', 0 ),
			'child_pending_review' => in_array( $t_level, array( SemanticReviewLevel::ONLY_CHILDREN, SemanticReviewLevel::ME_AND_CHILDREN ), true ),
			'needs_review' => $t_action !== SemanticPolicyAction::NOTHING,
			'review_level' => $t_level,
		);
	}

	private function to_dashboard_row_file( array $r ) {
		$t_file_id = (int)$this->rowv( $r, 'FileId', 0 );
		$t_action = (string)$this->rowv( $r, 'Action', SemanticPolicyAction::NOTHING );
		$t_hash = (string)$this->rowv( $r, 'Hash', '' );
		return array(
			'id' => $t_file_id,
			'name' => 'File #' . $t_file_id,
			'note_id' => (int)$this->rowv( $r, 'NoteId', 0 ),
			'indexable' => (int)$this->rowv( $r, 'Indexable', 0 ) === 1,
			'effective_indexable' => (int)$this->rowv( $r, 'Indexable', 0 ) === 1,
			'empty' => (int)$this->rowv( $r, 'Empty', 0 ) === 1,
			'deleted' => (int)$this->rowv( $r, 'Deleted', 0 ) === 1,
			'deleted_at' => (int)$this->rowv( $r, 'DeletedAt', 0 ),
			'indexed' => (int)$this->rowv( $r, 'Indexed', 0 ) === 1,
			'blocked_by_note' => false,
			'blocked_by_core' => false,
			'status' => $t_action,
			'target_status' => $t_action,
			'source_hash' => $t_hash,
			'last_indexed_hash' => $t_hash,
			'created_at' => (int)$this->rowv( $r, 'CreatedAt', 0 ),
			'updated_at' => (int)$this->rowv( $r, 'UpdatedAt', 0 ),
			'indexed_at' => (int)$this->rowv( $r, 'IndexedAt', 0 ),
			'needs_review' => $t_action !== SemanticPolicyAction::NOTHING,
			'review_level' => (string)$this->rowv( $r, 'NivelDeRevision', SemanticReviewLevel::NONE ),
		);
	}

	public function index_issue( $p_issue_id, array $p_options = array() ) {
		$t_issue_id = (int)$p_issue_id;
		$t_dash = $this->review_issue_policy( $t_issue_id );
		if( empty( $t_dash['has_pending_review'] ) ) {
			return;
		}
		$t_bug = bug_get( $t_issue_id, true );
		$t_text = $this->build_index_text( $t_bug, array(), $t_dash );
		if( trim( $t_text ) !== '' ) {
			$t_vector = $this->openai->embed( $t_text );
			$this->qdrant->ensure_collection( count( $t_vector ), (int)$t_bug->project_id, project_get_name( (int)$t_bug->project_id ) );
			$this->qdrant->upsert_issue( $t_issue_id, $t_vector, $this->build_payload( $t_bug, array() ) );
		}

		$t_now = time();
		$t_tree = $this->policy_repo->by_issue( $t_issue_id );
		$this->apply_index_result_issue( $t_issue_id, $t_tree['issue'], $t_now );
		foreach( $t_tree['notes'] as $t_note ) { $this->apply_index_result_note( $t_issue_id, $t_note, $t_now ); }
		foreach( $t_tree['files'] as $t_file ) { $this->apply_index_result_file( $t_issue_id, $t_file, $t_now ); }
	}

	private function apply_index_result_issue( $issue_id, array $r, $now ) {
		$action = (string)$this->rowv( $r, 'Action', SemanticPolicyAction::NOTHING );
		if( $action === SemanticPolicyAction::NOTHING ) { return; }
		$indexed = $action === SemanticPolicyAction::DELETE_INDEX ? false : true;
		$this->policy_repo->save_issue_state( $issue_id, array( 'Hash'=>(string)$this->rowv( $r, 'Hash', '' ), 'Empty'=>(int)$this->rowv( $r, 'Empty', 0 )===1, 'Indexed'=>$indexed, 'IndexedAt'=>($indexed ? $now : null), 'Action'=>SemanticPolicyAction::NOTHING, 'NivelDeRevision'=>SemanticReviewLevel::NONE ) );
		if( $action === SemanticPolicyAction::DELETE_INDEX ) { $this->delete_issue_vector( $issue_id ); }
	}
	private function apply_index_result_note( $issue_id, array $r, $now ) {
		$action = (string)$this->rowv( $r, 'Action', SemanticPolicyAction::NOTHING ); if( $action === SemanticPolicyAction::NOTHING ) { return; }
		$indexed = $action === SemanticPolicyAction::DELETE_INDEX ? false : true;
		$this->policy_repo->save_note_state( $issue_id, (int)$this->rowv( $r, 'NoteId', 0 ), array( 'Hash'=>(string)$this->rowv( $r, 'Hash', '' ), 'Empty'=>(int)$this->rowv( $r, 'Empty', 0 )===1, 'Indexed'=>$indexed, 'IndexedAt'=>($indexed ? $now : null), 'Action'=>SemanticPolicyAction::NOTHING, 'NivelDeRevision'=>SemanticReviewLevel::NONE ) );
	}
	private function apply_index_result_file( $issue_id, array $r, $now ) {
		$action = (string)$this->rowv( $r, 'Action', SemanticPolicyAction::NOTHING ); if( $action === SemanticPolicyAction::NOTHING ) { return; }
		$indexed = $action === SemanticPolicyAction::DELETE_INDEX ? false : true;
		$this->policy_repo->save_file_state( $issue_id, (int)$this->rowv( $r, 'NoteId', 0 ), (int)$this->rowv( $r, 'FileId', 0 ), array( 'Hash'=>(string)$this->rowv( $r, 'Hash', '' ), 'Empty'=>(int)$this->rowv( $r, 'Empty', 0 )===1, 'Indexed'=>$indexed, 'IndexedAt'=>($indexed ? $now : null), 'Action'=>SemanticPolicyAction::NOTHING, 'NivelDeRevision'=>SemanticReviewLevel::NONE ) );
	}

	public function delete_issue_vector( $p_issue_id ) {
		$t_bug = bug_get( (int)$p_issue_id, true );
		$this->qdrant->delete_issue( (int)$p_issue_id, (int)$t_bug->project_id, project_get_name( (int)$t_bug->project_id ) );
	}
	public function mark_issue_not_current( $p_issue_id, $p_status ) {
		$t_bug = bug_get( (int)$p_issue_id, true );
		$this->qdrant->update_payload( (int)$p_issue_id, array( 'is_currently_indexed_status' => false ), (int)$t_bug->project_id, project_get_name( (int)$t_bug->project_id ) );
	}

	public function count_reindex_candidates_filtered( array $p_filters ) { $s = $this->collect_reindex_stats_filtered( $p_filters ); return (int)$s['total']; }

	public function collect_reindex_stats_filtered( array $p_filters ) {
		$t_ids = $this->list_reindex_candidate_ids( $p_filters );
		$t_stats = array( 'total'=>count($t_ids), 'indexed_current'=>0, 'pending_total'=>0, 'pending_body'=>0, 'pending_attachments'=>0, 'pending_new_total'=>0, 'pending_modified_total'=>0, 'create_count'=>0, 'update_count'=>0, 'delete_count'=>0, 'nothing_count'=>0, 'review_solo_mi'=>0, 'review_solo_hijos'=>0, 'review_mi_hijos'=>0, 'review_none'=>0 );
		foreach( $t_ids as $id ) {
			$d = $this->get_issue_index_dashboard( $id );
			$t_stats['create_count'] += (int)$d['create_count'];
			$t_stats['update_count'] += (int)$d['update_count'];
			$t_stats['delete_count'] += (int)$d['delete_count'];
			$t_stats['nothing_count'] += (int)$d['nothing_count'];
			$t_stats['review_solo_mi'] += (int)$d['review_solo_mi'];
			$t_stats['review_solo_hijos'] += (int)$d['review_solo_hijos'];
			$t_stats['review_mi_hijos'] += (int)$d['review_mi_hijos'];
			$t_stats['review_none'] += (int)$d['review_none'];
			if( !empty( $d['has_pending_review'] ) ) { $t_stats['pending_total']++; } else { $t_stats['indexed_current']++; }
		}
		$t_stats['pending_new_total'] = $t_stats['create_count'];
		$t_stats['pending_modified_total'] = $t_stats['update_count'] + $t_stats['delete_count'];
		return $t_stats;
	}

	public function process_policy_batch_filtered( array $p_filters, $p_last_id = 0, $p_batch_size = 25, $p_processed = 0 ) {
		$t_ids = $this->list_reindex_candidate_ids( $p_filters );
		$start = count( $t_ids );
		for( $i = 0; $i < count($t_ids); $i++ ) { if( $t_ids[$i] > (int)$p_last_id ) { $start = $i; break; } }
		$chunk = array_slice( $t_ids, $start, (int)$p_batch_size );
		$flagged=0;$clean=0;$failed=0;$to_index=0;$to_delete=0;$last=(int)$p_last_id;
		$create=0;$update=0;$delete=0;$nothing=0;$solo=0;$hijos=0;$mix=0;$none=0;
		foreach( $chunk as $id ) {
			$last = $id;
			try {
				$d = $this->review_issue_policy( $id );
				if( !empty( $d['has_pending_review'] ) ) { $flagged++; } else { $clean++; }
				$to_index += (int)$d['effective_to_index'];
				$to_delete += (int)$d['effective_to_delete'];
				$create += (int)$d['create_count']; $update += (int)$d['update_count']; $delete += (int)$d['delete_count']; $nothing += (int)$d['nothing_count'];
				$solo += (int)$d['review_solo_mi']; $hijos += (int)$d['review_solo_hijos']; $mix += (int)$d['review_mi_hijos']; $none += (int)$d['review_none'];
			} catch( Throwable $e ) { $failed++; }
		}
		$done = ($start + count($chunk)) >= count($t_ids);
		return array('flagged'=>$flagged,'clean'=>$clean,'failed'=>$failed,'to_index'=>$to_index,'to_delete'=>$to_delete,'last_id'=>$last,'seen'=>count($chunk),'done'=>$done,'create_count'=>$create,'update_count'=>$update,'delete_count'=>$delete,'nothing_count'=>$nothing,'review_solo_mi'=>$solo,'review_solo_hijos'=>$hijos,'review_mi_hijos'=>$mix,'review_none'=>$none);
	}

	public function reindex_batch_filtered( array $p_filters, $p_last_id = 0, $p_batch_size = 25, $p_processed = 0 ) {
		$t_ids = $this->list_reindex_candidate_ids( $p_filters );
		$t_pending_only = !isset( $p_filters['pending_only'] ) || !empty( $p_filters['pending_only'] );
		$t_force = !empty( $p_filters['force_revectorize'] );
		$start = count( $t_ids );
		for( $i = 0; $i < count($t_ids); $i++ ) { if( $t_ids[$i] > (int)$p_last_id ) { $start = $i; break; } }
		$chunk = array_slice( $t_ids, $start, (int)$p_batch_size );
		$ok=0;$failed=0;$skip=0;$last=(int)$p_last_id;
		foreach( $chunk as $id ) {
			$last = $id;
			try {
				$d = $this->get_issue_index_dashboard( $id, true );
				if( $t_force ) {
					$this->force_revectorize_issue( $id );
					$ok++;
					continue;
				}
				if( $t_pending_only && empty( $d['has_pending_review'] ) ) { $skip++; continue; }
				$this->index_issue( $id, array( 'skip_review' => true ) );
				$ok++;
			} catch( Throwable $e ) { $failed++; }
		}
		$done = ($start + count($chunk)) >= count($t_ids);
		return array('indexed'=>$ok,'failed'=>$failed,'skipped'=>$skip,'last_id'=>$last,'seen'=>count($chunk),'done'=>$done);
	}

	private function force_revectorize_issue( $p_issue_id ) {
		$t_issue_id = (int)$p_issue_id;
		$t_bug = bug_get( $t_issue_id, true );
		$t_dash = $this->review_issue_policy( $t_issue_id );
		try { $this->delete_issue_vector( $t_issue_id ); } catch( Throwable $e ) { }
		$t_text = $this->build_index_text( $t_bug, array(), $t_dash );
		if( trim( $t_text ) === '' ) {
			return;
		}
		$t_vector = $this->openai->embed( $t_text );
		$this->qdrant->ensure_collection( count( $t_vector ), (int)$t_bug->project_id, project_get_name( (int)$t_bug->project_id ) );
		$this->qdrant->upsert_issue( $t_issue_id, $t_vector, $this->build_payload( $t_bug, array() ) );
		$t_now = time();
		$t_tree = $this->policy_repo->by_issue( $t_issue_id );
		$this->apply_index_result_issue( $t_issue_id, $t_tree['issue'], $t_now );
		foreach( $t_tree['notes'] as $t_note ) { $this->apply_index_result_note( $t_issue_id, $t_note, $t_now ); }
		foreach( $t_tree['files'] as $t_file ) { $this->apply_index_result_file( $t_issue_id, $t_file, $t_now ); }
	}

	private function list_reindex_candidate_ids( array $p_filters ) {
		list( $where, $params ) = $this->build_reindex_filter_sql( $p_filters );
		$bug = db_get_table( 'bug' );
		$res = db_query( "SELECT id FROM $bug WHERE $where ORDER BY id ASC", $params );
		$ids = array();
		$limit = isset($p_filters['max_issues']) ? (int)$p_filters['max_issues'] : 0;
		while( $r = db_fetch_array( $res ) ) {
			$t_id = (int)$r['id'];
			$ids[] = $t_id;
			if( $limit > 0 && count($ids) >= $limit ) { break; }
		}
		return $ids;
	}

	private function build_reindex_filter_sql( array $p_filters ) {
		$t_index_statuses = $this->plugin->get_index_statuses();
		$t_placeholders = implode( ',', array_fill( 0, count( $t_index_statuses ), '?' ) );
		$t_where_parts = array( "status IN ($t_placeholders)" );
		$t_params = $t_index_statuses;

		$t_project_id = isset( $p_filters['project_id'] ) ? (int)$p_filters['project_id'] : null;
		if( $t_project_id !== null && $t_project_id > 0 ) { $t_where_parts[] = 'project_id = ?'; $t_params[] = $t_project_id; }

		$t_issue_id = isset( $p_filters['issue_id'] ) ? (int)$p_filters['issue_id'] : 0;
		if( $t_issue_id > 0 ) {
			$t_where_parts[] = 'id = ?';
			$t_params[] = $t_issue_id;
		}

		$t_created_from = isset( $p_filters['created_from_ts'] ) ? (int)$p_filters['created_from_ts'] : 0;
		$t_created_to = isset( $p_filters['created_to_ts'] ) ? (int)$p_filters['created_to_ts'] : 0;
		if( $t_created_from > 0 ) { $t_where_parts[] = 'date_submitted >= ?'; $t_params[] = $t_created_from; }
		if( $t_created_to > 0 ) { $t_where_parts[] = 'date_submitted <= ?'; $t_params[] = $t_created_to; }

		return array( implode( ' AND ', $t_where_parts ), $t_params );
	}

	public function build_index_text( $p_bug, array $p_attachment_ctx = array(), array $p_dashboard = array() ) {
		$t_parts = array();
		if( !empty( $p_dashboard['core']['indexable'] ) ) {
			$t_parts[] = 'Summary: ' . trim( (string)$p_bug->summary );
			$t_parts[] = 'Description: ' . trim( (string)$p_bug->description );
		}

		$t_include_notes = $this->plugin->get_bool_setting( 'include_notes', true, 'SEMSEARCH_INCLUDE_NOTES' );
		$t_note_text_by_id = array();
		if( $t_include_notes ) {
			$t_issue_notes = $this->inventory_repo->load_notes( (int)$p_bug->id );
			foreach( $t_issue_notes as $t_note ) {
				$t_note_text_by_id[(int)$t_note->id] = trim( (string)$t_note->note );
			}
		}

		if( $t_include_notes && isset( $p_dashboard['notes'] ) ) {
			foreach( $p_dashboard['notes'] as $n ) {
				$t_note_id = isset( $n['id'] ) ? (int)$n['id'] : 0;
				$t_note_text = isset( $t_note_text_by_id[$t_note_id] ) ? $t_note_text_by_id[$t_note_id] : '';
				if( !empty( $n['effective_indexable'] ) && $t_note_text !== '' ) {
					$t_parts[] = $t_note_text;
				}
			}
		}

		$t_include_attachments = $this->plugin->get_bool_setting( 'include_attachments', false, 'SEMSEARCH_INCLUDE_ATTACHMENTS' );
		if( $t_include_attachments && isset( $p_dashboard['attachments'] ) ) {
			$t_allowed_extensions_raw = strtolower( trim( (string)$this->plugin->get_setting( 'attachment_extensions', 'txt,pdf,docx', 'SEMSEARCH_ATTACHMENT_EXTENSIONS' ) ) );
			$t_allowed_extensions = array_filter( array_map( 'trim', explode( ',', $t_allowed_extensions_raw ) ) );
			$t_attachment_by_id = array();
			foreach( $this->inventory_repo->load_attachments( (int)$p_bug->id ) as $t_attachment ) {
				$t_attachment_by_id[(int)$t_attachment['id']] = $t_attachment;
			}
			foreach( $p_dashboard['attachments'] as $t_attachment_row ) {
				$t_file_id = isset( $t_attachment_row['id'] ) ? (int)$t_attachment_row['id'] : 0;
				if( $t_file_id <= 0 || empty( $t_attachment_row['effective_indexable'] ) || !empty( $t_attachment_row['deleted'] ) ) {
					continue;
				}
				if( !isset( $t_attachment_by_id[$t_file_id] ) ) {
					continue;
				}
				$t_attachment = $t_attachment_by_id[$t_file_id];
				$t_filename = isset( $t_attachment['filename'] ) ? (string)$t_attachment['filename'] : '';
				$t_extension = strtolower( (string)pathinfo( $t_filename, PATHINFO_EXTENSION ) );
				if( !empty( $t_allowed_extensions ) && $t_extension !== '' && !in_array( $t_extension, $t_allowed_extensions, true ) ) {
					continue;
				}
				$t_text = isset( $t_attachment['sem_text_content'] ) ? trim( (string)$t_attachment['sem_text_content'] ) : '';
				if( $t_text === '' ) {
					continue;
				}
				$t_parts[] = 'Attachment ' . ( $t_filename !== '' ? $t_filename : ( 'File #' . $t_file_id ) ) . ': ' . $t_text;
			}
		}
		return implode( "\n", array_filter( $t_parts ) );
	}

	public function build_payload( $p_bug, array $p_attachment_ctx = array() ) {
		$t_project_id = isset( $p_bug->project_id ) ? (int)$p_bug->project_id : 0;
		$t_project_name = $t_project_id > 0 ? project_get_name( $t_project_id ) : '';
		return array(
			'issue_id' => (int)$p_bug->id,
			'issue_number' => bug_format_id( $p_bug->id ),
			'summary' => (string)$p_bug->summary,
			'project_id' => $t_project_id,
			'project_name' => $t_project_name,
			'updated_at' => date( DATE_ATOM, (int)$p_bug->last_updated ),
			'is_currently_indexed_status' => true,
		);
	}
}
