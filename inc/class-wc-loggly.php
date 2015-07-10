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
	 * The base endpoint URL to be used for API calls.
	 *
	 * @var string
	 */
	private $endpoint = '';

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
			)
		);

		$this->init_settings();
		$this->token = $this->get_option( 'token' );

		if( ! empty( $this->token ) ) {
			$this->endpoint = sprintf( 'http://logs-01.loggly.com/inputs/%s/', $this->token );
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
		wp_remote_post( $this->get_endpoint( $handle ), array(
			'headers' => 'Content-Type: application/json',
			'body'    => json_encode(
				array(
					'timestamp' => current_time( 'c' ), // Include timestamp in ISO 8601 format https://www.loggly.com/docs/automated-parsing/#json
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

}