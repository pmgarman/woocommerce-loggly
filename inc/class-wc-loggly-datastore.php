<?php
class WC_Loggly_Datastore {
	private $table = null;

	function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'wc_loggly_queued_logs';
	}

	function init() {
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $this->get_schema() );
	}

	private function get_schema() {
		global $wpdb;

		return "
			CREATE TABLE {$this->table} (
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

	/**
	 * Internal method to store logs for later sending.
	 *
	 * @param  string   $handle    handle, or source
	 * @param  string   $message   the log message
	 * @param  string   $time      time log was recorded, ISO8601 with microseconds, UTC
	 * @param  string   $level     log level. Null (debug) by default
	 */
	public function store( $handle, $message, $time, $level = null ) {
		global $wpdb;

		$values = array(
			'timestamp' => $time,
			'handle' => $handle,
			'message' => $message,
		);

		$format = array(
			'%s',
			'%s',
			'%s',
		);

		if ( null !== $level ) {
			$values['level'] = $level;
			$format[] = '%s';
		}

		$wpdb->insert( $this->table, $values, $format );
	}

	/**
	 * Gets a uuid, selects the first 100 that aren't claimed yet, and returns it. Uses an update-select
	 * so there's a table lock on update, which protects against race conditions.
	 *
	 * @param  string   $uuid   uuid used for logs
	 */
	public function get_things_to_send( $uuid ) {
		global $wpdb;

		// lock them in
		$update = $wpdb->prepare( "UPDATE {$this->table} SET `claim` = %s WHERE `claim` IS NULL LIMIT 100;", $uuid );
		$wpdb->query( $update );

		// grab them
		return $wpdb->get_results( $wpdb->prepare( "
			SELECT * FROM {$this->table} WHERE `claim` = %s
		", $uuid ), ARRAY_A );
	}

	/**
	 * Deletes logs with specified claim id after a successful send.
	 *
	 * @param  string   $uuid    uuid used for the logs
	 */
	public function delete_logs( $uuid ) {
		global $wpdb;

		$wpdb->delete( $this->table, ['claim' => $uuid], ['%s'] );
	}

	/**
	 * Removes the claim from the logs following an unsuccessful log transmit.
	 *
	 * @param  string    $uuid   uuid used for the logs
	 */
	public function rollback_logs( $uuid ) {
		global $wpdb;

		$update = $wpdb->prepare( "UPDATE {$this->table} SET `claim` = NULL WHERE `claim` = %s;", $uuid );
		$wpdb->query( $update );
	}
}
