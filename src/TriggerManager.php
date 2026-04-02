<?php
/**
 * Trigger Manager Class
 *
 * Handles automatic event tracking through configured triggers.
 * Supports setup and KUI events with threshold-based tracking.
 *
 * @package LinnoSDK\Telemetry
 * @since 1.0.0
 */

namespace LinnoSDK\Telemetry;

class TriggerManager {
    /**
     * Client instance
     *
     * @var Client
     */
    private Client $client;

    /**
     * Plugin slug
     *
     * @var string
     */
    private string $slug;

    /**
     * Registered triggers configuration
     *
     * @var array
     */
    private array $triggers = [];

    /**
     * KUI counter storage option key
     *
     * @var string
     */
    private string $kui_counter_key;

    /**
     * Constructor
     *
     * @param Client $client Client instance
     */
    public function __construct( Client $client ) {
        $this->client = $client;
        $this->slug = $client->get_slug();
        $this->kui_counter_key = $this->slug . '_telemetry_kui_counters';
    }

    /**
     * Register setup trigger
     *
     * Fires once when the specified hook is triggered.
     *
     * @param string $hook WordPress action hook to listen to
     * @param callable $callback Optional callback to generate properties
     * @return self
     */
    public function on_setup( string $hook, ?callable $callback = null ): self {
        $this->triggers['setup'] = [
            'type'     => 'setup',
            'hook'     => $hook,
            'callback' => $callback,
            'fired'    => false,
        ];

        return $this;
    }



    /**
     * Register KUI (Key Usage Indicator) trigger
     *
     * Can be threshold-based (fire after N events in a period) or simple hook-based.
     *
     * @param string $name KUI indicator name (e.g., 'order_received', 'student_enrolled')
     * @param array $config Configuration array with:
     *                       - hook: WordPress action hook to listen to (optional if using check_callback)
     *                       - threshold: array with 'count' and 'period' (e.g., ['count' => 2, 'period' => 'week'])
     *                       - callback: callable to generate properties (optional)
     *                       - check_callback: callable that returns true when KUI condition is met (alternative to hook)
     * @return self
     */
    public function on_kui( string $name, array $config ): self {
        $this->triggers['kui_' . $name] = [
            'type'       => 'kui',
            'name'       => $name,
            'hook'       => $config['hook'] ?? null,
            'threshold'  => $config['threshold'] ?? null,
            'callback'   => $config['callback'] ?? null,
            'check_callback' => $config['check_callback'] ?? null,
        ];

        return $this;
    }

