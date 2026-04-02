<?php
/**
 * OpenPanel Driver Implementation
 *
 * @package LinnoSDK\Telemetry
 * @since 1.0.0
 */

namespace LinnoSDK\Telemetry\Drivers;

/**
 * Class OpenPanelDriver
 *
 * Implements DriverInterface for OpenPanel analytics platform.
 * Handles HTTPS communication, authentication, and error handling.
 *
 * @since 1.0.0
 */
class OpenPanelDriver implements DriverInterface {
	/**
	 * OpenPanel API endpoint URL
	 *
	 * @since 1.0.0
	 */
	private const API_ENDPOINT = 'https://analytics.linno.io/api/track';

	/**
	 * API key for authentication
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $apiKey;


    /**
     * API Secret for authentication
     *
     * @var string
     * @since 1.0.0
     */
    private string $apiSecret;

	/**
	 * Last error message
	 *
	 * @var string|null
	 * @since 1.0.0
	 */
	private ?string $lastError = null;

	/**
	 * Send event data to OpenPanel
	 *
	 * @param string $event The event name.
	 * @param array  $properties The event properties.
	 *
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public function send( string $event, array $properties ): bool {
		$this->lastError = null;

		$payload = array(
			'event'      => $event,
			'properties' => $properties,
		);

		return $this->makeRequest( $payload );
	}

	/**
	 * Set the API key for authentication
	 *
	 * @param string $apiKey The API key.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function setApiKey( string $apiKey ): void {
		$this->apiKey = $apiKey;
	}


    public function setApiSecret( string $apiSecret ): void {
        $this->apiSecret = $apiSecret;
    }

	/**
	 * Get the last error message
	 *
	 * @return string|null The last error message or null if no error.
	 * @since 1.0.0
	 */
	public function getLastError(): ?string {
		return $this->lastError;
	}

	/**
	 * Build HTTP headers for the request
	 *
	 * @return array The headers array.
	 * @since 1.0.0
	 */
	private function buildHeaders(): array {
		return array(
			'openpanel-client-id'     => $this->apiKey,
			'openpanel-client-secret' => $this->apiSecret,
			'Content-Type'            => 'application/json',
		);
	}

	/**
	 * Make HTTPS request to OpenPanel API using cURL
	 *
	 * @param array $payload The payload to send.
	 *
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	private function makeRequest( array $payload ): bool {
		$ch = curl_init( self::API_ENDPOINT );
		$body = json_encode( array(
			'type'    => 'track',
			'payload' => array(
				'name'       => $payload['event'],
				'properties' => $payload['properties'],
			),
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		// Build headers for cURL
		$headers = array();
		foreach ( $this->buildHeaders() as $key => $value ) {
			$headers[] = "$key: $value";
		}

		// Set cURL options to match successful Postman request
		curl_setopt_array( $ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
		) );

		// Execute the request
		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error    = curl_error( $ch );
		$errno    = curl_errno( $ch );

		curl_close( $ch );

		return $this->handleResponse( $response, $httpCode, $error, $errno );
	}

	/**
	 * Handle the API response from cURL
	 *
	 * @param mixed  $response The response body from cURL.
	 * @param int    $httpCode The HTTP status code.
	 * @param string $error The cURL error message.
	 * @param int    $errno The cURL error number.
	 *
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	private function handleResponse( $response, int $httpCode, string $error, int $errno ): bool {
		// Check for cURL errors
		if ( $errno !== 0 ) {
			$this->lastError = sprintf( 'cURL error (%d): %s', $errno, $error );
			return false;
		}

		// Check HTTP status code
		if ( $httpCode < 200 || $httpCode >= 300 ) {
			$this->lastError = sprintf(
				'HTTP %d: %s',
				$httpCode,
				$response ? $response : 'Unknown error'
			);
			return false;
		}

		return true;
	}
}