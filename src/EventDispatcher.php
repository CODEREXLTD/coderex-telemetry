<?php
/**
 * EventDispatcher Class
 *
 * Orchestrates event sending by normalizing payloads, adding system information,
 * validating data, and delegating transmission to the appropriate driver.
 *
 * @package CodeRex\Telemetry
 * @since 1.0.0
 */

namespace CodeRex\Telemetry;

use CodeRex\Telemetry\Drivers\DriverInterface;
use CodeRex\Telemetry\Helpers\Utils;

/**
 * EventDispatcher class
 *
 * Acts as an abstraction layer between the Client and the analytics platform.
 *
 * @since 1.0.0
 */
class EventDispatcher {
	/**
	 * Driver instance for sending events
	 *
	 * @var DriverInterface
	 */
	private $driver;

	/**
	 * Plugin name
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin version
	 *
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Plugin slug
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Constructor
	 *
	 * @param DriverInterface $driver Driver instance for sending events.
	 * @param string          $plugin_name Plugin name.
	 * @param string          $plugin_version Plugin version.
	 * @param string          $plugin_slug Plugin slug.
	 *
	 * @since 1.0.0
	 */
	public function __construct( DriverInterface $driver, string $plugin_name, string $plugin_version, string $plugin_slug = '' ) {
		$this->driver         = $driver;
		$this->plugin_name    = $plugin_name;
		$this->plugin_version = $plugin_version;
		$this->plugin_slug    = $plugin_slug;
	}

	/**
	 * Dispatch an event to the analytics platform
	 *
	 * Orchestrates the event sending process by normalizing the payload,
	 * adding system information, validating, and sending via the driver.
	 *
	 * @param string $event Event name.
	 * @param array  $properties Event properties (optional).
	 *
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public function dispatch( string $event, array $properties = array() ): bool {
		// Normalize the payload
		$payload = $this->normalizePayload( $event, $properties );

		// Validate the payload
		if ( ! $this->validatePayload( $payload ) ) {
			return false;
		}
		// Send via driver
		$result = $this->driver->send( $payload['event'], $payload['properties'] );

		if ( ! $result ) {
			$error = $this->driver->getLastError();
			return false;
		}

		return true;
	}

	/**
	 * Dispatch a lifecycle event (activation/deactivation)
	 *
	 * Lifecycle events are sent regardless of opt-in status to ensure
	 * we capture plugin installation and removal events.
	 *
	 * @param string $event Event name.
	 * @param array  $properties Event properties (optional).
	 *
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public function dispatchLifecycleEvent( string $event, array $properties = array() ): bool {
		// Normalize the payload
		$payload = $this->normalizePayload( $event, $properties );

		// Validate the payload
		if ( ! $this->validatePayload( $payload ) ) {
			return false;
		}

		// Send via driver
		$result = $this->driver->send( $payload['event'], $payload['properties'] );

		if ( ! $result ) {
			$error = $this->driver->getLastError();
			return false;
		}

		return true;
	}

	/**
	 * Normalize payload to create consistent event structure
	 *
	 * Creates a standardized structure with event and properties keys,
	 * and adds required fields like site_url, plugin_name, plugin_version, and timestamp.
	 *
	 * @param string $event Event name.
	 * @param array  $properties Event properties.
	 *
	 * @return array Normalized payload with event and properties keys.
	 * @since 1.0.0
	 */
	private function normalizePayload( string $event, array $properties ): array {
		// Sanitize event name
		$sanitized_event = Utils::sanitizeEventName( $event );

		// Sanitize properties
		$sanitized_properties = Utils::sanitizeProperties( $properties );

		// Add required fields with proper sanitization
		$sanitized_properties['site_url']       = esc_url_raw( Utils::getSiteUrl() );
		$sanitized_properties['plugin_name']    = $this->plugin_name;
		$sanitized_properties['plugin_version'] = $this->plugin_version;
		$sanitized_properties['timestamp']      = Utils::getCurrentTimestamp();

		// Automatically add profile identification with current user info
		if ( ! isset( $sanitized_properties['__identify'] ) ) {
			// No __identify provided - create one with site profile ID and current user info
			$sanitized_properties['__identify'] = array(
				'profileId' => Utils::getSiteProfileId(),
			);
			
			// Add current user information if available
			if ( function_exists( 'wp_get_current_user' ) ) {
				$current_user = wp_get_current_user();
				if ( $current_user && $current_user->ID > 0 ) {
					$sanitized_properties['__identify']['email']     = $current_user->user_email;
					$sanitized_properties['__identify']['firstName'] = $current_user->first_name ?: 'User';
					$sanitized_properties['__identify']['lastName']  = $current_user->last_name ?: '';
					if ( function_exists( 'get_avatar_url' ) ) {
						$sanitized_properties['__identify']['avatar'] = get_avatar_url( $current_user->ID );
					}
				}
			}
		} elseif ( is_array( $sanitized_properties['__identify'] ) ) {
			if ( empty( $sanitized_properties['__identify']['profileId'] ) ) {
				// Add site profile ID while preserving other fields
				$sanitized_properties['__identify']['profileId'] = Utils::getSiteProfileId();
			}
			
			// Fill in missing user info if not provided
			if ( function_exists( 'wp_get_current_user' ) ) {
				$current_user = wp_get_current_user();
				if ( $current_user && $current_user->ID > 0 ) {
					if ( empty( $sanitized_properties['__identify']['email'] ) ) {
						$sanitized_properties['__identify']['email'] = $current_user->user_email;
					}
					if ( empty( $sanitized_properties['__identify']['firstName'] ) ) {
						$sanitized_properties['__identify']['firstName'] = $current_user->first_name ?: 'User';
					}
					if ( empty( $sanitized_properties['__identify']['lastName'] ) ) {
						$sanitized_properties['__identify']['lastName'] = $current_user->last_name ?: '';
					}
					if ( empty( $sanitized_properties['__identify']['avatar'] ) && function_exists( 'get_avatar_url' ) ) {
						$sanitized_properties['__identify']['avatar'] = get_avatar_url( $current_user->ID );
					}
				}
			}
		}
		return array(
			'event'      => $sanitized_event,
			'properties' => $sanitized_properties,
		);
	}

