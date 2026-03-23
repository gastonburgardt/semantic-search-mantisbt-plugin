<?php

access_ensure_project_level( plugin_config_get( 'search_access_level' ) );

$t_query = trim( gpc_get_string( 'q', '' ) );
$t_limit_raw = trim( gpc_get_string( 'limit', (string)plugin_config_get( 'top_k' ) ) );
$t_min_score_default = plugin_config_get( 'min_score' );
if( $t_min_score_default === null || $t_min_score_default === '' ) {
	$t_min_score_default = '0.2';
}
$t_min_score_raw = trim( gpc_get_string( 'min_score', (string)$t_min_score_default ) );
$t_project_raw = gpc_get_string( 'project_id', '' );
$t_issue_raw = trim( gpc_get_string( 'issue_id', '' ) );

$t_limit = is_numeric( $t_limit_raw ) ? (int)$t_limit_raw : (int)plugin_config_get( 'top_k' );
if( $t_limit < 1 ) { $t_limit = 1; }
if( $t_limit > 50 ) { $t_limit = 50; }

$t_min_score = is_numeric( $t_min_score_raw ) ? (float)$t_min_score_raw : (float)plugin_config_get( 'min_score' );
if( $t_min_score < 0 ) { $t_min_score = 0; }
if( $t_min_score > 1 ) { $t_min_score = 1; }

$t_project_id = $t_project_raw === '' ? null : (int)$t_project_raw;
$t_issue_id = 0;
$t_results = array();
$t_error = '';
$t_info = '';
$t_submitted = ( gpc_get_string( 'q', null ) !== null ) || ( gpc_get_string( 'project_id', null ) !== null );

if( $t_submitted ) {
	if( $t_query === '' ) {
		$t_error = 'La consulta no puede estar vacía.';
	} elseif( $t_project_id === null ) {
		$t_error = 'Seleccioná un proyecto o "Todos los proyectos".';
	} elseif( $t_issue_raw !== '' && !ctype_digit( $t_issue_raw ) ) {
		$t_error = 'Issue ID debe ser numérico.';
	} else {
		$t_issue_id = $t_issue_raw === '' ? 0 : (int)$t_issue_raw;
		try {
			$t_service = new SemanticSearchService( plugin_get( 'SemanticSearch' ) );
			$t_results = $t_service->search( $t_query, $t_limit, $t_min_score, $t_project_id, $t_issue_id );
			if( empty( $t_results ) ) {
				$t_info = 'No se encontraron resultados con los filtros actuales. Probá bajar el score mínimo o ampliar filtros.';
			}
		} catch( Throwable $e ) {
			$t_error = $e->getMessage();
			log_event( LOG_PLUGIN, '[SemanticSearch] Search failed: ' . $e->getMessage() );
		}
	}
}

layout_page_header( plugin_lang_get( 'menu_semantic_search' ) );
layout_page_begin();
?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="form-container">
		<div class="widget-box widget-color-blue2">
			<div class="widget-header widget-header-small">
				<h4 class="widget-title lighter"><?php echo plugin_lang_get( 'search_title' ) ?></h4>
			</div>
			<div class="widget-body">
				<div class="widget-main">
					<p class="text-muted" style="margin-bottom:12px"><?php echo plugin_lang_get( 'search_help' ) ?></p>
					<form method="get" action="plugin.php">
						<input type="hidden" name="page" value="SemanticSearch/search" />
						<div class="row">
							<div class="col-md-12">
								<label><strong><?php echo plugin_lang_get( 'search_query_label' ) ?></strong></label>
								<div class="input-group">
									<input class="form-control" style="height:42px;font-size:15px" type="text" name="q" value="<?php echo string_attribute( $t_query ) ?>" placeholder="<?php echo string_attribute( plugin_lang_get( 'search_help' ) ) ?>" />
									<span class="input-group-btn">
										<button class="btn btn-primary" style="height:42px" type="submit"><?php echo plugin_lang_get( 'search_button' ) ?></button>
									</span>
								</div>
							</div>
						</div>
						<div class="space-10"></div>
						<div class="row">
							<div class="col-md-4">
								<label><strong>Proyecto</strong></label>
								<select class="form-control input-sm" name="project_id">
									<option value="">Ninguno (seleccionar)</option>
									<option value="0" <?php echo $t_project_id === 0 ? 'selected' : '' ?>>Todos los proyectos</option>
									<?php print_project_option_list( $t_project_id, false ); ?>
								</select>
							</div>
							<div class="col-md-3">
								<label><strong>Issue ID (opcional)</strong></label>
								<input class="form-control input-sm" type="text" name="issue_id" inputmode="numeric" pattern="[0-9]*" value="<?php echo string_attribute( $t_issue_raw ) ?>" />
							</div>
							<div class="col-md-2">
								<label><strong><?php echo plugin_lang_get( 'search_limit_label' ) ?></strong></label>
								<input class="form-control input-sm" type="number" min="1" max="50" name="limit" value="<?php echo (int)$t_limit ?>" />
							</div>
							<div class="col-md-3">
								<label><strong><?php echo plugin_lang_get( 'search_min_score_label' ) ?></strong></label>
								<input class="form-control input-sm" type="number" step="0.01" min="0" max="1" name="min_score" value="<?php echo string_attribute( (string)$t_min_score ) ?>" />
							</div>
						</div>
						<div class="space-10"></div>
						<p class="text-muted"><small><?php echo plugin_lang_get( 'search_min_score_help' ) ?></small></p>
					</form>
				</div>
			</div>
		</div>

		<?php if( !empty( $t_error ) ) { ?>
		<div class="space-10"></div>
		<div class="alert alert-danger"><?php echo string_display_line( $t_error ) ?></div>
		<?php } ?>

		<?php if( empty( $t_error ) && !empty( $t_info ) ) { ?>
		<div class="space-10"></div>
		<div class="alert alert-warning"><?php echo string_display_line( $t_info ) ?></div>
		<?php } ?>

		<?php if( !empty( $t_results ) ) { ?>
		<div class="space-10"></div>
		<div class="table-responsive">
			<table class="table table-bordered table-striped table-condensed">
				<thead>
					<tr>
						<th><?php echo plugin_lang_get( 'issue_number' ) ?></th>
						<th><?php echo plugin_lang_get( 'summary' ) ?></th>
						<th><?php echo plugin_lang_get( 'score' ) ?></th>
						<th><?php echo plugin_lang_get( 'link' ) ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach( $t_results as $t_row ) { ?>
					<tr>
						<td><?php echo string_display_line( $t_row['issue_number'] ) ?></td>
						<td><?php echo string_display_line( $t_row['summary'] ) ?></td>
						<td><?php echo number_format( (float)$t_row['score'], 4 ) ?></td>
						<td><a href="<?php echo string_attribute( $t_row['url'] ) ?>"><?php echo plugin_lang_get( 'open_issue' ) ?></a></td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
		<?php } ?>
	</div>
</div>
<?php layout_page_end();
