<?php
/**
 * What PHPStan needs to know before it reads the plugin.
 *
 * The constants below are defined by WordPress at runtime and by the plugin's
 * own main file, which PHPStan analyses rather than executes.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

define( 'ABSPATH', '/wordpress/' );
define( 'WP_UNINSTALL_PLUGIN', true );
define( 'WC_VERSION', '9.0.0' );
define( 'ARRAY_A', 'ARRAY_A' );