	/**
	 * Add system information to the payload
	 *
	 * Adds PHP version, WordPress version, MySQL version, and server software
	 * to the event properties using the Utils class.
	 *
	 * @param array $payload Event payload.
	 *
	 * @return array Payload with system information added.
	 * @since 1.0.0
	 */
	private function addSystemInfo( array $payload ): array {
		$payload['properties']['php_version']     = Utils::getPhpVersion();
		$payload['properties']['wp_version']      = Utils::getWordPressVersion();
		$payload['properties']['mysql_version']   = Utils::getMySqlVersion();
		$payload['properties']['server_software'] = Utils::getServerSoftware();

		return $payload;
	}

	/**
	 * Validate payload structure and required fields
	 *
	 * Checks that all required fields are present: event, site_url,
	 * plugin_name, plugin_version, and timestamp.
	 *
	 * @param array $payload Event payload to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 * @since 1.0.0
	 */
	private function validatePayload( array $payload ): bool {
		// Check event key exists and is not empty
		if ( empty( $payload['event'] ) || ! is_string( $payload['event'] ) ) {
			return false;
		}

		// Check properties key exists and is an array
		if ( ! isset( $payload['properties'] ) || ! is_array( $payload['properties'] ) ) {
			return false;
		}

		$properties = $payload['properties'];

		// Check required fields in properties
		$required_fields = array( 'site_url', 'plugin_name', 'plugin_version', 'timestamp' );

		foreach ( $required_fields as $field ) {
			if ( empty( $properties[ $field ] ) || ! is_string( $properties[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}
}
