<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Class WC_Loggly
 *
 * Integrate WC_Logger in WooCommerce to send log data to Loggly as well.
 */
class WC_Loggly extends WC_Integration {

	/**
	 * The ID of the WooCommerce integration
	 *
	 * @var string
	 */
	public  $id       = 'loggly';

	/**
	 * The customer token used to authenticate with the Loggly API
	 *
	 * @var string
	 */
	private $token    = '';

	/**
	 * Whether to process the logs synchronously or async
	 *
	 * @var boolean
	 */
	private $async    = false;

	/**
	 * The base endpoint URL to be used for API calls.
	 *
	 * @var string
	 */
	private $endpoint = '';

	/**
	 * Bulk endpoint for loggly
	 *
	 * @var string
	 */
	private $bulk     = '';

	/**
	 * Datastore class
	 *
	 * @var null
	 */
	private $datastore = null;

	public function __construct() {
		$this->method_title = __( 'Loggly', 'woocommerce-loggly' );
		$this->method_description = __( 'Taps into WC_Logger and sends WooCommerce log data to Loggly.', 'woocommerce-loggly' );

		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_log_add', array( $this, 'add' ), 10, 2 );

		$this->form_fields = array(
			'token' => array(
				'title'       => __( 'Customer Token', 'woocommerce-loggly'),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Your Loggly customer token to make API calls.', 'woocommerce-loggly' ),
			),
			'async' => array(
				'title'       => __( 'Process in batches?', 'woocommerce-loggly'),
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => __( 'Whether to collect a lot of logs and send them in batches reducing network calls.', 'woocommerce-loggly' ),
			)
		);

		$this->init_settings();
		$this->token = $this->get_option( 'token' );
		$this->async = ( 'yes' == $this->get_option( 'async' ) );
		$this->datastore  = WC_Loggly_DataStoreFactory::create();

		if( ! empty( $this->token ) ) {
			$this->endpoint = sprintf( 'https://logs-01.loggly.com/inputs/%s/', $this->token );
			$this->bulk = sprintf( 'https://logs-01.loggly.com/bulk/%s/tag/bulk', $this->token );
		}

		if ( $this->async ) {
			if ( ! wp_next_scheduled( 'wc_loggly_drain_queue' ) ) {
				wp_schedule_event( time(), 'hourly', 'wc_loggly_drain_queue' );
			}

			add_action( 'wc_loggly_drain_queue', array( $this, 'send_bulk' ) );
		} else {
			wp_clear_scheduled_hook( 'wc_loggly_drain_queue' );
		}
	} // End __construct()

	/**
	 * Hook into WC_Logger and send log data to Loggy. Tagging the logs with the handle, and sending the timestamp &
	 * message in JSON format.
	 *
	 * @param string $handle
	 * @param string $message
	 */
	public function add( $handle = '', $message = '' ) {
		$time = $this->get_current_time();

		if ( $this->async ) {
			$this->datastore->store( $handle, $message, $time );
		} else {
			$this->send( $handle, $message, $time );
		}
	}

	/**
	 * We can't use current_time for this because that function uses date(), which will always return 000000.
	 *
	 * @see  http://php.net/manual/en/function.date.php
	 * @return string   an ISO8601 format time string in UTC timezone with microseconds. E.g.: 2017-10-01T15:23:10.974390+0000
	 */
	private function get_current_time() {
		$utc = new DateTimeZone( 'UTC' );
		$time = new DateTime( 'now', $utc );
		return $time->format( ISO8601U );
	}

	/**
	 * Send a log immediately. This happens when the Async option is not ticked.
	 *
	 * @param  string   $handle  where the log originates from
	 * @param  string   $message what the log contains
	 * @param  string   $time    when the log happened. UTC ISO8601 with microseconds, {@see self::get_current_time}
	 */
	private function send( $handle, $message, $time ) {
		wp_remote_post( $this->get_endpoint( $handle ), array(
			'headers' => 'Content-Type: application/json',
			'body'    => json_encode(
				array(
					'timestamp' => $time, // Include timestamp in ISO 8601 format https://www.loggly.com/docs/automated-parsing/#json
					'message'   => $message
				)
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

	/**
	 * Sending bulk needs to be line delimited. This practically means that every log entry needs
	 * to be JSON encoded, which will also turn newlines in the messages to "\n", so that does
	 * not break sending it.
	 *
	 * @uses  RealGUIs\generate_uuid_v4   A UUIDv4 generator by Ryan McCue
	 *
	 * @see  https://github.com/rmccue/realguids/  RealGUIs repo
	 * @see  https://www.loggly.com/docs/http-bulk-endpoint/  Loggly documentation on bulk endpoint
	 */
	public function send_bulk() {
		$uuid = \RealGUIDs\generate_uuid_v4();

		$things_to_send = $this->datastore->get_things_to_send( $uuid );

		$to_json = [];

		foreach ($things_to_send as $thing) {
			$to_json[] = json_encode( [
				'timestamp' => $thing['timestamp'],
				'log' => [
					'level' => $thing['level'],
					'handle' => $thing['handle'],
					'message' => $thing['message'],
				]
			] );
		}

		$payload = array(
			'headers' => 'content-type:text/plain',
			'body'    => implode( PHP_EOL, $to_json ),
		);

		$response = wp_remote_post( $this->bulk, $payload );

		if ( ! is_wp_error( $response ) ) {
			$this->datastore->delete_logs( $uuid );
			return;
		}
		$this->datastore->rollback_logs( $uuid );
	}
}
