<?php
/**
 * Plugin Name: Test Telemetry Plugin
 * Plugin URI: https://coderex.co
 * Description: A test plugin to demonstrate and validate CodeRex Telemetry SDK functionality
 * Version: 1.0.0
 * Author: Code Rex
 * Author URI: https://coderex.co
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

use CodeRex\Telemetry\Client;

/**
 * Initialize the Telemetry SDK
 *
 * @since 1.0.0
 */
function test_telemetry_init() {
    // Replace with your actual OpenPanel API key and secret
    $api_key = 'op_4d049e93ece5870c534a';
    $api_secret = 'sec_4d049e93ece5870c534a';
    
    try {
        // Initialize the telemetry client
        $telemetry = new Client(
            $api_key,
            $api_secret,
            'Test Telemetry Plugin',
            __FILE__
        );
        
        // DON'T call init() to skip consent notice and deactivation modal
        // Just store the client for manual tracking
        
        // The SDK automatically stores the client in a plugin-specific global variable
        // For this plugin (test-telemetry-plugin), it will be stored as:
        // $GLOBALS['test_telemetry_plugin_telemetry_client']
        
        // You can also access it via the helper function:
        // $client = coderex_telemetry(__FILE__);
        
        // Manually set opt-in to 'yes' for testing (bypass opt-in check)
        // Option name is plugin-specific: {plugin-folder-name}_allow_tracking
        update_option('test-telemetry-plugin_allow_tracking', 'yes');
        
    } catch (Exception $e) {
        error_log('Test Telemetry Plugin: Failed to initialize - ' . $e->getMessage());
    }
}
add_action('plugins_loaded', 'test_telemetry_init');

/**
 * Track a custom event when a post is published
 *
 * @param int $post_id Post ID
 * @since 1.0.0
 */
function test_telemetry_track_post_published($post_id) {
    if (function_exists('coderex_telemetry_track')) {
        coderex_telemetry_track(__FILE__, 'post_published', [
            'post_id' => $post_id,
            'post_type' => get_post_type($post_id),
        ]);
    }
}
add_action('publish_post', 'test_telemetry_track_post_published');

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
        
        // Always add profile identification for testing
        if (function_exists('coderex_telemetry_generate_profile_id')) {
            $current_user = wp_get_current_user();
            $properties['__identify'] = [
                'profileId' => coderex_telemetry_generate_profile_id(),
                'email'     => $current_user->user_email,
                'firstName' => $current_user->first_name ?: 'Test',
                'lastName'  => $current_user->last_name ?: 'User',
                'avatar'    => get_avatar_url($current_user->ID),
            ];
            error_log('Test Plugin [' . time() . '] - Added __identify: ' . print_r($properties['__identify'], true));
        }
        
        // Debug: Log what we're sending
        error_log('Test Plugin - Sending event: ' . $event_name);
        error_log('Test Plugin - Properties before tracking: ' . print_r($properties, true));
        
        if (function_exists('coderex_telemetry_track')) {
            $result = coderex_telemetry_track(__FILE__, $event_name, $properties);
            $message = $result ? 'Event tracked successfully with profile identification!' : 'Event tracking failed. Check opt-in status.';
            echo '<div class="notice notice-' . ($result ? 'success' : 'error') . '"><p>' . esc_html($message) . '</p></div>';
        }
    }
    
    // Handle manual cron trigger
    if (isset($_POST['trigger_cron']) && check_admin_referer('test_telemetry_cron')) {
        do_action('coderex_telemetry_weekly_report');
        echo '<div class="notice notice-success"><p>Weekly cron triggered manually!</p></div>';
    }
    
    ?>
    <div class="wrap">
        <h1>Telemetry SDK Test Page</h1>
        
        <p class="description" style="background: #fff; padding: 15px; border-left: 4px solid #00a0d2;">
            <strong>Note:</strong> This test plugin bypasses consent notices and deactivation modals for easier testing. 
            Events are sent directly to OpenPanel with automatic profile identification.
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
                            <p class="description">Optional: Enter JSON object with custom properties. Profile identification is automatically added. Leave empty to send event without custom properties.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="test_event" class="button button-primary">Send Test Event</button>
                </p>
            </form>
        </div>
        
        <div class="card">
            <h2>Test Weekly Cron</h2>
            <p>Manually trigger the weekly system info report:</p>
            <form method="post">
                <?php wp_nonce_field('test_telemetry_cron'); ?>
                <p class="submit">
                    <button type="submit" name="trigger_cron" class="button button-secondary">Trigger Weekly Report</button>
                </p>
            </form>
            <?php
            $next_scheduled = wp_next_scheduled('coderex_telemetry_weekly_report');
            if ($next_scheduled) {
                echo '<p>Next scheduled run: <strong>' . esc_html(date('Y-m-d H:i:s', $next_scheduled)) . '</strong></p>';
            } else {
                echo '<p style="color: orange;">No cron job scheduled. This may be normal if consent is not granted.</p>';
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
                <li>✓ Activate plugin and verify consent notice appears</li>
                <li>✓ Click "Allow" and verify install event is sent</li>
                <li>✓ Use form above to send custom test events</li>
                <li>✓ Trigger weekly cron manually and verify system info event</li>
                <li>✓ Deactivate plugin and verify reason modal appears</li>
                <li>✓ Submit deactivation reason and verify event is sent</li>
                <li>✓ Reactivate, click "No thanks" and verify no events are sent</li>
                <li>✓ Check browser console and PHP error logs for issues</li>
                <li>✓ Verify all nonces are working (check network tab)</li>
                <li>✓ Verify all output is properly escaped (view page source)</li>
            </ul>
        </div>
        
        <div class="card">
            <h2>Debug Information</h2>
            <p><strong>Plugin File:</strong> <?php echo esc_html(__FILE__); ?></p>
            <p><strong>Plugin Folder:</strong> test-telemetry-plugin</p>
            <p><strong>Plugin Version:</strong> 1.0.0</p>
            <p><strong>SDK Loaded:</strong> <?php echo class_exists('CodeRex\Telemetry\Client') ? '✓ Yes' : '✗ No'; ?></p>
            <p><strong>Helper Functions:</strong> <?php echo function_exists('coderex_telemetry_track') ? '✓ Available' : '✗ Not Available'; ?></p>
            <?php 
            // Check if client is initialized using the plugin-specific global variable
            $client = isset($GLOBALS['test_telemetry_plugin_telemetry_client']) ? $GLOBALS['test_telemetry_plugin_telemetry_client'] : null;
            ?>
            <?php if ($client): ?>
                <p style="color: green;"><strong>✓ Telemetry Client Initialized</strong></p>
                <p><strong>Global Variable:</strong> <code>$GLOBALS['test_telemetry_plugin_telemetry_client']</code></p>
            <?php else: ?>
                <p style="color: red;"><strong>✗ Telemetry Client Not Initialized</strong></p>
            <?php endif; ?>
            <p><strong>Opt-in Option:</strong> <code>test-telemetry-plugin_allow_tracking</code></p>
            <p><strong>Opt-in Status:</strong> <?php 
                $opt_in = get_option('test-telemetry-plugin_allow_tracking', 'no');
                echo $opt_in === 'yes' ? '<span style="color: green;">✓ Enabled</span>' : '<span style="color: red;">✗ Disabled</span>';
            ?></p>
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
add_filter('coderex_telemetry_report_interval', 'test_telemetry_custom_interval');

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
add_filter('coderex_telemetry_system_info', 'test_telemetry_custom_system_info');
