<?php
/**
 * Proving the numbering under real concurrency.
 *
 * The integration suite cannot do this. PHPUnit is one process, and the
 * WordPress test library wraps every test in a transaction, so a second
 * connection would not even see the rows. What that suite proves is that a
 * hundred sequential allocations come back distinct — which is necessary, and
 * says nothing about two people pressing Issue in the same second.
 *
 * So this script does the thing itself: it starts a dozen PHP processes, has
 * them all wait for the same moment, and then all take numbers from the same
 * counter as fast as they can. Afterwards it checks that the numbers they got
 * are exactly 1..N, each once.
 *
 * The SQL it runs is not a copy. It comes from Oxysoft\OxyDDT\Domain\SequenceSql,
 * which is the same class the plugin's repository uses, so a change to the real
 * statements changes what is being proved here.
 *
 * Usage:
 *   php scripts/concurrency-check.php [workers] [numbers-per-worker]
 *
 * Environment:
 *   WP_DB_NAME      database name      (default oxyddt_tests)
 *   WP_DB_USER      database user      (default root)
 *   WP_DB_PASSWORD  database password  (default empty)
 *   WP_DB_HOST      database host      (default 127.0.0.1)
 *
 * Exit code 0 means no number was ever handed out twice.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Domain/SequenceSql.php';

use Oxysoft\OxyDDT\Domain\SequenceSql;

const OXYDDT_CONCURRENCY_TABLE = 'oxyddt_concurrency_sequences';

/**
 * Connect to the database the way the workers do.
 *
 * @return mysqli
 */
function oxyddt_connect(): mysqli {
	$host = getenv( 'WP_DB_HOST' );
	$host = false === $host || '' === $host ? '127.0.0.1' : $host;
	$port = 3306;

	if ( false !== strpos( $host, ':' ) ) {
		list( $host, $port ) = explode( ':', $host, 2 );
		$port                = (int) $port;
	}

	$name     = getenv( 'WP_DB_NAME' );
	$user     = getenv( 'WP_DB_USER' );
	$password = getenv( 'WP_DB_PASSWORD' );

	mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );

	return new mysqli(
		$host,
		false === $user || '' === $user ? 'root' : $user,
		false === $password ? '' : $password,
		false === $name || '' === $name ? 'oxyddt_tests' : $name,
		$port
	);
}

/**
 * Turn a wpdb-style statement into a mysqli one.
 *
 * The placeholders differ and nothing else does: what is being proved is the
 * shape of the statement, not the spelling of its holes.
 *
 * @param string $sql The statement, with %s and %d.
 * @return string
 */
function oxyddt_placeholders( string $sql ): string {
	return str_replace( array( '%s', '%d' ), '?', $sql );
}

/**
 * Take numbers, as a worker.
 *
 * @param int    $count  How many to take.
 * @param string $series The series.
 * @param int    $year   The year.
 * @param float  $start  The moment, as a UNIX timestamp, when everybody begins.
 * @return void
 */
function oxyddt_worker( int $count, string $series, int $year, float $start ): void {
	$db  = oxyddt_connect();
	$now = gmdate( 'Y-m-d H:i:s' );

	$allocate = $db->prepare( oxyddt_placeholders( SequenceSql::allocate( OXYDDT_CONCURRENCY_TABLE ) ) );
	$allocate->bind_param( 'ssi', $now, $series, $year );

	// Everybody waits for the same instant. Without this the processes start a
	// tenth of a second apart, never overlap, and the check proves nothing.
	$wait = $start - microtime( true );

	if ( $wait > 0 ) {
		usleep( (int) ( $wait * 1000000 ) );
	}

	$taken = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$allocate->execute();

		$result = $db->query( SequenceSql::allocated() );
		$row    = $result instanceof mysqli_result ? $result->fetch_row() : null;

		$taken[] = (int) ( $row[0] ?? 0 );
	}

	echo implode( "\n", $taken ), "\n";
}

/**
 * Run the check.
 *
 * @param int $workers    How many processes.
 * @param int $per_worker How many numbers each takes.
 * @return int Exit code.
 */
