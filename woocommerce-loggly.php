<?php
/**
 * Plugin Name: WooCommerce - Loggly
 * Plugin URI: https://garman.io
 * Description: Hook into WC_Logger and send WC logs to Loggly.
 * Author: Patrick Garman
 * Author URI: http://pmgarman.me
 * Text Domain: woocommere-loggly
 * Version: 1.0.0
 *
 * Copyright (c) 2015 Patrick Garman
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

define( 'WC_LOGGLY_PATH', dirname( __FILE__ ) );
define( 'ISO8601U', 'Y-m-d\TH:i:s.uO' );

require_once WC_LOGGLY_PATH . '/vendor/realguids.php';
require_once WC_LOGGLY_PATH . '/inc/class-wc-loggly-datastore.php';

register_activation_hook( __FILE__, 'wc_loggly_setup' );
add_filter( 'woocommerce_integrations', 'wc_loggly_add_integration' );

/**
 * Add the Loggly integration to WooCommerce
 *
 * @param array $integrations
 *
 * @return array
 */
function wc_loggly_add_integration( $integrations = array() ) {
	require_once WC_LOGGLY_PATH . '/inc/class-wc-loggly.php';

	$integrations[] = 'WC_Loggly';

	return $integrations;
}

/**
 * Factory to return the shared instance of the datastore.
 */
final class WC_Loggly_DataStoreFactory {
	public static function create() {
        static $plugin = null;

		if ( null === $plugin ) {
			$plugin = new WC_Loggly_Datastore();
		}

		return $plugin;
	}
}

/**
 * Utility function so we can create the database table on activation.
 */
function wc_loggly_setup() {
	$ds = WC_Loggly_DataStoreFactory::create();
	$ds->init();
}
