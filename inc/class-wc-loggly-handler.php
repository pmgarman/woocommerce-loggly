<?php

class WC_Loggly_Handler implements WC_Log_Handler_Interface {
	/**
	 * Handle a log entry.
	 *
	 * @param int $timestamp Log timestamp.
	 * @param string $level emergency|alert|critical|error|warning|notice|info|debug
	 * @param string $message Log message.
	 * @param array $context Additional information for log handlers.
	 *
	 * @return bool False if value was not handled and true if value was handled.
	 */
	public function handle( $timestamp, $level, $message, $context ) {
		if ( isset( $context['source'] ) && $context['source'] ) {
			$handle = $context['source'];
		} else {
			$handle = 'log';
		}

		$time = WC_Loggly::get_current_time();
		$ds = WC_Loggly::get_datastore();
		$api = WC_Loggly::get_api();
		$async = WC_Loggly::is_async();

		if ( ! $async ) {
			$api->send( $handle, $message, $time, $level );
			return;
		}

		$ds->store( $handle, $message, $time, $level );
	}
}
