<?php
/**
 * Driver Interface for analytics platforms
 *
 * @package LinnoSDK\Telemetry
 * @since 1.0.0
 */

namespace LinnoSDK\Telemetry\Drivers;

/**
 * Interface DriverInterface
 *
 * Defines the contract for analytics platform drivers.
 *
 * @since 1.0.0
 */
interface DriverInterface {
	/**
	 * Send event data to the analytics platform
	 *
	 * @param string $event The event name.
	 * @param array  $properties The event properties.
	 *
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public function send( string $event, array $properties ): bool;

	/**
	 * Set the API key for authentication
	 *
	 * @param string $apiKey The API key.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function setApiKey( string $apiKey ): void;

	/**
	 * Get the last error message
	 *
	 * @return string|null The last error message or null if no error.
	 * @since 1.0.0
	 */
	public function getLastError(): ?string;
}