    /**
     * Register feature used trigger
     *
     * Fires every time the hook is triggered.
     *
     * @param string $feature_name Feature name to track
     * @param string $hook WordPress action hook to listen to
     * @param callable $callback Optional callback to generate properties
     * @return self
     */
    public function on_feature_used( string $feature_name, string $hook, ?callable $callback = null ): self {
        $this->triggers['feature_used_' . $feature_name] = [
            'type'     => 'feature_used',
            'feature'  => $feature_name,
            'hook'     => $hook,
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * Register custom event trigger
     *
     * Fires every time the hook is triggered.
     *
     * @param string $event_name Event name to track
     * @param string $hook WordPress action hook to listen to
     * @param callable $callback Optional callback to generate properties
     * @return self
     */
    public function on( string $event_name, string $hook, ?callable $callback = null ): self {
        $this->triggers['custom_' . $event_name] = [
            'type'     => 'custom',
            'event'    => $event_name,
            'hook'     => $hook,
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * Initialize all registered triggers
     *
     * Sets up WordPress hooks for all configured triggers.
     *
     * @return void
     */
    public function init(): void {
        foreach ( $this->triggers as $key => $trigger ) {
            $this->register_trigger( $key, $trigger );
        }

        if ( $this->has_kui_triggers() ) {
            $this->schedule_kui_check();
        }
    }

    /**
     * Register a single trigger
     *
     * @param string $key Trigger key
     * @param array $trigger Trigger configuration
     * @return void
     */
    private function register_trigger( string $key, array $trigger ): void {
        if ( empty( $trigger['hook'] ) && empty( $trigger['check_callback'] ) ) {
            return;
        }

        if ( ! empty( $trigger['hook'] ) ) {
            $priority = $trigger['priority'] ?? 10;
            // Always accept up to 10 arguments to be safe with any WordPress hook
            $accepted_args = 10;

            add_action(
                $trigger['hook'],
                function( ...$args ) use ( $key, $trigger ) {
                    $this->handle_trigger( $key, $trigger, $args );
                },
                $priority,
                $accepted_args
            );
        }

        if ( ! empty( $trigger['check_callback'] ) && $trigger['type'] === 'kui' ) {
            add_action( $trigger['hook'] ?? 'init', function() use ( $key, $trigger ) {
                $this->handle_kui_check( $key, $trigger );
            }, 5 );
        }
    }

    /**
     * Handle trigger execution
     *
     * @param string $key Trigger key
     * @param array $trigger Trigger configuration
     * @param array $args Hook arguments
     * @return void
     */
    private function handle_trigger( string $key, array $trigger, array $args ): void {
        switch ( $trigger['type'] ) {
            case 'setup':
                $this->handle_setup( $key, $trigger, $args );
                break;

            case 'kui':
                $this->handle_kui( $key, $trigger, $args );
                break;
            case 'feature_used':
                $this->handle_feature_used( $key, $trigger, $args );
                break;
            case 'custom':
                $this->handle_custom( $key, $trigger, $args );
                break;
        }
    }

    /**
     * Handle setup trigger
     *
     * @param string $key Trigger key
     * @param array $trigger Trigger configuration
     * @param array $args Hook arguments
     * @return void
     */
    private function handle_setup( string $key, array $trigger, array $args ): void {
        if ( $this->client->has_sent_event( 'onboarding_completed' ) ) {
            return;
        }

        $properties = $this->execute_callback( $trigger['callback'], $args );
        $this->client->track_setup( $properties ?: [] );
    }



    /**
     * Handle KUI trigger
     *
     * @param string $key Trigger key
     * @param array $trigger Trigger configuration
     * @param array $args Hook arguments
     * @return void
     */
    private function handle_kui( string $key, array $trigger, array $args ): void {
        $name = $trigger['name'];

        // If threshold count is defined, track progress
        if ( ! empty( $trigger['threshold']['count'] ) ) {
            $this->increment_kui_counter( $name );

            // If current count is >= threshold, send the event
            if ( $this->should_fire_kui( $name, $trigger['threshold'] ) ) {
                $properties = $this->execute_callback( $trigger['callback'], $args );
                $this->client->track_kui( $name, $properties ?: [] );
            }
        } else {
            // No threshold count defined - fire on every hit
            $properties = $this->execute_callback( $trigger['callback'], $args );
            $this->client->track_kui( $name, $properties ?: [] );
        }
    }

    /**
     * Handle feature used trigger
     *
     * @param string $key Trigger key
     * @param array $trigger Trigger configuration
     * @param array $args Hook arguments
     * @return void
     */
    private function handle_feature_used( string $key, array $trigger, array $args ): void {
        $properties = $this->execute_callback( $trigger['callback'], $args );
        $this->client->track_feature_used( $trigger['feature'], $properties ?: [] );
    }

    /**
     * Mark a KUI as fired for a specific period
     *
     * @param string $name KUI name
     * @param string $period_key The period key (e.g., YYYY-WW)
     * @return void
     */
    private function mark_kui_fired( string $name, string $period_key ): void {
        $counters = $this->get_kui_counters();
        
        if ( ! isset( $counters[ $name ] ) ) {
            $counters[ $name ] = [];
        }
        
        $counters[ $name ]['last_fired_period'] = $period_key;
        $this->save_kui_counters( $counters );
    }

    /**
     * Handle custom event trigger
     *
     * @param string $key Trigger key
     * @param array $trigger Trigger configuration
     * @param array $args Hook arguments
     * @return void
     */
    private function handle_custom( string $key, array $trigger, array $args ): void {
        $properties = $this->execute_callback( $trigger['callback'], $args );
        $this->client->track( $trigger['event'], $properties ?: [] );
    }

    /**
     * Handle KUI check callback
     *
     * @param string $key Trigger key
     * @param array $trigger Trigger configuration
     * @return void
     */
    private function handle_kui_check( string $key, array $trigger ): void {
        if ( ! is_callable( $trigger['check_callback'] ) ) {
            return;
        }

        $result = call_user_func( $trigger['check_callback'] );

        if ( $result ) {
            $name = $trigger['name'];
            $properties = [];

            if ( is_array( $result ) ) {
                $properties = $result;
            }

            if ( $this->client->has_sent_event( 'aha_reached_' . $name ) ) {
                return;
            }

            $this->client->track_kui( $name, $properties );
            $this->client->mark_event_sent( 'aha_reached_' . $name );
        }
    }

    /**
     * Execute callback with arguments
     *
     * @param callable|null $callback
     * @param array $args
     * @return mixed
     */
    private function execute_callback( ?callable $callback, array $args ) {
        if ( ! $callback ) {
            return null;
        }

        return call_user_func_array( $callback, $args );
    }

    /**
     * Increment KUI counter
     *
     * @param string $name KUI name
     * @return void
     */
    private function increment_kui_counter( string $name ): void {
        $counters = $this->get_kui_counters();
        
        if ( ! isset( $counters[ $name ] ) ) {
            $counters[ $name ] = [
                'count'      => 0,
                'start_time' => time(),
            ];
        }

        $counters[ $name ]['count']++;
        $this->save_kui_counters( $counters );
    }

    /**
     * Reset all KUI counters for this plugin.
     *
     * Called after successful telemetry reporting.
     *
     * @return void
     */
    public function reset_all_counters(): void {
        update_option( $this->kui_counter_key, [] );
    }

    /**
     * Get period from threshold config
     *
     * @param array $threshold Threshold config
     * @return string
     */
    private function get_period_from_threshold( array $threshold ): string {
        return $threshold['period'] ?? 'week';
    }

    /**
     * Check if KUI should fire based on threshold
     *
     * @param string $name KUI name
     * @param array $threshold Threshold config with 'count' and 'period'
     * @return bool
     */
    private function should_fire_kui( string $name, array $threshold ): bool {
        $counters = $this->get_kui_counters();
        $count = $counters[ $name ]['count'] ?? 0;
        $required = $threshold['count'] ?? 1;

        return $count >= $required;
    }

    /**
     * Reset KUI counter
     *
     * @param string $name KUI name
     * @return void
     */
    private function reset_kui_counter( string $name ): void {
        $counters = $this->get_kui_counters();

        if ( isset( $counters[ $name ] ) ) {
            $counters[ $name ]['count'] = 0;
            $this->save_kui_counters( $counters );
        }
    }

    /**
     * Get KUI counters from database
     *
     * @return array
     */
    private function get_kui_counters(): array {
        return get_option( $this->kui_counter_key, [] );
    }

    /**
     * Save KUI counters to database
     *
     * @param array $counters
     * @return void
     */
    private function save_kui_counters( array $counters ): void {
        update_option( $this->kui_counter_key, $counters );
    }

    /**
     * Get period key based on period type
     *
     * @param string $name KUI name
     * @return string
     */
    private function get_period_key( string $name ): string {
        $counters = $this->get_kui_counters();
        $period = $counters[ $name ]['period'] ?? 'week';

        return $this->get_period_key_for_period( $period );
    }

    /**
     * Check if there are any KUI triggers
     *
     * @return bool
     */
    private function has_kui_triggers(): bool {
        foreach ( $this->triggers as $trigger ) {
            if ( $trigger['type'] === 'kui' && ! empty( $trigger['check_callback'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Schedule KUI check
     *
     * @return void
     */
    private function schedule_kui_check(): void {
        $hook = $this->slug . '_telemetry_kui_check';

        add_action( $hook, [ $this, 'run_kui_checks' ] );

        if ( ! wp_next_scheduled( $hook ) ) {
            wp_schedule_event( time(), 'daily', $hook );
        }
    }

    /**
     * Run all KUI checks
     *
     * @return void
     */
    public function run_kui_checks(): void {
        foreach ( $this->triggers as $key => $trigger ) {
            if ( $trigger['type'] === 'kui' && ! empty( $trigger['check_callback'] ) ) {
                $this->handle_kui_check( $key, $trigger );
            }
        }
    }

    /**
     * Get all registered triggers
     *
     * @return array
     */
    public function get_triggers(): array {
        return $this->triggers;
    }
}
