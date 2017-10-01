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
define( 'WC_LOGGLY_TABLENAME', 'wc_loggly_queued_logs' );

require_once WC_LOGGLY_PATH . '/vendor/realguids.php';

/**
 * Add the Loggly integration to WooCommerce
 *
 * @param array $integrations
 *
 * @return array
 */
function wc_loggly_add_integration( $integrations = array() ) {
	require_once 'inc/class-wc-loggly.php';

	$integrations[] = 'WC_Loggly';

	return $integrations;
}
add_filter( 'woocommerce_integrations', 'wc_loggly_add_integration' );

register_activation_hook( __FILE__, 'wc_loggly_setup' );

function wc_loggly_setup() {
	global $wpdb;
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

	dbDelta( wc_loggly_schema() );
}


function wc_loggly_schema() {
	global $wpdb;
	$table = WC_LOGGLY_TABLENAME;
	return "
		CREATE TABLE {$wpdb->prefix}{$table} (
		`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		`timestamp` VARCHAR(31) NOT NULL,
		`level` ENUM('debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency') NOT NULL DEFAULT 'debug',
		`handle` VARCHAR(191),
		`message` LONGTEXT NOT NULL,
		`claim` VARCHAR(36),
		UNIQUE `id`(`id`),
		KEY `handle` (`handle`),
		KEY `timestamp` (`timestamp`),
		KEY `level` (`level`)
	) {$wpdb->get_charset_collate()}";
}

const ISO8601U = 'Y-m-d\TH:i:s.uO';

