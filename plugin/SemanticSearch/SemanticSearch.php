<?php

class SemanticSearchPlugin extends MantisPlugin {
	function register() {
		$this->name = 'Semantic Search';
		$this->description = plugin_lang_get( 'description' );
		$this->page = 'config_page';
		$this->version = '1.0.0';
		$this->requires = array(
			'MantisCore' => '2.25.0',
		);
		$this->author = 'Gaston Burgardt';
		$this->contact = 'n/a';
		$this->url = 'https://github.com/mantisbt/mantisbt';
	}

	function config() {
		return array(
			'enabled' => ON,
			'search_access_level' => REPORTER,
			'admin_access_level' => ADMINISTRATOR,
			'qdrant_url' => 'http://qdrant:6333',
			'qdrant_collection' => 'mantis_resolved_issues',
			'openai_embedding_model' => 'text-embedding-3-large',
			'top_k' => 10,
			'min_score' => 0.0,
			'include_notes' => ON,
			'include_attachments' => OFF,
			'index_hierarchy_mode' => 'strict',
			'attachment_extensions' => 'txt,pdf,docx',
			'index_statuses' => '80,90',
			'remove_on_unresolved' => ON,
		);
	}

	function hooks() {
		return array(
			'EVENT_UPDATE_BUG' => 'on_bug_updated',
			'EVENT_BUGNOTE_ADD' => 'on_bugnote_changed',
			'EVENT_BUGNOTE_EDIT' => 'on_bugnote_changed',
			'EVENT_BUGNOTE_DELETED' => 'on_bugnote_changed',
			'EVENT_MENU_MAIN' => 'menu_main',
			'EVENT_MENU_MANAGE' => 'menu_manage',
			'EVENT_VIEW_BUG_EXTRA' => 'view_bug_index_status',
		);
	}

	function init() {
		require_once( __DIR__ . '/core/OpenAIEmbeddingClient.php' );
		require_once( __DIR__ . '/core/QdrantClient.php' );
		require_once( __DIR__ . '/core/v2/SemanticDomain.php' );
		require_once( __DIR__ . '/core/v2/SemanticPolicyRepository.php' );
		require_once( __DIR__ . '/core/v2/SemanticIssueInventoryRepository.php' );
		require_once( __DIR__ . '/core/v2/SemanticV2Engine.php' );
		require_once( __DIR__ . '/core/IssueIndexer.php' );
		require_once( __DIR__ . '/core/SemanticSearchService.php' );
		require_once( __DIR__ . '/core/V2Schema.php' );
		try {
			$t_schema = new SemanticSearchV2Schema();
			$t_schema->ensure();
		} catch( Throwable $e ) {
			log_event( LOG_PLUGIN, '[SemanticSearch] V2 schema ensure failed: ' . $e->getMessage() );
		}
	}

	function menu_main() {
		if( !plugin_config_get( 'enabled', ON ) ) {
			return array();
		}
		if( !access_has_project_level( plugin_config_get( 'search_access_level' ) ) ) {
			return array();
		}
		return array(
			array(
				'url' => plugin_page( 'search' ),
				'title' => plugin_lang_get( 'menu_semantic_search' ),
				'icon' => 'fa-search',
				'access_level' => plugin_config_get( 'search_access_level' ),
			),
		);
	}

	function menu_manage() {
		if( !plugin_config_get( 'enabled', ON ) ) {
			return array();
		}
		if( !access_has_global_level( plugin_config_get( 'admin_access_level' ) ) ) {
			return array();
		}
		return array( '<a href="' . plugin_page( 'reindex' ) . '">' . plugin_lang_get( 'menu_reindex' ) . '</a>' );
	}

