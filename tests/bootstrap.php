<?php
/**
 * PHPUnit bootstrap — WordPress function stubs for library-level tests.
 *
 * Provides minimal no-op implementations of WordPress functions so the
 * LinnoSDK\Telemetry classes can be exercised without a full WordPress runtime.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// WordPress constants
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// ---------------------------------------------------------------------------
// Global $wpdb stub (used by Queue)
// ---------------------------------------------------------------------------

global $wpdb;
$wpdb = new class {
    public string $prefix = 'wp_';

    public function get_var( $query ) {
        return null;
    }

    public function prepare( $query, ...$args ) {
        return $query;
    }

    public function get_charset_collate(): string {
        return '';
    }

    public function get_results( $query, $output = OBJECT ): array {
        return [];
    }

    public function insert( string $table, array $data ) {
        return false;
    }

    public function delete( string $table, array $where ) {
        return false;
    }

    public function query( $query ) {
        return false;
    }
};

// ---------------------------------------------------------------------------
// In-memory WordPress option store
// ---------------------------------------------------------------------------

$_wp_options = [];

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $key, $default = false ) {
        global $_wp_options;
        return array_key_exists( $key, $_wp_options ) ? $_wp_options[ $key ] : $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $key, $value, $autoload = true ): bool {
        global $_wp_options;
        $_wp_options[ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( string $key ): bool {
        global $_wp_options;
        unset( $_wp_options[ $key ] );
        return true;
    }
}

// ---------------------------------------------------------------------------
// In-memory WordPress transient store
// ---------------------------------------------------------------------------

$_wp_transients = [];

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( string $key ) {
        global $_wp_transients;
        return $_wp_transients[ $key ] ?? false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( string $key, $value, int $expiration = 0 ): bool {
        global $_wp_transients;
        $_wp_transients[ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( string $key ): bool {
        global $_wp_transients;
        unset( $_wp_transients[ $key ] );
        return true;
    }
}

// ---------------------------------------------------------------------------
// WordPress hook system stubs
// ---------------------------------------------------------------------------

$_wp_actions    = [];
$_wp_filters    = [];
$_wp_hooks      = []; // action name => array of [callback, priority, accepted_args]

if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
        global $_wp_hooks;
        $_wp_hooks[ $hook ][] = [
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        ];
        return true;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
        global $_wp_hooks;
        $_wp_hooks[ $hook ][] = [
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        ];
        return true;
    }
}

if ( ! function_exists( 'remove_action' ) ) {
    function remove_action( string $hook, $callback, int $priority = 10 ): bool {
        return true;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( string $hook, ...$args ): void {
        global $_wp_hooks;
        if ( empty( $_wp_hooks[ $hook ] ) ) {
            return;
        }
        foreach ( $_wp_hooks[ $hook ] as $entry ) {
            $accepted = min( $entry['accepted_args'], count( $args ) );
            call_user_func_array( $entry['callback'], array_slice( $args, 0, $accepted ) );
        }
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $hook, $value, ...$args ) {
        return $value;
    }
}

if ( ! function_exists( 'has_action' ) ) {
    function has_action( string $hook, $callback = false ) {
        global $_wp_hooks;
        if ( false === $callback ) {
            return ! empty( $_wp_hooks[ $hook ] );
        }
        if ( empty( $_wp_hooks[ $hook ] ) ) {
            return false;
        }
        foreach ( $_wp_hooks[ $hook ] as $entry ) {
            if ( $entry['callback'] === $callback ) {
                return $entry['priority'];
            }
        }
        return false;
    }
}

// ---------------------------------------------------------------------------
// WordPress plugin/activation helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( 'register_activation_hook' ) ) {
    function register_activation_hook( string $file, $callback ): void {
        // no-op in test context
    }
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( string $file, $callback ): void {
        // no-op in test context
    }
}

if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( string $file ): string {
        return basename( dirname( $file ) ) . '/' . basename( $file );
    }
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
    function load_plugin_textdomain( string $domain, $deprecated = false, string $path = '' ): bool {
        return true;
    }
}

// ---------------------------------------------------------------------------
// WordPress URL helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_site_url' ) ) {
    function get_site_url( $blog_id = null, string $path = '', string $scheme = '' ): string {
        return 'https://example.com';
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url( string $path = '', ?string $scheme = null ): string {
        return 'https://example.com';
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( string $url, array $protocols = [] ): string {
        return $url;
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( string $url, array $protocols = [], string $context = 'display' ): string {
        return $url;
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $str ): string {
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( string $key ): string {
        return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) );
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( string $text ): string {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( string $text ): string {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( string $nonce, string $action = '-1' ) {
        return 1;
    }
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( string $action = '-1' ): string {
        return 'test_nonce';
    }
}

// ---------------------------------------------------------------------------
// WordPress cron stubs
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( string $hook, array $args = [] ) {
        return false;
    }
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
    function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = [] ): bool {
        return true;
    }
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
    function wp_unschedule_event( int $timestamp, string $hook, array $args = [] ): bool {
        return true;
    }
}

// ---------------------------------------------------------------------------
// WordPress user helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_get_current_user' ) ) {
    function wp_get_current_user(): object {
        return (object) [
            'ID'         => 0,
            'user_email' => '',
            'first_name' => '',
            'last_name'  => '',
        ];
    }
}

if ( ! function_exists( 'get_avatar_url' ) ) {
    function get_avatar_url( $id_or_email, array $args = [] ): string {
        return '';
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( string $capability, ...$args ): bool {
        return false;
    }
}

// ---------------------------------------------------------------------------
// WordPress misc
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
    function wp_generate_uuid4(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }
}

// ---------------------------------------------------------------------------
// WordPress DB / queue helpers
// ---------------------------------------------------------------------------

if ( ! function_exists( 'current_time' ) ) {
    function current_time( string $type ) {
        if ( 'mysql' === $type ) {
            return gmdate( 'Y-m-d H:i:s' );
        }
        return time();
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'dbDelta' ) ) {
    function dbDelta( $queries = '', bool $execute = true ): array {
        return [];
    }
}

if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( string $title ): string {
        return strtolower( preg_replace( '/[^a-z0-9\-_]/', '', preg_replace( '/\s+/', '-', $title ) ) );
    }
}

/**
 * Reset all in-memory stub state between tests.
 *
 * Call this in setUp() or tearDown() to isolate test state.
 */
function wp_reset_stubs(): void {
    global $_wp_options, $_wp_transients, $_wp_hooks;
    $_wp_options    = [];
    $_wp_transients = [];
    $_wp_hooks      = [];
}
