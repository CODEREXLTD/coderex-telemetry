<?php
/**
 * Client Class
 *
 * Main entry point for plugin developers to integrate telemetry tracking.
 * Handles initialization, configuration, and provides the public API for tracking events.
 *
 * @package LinnoSDK\Telemetry
 * @since 1.0.0
 */

namespace LinnoSDK\Telemetry;

use LinnoSDK\Telemetry\Drivers\DriverInterface;
use LinnoSDK\Telemetry\Drivers\NullDriver;
use LinnoSDK\Telemetry\Drivers\OpenPanelDriver;
use LinnoSDK\Telemetry\Drivers\PostHogDriver;
use LinnoSDK\Telemetry\Helpers\Utils;
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
     * Registry of all active Client instances.
     *
     * Used by add_feature_used_event to dispatch events across all initialized clients.
     *
     * @var Client[]
     */
    private static array $instances = [];

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
     * Accepts either an array configuration or the legacy 4-positional-parameter signature.
     *
     * Array form (current):
     *   new Client(['pluginFile' => ..., 'slug' => ..., ...])
     *
     * Legacy positional form (deprecated):
     *   new Client($apiKey, $apiSecret, $pluginName, $pluginFile)
     *
     * @param array|string $configOrApiKey Configuration array, or API key string for legacy form.
     * @param string       $apiSecret      (Legacy) API secret.
     * @param string       $pluginName     (Legacy) Human-readable plugin name.
     * @param string       $pluginFile     (Legacy) Path to the main plugin file.
     *
     * @throws InvalidArgumentException If the first argument is not an array or string, or if
     *                                  required fields are missing.
     * @since 1.0.0
     */
    public function __construct($configOrApiKey, string $apiSecret = '', string $pluginName = '', string $pluginFile = '')
    {
        if ( is_array( $configOrApiKey ) ) {
            $config = $configOrApiKey;

            if (empty($config['pluginFile']) || empty($config['slug'])) {
                throw new InvalidArgumentException('The "pluginFile" and "slug" parameters are required.');
            }
        } elseif ( is_string( $configOrApiKey ) ) {
            $config = $this->buildLegacyConfig( $configOrApiKey, $apiSecret, $pluginName, $pluginFile );
        } else {
            throw new InvalidArgumentException( 'First argument must be a configuration array or a string API key' );
        }

        $this->config = array_merge([
            'apiKey'      => '',
            'apiSecret'   => '',
            'pluginName'  => '',
            'version'     => '',
            'unique_id'   => '',
            'driver'      => '',
            'driver_config' => [],
        ], $config);

        // Normalize version key: accept both 'version' and 'pluginVersion'
        if ( empty( $this->config['version'] ) && ! empty( $this->config['pluginVersion'] ) ) {
            $this->config['version'] = $this->config['pluginVersion'];
        }

        // Ensure unique_id is populated
        if ( empty( $this->config['unique_id'] ) ) {
            $this->config['unique_id'] = $this->get_or_create_unique_id();
        }

        self::$textDomain = $this->config['slug'];

        $driver = $this->resolve_driver();

        $this->handlers = [
            'dispatcher'  => new EventDispatcher( $driver, $this->config ),
            'consent'     => new Consent( $this ),
            'deactivation' => new Deactivation( $this ),
            'queue'       => new Queue(),
        ];

        self::$instances[] = $this;

        $this->init();
    }

    public function getDispatcher(): EventDispatcher
    {
        return $this->handlers['dispatcher'];
    }

    /**
     * Build a config array from legacy 4-positional-parameter constructor arguments.
     *
     * @param string $apiKey      API key.
     * @param string $apiSecret   API secret.
     * @param string $pluginName  Human-readable plugin name.
     * @param string $pluginFile  Path to the main plugin file.
     * @return array
     * @throws InvalidArgumentException If any required parameter is missing or empty.
     */
    private function buildLegacyConfig( string $apiKey, string $apiSecret, string $pluginName, string $pluginFile ): array
    {
        if ( '' === $apiSecret && '' === $pluginName && '' === $pluginFile ) {
            throw new InvalidArgumentException( 'Legacy constructor requires exactly 4 string parameters' );
        }

        if ( '' === $apiKey ) {
            throw new InvalidArgumentException( 'API key must not be empty' );
        }

        if ( '' === $pluginFile ) {
            throw new InvalidArgumentException( 'Plugin file path must not be empty' );
        }

        if ( '' === $pluginName ) {
            throw new InvalidArgumentException( 'Plugin name must not be empty' );
        }

        trigger_error(
            'Passing positional parameters to LinnoSDK\Telemetry\Client::__construct() is deprecated. Use an array configuration instead. See https://github.com/user/coderex-telemetry#migration for details. This will be removed in the next major version.',
            E_USER_DEPRECATED
        );

        return [
            'apiKey'     => $apiKey,
            'apiSecret'  => $apiSecret,
            'pluginName' => $pluginName,
            'pluginFile' => $pluginFile,
            'slug'       => sanitize_title( $pluginName ),
            'driver'     => 'open_panel',
        ];
    }

    /**
     * Get a copy of the current configuration array.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Resolve the configured telemetry driver.
     *
     * Supports an injected test driver via config key '_test_driver' for unit tests.
     * Falls back to NullDriver with a warning when the driver is missing or unrecognized.
     *
     * @return DriverInterface
     */
    private function resolve_driver(): DriverInterface
    {
        // Allow test injection without touching the real driver factories.
        if ( ! empty( $this->config['_test_driver'] ) && $this->config['_test_driver'] instanceof DriverInterface ) {
            return $this->config['_test_driver'];
        }

        $driver_type = strtolower( trim( $this->config['driver'] ?? '' ) );

        if ( 'posthog' === $driver_type ) {
            if ( ! class_exists( \PostHog\PostHog::class ) ) {
                error_log( '[Linno Telemetry] Warning: PostHog SDK not found. Install posthog/posthog-php or switch to a supported driver. Falling back to NullDriver.' );
                return new NullDriver();
            }
            $host   = $this->config['driver_config']['host'] ?? '';
            $driver = new PostHogDriver( $host );
            $driver->setApiKey( $this->config['driver_config']['api_key'] ?? $this->config['apiKey'] ?? '' );
            return $driver;
        }

        if ( 'open_panel' === $driver_type ) {
            $driver = new OpenPanelDriver();
            $driver->setApiKey( $this->config['apiKey'] ?? '' );
            if ( method_exists( $driver, 'setApiSecret' ) ) {
                $driver->setApiSecret( $this->config['apiSecret'] ?? '' );
            }
            return $driver;
        }

        if ( '' !== $driver_type ) {
            error_log( sprintf(
                '[Linno Telemetry] Warning: Unrecognized driver "%s". Supported drivers: open_panel, posthog. Falling back to NullDriver.',
                $driver_type
            ) );
        } else {
            error_log( '[Linno Telemetry] Warning: No telemetry driver configured. Events will be silently dropped. Set the "driver" key to "open_panel" or "posthog" to enable tracking.' );
        }

        return new NullDriver();
    }

    /**
     * WordPress action handler for the generic custom-event hook.
     *
     * Registered as: add_action( '<slug>_telemetry_track', ... )
     *
     * @param string $event_name  The event name.
     * @param array  $properties  Optional associative properties array.
     * @return void
     */
    public function handle_telemetry_action( string $event_name, array $properties = [] ): void {
        $this->track( $event_name, $properties );
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

        // Register the generic custom-event action hook: <slug>_telemetry_track
        add_action(
            $this->config['slug'] . '_telemetry_track',
            [ $this, 'handle_telemetry_action' ],
            10,
            2
        );

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
                'activation/plugin_activated',
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
                'activation/plugin_deactivated',
                [
                    'site_url' => get_site_url(),
                    'reason'   => 'none',
                ]
            );
        }
        // Clean up the transient regardless
        delete_transient( $transient_key );

        $this->handlers['queue']->clear_for_plugin( $this->config['slug'] );

        $this->unscheduleBackgroundReporting();
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
        $properties['plugin_version'] = $properties['plugin_version'] ?? $this->config['version'] ?? '';
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

        if ( 'activation/plugin_deactivated' === $event ) {
            $minimal_properties['reason']     = sanitize_text_field( (string) ( $properties['reason'] ?? 'none' ) );
            $minimal_properties['reason_key'] = sanitize_text_field( (string) ( $properties['reason_key'] ?? '' ) );

            // Pass through any extra properties added by the plugin via the deactivation_payload filter.
            $reserved = array( 'site_url', 'unique_id', '__identify', 'reason', 'reason_key' );
            foreach ( $properties as $key => $value ) {
                if ( ! in_array( $key, $reserved, true ) ) {
                    $minimal_properties[ sanitize_key( $key ) ] = is_numeric( $value ) ? $value : sanitize_text_field( (string) $value );
                }
            }
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
        if ( $this->has_sent_event( 'onboarding_completed' ) ) {
            return;
        }

        if ( ! $this->isOptInEnabled() ) {
            return;
        }

        $this->track( 'activation/onboarding_completed', $properties );
        $this->mark_event_sent( 'onboarding_completed' );
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
        $this->track( 'activation/aha_reached', array_merge( [ 'indicator' => $kui_name ], $properties ) );
    }

    /**
     * Track a 'feature_used' event.
     *
     * This event is sent when the user uses a core feature of the product.
     * Requires user consent.
     *
     * @param string $feature_name The name of the feature.
     * @param array $properties Additional properties for the event.
     * @return void
     */
    public function track_feature_used( string $feature_name, array $properties = [] ): void {
        $this->track( 'retention/feature_used', array_merge( [ 'feature' => $feature_name ], $properties ) );
    }

    /**
     * Register a WordPress action hook that sends a retention/feature_used event when triggered.
     *
     * This static convenience method attaches a callback to the given WordPress
     * action hook. When that hook fires, a `retention/feature_used` event is
     * dispatched through every active Client instance initialized on the current
     * request.
     *
     * @param string $hook_name    WordPress action hook to listen for.
     * @param string $feature_name Name of the feature being tracked.
     * @param array  $params       Optional key-value pairs sent with the event.
     * @return void
     */
    public static function add_feature_used_event( string $hook_name, string $feature_name, array $params = [] ): void {
        add_action( $hook_name, function() use ( $feature_name, $params ) {
            foreach ( self::$instances as $instance ) {
                $instance->track_feature_used( $feature_name, $params );
            }
        } );
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
     *                       - kui: array of KUI configurations
     *                       - feature_used: array of feature used configurations
     * @return self
     * @since 1.0.0
     */
    public function define_triggers( array $config ): self {
        $triggers = $this->triggers();

        // setup → fires activation/onboarding_completed (once)
        if ( isset( $config['setup'] ) ) {
            $hook = is_array( $config['setup'] ) ? $config['setup']['hook'] : $config['setup'];
            $callback = is_array( $config['setup'] ) ? ( $config['setup']['callback'] ?? null ) : null;
            $triggers->on_setup( $hook, $callback );
        }

        // onboarding → canonical alias for setup
        if ( isset( $config['onboarding'] ) ) {
            $hook = is_array( $config['onboarding'] ) ? $config['onboarding']['hook'] : $config['onboarding'];
            $callback = is_array( $config['onboarding'] ) ? ( $config['onboarding']['callback'] ?? null ) : null;
            $triggers->on_setup( $hook, $callback );
        }



        // kui → fires activation/aha_reached for each defined indicator
        if ( isset( $config['kui'] ) && is_array( $config['kui'] ) ) {
            foreach ( $config['kui'] as $name => $kui_config ) {
                if ( is_array( $kui_config ) ) {
                    $triggers->on_kui( $name, $kui_config );
                }
            }
        }

        // aha → canonical alias for kui
        if ( isset( $config['aha'] ) && is_array( $config['aha'] ) ) {
            foreach ( $config['aha'] as $name => $aha_config ) {
                if ( is_array( $aha_config ) ) {
                    $triggers->on_kui( $name, $aha_config );
                }
            }
        }

        // feature_used → fires retention/feature_used for each defined feature
        if ( isset( $config['feature_used'] ) && is_array( $config['feature_used'] ) ) {
            foreach ( $config['feature_used'] as $name => $feature_config ) {
                if ( is_array( $feature_config ) && isset($feature_config['hook'])) {
                    $triggers->on_feature_used( $name, $feature_config['hook'], $feature_config['callback'] ?? null );
                }
            }
        }

        // Register all newly-defined triggers so their WordPress hooks fire.
        $triggers->init();

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

        // Hook callback for weekly report
        add_action( $hook, array( $this, 'process_queue' ) );

        // Schedule cron job if not already scheduled
        if ( ! wp_next_scheduled( $hook ) ) {
            // Apply filter for customizable interval (default: daily)
            $interval = apply_filters( $this->config['slug'] . '_telemetry_report_interval', 'daily' );

            // Schedule the event
            wp_schedule_event( time(), $interval, $hook );
        }
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

        $this->scheduleBackgroundReporting();
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