function oxyddt_check( int $workers, int $per_worker ): int {
	$db     = oxyddt_connect();
	$series = '';
	$year   = 2026;

	$db->query( 'DROP TABLE IF EXISTS ' . OXYDDT_CONCURRENCY_TABLE );
	$db->query(
		'CREATE TABLE ' . OXYDDT_CONCURRENCY_TABLE . ' (
			series varchar(20) NOT NULL DEFAULT "",
			sequence_year smallint(5) unsigned NOT NULL,
			next_number int(10) unsigned NOT NULL DEFAULT 1,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (series, sequence_year)
		) ENGINE=InnoDB'
	);

	$create = $db->prepare( oxyddt_placeholders( SequenceSql::create( OXYDDT_CONCURRENCY_TABLE ) ) );
	$one    = 1;
	$now    = gmdate( 'Y-m-d H:i:s' );
	$create->bind_param( 'siis', $series, $year, $one, $now );
	$create->execute();

	$start     = microtime( true ) + 1.5;
	$processes = array();
	$pipes     = array();

	for ( $worker = 0; $worker < $workers; $worker++ ) {
		$command = sprintf(
			'%s %s --worker %d %d %s',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( __FILE__ ),
			$per_worker,
			$year,
			escapeshellarg( (string) $start )
		);

		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $command, $descriptors, $worker_pipes );

		if ( ! is_resource( $process ) ) {
			fwrite( STDERR, "A worker could not be started.\n" );

			return 1;
		}

		$processes[ $worker ] = $process;
		$pipes[ $worker ]     = $worker_pipes;
	}

	$taken  = array();
	$failed = false;

	foreach ( $processes as $worker => $process ) {
		$output = (string) stream_get_contents( $pipes[ $worker ][1] );
		$errors = (string) stream_get_contents( $pipes[ $worker ][2] );

		fclose( $pipes[ $worker ][1] );
		fclose( $pipes[ $worker ][2] );

		$status = proc_close( $process );

		if ( 0 !== $status ) {
			$failed = true;

			fwrite( STDERR, sprintf( "Worker %d failed: %s\n", $worker, trim( $errors ) ) );
		}

		foreach ( preg_split( '/\R/', trim( $output ) ) ?: array() as $line ) {
			if ( '' !== trim( $line ) ) {
				$taken[] = (int) $line;
			}
		}
	}

	$expected = $workers * $per_worker;
	$unique   = array_unique( $taken );

	sort( $taken );

	printf(
		"%d workers × %d numbers: %d taken, %d distinct\n",
		$workers,
		$per_worker,
		count( $taken ),
		count( $unique )
	);

	if ( $failed ) {
		fwrite( STDERR, "At least one worker did not finish.\n" );

		return 1;
	}

	if ( count( $taken ) !== $expected ) {
		fwrite( STDERR, sprintf( "Expected %d numbers, got %d.\n", $expected, count( $taken ) ) );

		return 1;
	}

	if ( count( $unique ) !== $expected ) {
		$duplicates = array_diff_assoc( $taken, array_unique( $taken ) );

		fwrite(
			STDERR,
			sprintf(
				"A number was handed out more than once: %s\n",
				implode( ', ', array_slice( $duplicates, 0, 10 ) )
			)
		);

		return 1;
	}

	if ( $taken !== range( 1, $expected ) ) {
		fwrite( STDERR, "The numbers are distinct but not contiguous: something skipped one.\n" );

		return 1;
	}

	echo "No number was handed out twice, and none was skipped.\n";

	return 0;
}

$arguments = $argv ?? array();

if ( in_array( '--worker', $arguments, true ) ) {
	$at = (int) array_search( '--worker', $arguments, true );

	oxyddt_worker(
		(int) ( $arguments[ $at + 1 ] ?? 10 ),
		'',
		(int) ( $arguments[ $at + 2 ] ?? 2026 ),
		(float) ( $arguments[ $at + 3 ] ?? microtime( true ) )
	);

	exit( 0 );
}

exit( oxyddt_check( (int) ( $arguments[1] ?? 12 ), (int) ( $arguments[2] ?? 25 ) ) );
