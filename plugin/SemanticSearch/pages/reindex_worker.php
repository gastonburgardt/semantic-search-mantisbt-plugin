<?php

if( php_sapi_name() !== 'cli' ) {
	echo "CLI only\n";
	exit( 1 );
}

$opts = getopt( '', array( 'run_id:', 'kind:', 'batch_size::', 'heartbeat_timeout::' ) );
$run_id = isset( $opts['run_id'] ) ? (string)$opts['run_id'] : '';
$kind = isset( $opts['kind'] ) ? (string)$opts['kind'] : '';
$batch_size = isset( $opts['batch_size'] ) ? max( 1, (int)$opts['batch_size'] ) : 25;
$heartbeat_timeout = isset( $opts['heartbeat_timeout'] ) ? max( 30, (int)$opts['heartbeat_timeout'] ) : 120;

if( $run_id === '' || ( $kind !== 'policy' && $kind !== 'vectorize' ) ) {
	echo "Invalid args\n";
	exit( 2 );
}

$t_root = dirname( dirname( dirname( __DIR__ ) ) );
if( !is_file( $t_root . '/core.php' ) ) {
	$t_root = dirname( $t_root );
}
require_once $t_root . '/core.php';
require_once dirname( __DIR__ ) . '/SemanticSearch.php';
require_once dirname( __DIR__ ) . '/core/OpenAIEmbeddingClient.php';
require_once dirname( __DIR__ ) . '/core/QdrantClient.php';
require_once dirname( __DIR__ ) . '/core/IssueIndexer.php';
require_once dirname( __DIR__ ) . '/core/JobControl.php';

$t_plugin = new SemanticSearchPlugin( 'SemanticSearch' );
$t_plugin->init();
$t_jobs = new SemanticSearchJobControl();
$t_indexer = new IssueIndexer( $t_plugin );

$run = $t_jobs->get_run( $run_id );
if( !$run ) {
	exit( 3 );
}
$filters = array();
if( !empty( $run['FiltersJson'] ) ) {
	$tmp = json_decode( (string)$run['FiltersJson'], true );
	if( is_array( $tmp ) ) {
		$filters = $tmp;
	}
}

$last_id = 0;
while( true ) {
	if( $t_jobs->stop_requested( $run_id ) ) {
		$t_jobs->finish( $run_id, 'stopped', 'Detenido por usuario' );
		exit( 0 );
	}
	$t_jobs->heartbeat( $run_id, $heartbeat_timeout );
	try {
		if( $kind === 'policy' ) {
			$step = $t_indexer->process_policy_batch_filtered( $filters, $last_id, $batch_size, 0 );
			$t_jobs->update_progress( $run_id, array(
				'ok' => (int)$step['flagged'] + (int)$step['clean'],
				'skip' => 0,
				'fail' => (int)$step['failed'],
				'seen' => (int)$step['seen'],
			), (int)$step['last_id'] );
		} else {
			$step = $t_indexer->reindex_batch_filtered( $filters, $last_id, $batch_size, 0 );
			$t_jobs->update_progress( $run_id, array(
				'ok' => (int)$step['indexed'],
				'skip' => (int)$step['skipped'],
				'fail' => (int)$step['failed'],
				'seen' => (int)$step['seen'],
			), (int)$step['last_id'] );
		}
		$last_id = isset( $step['last_id'] ) ? (int)$step['last_id'] : $last_id;
		if( !empty( $step['done'] ) ) {
			$t_jobs->finish( $run_id, 'done', 'Completado' );
			exit( 0 );
		}
	} catch( Throwable $e ) {
		$t_jobs->finish( $run_id, 'failed', $e->getMessage() );
		exit( 10 );
	}
	usleep( 150000 );
}
