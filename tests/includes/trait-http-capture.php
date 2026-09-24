<?php
/**
 * Captures outgoing HTTP requests in tests.
 *
 * @package DFXPRAY
 */

/**
 * HTTP capture helpers.
 */
trait DFXPRAY_Http_Capture {

	/**
	 * Captured requests: url, args.
	 *
	 * @var array[]
	 */
	protected array $http_requests = array();

	/**
	 * Response returned to every captured request.
	 *
	 * @var array|WP_Error
	 */
	protected $http_response;

	/**
	 * Start capturing; every request gets $response.
	 *
	 * @param array|WP_Error|null $response Response to return.
	 */
	protected function capture_http( $response = null ): void {
		$this->http_requests = array();
		$this->http_response = $response ?? array(
			'headers'  => array(),
			'body'     => '{"ok":true}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
	}

	/**
	 * Filter callback.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return array|WP_Error
	 */
	public function intercept_http( $pre, $args, $url ) {
		$this->http_requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return $this->http_response;
	}

	/**
	 * Stop capturing.
	 */
	protected function stop_capturing_http(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );
	}
}
