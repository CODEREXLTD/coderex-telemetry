<?php
/**
 * Plugin Name: Test Telemetry Plugin
 * Plugin URI: https://linno.co
 * Description: A test plugin to demonstrate and validate Linno Telemetry SDK functionality
 * Version: 1.0.0
 * Author: Linno
 * Author URI: https://linno.co
 * License: GPL-2.0-or-later
 * Text Domain: test-telemetry-plugin
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Load Composer autoloader
// Try plugin's own vendor directory first (if installed via Composer in plugin dir)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
// Fall back to SDK's vendor directory (when testing from SDK repository)
elseif (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}
// If neither exists, show error
else {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Test Telemetry Plugin Error:</strong> Composer autoloader not found. ';
        echo 'Please run <code>composer install</code> from the plugin directory or the SDK root directory.';
        echo '</p></div>';
    });
    return;
}

use LinnoSDK\Telemetry\Client;

/**
 * Global telemetry client instance.
 *
 * @var Client|null
 */
$test_telemetry_client = null;

$text_domain = 'test-telemetry-plugin';

try {
    // Optional: Set text domain for i18n (defaults to plugin slug if not set)
    Client::set_text_domain( $text_domain );

    // Initialize the telemetry client using a config array.
    // Only 'pluginFile' and 'slug' are required.
    // Set 'driver' to 'posthog' or 'open_panel'.  Omit it to run with no driver (events are silently dropped).
    $test_telemetry_client = new Client([
        'pluginFile'  => __FILE__,
        'slug'        => 'test-telemetry-plugin',
        'pluginName'  => 'Test Telemetry Plugin',
        'version'     => '1.0.0',

        // --- PostHog driver example ---
        // 'driver'       => 'posthog',
        // 'driver_config' => [
        //     'host'    => 'https://app.posthog.com',
        //     'api_key' => 'phc_YOUR_POSTHOG_API_KEY',
        // ],

        // --- OpenPanel driver example ---
        'driver'     => 'open_panel',
        'apiKey'     => 'op_YOUR_CLIENT_ID',
        'apiSecret'  => 'sec_YOUR_API_SECRET',
    ]);

    // Define optional automatic triggers.
    // Each key is optional — omitting a key simply disables that module.
    $test_telemetry_client->define_triggers([

        // Onboarding completion: fires activation/onboarding_completed once.
        // Both 'setup' (legacy) and 'onboarding' (canonical) are accepted.
        'setup' => 'my_plugin_setup_complete',
        // 'onboarding' => 'my_plugin_onboarding_finished',  // canonical alias

        // Feature Used: fires retention/feature_used.
        'feature_used' => [
            'funnel_created' => [
                'hook' => 'my_plugin_funnel_created',
            ],
        ],

        // AHA-milestone indicators (fire activation/aha_reached).
        // Both 'kui' (legacy) and 'aha' (canonical) are accepted.
        'aha' => [
            'order_received' => [
                'hook'      => 'woocommerce_order_created',
                'threshold' => ['count' => 2, 'period' => 'week'],
                'callback'  => function( $order_id ) {
                    return ['order_id' => $order_id];
                },
            ],
            'student_enrolled' => [
                'hook'      => 'lms_student_enrolled',
                'threshold' => ['count' => 2, 'period' => 'week'],
                'callback'  => function( $course_id, $student_id ) {
                    return ['course_id' => $course_id, 'student_id' => $student_id];
                },
            ],
        ],
    ]);

} catch (Exception $e) {
    error_log('Test Telemetry Plugin: Failed to initialize - ' . $e->getMessage());
}

/**
 * Track a custom event when a post is published.
 *
 * Option A — direct PHP API call:
 *
 * @param int $post_id Post ID
 * @since 1.0.0
 */
function test_telemetry_track_post_published($post_id) {
    global $test_telemetry_client;
    if ($test_telemetry_client instanceof Client) {
        $test_telemetry_client->track('post_published', [
            'post_id'   => $post_id,
            'post_type' => get_post_type($post_id),
        ]);
    }
}
add_action('publish_post', 'test_telemetry_track_post_published');

/**
 * Option B — WordPress action hook:
 * Any code in the plugin can fire this action to send a custom telemetry event:
 *
 *     do_action( 'test-telemetry-plugin_telemetry_track', 'post_published', ['post_id' => 42] );
 *
 * The client registers this handler automatically during initialization.
 * No extra setup is required.
 */

// --- Examples of optional trigger-module tracking (commented out) ---

// Example: Track onboarding completion (once, requires consent)
// function test_telemetry_track_setup_complete() {
//     global $test_telemetry_client;
//     if ($test_telemetry_client instanceof Client) {
//         $test_telemetry_client->track_setup(['setup_method' => 'quick_install']);
//         // Emits: activation/onboarding_completed
//     }
// }
// add_action('my_plugin_setup_complete', 'test_telemetry_track_setup_complete');

