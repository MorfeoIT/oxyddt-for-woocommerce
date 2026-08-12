<?php
/**
 * The schema, inside a real WordPress.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Infrastructure\Migrator;
use WP_UnitTestCase;

/**
 * Migrations run, and run again without damage.
 */
final class MigratorTest extends WP_UnitTestCase {

	/**
	 * The bootstrap migrated at install; the table is there.
	 *
	 * @return void
	 */
	public function test_the_log_table_exists(): void {
		global $wpdb;

		$table = Migrator::table( Migrator::TABLE_LOGS );

		$this->assertSame(
			$table,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) )
		);
	}

	/**
	 * The site records which schema it is on.
	 *
	 * @return void
	 */
	public function test_the_schema_version_is_recorded(): void {
		$this->assertSame( Migrator::TARGET_VERSION, (int) get_option( Migrator::VERSION_OPTION ) );
		$this->assertFalse( ( new Migrator() )->needs_migration() );
	}

	/**
	 * Activation happens again on every reactivation, so migrating twice has to
	 * be uneventful.
	 *
	 * @return void
	 */
	public function test_migrating_again_changes_nothing(): void {
		$migrator = new Migrator();

		$migrator->migrate();
		$migrator->migrate();

		$this->assertSame( Migrator::TARGET_VERSION, $migrator->current_version() );
	}

	/**
	 * A site that has never seen the plugin migrates from zero.
	 *
	 * @return void
	 */
	public function test_a_fresh_site_migrates_from_zero(): void {
		delete_option( Migrator::VERSION_OPTION );

		$migrator = new Migrator();

		$this->assertSame( 0, $migrator->current_version() );
		$this->assertTrue( $migrator->needs_migration() );

		$migrator->migrate();

		$this->assertSame( Migrator::TARGET_VERSION, $migrator->current_version() );
	}

	/**
	 * Every table the plugin owns is named with the site's prefix.
	 *
	 * @return void
	 */
	public function test_tables_carry_the_site_prefix(): void {
		global $wpdb;

		foreach ( Migrator::tables() as $table ) {
			$this->assertStringStartsWith( $wpdb->prefix . 'oxyddt_', $table );
		}
	}
}
