<?php
/**
 * Client Class
 *
 * Main entry point for plugin developers to integrate telemetry tracking.
 * Handles initialization, configuration, and provides the public API for tracking events.
 *
 * @package Linno\Telemetry
 * @since 1.0.0
 */

namespace Linno\Telemetry;

use Linno\Telemetry\Drivers\DriverInterface;
use Linno\Telemetry\Drivers\OpenPanelDriver;
use Linno\Telemetry\Helpers\Utils;
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
     * Global option key for telemetry consent.
     */
    private const GLOBAL_OPTIN_KEY = 'linno_telemetry_allow_tracking';

    /**
     * Consent schema version marker.
     */
    private const GLOBAL_CONSENT_VERSION_KEY = 'linno_telemetry_consent_version';

    /**
     * Current consent schema version.
     */
    private const CONSENT_VERSION = '2';

    /**
     * Known legacy Appsero consent option keys.
     */
    private const LEGACY_APPSERO_OPTIN_KEYS = array(
        'best-woocommerce-feed_allow_tracking',
        'wpvr_allow_tracking',
        'wpfunnels_allow_tracking',
        'cart-lift_allow_tracking',
        'creatorlms_allow_tracking',
        'mail-mint_allow_tracking',
    );

    /**
     * Global option key for telemetry queue table creation state.
     */
    private const GLOBAL_TABLE_CREATED_KEY = 'linno_telemetry_table_created';

    /**
     * Global option key for telemetry notice dismissal state.
     */
    private const GLOBAL_NOTICE_DISMISSED_KEY = 'linno_telemetry_notice_dismissed';

    /**
     * Global option key for shared telemetry unique ID.
     */
    private const GLOBAL_UNIQUE_ID_KEY = 'linno_telemetry_unique_id';

    /**
     * Configuration data (apiKey, apiSecret, pluginName, pluginFile, slug, version, unique_id)
     *
     * @var array
     */
    private array $config = [];

    /**
     * Text domain for i18n
     *
     * @var string
     */
    private static string $textDomain = '';

    /**
     * Privacy policy URL for consent notice Learn more link.
     *
     * @var string
     */
    private static string $privacyUrl = 'https://rextheme.com/privacy-policy/';

    /**
     * Analytics service label used in consent notice message.
     *
     * @var string
     */
    private static string $consentServiceName = 'our analytics service';

    /**
     * Handlers (dispatcher, consent, deactivation, queue)
     *
     * @var array
     */
    private array $handlers = [];

    /**
     * TriggerManager instance
     *
     * @var TriggerManager|null
     */
    private ?TriggerManager $trigger_manager = null;

    /**
     * Constructor
     *
     * Initializes the telemetry client with API key, plugin name, and plugin file path.
     *
     * Accepts an optional driver instance for dependency injection. When no driver
     * is supplied the default OpenPanelDriver is created automatically using the
     * provided API key and secret, so existing integrations require no changes.
     *
     * To use a different driver (e.g. PostHog), create and configure it first,
     * then pass it as the fifth argument:
     *
     *   $driver = new \Linno\Telemetry\Drivers\PostHogDriver();
     *   $driver->setApiKey( 'phc_xxxx' );
     *   $client = new Client( 'phc_xxxx', '', 'My Plugin', __FILE__, $driver );
     *
     * @param string               $apiKey     API key (used by the default OpenPanelDriver or passed to a custom driver manually).
     * @param string               $apiSecret  API secret (used by OpenPanelDriver; pass empty string for drivers that do not need it).
     * @param string               $pluginName Human-readable plugin name.
     * @param string               $pluginFile Path to the main plugin file.
     * @param DriverInterface|null $driver     Optional pre-configured driver. Defaults to OpenPanelDriver.
     *
     * @throws InvalidArgumentException If API key is empty.
     * @since 1.0.0
     */
    public function __construct( string $apiKey, string $apiSecret, string $pluginName, string $pluginFile, ?DriverInterface $driver = null ) {
        // Validate API key
        if ( empty( $apiKey ) ) {
            throw new InvalidArgumentException( 'API key cannot be empty' );
        }

        $this->config['apiKey']        = $apiKey;
        $this->config['apiSecret']     = $apiSecret;
        $this->config['pluginName']    = $pluginName;
        $this->config['pluginFile']    = $pluginFile;
        $this->config['pluginVersion'] = Utils::getPluginVersion( $pluginFile );
        
        $this->set_slug();
        $this->config['unique_id']     = $this->get_or_create_unique_id();

        // Default text domain if not already set
        if ( empty( self::$textDomain ) ) {
            self::$textDomain = $this->config['slug'];
        }

        // Use the injected driver, or fall back to OpenPanelDriver.
        if ( null === $driver ) {
            $driver = new OpenPanelDriver();
            $driver->setApiKey( $apiKey );
            $driver->setApiSecret( $apiSecret );
        }

        // Initialize EventDispatcher
        $this->handlers['dispatcher'] = new EventDispatcher( $driver, $pluginName, $this->config['pluginVersion'], $this->config['unique_id'] );

        // Initialize other handlers
        $this->handlers['consent'] = new Consent( $this );
        $this->handlers['deactivation'] = new Deactivation( $this );
        $this->handlers['queue'] = new Queue();

        // Schedule background reporting
        $this->scheduleBackgroundReporting();
    }

    /**
     * Get the text domain.
     *
     * @return string
     */
    public function get_text_domain(): string {
        return self::$textDomain;
    }

    /**
     * Set the text domain.
     *
     * @param string $textDomain
     */
    public static function set_text_domain( string $textDomain ): void {
        self::$textDomain = $textDomain;
    }

    /**
     * Get the privacy policy URL used for Learn more link.
     *
     * @return string
     */
    public function get_privacy_url(): string {
        return self::$privacyUrl;
    }

    /**
     * Set the privacy policy URL used for Learn more link.
     *
     * @param string $privacyUrl
     * @return void
     */
    public static function set_privacy_url( string $privacyUrl ): void {
        if ( ! empty( $privacyUrl ) ) {
            self::$privacyUrl = $privacyUrl;
        }
    }

    /**
     * Get the analytics service label used in consent message.
     *
     * @return string
     */
    public function get_consent_service_name(): string {
        return self::$consentServiceName;
    }

    /**
     * Set the analytics service label used in consent message.
     *
     * @param string $serviceName
     * @return void
     */
    public static function set_consent_service_name( string $serviceName ): void {
        if ( ! empty( $serviceName ) ) {
            self::$consentServiceName = $serviceName;
        }
    }

    /**
     * Initialize the telemetry client
     *
     * This method should be called by the plugin developer to initialize the hooks.
     *
     * @return void
     */
    public function init(): void {
        $this->maybe_upgrade_consent_state();

        if ( ! empty( self::$textDomain ) ) {
            load_plugin_textdomain( self::$textDomain, false, dirname( plugin_basename( $this->config['pluginFile'] ) ) . '/languages' );
        }
        
        $this->handlers['consent']->init();
        $this->handlers['deactivation']->init();
        $this->init_triggers();

        // Internally register activation and deactivation hooks
        register_activation_hook( $this->config['pluginFile'], [ $this, 'activate' ] );
        register_deactivation_hook( $this->config['pluginFile'], [ $this, 'deactivate' ] );

        // Ensure post-consent setup is completed for already-consented sites.
        if ( $this->isOptInEnabled() ) {
            $this->finalize_optin_setup();
        }
    }

    /**
     * Plugin activation hook.
     *
     * @return void
     */
    public function activate(): void {
        // Track activation without consent using minimal non-personal payload.
        if ( ! get_option( $this->config['slug'] . '_telemetry_activated_tracked' ) ) {
            $this->track_lifecycle_event(
                'plugin_activated',
                [
                    'site_url' => get_site_url(),
                ]
            );

            update_option( $this->config['slug'] . '_telemetry_activated_tracked', 'yes' );
        }
    }

    /**
     * Create the queue table.
     *
     * @return void
     * @since 1.0.1
     */
    public function create_queue_table(): void {
        $this->handlers['queue']->create_table();
    }

    /**
     * Plugin deactivation hook.
     *
     * This method should be called from the plugin's deactivation hook.
     * It clears all pending events for this plugin from the queue.
     *
     * @return void
     * @since 1.0.1
     */
    public function deactivate(): void {
        // Check if the deactivation event was already sent by the feedback form
        $transient_key = $this->get_slug() . '_deactivation_event_sent';
        if ( 'yes' !== get_transient( $transient_key ) ) {
            // Send a generic deactivation event if the feedback form didn't send one
            $this->track_lifecycle_event(
                'plugin_deactivated',
                [
                    'site_url' => get_site_url(),
                    'reason'   => 'none',
                ]
            );
        }
        // Clean up the transient regardless
        delete_transient( $transient_key );

        $this->handlers['queue']->clear_for_plugin( $this->config['slug'] );
    }


    /**
     * Track an event immediately
     *
     * Sends an event directly without adding it to the queue.
     *
     * @param string $event Event name.
     * @param array $properties Event properties (optional).
     * @param bool $override Whether to override the opt-in check.
     *
     * @return void
     * @since 1.0.1
     */
    public function track_immediate( string $event, array $properties = array(), bool $override = false ): void {
        // Check if opt-in is enabled
        if ( ! $override && ! $this->isOptInEnabled() ) {
            return;
        }

        // Prepare full properties with metadata
        $properties = $this->prepare_properties( $properties );

        $result = $this->handlers['dispatcher']->dispatch( $event, $properties );

        if ( $result ) {
            update_option( $this->config['slug'] . '_telemetry_last_send', time(), false );
        } else {
            // Fallback: Add to queue if immediate send fails
            $this->handlers['queue']->add( $this->config['slug'], $event, $properties );
        }
    }

    /**
     * Track a custom event
     *
     * Adds a custom event to the queue if opt-in is enabled.
     *
     * @param string $event Event name.
     * @param array  $properties Event properties (optional).
     * @param bool   $override Whether to override the opt-in check.
     *
     * @return void
     * @since 1.0.0
     */
    public function track( string $event, array $properties = array(), bool $override = false ): void {
        // Check if opt-in is enabled
        if ( ! $override && ! $this->isOptInEnabled() ) {
            return;
        }

        // Prepare full properties with metadata
        $properties = $this->prepare_properties( $properties );

        // Add event to queue
        $this->handlers['queue']->add( $this->config['slug'], $event, $properties );
    }

    /**
     * Prepare event properties with all necessary metadata.
     *
     * @param array $properties Original properties.
     * @return array Enriched properties.
     */
    private function prepare_properties( array $properties ): array {
        // Add metadata if not already present
        $properties['site_url']       = $properties['site_url'] ?? get_site_url();
        $properties['unique_id']      = $properties['unique_id'] ?? $this->config['unique_id'];
        $properties['plugin_name']    = $properties['plugin_name'] ?? $this->config['pluginName'];
        $properties['plugin_version'] = $properties['plugin_version'] ?? $this->config['pluginVersion'];
        $properties['timestamp']      = $properties['timestamp'] ?? Utils::getCurrentTimestamp();

        // Add user identification context if not already present
        if ( ! isset( $properties['__identify'] ) ) {
            $properties['__identify'] = Utils::get_current_user_identify();
        }

        return $properties;
    }

    /**
     * Track lifecycle events without consent using a strict non-personal payload.
     *
     * Allowed properties:
     * - plugin_activated: site_url, unique_id
     * - plugin_deactivated: site_url, unique_id, reason
     *
     * @param string $event Event name.
     * @param array  $properties Event properties.
     *
     * @return void
     */
    public function track_lifecycle_event( string $event, array $properties = array() ): void {
        $minimal_properties = array(
            'site_url'   => esc_url_raw( (string) ( $properties['site_url'] ?? get_site_url() ) ),
            'unique_id'  => sanitize_text_field( (string) ( $properties['unique_id'] ?? $this->config['unique_id'] ) ),
            '__identify' => array(
                'profileId' => Utils::getSiteProfileId(),
            ),
        );

        if ( 'plugin_deactivated' === $event ) {
            $minimal_properties['reason'] = sanitize_text_field( (string) ( $properties['reason'] ?? 'none' ) );
        }

        $result = $this->handlers['dispatcher']->dispatch_minimal( $event, $minimal_properties );

        if ( $result ) {
            update_option( $this->config['slug'] . '_telemetry_last_send', time(), false );
        }
    }

    /**
     * Check if opt-in is enabled
     *
     * Checks if the user has opted in to telemetry tracking.
     *
     * @return bool True if opt-in is enabled, false otherwise.
     * @since 1.0.0
     */
    private function isOptInEnabled(): bool {
        return 'yes' === $this->get_optin_state();
    }

    /**
     * Get normalized consent state from current or legacy keys.
     *
     * Priority order:
     * 1) Linno global key
     * 2) Current plugin's Appsero-style key ({slug}_allow_tracking)
     * 3) Known legacy Appsero keys
     *
     * If a legacy key is found and Linno global key is missing,
     * the value is migrated to Linno global key for future reads.
     *
     * @return string|null Returns 'yes', 'no', or null when no decision exists.
     */
    public function get_optin_state(): ?string {
        $global_state = $this->normalize_optin_value( get_option( $this->get_optin_key(), null ) );
        if ( null !== $global_state ) {
            return $global_state;
        }

        foreach ( $this->get_legacy_optin_keys() as $legacy_key ) {
            $legacy_state = $this->normalize_optin_value( get_option( $legacy_key, null ) );
            if ( null !== $legacy_state ) {
                update_option( $this->get_optin_key(), $legacy_state );
                return $legacy_state;
            }
        }

        return null;
    }

    /**
     * Persist consent decision to Linno key and current plugin legacy key.
     *
     * @param string $state Accepted values: 'yes' or 'no'.
     * @return void
     */
    public function set_optin_state( string $state ): void {
        $normalized_state = $this->normalize_optin_value( $state );

        if ( null === $normalized_state ) {
            return;
        }

        update_option( $this->get_optin_key(), $normalized_state );
        update_option( $this->get_slug() . '_allow_tracking', $normalized_state );

        if ( 'yes' === $normalized_state ) {
            $this->finalize_optin_setup();
        }
    }

    /**
     * Synchronize telemetry side effects after consent changes.
     *
     * Use this when consent is managed in a custom onboarding flow and
     * state was persisted outside set_optin_state().
     *
     * @return void
     */
    public function sync_consent_state(): void {
        if ( $this->isOptInEnabled() ) {
            $this->finalize_optin_setup();
        }
    }

        /**
         * Get all legacy consent keys to check for migration.
         *
         * @return array
         */
    public function get_legacy_optin_keys(): array {
        return array_values(
            array_unique(
                array_merge(
                    array( $this->get_slug() . '_allow_tracking' ),
                    self::LEGACY_APPSERO_OPTIN_KEYS
                )
            )
        );
    }

    /**
     * Normalize stored consent value.
     *
     * @param mixed $value
     * @return string|null
     */
    private function normalize_optin_value( $value ): ?string {
        if ( is_string( $value ) ) {
            $value = strtolower( trim( $value ) );
            if ( 'yes' === $value ) {
                return 'yes';
            }
            if ( 'no' === $value ) {
                return 'no';
            }
        }

        return null;
    }

    /**
     * Get the option key for tracking consent.
     *
     * @return string
     */
    public function get_optin_key(): string {
        return self::GLOBAL_OPTIN_KEY;
    }

    /**
     * Get the global option key for notice dismissal.
     *
     * @return string
     */
    public function get_notice_dismissed_key(): string {
        return self::GLOBAL_NOTICE_DISMISSED_KEY;
    }

    /**
     * Get the plugin slug.
     *
     * @return string
     */
    public function get_slug(): string {
        return $this->config['slug'];
    }

    /**
     * Get the plugin file path.
     *
     * @return string
     */
    public function get_plugin_file(): string {
        return $this->config['pluginFile'];
    }

    /**
     * Get the plugin name.
     *
     * @return string
     */
    public function get_plugin_name(): string {
        return $this->config['pluginName'];
    }

    /**
     * Get the unique ID for the site.
     *
     * @return string
     */
    public function get_unique_id(): string {
        return $this->config['unique_id'];
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

    /**
     * Track a 'setup' event.
     *
     * This event is sent only once after the plugin setup is completed.
     * Requires user consent.
     *
     * @param array $properties Additional properties for the event.
     * @return void
     */
    public function track_setup( array $properties = [] ): void {
        if ( $this->has_sent_event( 'setup' ) ) {
            return;
        }

        if ( ! $this->isOptInEnabled() ) {
            return;
        }

        $this->track( 'setup', $properties );
        $this->mark_event_sent( 'setup' );
    }

    /**
     * Track a 'first_strike' event.
     *
     * This event is sent only once when the user experiences the core value of the product.
     * Requires user consent.
     *
     * @param array $properties Additional properties for the event.
     * @return void
     */
    public function track_first_strike( array $properties = [] ): void {
        if ( $this->has_sent_event( 'first_strike' ) ) {
            return;
        }

        if ( ! $this->isOptInEnabled() ) {
            return;
        }

        // Ensure first_strike appears at least 1 second after setup in telemetry timelines.
        $properties['__timestamp'] = gmdate( 'c', time() + 1 );

        $this->track( 'first_strike', $properties );
        $this->mark_event_sent( 'first_strike' );
    }

    /**
     * Track a 'kui' (Key Usage Indicator) event.
     *
     * This event can be sent multiple times when the user gets significant value from the plugin.
     * Requires user consent.
     *
     * @param string $kui_name The name of the KUI event (e.g., 'funnel_order_received').
     * @param array $properties Additional properties for the event.
     * @return void
     */
    public function track_kui( string $kui_name, array $properties = [] ): void {
        $this->track( 'kui_' . $kui_name, $properties );
    }

    /**
     * Get the TriggerManager instance
     *
     * Provides access to configure automatic event triggers.
     *
     * @return TriggerManager
     * @since 1.0.0
     */
    public function triggers(): TriggerManager {
        if ( null === $this->trigger_manager ) {
            $this->trigger_manager = new TriggerManager( $this );
        }
        return $this->trigger_manager;
    }

    /**
     * Define automatic triggers for PLG events
     *
     * Simplified method to configure all triggers at once.
     *
     * @param array $config Configuration array with:
     *                       - setup: hook name or ['hook' => hook_name, 'callback' => callable]
     *                       - first_strike: hook name or ['hook' => hook_name, 'callback' => callable]
     *                       - kui: array of KUI configurations
     * @return self
     * @since 1.0.0
     */
    public function define_triggers( array $config ): self {
        $triggers = $this->triggers();

        if ( isset( $config['setup'] ) ) {
            $hook = is_array( $config['setup'] ) ? $config['setup']['hook'] : $config['setup'];
            $callback = is_array( $config['setup'] ) ? ( $config['setup']['callback'] ?? null ) : null;
            $triggers->on_setup( $hook, $callback );
        }

        if ( isset( $config['first_strike'] ) ) {
            $hook = is_array( $config['first_strike'] ) ? $config['first_strike']['hook'] : $config['first_strike'];
            $callback = is_array( $config['first_strike'] ) ? ( $config['first_strike']['callback'] ?? null ) : null;
            $triggers->on_first_strike( $hook, $callback );
        }

        if ( isset( $config['kui'] ) && is_array( $config['kui'] ) ) {
            foreach ( $config['kui'] as $name => $kui_config ) {
                if ( is_array( $kui_config ) ) {
                    $triggers->on_kui( $name, $kui_config );
                }
            }
        }

        return $this;
    }

    /**
     * Initialize trigger manager
     *
     * Must be called after defining triggers and before init completes.
     *
     * @return void
     * @since 1.0.0
     */
    private function init_triggers(): void {
        if ( null !== $this->trigger_manager ) {
            $this->trigger_manager->init();
        }
    }

    /**
     * Check if a specific event has already been sent.
     *
     * @param string $event_name The name of the event to check.
     * @return bool True if the event has been sent, false otherwise.
     * @since 1.0.0
     */
    public function has_sent_event( string $event_name ): bool {
        return 'yes' === get_option( $this->config['slug'] . '_event_sent_' . $event_name, 'no' );
    }

    /**
     * Mark a specific event as sent.
     *
     * @param string $event_name The name of the event to mark as sent.
     * @return void
     * @since 1.0.0
     */
    public function mark_event_sent( string $event_name ): void {
        update_option( $this->config['slug'] . '_event_sent_' . $event_name, 'yes' );
    }

    /**
     * Schedule background reporting via WP-Cron
     *
     * Creates a weekly cron job for sending system info events.
     * Allows customization via a filter.
     *
     * @return void
     * @since 1.0.0
     */
    private function scheduleBackgroundReporting(): void {
        $hook = $this->get_cron_hook();

        // Register the callback so it runs whenever WP-Cron fires this hook.
        add_action( $hook, array( $this, 'process_queue' ) );

        // Defer scheduling to the init hook so WordPress is fully loaded
        // before we read or write the cron option.
        add_action( 'init', function() use ( $hook ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                $interval = apply_filters( $this->config['slug'] . '_telemetry_report_interval', 'daily' );
                wp_schedule_event( time(), $interval, $hook );
            }
        } );
    }

    /**
     * Upgrade consent state to the current schema version.
     *
     * This intentionally clears prior consent + notice state that may have
     * been influenced by legacy compatibility behavior, so only new
     * Linno consent decisions are used going forward.
     *
     * @return void
     */
    private function maybe_upgrade_consent_state(): void {
        $current_version = (string) get_option( self::GLOBAL_CONSENT_VERSION_KEY, '' );

        if ( self::CONSENT_VERSION === $current_version ) {
            return;
        }

        delete_option( self::GLOBAL_OPTIN_KEY );
        delete_option( self::GLOBAL_NOTICE_DISMISSED_KEY );

        update_option( self::GLOBAL_CONSENT_VERSION_KEY, self::CONSENT_VERSION, false );
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
        $hook = $this->get_cron_hook();
        $timestamp = wp_next_scheduled( $hook );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
        }
    }

    /**
     * Get the cron hook name.
     *
     * @return string
     */
    public function get_cron_hook(): string {
        return $this->config['slug'] . '_telemetry_queue_process';
    }

    /**
     * Finalize required setup once user has granted telemetry consent.
     *
     * Creates queue table (once) and flushes pending activation tracking.
     *
     * @return void
     */
    private function finalize_optin_setup(): void {
        if ( ! get_option( self::GLOBAL_TABLE_CREATED_KEY ) ) {
            $this->create_queue_table();
            update_option( self::GLOBAL_TABLE_CREATED_KEY, 'yes' );
        }
    }

    /**
     * Process the event queue
     *
     * Callback for the cron job. Sends events from the queue if opt-in is enabled.
     *
     * @return void
     * @since 1.0.0
     */
    public function process_queue(): void {
        if ( ! $this->isOptInEnabled() ) {
            return;
        }

        if ( ! $this->handlers['queue']->table_exists() ) {
            return;
        }

        $events = $this->handlers['queue']->get_all( $this->config['slug'] );

        if ( empty( $events ) ) {
            return;
        }

        $ids_to_delete = [];

        foreach ( $events as $event ) {
            $properties = json_decode( $event->properties, true );
            $result = $this->handlers['dispatcher']->dispatch( $event->event, $properties );

            if ( $result ) {
                $ids_to_delete[] = $event->id;
                update_option( $this->config['slug'] . '_telemetry_last_send', time(), false );
            }
        }

        if ( ! empty( $ids_to_delete ) ) {
            $this->handlers['queue']->delete( $ids_to_delete );
            
            // Reset KUI counters after successful reporting
            if ( null !== $this->trigger_manager ) {
                $this->trigger_manager->reset_all_counters();
            }
        }
    }


    /**
     * Set the slug for the plugin
     *
     * @return void
     */
    private function set_slug() {
        $this->config['slug'] = dirname( plugin_basename( $this->config['pluginFile'] ) );
    }

    /**
     * Get or create a unique ID for the site.
     *
     * @return string
     */
    private function get_or_create_unique_id(): string {
        $unique_id = get_option( self::GLOBAL_UNIQUE_ID_KEY );

        if ( empty( $unique_id ) ) {
            $unique_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid();
            update_option( self::GLOBAL_UNIQUE_ID_KEY, $unique_id, false );
        }

        return $unique_id;
    }
}
