<?php

access_ensure_global_level( config_get( 'manage_plugin_threshold' ) );

layout_page_header( plugin_lang_get( 'config_title' ) );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_plugin_page.php' );
?>
<div class="col-md-12 col-xs-12">
<div class="space-10"></div>
<div class="form-container">
<form action="<?php echo plugin_page( 'config' ) ?>" method="post">
	<?php echo form_security_field( 'plugin_SemanticSearch_config' ) ?>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter"><?php echo plugin_lang_get( 'config_title' ) ?></h4>
		</div>
		<div class="widget-body">
			<div class="widget-main no-padding">
				<table class="table table-bordered table-condensed table-striped">
					<tr><td class="category">Habilitado</td><td><input type="checkbox" name="enabled" value="1" <?php echo plugin_config_get( 'enabled' ) ? 'checked' : '' ?> /></td></tr>
					<tr><td class="category">URL de Qdrant</td><td><input class="form-control" type="text" name="qdrant_url" value="<?php echo string_attribute( plugin_config_get( 'qdrant_url' ) ) ?>" /></td></tr>
					<tr><td class="category">Colección</td><td><input class="form-control" type="text" name="qdrant_collection" value="<?php echo string_attribute( plugin_config_get( 'qdrant_collection' ) ) ?>" /></td></tr>
					<tr><td class="category">Modelo de embeddings</td><td><input class="form-control" type="text" name="openai_embedding_model" value="<?php echo string_attribute( plugin_config_get( 'openai_embedding_model' ) ) ?>" /></td></tr>
					<tr><td class="category">Máx. resultados (Top K)</td><td><input class="form-control" type="number" min="1" max="50" name="top_k" value="<?php echo (int)plugin_config_get( 'top_k' ) ?>" /></td></tr>
					<tr><td class="category">Score mínimo</td><td><input class="form-control" type="number" step="0.01" min="0" max="1" name="min_score" value="<?php echo string_attribute( (string)plugin_config_get( 'min_score' ) ) ?>" /></td></tr>
					<tr><td class="category">Incluir notas en vectorización</td><td><input type="checkbox" name="include_notes" value="1" <?php echo plugin_config_get( 'include_notes' ) ? 'checked' : '' ?> /></td></tr>
					<tr><td class="category">Incluir adjuntos en vectorización</td><td><input type="checkbox" name="include_attachments" value="1" <?php echo plugin_config_get( 'include_attachments' ) ? 'checked' : '' ?> /></td></tr>
					<tr><td class="category">Extensiones de adjuntos (csv)</td><td><input class="form-control" type="text" name="attachment_extensions" value="<?php echo string_attribute( plugin_config_get( 'attachment_extensions' ) ) ?>" /></td></tr>
					<tr><td class="category">Estados indexables de Mantis (csv)</td><td><input class="form-control" type="text" name="index_statuses" value="<?php echo string_attribute( plugin_config_get( 'index_statuses' ) ) ?>" /></td></tr>
					<tr><td class="category">Eliminar vector al pasar a no indexable</td><td><input type="checkbox" name="remove_on_unresolved" value="1" <?php echo plugin_config_get( 'remove_on_unresolved' ) ? 'checked' : '' ?> /></td></tr>
				</table>
			</div>
			<div class="widget-toolbox padding-8 clearfix">
				<input type="submit" class="btn btn-primary btn-white btn-round" value="<?php echo plugin_lang_get( 'action_update' ) ?>" />
			</div>
		</div>
	</div>
</form>
</div>
</div>
<?php layout_page_end();
