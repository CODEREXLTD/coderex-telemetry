# Test Telemetry Plugin

A comprehensive test WordPress plugin to demonstrate and validate all functionality of the Linno Telemetry SDK.

## Purpose

This plugin serves as both a testing tool and a reference implementation for integrating the Linno Telemetry SDK into WordPress plugins.

## Installation

### Prerequisites

- WordPress 5.0 or higher
- PHP 7.4 or higher
- PHP cURL extension enabled
- Composer installed

### Setup Steps

#### Option A: Testing from SDK Repository (Recommended for Development)

1. **Install the Telemetry SDK dependencies** (from the root of the telemetry package):
   ```bash
   composer install
   ```

2. **Create a symlink in WordPress plugins directory**:
   ```bash
   # This keeps the plugin in the SDK repo while making it available to WordPress
   ln -s /path/to/telemetry-sdk/examples/test-plugin /path/to/wordpress/wp-content/plugins/test-telemetry-plugin
   ```

3. **Configure API Key**:
   - Edit `examples/test-plugin/test-telemetry-plugin.php`
   - Replace `test-api-key-replace-with-real-key` with your actual OpenPanel API key

4. **Activate the plugin** in WordPress admin (Plugins → Installed Plugins)

#### Option B: Standalone Installation (For Production Testing)

1. **Copy the test plugin to WordPress**:
   ```bash
   cp -r examples/test-plugin /path/to/wordpress/wp-content/plugins/test-telemetry-plugin
   ```

2. **Install dependencies in the plugin directory**:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/test-telemetry-plugin
   composer require linno/telemetry
   ```

3. **Configure API Key**:
   - Edit `test-telemetry-plugin.php`
   - Replace `test-api-key-replace-with-real-key` with your actual OpenPanel API key

4. **Activate the plugin** in WordPress admin (Plugins → Installed Plugins)

#### Option C: Using the Setup Script

```bash
cd examples/test-plugin
./setup.sh /path/to/wordpress
```

The setup script will:
- Verify WordPress installation
- Check Composer dependencies
- Copy plugin files
- Create necessary symlinks
- Provide next steps

## Features

### Admin Test Interface

The plugin adds a "Telemetry Test" menu item in WordPress admin with:

- **Consent Status Display**: Shows current opt-in/opt-out status
- **Custom Event Testing**: Form to send test events with custom properties
- **System Information**: Display of all collected system data
- **Testing Checklist**: Complete list of test scenarios
- **Debug Information**: SDK initialization status and diagnostics

### Automatic Event Tracking

The plugin demonstrates automatic event tracking:

- **Post Published Event**: Tracks when a post is published with post ID and type
- **Install Event**: Sent automatically when user grants consent
- **Weekly System Info**: Sent via WP-Cron (can be triggered manually)
- **Deactivation Event**: Captured when plugin is deactivated
- **Feature Used Event**: `Client::add_feature_used_event()` is used to register a `retention/feature_used` event triggered by a WordPress action hook

### Filter Demonstrations

Shows how to use SDK filters:

- `linno_telemetry_report_interval`: Customize reporting frequency
- `linno_telemetry_system_info`: Add custom system information

## Testing Scenarios

### 1. Complete Activation Flow with Consent

1. Activate the plugin
2. Notice should appear at top of admin pages
3. Click "Allow" button
4. Verify install event sent to OpenPanel
5. Check that consent status shows "Granted ✓"

### 2. Custom Event Tracking

1. Navigate to "Telemetry Test" menu
2. Enter event name (e.g., `button_clicked`)
3. Enter JSON properties (e.g., `{"button_id": "submit", "page": "settings"}`)
4. Click "Send Test Event"
5. Verify event appears in OpenPanel dashboard

### 3. Weekly System Info Report

1. Navigate to "Telemetry Test" menu
2. Click "Trigger Weekly Report" button
3. Verify system info event sent to OpenPanel
4. Check that next scheduled time is displayed

### 4. Deactivation Flow

1. Ensure consent is granted
2. Go to Plugins page
3. Click "Deactivate" on Test Telemetry Plugin
4. Modal should appear asking for reason
5. Select a reason and optionally add text
6. Click "Submit & Deactivate"
7. Verify deactivation event sent with reason data

### 5. Consent Denial Flow

1. Deactivate and reactivate the plugin (or clear consent option)
2. When notice appears, click "No thanks"
3. Try sending test events from admin page
4. Verify no events are sent (should show failure message)
5. Check OpenPanel - no events should appear

### 6. Post Publishing Event

1. Ensure consent is granted
2. Create a new post
3. Publish the post
4. Verify `post_published` event sent to OpenPanel with post details

## Testing with Different PHP Versions

### Using Docker

Test with multiple PHP versions using Docker:

```bash
# PHP 7.4
docker run -v $(pwd):/app -w /app php:7.4-cli php -v