	function on_bug_updated( $p_event, $p_bug1 = null, $p_bug2 = null ) {
		if( !plugin_config_get( 'enabled', ON ) ) {
			return;
		}

		if( is_array( $p_bug1 ) && isset( $p_bug1[0] ) && isset( $p_bug1[1] ) ) {
			$t_old_bug = $p_bug1[0];
			$t_new_bug = $p_bug1[1];
		} else {
			$t_old_bug = $p_bug1;
			$t_new_bug = $p_bug2;
		}
		if( !is_object( $t_old_bug ) || !is_object( $t_new_bug ) ) {
			return;
		}
		$t_index_statuses = $this->get_index_statuses();
		$t_old_is_indexable = in_array( (int)$t_old_bug->status, $t_index_statuses, true );
		$t_new_is_indexable = in_array( (int)$t_new_bug->status, $t_index_statuses, true );

		try {
			$t_indexer = new IssueIndexer( $this );
			// Siempre revalida política/estado en cada update (policy-first).
			$t_indexer->review_issue_policy( (int)$t_new_bug->id );
			// Política: cambios de datos del incidente NO disparan indexación automática.
			// La indexación queda a cargo del proceso de indexación general/manual.
			if( $t_old_is_indexable && !$t_new_is_indexable ) {
				if( $this->get_bool_setting( 'remove_on_unresolved', true, 'SEMSEARCH_REMOVE_ON_UNRESOLVED' ) ) {
					$t_indexer->delete_issue_vector( (int)$t_new_bug->id );
				} else {
					$t_indexer->mark_issue_not_current( (int)$t_new_bug->id, (int)$t_new_bug->status );
				}
			}
		} catch( Throwable $e ) {
			log_event( LOG_PLUGIN, '[SemanticSearch] Bug update hook error for issue #' . $t_new_bug->id . ': ' . $e->getMessage() );
		}
	}

	function on_bugnote_changed( $p_event, $p_params = null ) {
		$this->run_review_pipeline_for_event( $p_event, $p_params, 'Bugnote' );
	}

	private function run_review_pipeline_for_event( $p_event, $p_params, $p_label ) {
		if( !plugin_config_get( 'enabled', ON ) ) {
			return;
		}
		$t_issue_id = $this->resolve_issue_id_from_hook_params( $p_params );
		if( $t_issue_id <= 0 ) {
			return;
		}
		try {
			$t_indexer = new IssueIndexer( $this );
			$t_indexer->review_issue_policy( $t_issue_id );
		} catch( Throwable $e ) {
			log_event( LOG_PLUGIN, '[SemanticSearch] ' . $p_label . ' hook error for issue #' . $t_issue_id . ': ' . $e->getMessage() );
		}
	}

	private function resolve_issue_id_from_hook_params( $p_params ) {
		if( is_numeric( $p_params ) ) {
			return (int)$p_params;
		}
		if( is_array( $p_params ) ) {
			if( isset( $p_params['bug_id'] ) ) {
				return (int)$p_params['bug_id'];
			}
			if( isset( $p_params[0] ) && is_numeric( $p_params[0] ) ) {
				return (int)$p_params[0];
			}
			foreach( $p_params as $t_value ) {
				if( is_numeric( $t_value ) ) {
					return (int)$t_value;
				}
			}
		}
		return 0;
	}

	public function get_openai_api_key() {
		$t_env_key = getenv( 'OPENAI_API_KEY' );
		return $t_env_key ? $t_env_key : '';
	}

	public function get_setting( $p_config_key, $p_default, $p_env_key = '' ) {
		if( !empty( $p_env_key ) ) {
			$t_env_value = getenv( $p_env_key );
			if( $t_env_value !== false && $t_env_value !== '' ) {
				return $t_env_value;
			}
		}

		$t_config_value = plugin_config_get( $p_config_key, null );
		if( $t_config_value === null || $t_config_value === '' ) {
			return $p_default;
		}
		return $t_config_value;
	}

	public function get_bool_setting( $p_config_key, $p_default = false, $p_env_key = '' ) {
		$t_value = strtolower( (string)$this->get_setting( $p_config_key, $p_default ? '1' : '0', $p_env_key ) );
		return in_array( $t_value, array( '1', 'true', 'on', 'yes' ), true );
	}

