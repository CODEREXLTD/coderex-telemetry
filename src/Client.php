<?php
/**
 * Client Class
 *
 * Main entry point for plugin developers to integrate telemetry tracking.
 * Handles initialization, configuration, and provides the public API for tracking events.
 *
 * @package CodeRex\Telemetry
 * @since 1.0.0
 */

namespace CodeRex\Telemetry;

use CodeRex\Telemetry\Drivers\OpenPanelDriver;
use CodeRex\Telemetry\Helpers\Utils;
use InvalidArgumentException;

/**
 * Client class
 *
 * Provides the main API for telemetry tracking with event dispatching
 * and background reporting.
 *
 * @since 1.0.0
 */
class Client {
    /**
     * API key for OpenPanel
     *
     * @var string
     * @since 1.0.0
     */
    private string $apiKey;

    /**
     * API secret for OpenPanel
     *
     * @var string
     * @since 1.0.0
     */
    private string $apiSecret;

    /**
     * Plugin name
     *
     * @var string
     * @since 1.0.0
     */
    private string $pluginName;


    /**
     * Plugin slug
     *
     * @var string
     * @since 1.0.0
     */
    private string $slug;

    /**
     * Plugin file path
     *
     * @var string
     * @since 1.0.0
     */
    private string $pluginFile;

    /**
     * Plugin version
     *
     * @var string
     * @since 1.0.0
     */
    private string $pluginVersion;

    /**
     * EventDispatcher instance
     *
     * @var EventDispatcher
     * @since 1.0.0
     */
    private EventDispatcher $dispatcher;

    /**
     * Constructor
     *
     * Initializes the telemetry client with API key, plugin name, and plugin file path.
     * Validates the API key and extracts plugin version from the plugin file.
     *
     * @param string $apiKey API key for OpenPanel authentication.
     * @param string $pluginName Human-readable plugin name.
     * @param string $pluginFile Path to the main plugin file.
     *
     * @throws InvalidArgumentException If API key is empty.
     * @since 1.0.0
     */
    public function __construct( string $apiKey, string $apiSecret, string $pluginName, string $pluginFile ) {
        // Validate API key
        if ( empty( $apiKey ) ) {
            throw new InvalidArgumentException( 'API key cannot be empty' );
        }

        $this->apiKey       = $apiKey;
        $this->apiSecret    = $apiSecret;
        $this->pluginName   = $pluginName;
        $this->pluginFile   = $pluginFile;
        $this->pluginVersion= Utils::getPluginVersion( $pluginFile );
        $this->set_slug();

        // Initialize OpenPanelDriver with API key
        $driver = new OpenPanelDriver();
        $driver->setApiKey( $apiKey );
        $driver->setApiSecret( $apiSecret );

        // Initialize EventDispatcher
        $this->dispatcher = new EventDispatcher( $driver, $pluginName, $this->pluginVersion );

        // Store instance in global variable with plugin-specific name
        $this->storeGlobalInstance();
    }


    /**
     * Track a custom event
     *
     * Sends a custom event to OpenPanel if opt-in is enabled.
     *
     * @param string $event Event name.
     * @param array  $properties Event properties (optional).
     *
     * @return bool True on success, false on failure or opt-in not enabled.
     * @since 1.0.0
     */
    public function track( string $event, array $properties = array() ): bool {
        // Check if opt-in is enabled
        if ( ! $this->isOptInEnabled() ) {
            return false;
        }

        // Dispatch event
        return $this->dispatcher->dispatch( $event, $properties );
    }

    /**
     * Check if opt-in is enabled
     *
     * Checks if the user has opted in to telemetry tracking.
     * You can set this via: update_option('coderex_telemetry_opt_in', 'yes');
     *
     * @return bool True if opt-in is enabled, false otherwise.
     * @since 1.0.0
     */
    private function isOptInEnabled(): bool {
        $opt_in = get_option( $this->slug.'_allow_tracking', 'no' );
        return $opt_in === 'yes';
    }

    /**
     * Schedule background reporting via WP-Cron
     *
     * Creates a weekly cron job for sending system info events.
     * Allows customization via the 'coderex_telemetry_report_interval' filter.
     *
     * @return void
     * @since 1.0.0
     */
    private function scheduleBackgroundReporting(): void {
        // Hook callback for weekly report
        add_action( 'coderex_telemetry_weekly_report', array( $this, 'sendSystemInfoReport' ) );

        // Schedule cron job if not already scheduled
        if ( ! wp_next_scheduled( 'coderex_telemetry_weekly_report' ) ) {
            // Apply filter for customizable interval (default: weekly)
            $interval = apply_filters( 'coderex_telemetry_report_interval', 'weekly' );

            // Schedule the event
            wp_schedule_event( time(), $interval, 'coderex_telemetry_weekly_report' );
        }
    }

    /**
     * Unschedule background reporting
     *
     * Removes the scheduled cron job for system info reporting.
     * Called when consent is revoked.
     *
     * @return void
     * @since 1.0.0
     */
    private function unscheduleBackgroundReporting(): void {
        $timestamp = wp_next_scheduled( 'coderex_telemetry_weekly_report' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'coderex_telemetry_weekly_report' );
        }
    }

    /**
     * Send system info report
     *
     * Callback for the weekly cron job. Sends system info event if opt-in is enabled.
     *
     * @return void
     * @since 1.0.0
     */
    public function sendSystemInfoReport(): void {
        // Check if opt-in is enabled
        if ( ! $this->isOptInEnabled() ) {
            return;
        }

        // Send system info event
        $this->dispatcher->dispatch( 'system_info', array() );
    }


    /**
     * Set the slug for the plugin
     *
     * @return void
     */
    public function set_slug() {
        $base_name = plugin_basename( $this->pluginFile );
        $this->slug = dirname( $base_name );
    }

    /**
     * Store the client instance in a plugin-specific global variable
     *
     * @return void
     * @since 1.0.0
     */
    private function storeGlobalInstance(): void {
        $global_name = $this->getGlobalVariableName();
        $GLOBALS[ $global_name ] = $this;
    }

    /**
     * Get the plugin-specific global variable name
     *
     * Converts plugin slug to a valid variable name.
     * Example: "best-woocommerce-feed" becomes "best_woocommerce_feed_telemetry_client"
     *
     * @return string Global variable name
     * @since 1.0.0
     */
    private function getGlobalVariableName(): string {
        // Convert slug to valid variable name (replace hyphens with underscores)
        $safe_slug = str_replace( '-', '_', $this->slug );
        return $safe_slug . '_telemetry_client';
    }

    /**
     * Get the client instance for a specific plugin
     *
     * Static method to retrieve the telemetry client for a plugin.
     *
     * @param string $plugin_file The main plugin file path
     * @return Client|null The client instance or null if not found
     * @since 1.0.0
     */
    public static function getInstance( string $plugin_file ): ?Client {
        $base_name = plugin_basename( $plugin_file );
        $slug = dirname( $base_name );
        $safe_slug = str_replace( '-', '_', $slug );
        $global_name = $safe_slug . '_telemetry_client';
        return $GLOBALS[ $global_name ] ?? null;
    }
}