// Example: Track feature usage via static convenience method (requires consent)
// Call this after the client is initialized to register the event for a specific hook.
Client::add_feature_used_event( 'my_plugin_settings_exported', 'Export Settings' );
// When 'my_plugin_settings_exported' action fires, a retention/feature_used event
// is sent with feature='Export Settings'.

// With optional extra parameters:
// Client::add_feature_used_event( 'my_plugin_settings_imported', 'Import Settings', [ 'source' => 'file' ] );

// Example: Track AHA milestone (multiple times, requires consent)
// function test_telemetry_track_order_received($order_id, $amount) {
//     global $test_telemetry_client;
//     if ($test_telemetry_client instanceof Client) {
//         $test_telemetry_client->track_kui('order_received', ['order_id' => $order_id, 'amount' => $amount]);
//         // Emits: activation/aha_reached with indicator=order_received
//     }
// }
// add_action('woocommerce_new_order', 'test_telemetry_track_order_received', 10, 2);


/**
 * Add admin menu for testing
 *
 * @since 1.0.0
 */
function test_telemetry_admin_menu() {
    add_menu_page(
        'Telemetry Test',
        'Telemetry Test',
        'manage_options',
        'test-telemetry',
        'test_telemetry_admin_page',
        'dashicons-chart-line',
        100
    );
}
add_action('admin_menu', 'test_telemetry_admin_menu');

/**
 * Render admin test page
 *
 * @since 1.0.0
 */