	public function get_index_statuses() {
		$t_csv = (string)$this->get_setting( 'index_statuses', '80,90', 'SEMSEARCH_INDEX_STATUSES' );
		$t_parts = explode( ',', $t_csv );
		$t_statuses = array();
		foreach( $t_parts as $t_part ) {
			$t_value = (int)trim( $t_part );
			if( $t_value > 0 ) {
				$t_statuses[] = $t_value;
			}
		}
		if( empty( $t_statuses ) ) {
			$t_statuses = array( RESOLVED, CLOSED );
		}
		return array_values( array_unique( $t_statuses ) );
	}

	function view_bug_index_status( $p_event, $p_bug_id ) {
		if( !plugin_config_get( 'enabled', ON ) ) {
			return;
		}

		try {
			$t_indexer = new IssueIndexer( $this );
			// Revisión obligatoria al pintar panel: garantiza bandera correcta aunque no haya corrido proceso general.
			$t_dash = $t_indexer->review_issue_policy( (int)$p_bug_id );
			$t_notes = isset( $t_dash['notes'] ) ? $t_dash['notes'] : array();
			$t_attachments = isset( $t_dash['attachments'] ) ? $t_dash['attachments'] : array();
			$t_core = isset( $t_dash['core'] ) ? $t_dash['core'] : array( 'indexable' => false, 'status' => 'never_indexed' );
			$t_has_pending_review = !empty( $t_dash['has_pending_review'] );
			$t_pending_review_count = isset( $t_dash['pending_review_count'] ) ? (int)$t_dash['pending_review_count'] : 0;
			$t_effective_to_index = isset( $t_dash['effective_to_index'] ) ? (int)$t_dash['effective_to_index'] : 0;
			$t_effective_to_delete = isset( $t_dash['effective_to_delete'] ) ? (int)$t_dash['effective_to_delete'] : 0;
			// Fallback local (UI-safe) bajo esquema estricto Action/NivelDeRevision.
			$t_effective_to_index = 0;
			$t_effective_to_delete = 0;
			$t_collect_rows = array_merge( array( $t_core ), $t_notes, $t_attachments );
			foreach( $t_collect_rows as $t_row ) {
				$t_action = isset( $t_row['target_status'] ) ? (string)$t_row['target_status'] : ( isset( $t_row['status'] ) ? (string)$t_row['status'] : 'Nothing' );
				if( $t_action === 'CreateIndex' || $t_action === 'UpdateIndex' ) {
					$t_effective_to_index++;
				}
				if( $t_action === 'DeleteIndex' ) {
					$t_effective_to_delete++;
				}
			}
			$t_has_pending_review = ( $t_effective_to_index + $t_effective_to_delete ) > 0;
			$t_pending_review_count = $t_effective_to_index + $t_effective_to_delete;

			$t_translate_action = function( $p_action ) {
				$t_action = (string)$p_action;
				if( $t_action === 'Nothing' ) { return 'SinAccion'; }
				if( $t_action === 'CreateIndex' ) { return 'CrearIndice'; }
				if( $t_action === 'UpdateIndex' ) { return 'ActualizarIndice'; }
				if( $t_action === 'DeleteIndex' ) { return 'EliminarIndice'; }
				return $t_action;
			};
			$t_translate_review = function( $p_level ) {
				$t_level = (string)$p_level;
				if( $t_level === 'NoRevisarNada' ) { return 'NoRevisarNada'; }
				if( $t_level === 'SoloYo' ) { return 'SoloYo'; }
				if( $t_level === 'YoYMisHijos' ) { return 'YoYMisHijos'; }
				if( $t_level === 'SoloMisHijos' ) { return 'SoloMisHijos'; }
				return $t_level;
			};
			$t_reason_text = function( $p_row ) {
				$t_action = isset( $p_row['target_status'] ) ? (string)$p_row['target_status'] : ( isset( $p_row['status'] ) ? (string)$p_row['status'] : 'Nothing' );
				$t_empty = !empty( $p_row['empty'] );
				$t_indexable = !empty( $p_row['indexable'] );
				$t_indexed = !empty( $p_row['indexed'] );
				if( $t_action === 'CreateIndex' ) { return 'Pendiente de alta en índice (indexable y aún no indexado).'; }
				if( $t_action === 'UpdateIndex' ) { return 'Pendiente de actualización (cambió desde la última indexación).'; }
				if( $t_action === 'DeleteIndex' ) { return 'Pendiente de borrado del índice (ya no debe estar indexado).'; }
				if( !empty( $p_row['deleted'] ) ) { return 'Elemento marcado como eliminado en origen.'; }
				if( !$t_indexable ) { return 'Sin acción: marcado como no indexable.'; }
				if( $t_empty ) { return 'Sin acción: contenido vacío.'; }
				if( $t_indexed ) { return 'Sin acción: indexado y sin cambios pendientes.'; }
				return 'Sin acción.';
			};

			echo '<div class="col-md-12 col-xs-12 semsearch-wrapper">';
			echo '<div class="space-10"></div>';
			echo '<div class="widget-box widget-color-blue2 semsearch-box">';
			echo '<div class="widget-header widget-header-small"><h4 class="widget-title lighter">Indexación semántica</h4></div>';
			echo '<div class="widget-body"><div class="widget-main">';
			echo '<style>.semsearch-kpi{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px}.semsearch-kpi .item{background:#f7f8fa;border:1px solid #d8dce3;border-radius:6px;padding:6px 10px;font-size:12px}.semsearch-actions{margin-top:10px;text-align:right}.semsearch-actions .btn{margin-left:6px}.semsearch-stack{display:block}.semsearch-stack .box{border:1px solid #d8dce3;border-radius:6px;padding:8px;background:#fff;margin-bottom:10px}.semsearch-subbox{margin-top:10px;margin-left:16px;border-left:3px solid #d8dce3;padding-left:10px}.semsearch-subbox.level2{margin-left:28px;border-left-color:#c7ced9}.semsearch-meta{margin:10px 0}.semsearch-meta table td,.semsearch-meta table th{font-size:12px}.semsearch-flow{font-size:12px;color:#555;background:#f7f8fa;border:1px solid #d8dce3;border-radius:6px;padding:6px 10px;margin:8px 0 10px 0}</style>';

			if( $t_has_pending_review ) {
				echo '<div class="alert alert-warning" style="margin-bottom:10px;"><strong>⚑ Pendiente de revisión:</strong> ' . $t_pending_review_count . ' alteraciones, para indexar: ' . $t_effective_to_index . ', para borrar: ' . $t_effective_to_delete . '.</div>';
			} else {
				echo '<div class="alert alert-success" style="margin-bottom:10px;"><strong>✓ Sin pendientes:</strong> no hay elementos indexables por procesar en este issue.</div>';
			}

			echo '<form method="post" action="' . plugin_page( 'attachment_index_action' ) . '">';
			echo form_security_field( 'plugin_SemanticSearch_attachment_index_action' );
			echo '<input type="hidden" name="bug_id" value="' . (int)$p_bug_id . '" />';

			echo '<div class="semsearch-flow"><strong>Relación:</strong> Core del incidente → Notas del core → Archivos de cada nota.</div>';
			echo '<div class="semsearch-meta"><h5 style="margin-top:0;">1) Core del incidente (tabla semsearch_issue)</h5><div class="table-responsive"><table class="table table-condensed table-bordered"><thead><tr><th>Indexable</th><th>Issue</th><th>Vacío</th><th>Eliminado</th><th>Indexado</th><th>Acción</th><th>Nivel</th><th>Motivo</th><th>Fecha Creado</th><th>Fecha Actualizado</th><th>Fecha Indexado</th></tr></thead><tbody>';
			echo '<tr>';
			echo '<td><input type="checkbox" name="core_indexable" value="1" ' . ( !empty($t_core['indexable']) ? 'checked' : '' ) . ' /></td>';
			echo '<td>#' . (int)$p_bug_id . '</td>';
			echo '<td>' . ( !empty($t_core['empty']) ? 'Sí' : 'No' ) . '</td>';
			echo '<td>' . ( !empty($t_core['deleted']) ? 'Sí' : 'No' ) . '</td>';
			echo '<td>' . ( !empty($t_core['indexed']) ? 'Sí' : 'No' ) . '</td>';
			echo '<td>' . string_display_line( isset($t_core['target_status']) ? $t_translate_action( (string)$t_core['target_status'] ) : '-' ) . '</td>';
			echo '<td>' . string_display_line( isset($t_core['review_level']) ? $t_translate_review( (string)$t_core['review_level'] ) : '-' ) . '</td>';
			echo '<td>' . string_display_line( $t_reason_text( $t_core ) ) . '</td>';
			echo '<td>' . ( !empty($t_core['created_at']) ? date( 'Y-m-d H:i:s', (int)$t_core['created_at'] ) : '-' ) . '</td>';
			echo '<td>' . ( !empty($t_core['updated_at']) ? date( 'Y-m-d H:i:s', (int)$t_core['updated_at'] ) : '-' ) . '</td>';
			echo '<td>' . ( !empty($t_core['indexed_at']) ? date( 'Y-m-d H:i:s', (int)$t_core['indexed_at'] ) : '-' ) . '</td>';
			echo '</tr></tbody></table></div>';

			echo '<div class="semsearch-subbox"><h5 style="margin-top:0;">2) Notas del core (tabla semsearch_issuenote)</h5><div class="table-responsive"><table class="table table-condensed table-striped table-bordered"><thead><tr><th style="width:90px;">Indexable</th><th>Nota</th><th style="width:70px;">Vacío</th><th style="width:80px;">Eliminado</th><th style="width:80px;">Indexado</th><th style="width:120px;">Acción</th><th style="width:130px;">Nivel revisión</th><th>Motivo</th><th style="width:120px;">Fecha Creado</th><th style="width:120px;">Fecha Actualizado</th><th style="width:120px;">Fecha Indexado</th></tr></thead><tbody>';
			foreach( $t_notes as $t_note ) {
				echo '<tr>';
				echo '<td><input type="checkbox" name="note_ids[]" value="' . (int)$t_note['id'] . '" ' . ( !empty($t_note['indexable']) ? 'checked' : '' ) . ' /></td>';
				$t_note_extra = !empty($t_note['blocked_by_core']) ? ' (bloq. por core)' : '';
				echo '<td>#' . (int)$t_note['id'] . $t_note_extra . '</td>';
				echo '<td>' . ( !empty($t_note['empty']) ? 'Sí' : 'No' ) . '</td>';
				echo '<td>' . ( !empty($t_note['deleted']) ? 'Sí' : 'No' ) . '</td>';
				echo '<td>' . ( !empty($t_note['indexed']) ? 'Sí' : 'No' ) . '</td>';
				echo '<td>' . string_display_line( isset($t_note['target_status']) ? $t_translate_action( (string)$t_note['target_status'] ) : '-' ) . '</td>';
				echo '<td>' . string_display_line( isset($t_note['review_level']) ? $t_translate_review( (string)$t_note['review_level'] ) : '-' ) . '</td>';
				echo '<td>' . string_display_line( $t_reason_text( $t_note ) ) . '</td>';
				echo '<td>' . ( !empty($t_note['created_at']) ? date( 'm-d H:i', (int)$t_note['created_at'] ) : '-' ) . '</td>';
				echo '<td>' . ( !empty($t_note['updated_at']) ? date( 'm-d H:i', (int)$t_note['updated_at'] ) : '-' ) . '</td>';
				echo '<td>' . ( !empty($t_note['indexed_at']) ? date( 'm-d H:i', (int)$t_note['indexed_at'] ) : '-' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';

			echo '<div class="semsearch-subbox level2"><h5 style="margin-top:0;">3) Archivos de las notas (tabla semsearch_issuenotefile)</h5><div class="table-responsive"><table class="table table-condensed table-striped table-bordered"><thead><tr><th style="width:90px;">Indexable</th><th>Archivo</th><th style="width:70px;">Vacío</th><th style="width:80px;">Eliminado</th><th style="width:80px;">Indexado</th><th style="width:120px;">Acción</th><th style="width:130px;">Nivel revisión</th><th>Motivo</th><th style="width:120px;">Fecha Creado</th><th style="width:120px;">Fecha Actualizado</th><th style="width:120px;">Fecha Indexado</th><th style="width:120px;">Nota</th></tr></thead><tbody>';
			foreach( $t_attachments as $t_file ) {
				$t_extra = '';
				if( !empty($t_file['blocked_by_note']) && !empty($t_file['blocked_by_core']) ) { $t_extra .= ' (bloq. por nota y core)'; }
				elseif( !empty($t_file['blocked_by_note']) ) { $t_extra .= ' (bloq. por nota)'; }
				elseif( !empty($t_file['blocked_by_core']) ) { $t_extra .= ' (bloq. por core)'; }
				echo '<tr>';
				echo '<td><input type="checkbox" name="attachment_ids[]" value="' . (int)$t_file['id'] . '" ' . ( !empty($t_file['indexable']) ? 'checked' : '' ) . ' /></td>';
				echo '<td>' . string_display_line( preg_replace('/^File\s+/i', '', (string)$t_file['name']) ) . $t_extra . '</td>';
				echo '<td>' . ( !empty($t_file['empty']) ? 'Sí' : 'No' ) . '</td>';
				echo '<td>' . ( !empty($t_file['deleted']) ? 'Sí' : 'No' ) . '</td>';
				echo '<td>' . ( !empty($t_file['indexed']) ? 'Sí' : 'No' ) . '</td>';
				echo '<td>' . string_display_line( isset($t_file['target_status']) ? $t_translate_action( (string)$t_file['target_status'] ) : '-' ) . '</td>';
				echo '<td>' . string_display_line( isset($t_file['review_level']) ? $t_translate_review( (string)$t_file['review_level'] ) : '-' ) . '</td>';
				echo '<td>' . string_display_line( $t_reason_text( $t_file ) ) . '</td>';
				echo '<td>' . ( !empty($t_file['created_at']) ? date( 'm-d H:i', (int)$t_file['created_at'] ) : '-' ) . '</td>';
				echo '<td>' . ( !empty($t_file['updated_at']) ? date( 'm-d H:i', (int)$t_file['updated_at'] ) : '-' ) . '</td>';
				echo '<td>' . ( !empty($t_file['indexed_at']) ? date( 'm-d H:i', (int)$t_file['indexed_at'] ) : '-' ) . '</td>';
				echo '<td>' . ( (int)$t_file['note_id'] > 0 ? ('#' . (int)$t_file['note_id']) : '-' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div></div>';
			echo '</div></div>';

			echo '<div class="semsearch-actions">';
			echo '<span style="margin-right:8px;font-size:12px;">Cantidad: <input type="number" min="1" max="50" name="similar_limit" value="10" style="width:70px;" /></span>';
			echo '<span style="margin-right:8px;font-size:12px;">Score mín: <input type="number" step="0.01" min="0" max="1" name="similar_min_score" value="0.3" style="width:80px;" /></span>';
			echo '<button type="submit" class="btn btn-xs btn-info" name="mode" value="similar_now">Obtener similares</button>';
			echo '<button type="submit" class="btn btn-xs btn-success" name="mode" value="solution_now">Obtener posible solución</button>';
			echo '<button type="submit" class="btn btn-xs btn-default" name="mode" value="save_policy">Guardar política</button>';
			echo '<button type="submit" class="btn btn-xs btn-primary" name="mode" value="index_now">Guardar e indexar ahora</button>';
			echo '</div>';
			echo '</form>';
			echo '</div></div></div></div>';
		} catch( Throwable $e ) {
			log_event( LOG_PLUGIN, '[SemanticSearch] Status panel failed for issue #' . (int)$p_bug_id . ': ' . $e->getMessage() );
		}
	}
}
