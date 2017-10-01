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
	public static $async    = false;

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
	public static $datastore = null;

	public static $api = null;

	public function __construct() {
		$this->method_title = __( 'Loggly', 'woocommerce-loggly' );
		$this->method_description = __( 'Taps into WC_Logger and sends WooCommerce log data to Loggly.', 'woocommerce-loggly' );

		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );

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
		$this->bulk = sprintf( 'https://logs-01.loggly.com/bulk/%s/tag/bulk', $this->token );

		self::$async = ( 'yes' == $this->get_option( 'async' ) );
		self::$datastore  = WC_Loggly_DataStoreFactory::create();
		self::$api = new WC_Loggly_API( $this->token );

		if ( self::$async ) {
			if ( ! wp_next_scheduled( 'wc_loggly_drain_queue' ) ) {
				wp_schedule_event( time(), 'every2min', 'wc_loggly_drain_queue' );
			}

			add_action( 'wc_loggly_drain_queue', array( $this, 'send_bulk' ) );
		} else {
			wp_clear_scheduled_hook( 'wc_loggly_drain_queue' );
		}

		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '3.0.0', 'ge' ) ) {
			// 3.x
			require_once WC_LOGGLY_PATH . '/inc/class-wc-loggly-handler.php';
			add_filter( 'woocommerce_register_log_handlers', array( $this, 'add_loghandler' ) );
		} else {
			// 2.6.x
			add_action( 'woocommerce_log_add', array( $this, 'add' ), 10, 2 );
		}
	} // End __construct()

	public function add_loghandler( $handlers ) {
		$loggly_handler = new WC_Loggly_Handler();

		array_push( $handlers, $loggly_handler );

		return $handlers;
	}

	public function add_schedule( $schedules ) {
		$schedules['every2min'] = array(
			'interval' => 2 * MINUTE_IN_SECONDS,
			'display' => __('Every 2 minutes')
		);
		return $schedules;
	}

	/**
	 * Hook into WC_Logger and send log data to Loggy. Tagging the logs with the handle, and sending the timestamp &
	 * message in JSON format.
	 *
	 * This is WC 2.6.x
	 *
	 * @param string $handle
	 * @param string $message
	 */
	public function add( $handle = '', $message = '' ) {
		$time = self::get_current_time();

		if ( self::$async ) {
			self::$datastore->store( $handle, $message, $time );
		} else {
			self::$api->send( $handle, $message, $time );
		}
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

		$things_to_send = self::$datastore->get_things_to_send( $uuid );


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
			self::$datastore->delete_logs( $uuid );
			return;
		}
		self::$datastore->rollback_logs( $uuid );
	}

	/**
	 * We can't use current_time for this because that function uses date(), which will always return 000000.
	 *
	 * @see  http://php.net/manual/en/function.date.php
	 * @return string   an ISO8601 format time string in UTC timezone with microseconds. E.g.: 2017-10-01T15:23:10.974390+0000
	 */
	public static function get_current_time() {
		$utc = new DateTimeZone( 'UTC' );
		$time = new DateTime( 'now', $utc );
		return $time->format( ISO8601U );
	}

	public static function get_datastore() {
		return self::$datastore;
	}

	public static function is_async() {
		return self::$async;
	}

	public static function get_api() {
		return self::$api;
	}
}
