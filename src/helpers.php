<?php
/**
 * Global helper functions for Linno Telemetry SDK
 *
 * @package LinnoSDK\Telemetry
 * @since 1.0.0
 */

if (!function_exists('linno_telemetry')) {
    /**
     * Get the Telemetry Client instance for a specific plugin
     *
     * This function returns the Client instance for the specified plugin file.
     * The Client must be initialized elsewhere in the plugin before calling this function.
     *
     * @param string $plugin_file The main plugin file path (use __FILE__ from your main plugin file)
    * @return \LinnoSDK\Telemetry\Client|null The Client instance or null if not initialized
     * @since 1.0.0
     */
    function linno_telemetry(string $plugin_file) {
        return \LinnoSDK\Telemetry\Client::getInstance($plugin_file);
    }
}

if (!function_exists('linno_telemetry_track')) {
    /**
     * Track a telemetry event for a specific plugin
     *
     * This is a convenience function that calls the track() method on the
     * plugin's Client instance. If the Client is not initialized or opt-in
     * is not enabled, the event will not be sent.
     *
     * @param string $plugin_file The main plugin file path (use __FILE__ from your main plugin file)
     * @param string $event The event name (alphanumeric and underscores only)
     * @param array  $properties Optional array of event properties
     *
     * @return bool True if event was sent successfully, false otherwise
     * @since 1.0.0
     */
    function linno_telemetry_track(string $plugin_file, string $event, array $properties = []): bool {
        $client = linno_telemetry($plugin_file);

        if ($client === null) {
            return false;
        }

        return $client->track($event, $properties);
    }
}

if (!function_exists('linno_telemetry_generate_profile_id')) {
    /**
     * Generate a UUID v4 for profile identification
     *
     * This function generates a unique identifier that can be used as a profileId
     * in the __identify property when tracking events with user profiles.
     *
     * @return string UUID v4 string
     * @since 1.0.0
     */
    function linno_telemetry_generate_profile_id(): string {
        return \LinnoSDK\Telemetry\Helpers\Utils::generateProfileId();
    }
}

if (!function_exists('linno_telemetry_update_last_action')) {
    /**
     * Update the last core action for a plugin
     *
     * This updates the last core action performed, which will be included
     * in the deactivation event to understand what the user was doing
     * before deactivating the plugin.
     *
     * @param string $plugin_file The main plugin file path
     * @param string $action The action name (e.g., 'feed_created', 'settings_saved')
     *
     * @return bool True on success, false on failure
     * @since 1.0.0
     */
    function linno_telemetry_update_last_action(string $plugin_file, string $action): bool {
        $client = linno_telemetry($plugin_file);

        if ($client === null) {
            return false;
        }

        $client->updateLastCoreAction($action);
        return true;
    }
}

if (!function_exists('linno_telemetry_sync_consent_state')) {
    /**
     * Synchronize telemetry state after custom onboarding consent updates
     *
     * Use this helper when your onboarding flow stores consent directly
     * (outside of Client::set_optin_state()). It ensures post-consent
     * setup is completed, including pending activation tracking.
     *
     * @param string $plugin_file The main plugin file path
     *
     * @return bool True on success, false if client is unavailable
     * @since 1.0.1
     */
    function linno_telemetry_sync_consent_state(string $plugin_file): bool {
        $client = linno_telemetry($plugin_file);

        if ($client === null) {
            return false;
        }

        $client->sync_consent_state();
        return true;
    }
}