# PHP 8.0
docker run -v $(pwd):/app -w /app php:8.0-cli php -v

# PHP 8.1
docker run -v $(pwd):/app -w /app php:8.1-cli php -v

# PHP 8.2
docker run -v $(pwd):/app -w /app php:8.2-cli php -v
```

### Using Local PHP Manager

If you have multiple PHP versions installed:

```bash
# Switch PHP version (example with Homebrew on macOS)
brew unlink php && brew link php@7.4
php -v

# Test the plugin
# Then switch to next version
brew unlink php@7.4 && brew link php@8.0
php -v
```

## Security Verification

### Nonce Verification

1. Open browser DevTools (Network tab)
2. Submit test event form
3. Check request payload includes `_wpnonce` parameter
4. Verify request succeeds with valid nonce

### Input Sanitization

1. Try entering HTML/JavaScript in event name: `<script>alert('xss')</script>`
2. Verify it's sanitized (should become `scriptalertxssscript` or similar)
3. Check deactivation reason textarea with special characters
4. Verify all input is properly sanitized

### Output Escaping

1. View page source of admin test page
2. Verify all dynamic content is properly escaped
3. Check for any unescaped variables in HTML attributes
4. Inspect consent notice HTML for proper escaping

### HTTPS Verification

1. Check browser console for mixed content warnings
2. Verify all API calls use HTTPS
3. Check that API key is never exposed in frontend

## Debugging

### Enable WordPress Debug Mode

Add to `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Check Error Logs

```bash
# WordPress debug log
tail -f /path/to/wordpress/wp-content/debug.log

# PHP error log (location varies)
tail -f /var/log/php/error.log
```

### Common Issues

**SDK Not Loading**
- Verify Composer autoloader is present: `vendor/autoload.php`
- Check that `composer install` was run successfully
- Verify namespace is correct: `Linno\Telemetry\Client`

**Events Not Sending**
- Check consent status (must be "yes")
- Verify API key is set correctly
- Ensure cURL extension is enabled: `php -m | grep curl`
- Check network tab for failed requests
- Review error logs for API errors
- Test cURL manually: `curl -I https://api.openpanel.dev/track`

**Consent Notice Not Appearing**
- Clear browser cache
- Check that option `test_telemetry_plugin_telemetry_opt_in` is not set
- Verify you're logged in as admin
- Check that `admin_notices` hook is firing

**Deactivation Modal Not Showing**
- Ensure consent is granted
- Check browser console for JavaScript errors
- Verify assets are being enqueued
- Clear browser cache

## Expected Results

### OpenPanel Events

After completing all tests, you should see these events in OpenPanel:

1. **telemetry_installed** - When consent is granted
   - Contains: site_url, plugin info, system info, install_time

2. **test_custom_event** - From manual test form
   - Contains: site_url, plugin info, system info, custom properties

3. **post_published** - When publishing a post
   - Contains: site_url, plugin info, system info, post_id, post_type

4. **system_info** - From weekly cron or manual trigger
   - Contains: site_url, plugin info, all system information

5. **plugin_deactivated** - When deactivating with reason
   - Contains: site_url, plugin info, reason_category, reason_text

## Code Examples

### Basic Tracking

```php
// Using helper function
linno_telemetry_track('user_action', [
    'action_type' => 'button_click',
    'button_id' => 'save_settings'
]);

// Using client instance
if (isset($GLOBALS['test_telemetry_client'])) {
    $GLOBALS['test_telemetry_client']->track('user_action', [
        'action_type' => 'form_submit'
    ]);
}
```

### Custom Filters

```php
// Change reporting interval
add_filter('linno_telemetry_report_interval', function($interval) {
    return 'daily'; // or 'hourly', 'twicedaily'
});

// Add custom system info
add_filter('linno_telemetry_system_info', function($info) {
    $info['custom_field'] = 'custom_value';
    return $info;
});
```

## Support

For issues or questions:
- Check the main SDK documentation in `/docs`
- Review the integration guide: `/docs/integration.md`
- Check event catalog: `/docs/event-catalog.md`
- Review privacy policy: `/docs/privacy.md`

## License

GPL-2.0-or-later
