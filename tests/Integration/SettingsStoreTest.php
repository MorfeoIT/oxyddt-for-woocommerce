<?php
/**
 * The settings, through a real option row.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Domain\Address;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Settings\Settings;
use WP_UnitTestCase;

/**
 * What is stored comes back the same.
 */
final class SettingsStoreTest extends WP_UnitTestCase {

	/**
	 * A site with no settings row still answers every question.
	 *
	 * @return void
	 */
	public function test_a_site_without_a_settings_row_reads_the_defaults(): void {
		delete_option( Settings::OPTION );

		$settings = new Settings();

		$this->assertFalse( $settings->get( 'delete_data_on_uninstall' ) );
		$this->assertSame( '', $settings->company()->name );
		$this->assertFalse( $settings->company()->is_ready_to_issue() );
	}

	/**
	 * The sender survives the round trip.
	 *
	 * @return void
	 */
	public function test_the_sender_is_stored_and_read_back(): void {
		$settings = new Settings();

		$settings->update_company(
			new Company(
				'Oxysoft S.r.l.',
				new Address( 'Via Roma 1', '20121', 'Milano', 'MI', 'IT' ),
				'01234567897'
			)
		);

		$stored = ( new Settings() )->company();

		$this->assertSame( 'Oxysoft S.r.l.', $stored->name );
		$this->assertSame( '01234567897', $stored->vat_number );
		$this->assertSame( 'Milano', $stored->address->city );
		$this->assertTrue( $stored->is_ready_to_issue() );
	}

	/**
	 * Writing one setting does not quietly reset the others.
	 *
	 * @return void
	 */
	public function test_writing_one_setting_leaves_the_rest_alone(): void {
		$settings = new Settings();

		$settings->update_company( new Company( 'Oxysoft S.r.l.' ) );
		$settings->update( array( 'delete_data_on_uninstall' => true ) );

		$this->assertSame( 'Oxysoft S.r.l.', ( new Settings() )->company()->name );
		$this->assertTrue( ( new Settings() )->get( 'delete_data_on_uninstall' ) );
	}

	/**
	 * A row that somebody has written rubbish into is read as defaults rather
	 * than dragged around the plugin.
	 *
	 * @return void
	 */
	public function test_a_corrupt_row_is_read_as_defaults(): void {
		update_option( Settings::OPTION, 'not an array' );

		$this->assertSame( '', ( new Settings() )->company()->name );
	}
}
