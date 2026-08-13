<?php
/**
 * Who may do what with delivery notes.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Security;

/**
 * The plugin's own capabilities, and who holds them.
 *
 * Seven of them, and the split is not decoration. Issuing a document consumes a
 * number that can never be used again, and cancelling one is an entry in a
 * register somebody may have to explain: neither should be a side effect of
 * being allowed to prepare a shipment.
 *
 * A shop that wants one person to do everything grants them everything, and that
 * is a decision it has made rather than one the plugin made for it.
 */
final class Capabilities {

	/**
	 * See delivery notes and the register.
	 */
	public const VIEW = 'oxyddt_view';

	/**
	 * Prepare a draft from an order.
	 */
	public const CREATE = 'oxyddt_create';

	/**
	 * Issue a document, which takes the next number for good.
	 */
	public const ISSUE = 'oxyddt_issue';

	/**
	 * Email a document to somebody.
	 */
	public const SEND = 'oxyddt_send';

	/**
	 * Cancel an issued document.
	 */
	public const CANCEL = 'oxyddt_cancel';

	/**
	 * Change the sender, the templates and the rest of the configuration.
	 */
	public const MANAGE_SETTINGS = 'oxyddt_manage_settings';

	/**
	 * Change how documents are numbered.
	 */
	public const MANAGE_SEQUENCES = 'oxyddt_manage_sequences';

	/**
	 * Bumped whenever the map below changes, so that an upgrade re-grants.
	 *
	 * Without this an existing site would keep exactly the capabilities it was
	 * given the day it installed the plugin, and a capability added in a later
	 * sprint would reach nobody. It is the mistake that cost OxyProfit a sprint.
	 */
	private const GRANT_VERSION = 1;

	/**
	 * The option recording the granted version.
	 */
	private const GRANT_OPTION = 'oxyddt_capabilities_version';

	/**
	 * Every capability the plugin defines.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::VIEW,
			self::CREATE,
			self::ISSUE,
			self::SEND,
			self::CANCEL,
			self::MANAGE_SETTINGS,
			self::MANAGE_SEQUENCES,
		);
	}

	/**
	 * What each role gets.
	 *
	 * A shop manager runs the shipping day: they look, prepare, issue, send and,
	 * when something goes wrong, cancel. What they do not get is the numbering
	 * and the sender block — the two settings that are wrong on every document
	 * printed after somebody changes them, and that a shop configures once.
	 *
	 * @return array<string, list<string>>
	 */
	public static function by_role(): array {
		return array(
			'administrator' => self::all(),
			'shop_manager'  => array(
				self::VIEW,
				self::CREATE,
				self::ISSUE,
				self::SEND,
				self::CANCEL,
			),
		);
	}

	/**
	 * Make sure the roles hold their capabilities.
	 *
	 * Only adds. A site that has deliberately given oxyddt_issue to a warehouse
	 * role keeps that arrangement; a site that has taken one away from
	 * administrators finds it back after an upgrade, which is the lesser of the
	 * two surprises.
	 *
	 * Runs at activation and again on the first request after the map changes,
	 * because a plugin updated over FTP or by WP-CLI never fires an activation
	 * hook and would otherwise define a capability that nobody has.
	 *
	 * @return void
	 */
	public static function ensure_granted(): void {
		if ( (int) get_option( self::GRANT_OPTION, 0 ) === self::GRANT_VERSION ) {
			return;
		}

		foreach ( self::by_role() as $role_name => $capabilities ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				// shop_manager does not exist until WooCommerce has installed its
				// roles. Leaving the option unwritten is what brings us back here:
				// on `init` of this same request if WooCommerce is installing now,
				// and otherwise on the next request.
				continue;
			}

			foreach ( $capabilities as $capability ) {
				$role->add_cap( $capability );
			}
		}

		if ( null === get_role( 'shop_manager' ) ) {
			return;
		}

		update_option( self::GRANT_OPTION, self::GRANT_VERSION, false );
	}

	/**
	 * Take every capability back.
	 *
	 * Uninstall only. Deactivating the plugin leaves the grants alone: a site that
	 * switches it off for an afternoon should not have to hand out permissions
	 * again afterwards.
	 *
	 * @return void
	 */
	public static function remove_all(): void {
		foreach ( array_keys( self::by_role() ) as $role_name ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( self::all() as $capability ) {
				$role->remove_cap( $capability );
			}
		}

		delete_option( self::GRANT_OPTION );
	}
}
