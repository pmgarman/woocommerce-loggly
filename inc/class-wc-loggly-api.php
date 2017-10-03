<?php
class WC_Loggly_API {
	private $token = '';

	public function __construct( $token ) {
		$this->token = $token;

		if( ! empty( $this->token ) ) {
			$this->endpoint = sprintf( 'https://logs-01.loggly.com/inputs/%s/', $this->token );
		}
	}

	/**
	 * Send a log immediately. This happens when the Async option is not ticked.
	 *
	 * @param  string   $handle  where the log originates from
	 * @param  string   $message what the log contains
	 * @param  string   $time    when the log happened. UTC ISO8601 with microseconds, {@see self::get_current_time}
	 * @param  string   $level   level of log. Debug by default
	 */
	public function send( $handle, $message, $time, $level = 'debug' ) {
		wp_remote_post( $this->get_endpoint( $handle ), array(
			'headers' => 'Content-Type: application/json',
			'body'    => json_encode(
				[
					'timestamp' => $time, // Include timestamp in ISO 8601 format https://www.loggly.com/docs/automated-parsing/#json
					'level' => $level,
					'handle' => $handle,
					'message'   => $message,
				]
			)
		) );
	}

	/**
	 * Using the handle and token generate an API endpoint URL which uses the handle to create a tag.
	 *
	 * A handle is required by WC_Logger, so if somehow we do not get one here we will tag as generic woocommerce.
	 *
	 * @param string $handle
	 *
	 * @return string
	 */
	public function get_endpoint( $handle = '' ) {
		if( empty( $handle ) ) {
			$handle = 'woocommerce';
		}

		return trailingslashit( $this->endpoint ) . 'tag/' . sanitize_title( $handle );
	}
}