function test_telemetry_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    global $test_telemetry_client;

    // Handle test event submission
    if (isset($_POST['test_event']) && check_admin_referer('test_telemetry_event')) {
        error_log('=== TEST PLUGIN FORM HANDLER EXECUTING - ' . time() . ' ===');
        
        // Remove WordPress magic quotes from entire POST array
        $_POST = array_map('stripslashes_deep', $_POST);
        
        $event_name = sanitize_text_field($_POST['event_name']);
        $event_data = isset($_POST['event_data']) ? trim($_POST['event_data']) : '';
        
        error_log('Test Plugin [' . time() . '] - Raw event_data: ' . $event_data);
        
        $properties = [];
        if (!empty($event_data)) {
            // Try to decode JSON
            $decoded = json_decode($event_data, true);
            
            error_log('Test Plugin [' . time() . '] - JSON decode result: ' . print_r($decoded, true));
            error_log('Test Plugin [' . time() . '] - JSON error: ' . json_last_error_msg());
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // JSON is valid - use decoded array
                $properties = $decoded;
                error_log('Test Plugin [' . time() . '] - SUCCESS: Using decoded properties');
            } else {
                // JSON is invalid - store as raw_data with error info
                $properties = [
                    'raw_data' => $event_data,
                    'json_error' => json_last_error_msg()
                ];
                error_log('Test Plugin [' . time() . '] - FAILED: JSON decode failed, using raw_data');
            }
        }
        
        if ($test_telemetry_client instanceof Client) {
            $test_telemetry_client->track($event_name, $properties);
            $message = 'Event added to queue! It will be sent during the next cron run.';
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        } else {
            $message = 'Telemetry Client not initialized.';
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }
    }
    
    // Handle manual cron trigger
    if (isset($_POST['trigger_cron']) && check_admin_referer('test_telemetry_cron')) {
        global $test_telemetry_client;
        if ($test_telemetry_client instanceof Client) {
            $test_telemetry_client->process_queue();
            echo '<div class="notice notice-success"><p>Telemetry queue processed manually!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Telemetry Client not initialized for cron processing.</p></div>';
        }
    }
    
    ?>
    <div class="wrap">
        <h1>Telemetry SDK Test Page</h1>
        
        <p class="description" style="background: #fff; padding: 15px; border-left: 4px solid #00a0d2;">
            <strong>Note:</strong> This test plugin demonstrates integration with the Linno Telemetry SDK.
            Consent notices and deactivation modals will appear as expected. Events are added to a queue and sent via WP-Cron.
        </p>
        
        <div class="card">
            <h2>Test Custom Event</h2>
            <form method="post">
                <?php wp_nonce_field('test_telemetry_event'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="event_name">Event Name</label></th>
                        <td>
                            <input type="text" id="event_name" name="event_name" value="test_custom_event" class="regular-text" required>
                            <p class="description">Use alphanumeric characters and underscores only</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="event_data">Event Properties (JSON)</label></th>
                        <td>
                            <textarea id="event_data" name="event_data" rows="5" class="large-text" placeholder='{"test_key": "test_value", "user_action": "button_click"}'></textarea>
                            <p class="description">Optional: Enter JSON object with custom properties.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="test_event" class="button button-primary">Send Test Event</button>
                </p>
            </form>
        </div>
        
        <div class="card">
            <h2>Test Telemetry Queue Processing</h2>
            <p>Manually trigger the telemetry queue processing:</p>
            <form method="post">
                <?php wp_nonce_field('test_telemetry_cron'); ?>
                <p class="submit">
                    <button type="submit" name="trigger_cron" class="button button-secondary">Process Telemetry Queue</button>
                </p>
            </form>
            <?php
            $next_scheduled_cron_hook = $test_telemetry_client ? $test_telemetry_client->get_slug() . '_telemetry_queue_process' : '';
            $next_scheduled = $next_scheduled_cron_hook ? wp_next_scheduled($next_scheduled_cron_hook) : false;
            
            if ($next_scheduled) {
                echo '<p>Next scheduled queue processing: <strong>' . esc_html(date('Y-m-d H:i:s', $next_scheduled)) . '</strong></p>';
            } else {
                echo '<p style="color: orange;">No cron job scheduled. This may be normal if consent is not granted or if the client is not initialized.</p>';
            }
            ?>
        </div>
        
        <div class="card">
            <h2>System Information</h2>
            <table class="widefat">
                <tbody>
                    <tr>
                        <td><strong>PHP Version:</strong></td>
                        <td><?php echo esc_html(PHP_VERSION); ?></td>
                    </tr>
                    <tr>
                        <td><strong>WordPress Version:</strong></td>
                        <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                    </tr>
                    <tr>
                        <td><strong>MySQL Version:</strong></td>
                        <td><?php global $wpdb; echo esc_html($wpdb->db_version()); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Server Software:</strong></td>
                        <td><?php echo esc_html($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Site URL:</strong></td>
                        <td><?php echo esc_html(get_site_url()); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="card">
            <h2>Testing Checklist</h2>
            <ul style="list-style: disc; margin-left: 20px;">
                <li>✓ Activate plugin and verify consent notice appears.</li>
                <li>✓ Click "Allow" and verify `plugin_activated` event is queued.</li>
                <li>✓ Use form above to send custom test events and verify they are queued.</li>
                <li>✓ Trigger queue processing manually and verify events are dispatched.</li>
                <li>✓ Deactivate plugin and verify reason modal appears.</li>
                <li>✓ Submit deactivation reason and verify `plugin_deactivated` event is queued.</li>
                <li>✓ Reactivate, click "Do not allow" on consent notice and verify custom events are not queued.</li>
                <li>✓ Check browser console and PHP error logs for issues.</li>
            </ul>
        </div>
        
        <div class="card">
            <h2>Debug Information</h2>
            <p><strong>Plugin File:</strong> <?php echo esc_html(__FILE__); ?></p>
            <p><strong>Plugin Folder:</strong> test-telemetry-plugin</p>
            <p><strong>Plugin Version:</strong> 1.0.0</p>
            <p><strong>SDK Loaded:</strong> <?php echo class_exists('LinnoSDK\Telemetry\Client') ? '✓ Yes' : '✗ No'; ?></p>
            <?php 
            if ($test_telemetry_client instanceof Client): ?>
                <p style="color: green;"><strong>✓ Telemetry Client Initialized</strong></p>
                <p><strong>Plugin Slug:</strong> <?php echo esc_html($test_telemetry_client->get_slug()); ?></p>
                <p><strong>Text Domain:</strong> <?php echo esc_html($test_telemetry_client->get_text_domain()); ?></p>
                <p><strong>Opt-in Option Key:</strong> <code><?php echo esc_html($test_telemetry_client->get_optin_key()); ?></code></p>
                <p><strong>Opt-in Status:</strong> <?php 
                    $opt_in_key = $test_telemetry_client->get_optin_key();
                    $opt_in = get_option($opt_in_key, 'no');
                    echo $opt_in === 'yes' ? '<span style="color: green;">✓ Enabled</span>' : '<span style="color: red;">✗ Disabled</span>';
                ?></p>
                <p><strong>Next Queue Processing Cron:</strong> <code><?php echo esc_html($test_telemetry_client->get_cron_hook()); ?></code></p>
            <?php else: ?>
                <p style="color: red;"><strong>✗ Telemetry Client Not Initialized</strong></p>
            <?php endif; ?>
        </div>
    </div>
    
    <style>
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .card h2 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
    </style>
    <?php
}

/**
 * Customize the telemetry report interval for testing
 *
 * @param string $interval Default interval
 * @return string Modified interval
 * @since 1.0.0
 */
function test_telemetry_custom_interval($interval) {
    // Change to 'hourly' for faster testing, or keep 'weekly' for production
    return 'weekly';
}
add_filter('test-telemetry-plugin_telemetry_report_interval', 'test_telemetry_custom_interval');

/**
 * Add custom system info for testing
 *
 * @param array $info System information array
 * @return array Modified system information
 * @since 1.0.0
 */
function test_telemetry_custom_system_info($info) {
    $info['test_plugin_active'] = true;
    $info['active_theme'] = wp_get_theme()->get('Name');
    return $info;
}
add_filter($test_telemetry_client->get_slug() . '_telemetry_system_info', 'test_telemetry_custom_system_info');
