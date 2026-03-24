<?php

access_ensure_global_level( plugin_config_get( 'admin_access_level' ) );

layout_page_header( plugin_lang_get( 'menu_reindex' ) );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( plugin_page( 'reindex' ) );
?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="form-container">
		<div class="widget-box widget-color-blue2">
			<div class="widget-header widget-header-small">
				<h4 class="widget-title lighter"><?php echo plugin_lang_get( 'menu_reindex' ) ?></h4>
			</div>
			<div class="widget-body">
				<div class="widget-main">
					<p class="text-muted">Flujo recomendado: 1) Revisar política (Mantis + tablas de vectorización), 2) Ejecutar vectorización (solo tablas de vectorización).</p>

					<div class="row">
						<div class="col-md-6">
							<label><strong>Proyecto</strong></label>
							<select id="project_id" class="form-control input-sm">
								<option value="">Ninguno (seleccionar)</option>
								<option value="0">Todos los proyectos</option>
								<?php print_project_option_list( null, false ); ?>
							</select>
						</div>
						<div class="col-md-6">
							<label><strong>Issue ID (opcional)</strong></label>
							<input id="issue_id" class="form-control no-spin" type="text" inputmode="numeric" pattern="[0-9]*" placeholder="ej: 123" />
						</div>
					</div>
					<div class="space-5"></div>
					<div class="row"><div class="col-md-12"><small class="text-muted">Requerido: Proyecto o Issue ID.</small></div></div>

					<div class="space-10"></div>
					<div class="row">
						<div class="col-md-6">
							<label><strong>Tamaño de lote</strong></label>
							<input id="batch_size" class="form-control" type="number" min="1" max="200" value="25" />
						</div>
						<div class="col-md-6">
							<label><strong>Cantidad máx.</strong></label>
							<input id="max_issues" class="form-control" type="number" min="0" placeholder="0 = sin límite" />
						</div>
					</div>

					<div class="space-10"></div>
					<div class="row">
						<div class="col-md-6">
							<label><strong>Creación desde</strong></label>
							<input id="created_from" class="form-control" type="date" />
						</div>
						<div class="col-md-6">
							<label><strong>Creación hasta</strong></label>
							<input id="created_to" class="form-control" type="date" />
						</div>
					</div>

					<div class="space-10"></div>
					<div class="row">
						<div class="col-md-7">
							<label><strong>Modo de vectorización</strong></label><br/>
							<label><input type="radio" name="vector_mode" value="pending" checked /> Solo pendientes</label><br/>
							<label><input type="radio" name="vector_mode" value="force" /> Forzar re-vectorización total (elimina vector previo y vuelve a generar)</label>
						</div>
						<div class="col-md-5 text-right">
							<button id="process_state_btn" class="btn btn-info btn-white btn-round" type="button">Revisar política</button>
							<button id="start_reindex_btn" class="btn btn-warning btn-white btn-round" type="button">Iniciar vectorización</button>
							<button id="stop_reindex_btn" class="btn btn-danger btn-white btn-round" type="button">Detener run</button>
						</div>
					</div>

					<div class="space-10"></div>
					<div id="reindex_status" class="text-muted">Listo para iniciar.</div>
					<div id="policy_info" class="well well-sm" style="margin-top:8px;">Política: sin ejecutar.</div>
					<div id="vector_info" class="well well-sm" style="margin-top:8px;">Vectorización: sin ejecutar.</div>
				</div>
			</div>
		</div>
	</div>
</div>

<style>
.no-spin::-webkit-outer-spin-button,
.no-spin::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.no-spin { -moz-appearance: textfield; }
</style>

<input type="hidden" id="reindex_action_base" value="<?php echo string_attribute( plugin_page( 'reindex_action' ) ); ?>" />
<input type="hidden" id="reindex_form_token" value="<?php echo string_attribute( form_security_token( 'plugin_SemanticSearch_reindex' ) ); ?>" />
<script src="<?php echo plugin_file( 'reindex.js' ) . '&v=20260324_0225'; ?>"></script>

<?php layout_page_end();