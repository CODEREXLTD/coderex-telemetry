# Lifecycle Event Tracking

## Overview

The CodeRex Telemetry SDK now automatically tracks plugin lifecycle events (activation and deactivation) for all integrating plugins. This eliminates the need for plugin-level code to manually fire these events.

## Automatic Events

### 1. `plugin_activated`

**When**: Automatically triggered when the plugin is activated via WordPress admin or WP-CLI.

**Properties Included**:
- `site_url` - The WordPress site URL
- `activation_time` - ISO 8601 timestamp of when the plugin was activated
- `plugin_name` - Name of the plugin (automatically added)
- `plugin_version` - Version of the plugin (automatically added)
- `timestamp` - Event timestamp

**Opt-in Requirement**: This event is sent **without requiring opt-in** to ensure we capture installation events.

**Example Payload**:
```json
{
  "event": "plugin_activated",
  "properties": {
    "site_url": "https://example.com",
    "activation_time": "2026-01-22T10:30:00+00:00",
    "plugin_name": "Product Feed Manager for WooCommerce",
    "plugin_version": "7.4.63",
    "timestamp": "2026-01-22T10:30:00+00:00"
  }
}
```

### 2. `plugin_deactivated`

**When**: Automatically triggered when the plugin is deactivated via WordPress admin or WP-CLI.

**Properties Included**:
- `usage_duration` - Total time (in seconds) the plugin was active
- `last_core_action` - The last significant action performed before deactivation
- `deactivation_time` - ISO 8601 timestamp of when the plugin was deactivated
- `plugin_name` - Name of the plugin (automatically added)
- `plugin_version` - Version of the plugin (automatically added)
- `timestamp` - Event timestamp

**Opt-in Requirement**: This event is sent **without requiring opt-in** to ensure we capture uninstallation events.

**Example Payload**:
```json
{
  "event": "plugin_deactivated",
  "properties": {
    "usage_duration": 86400,
    "last_core_action": "feed_created",
    "deactivation_time": "2026-01-23T10:30:00+00:00",
    "plugin_name": "Product Feed Manager for WooCommerce",
    "plugin_version": "7.4.63",
    "timestamp": "2026-01-23T10:30:00+00:00"
  }
}
```

## Implementation Details

### How It Works

1. **Activation Tracking**:
   - When a plugin using the SDK is activated, `register_activation_hook()` triggers
   - The SDK stores the activation timestamp in options table as `{plugin-slug}_activated_time`
   - The `plugin_activated` event is immediately sent with site_url

2. **Deactivation Tracking**:
   - When the plugin is deactivated, `register_deactivation_hook()` triggers
   - The SDK calculates usage duration (current_time - activated_time)
   - The SDK retrieves the last core action from `{plugin-slug}_last_core_action`
   - The `plugin_deactivated` event is sent with all collected data
   - The activation time option is cleaned up

3. **Last Core Action Tracking**:
   - Plugins update this via `coderex_telemetry_update_last_action()`
   - This helps understand what users were doing before deactivation
   - Stored in options table as `{plugin-slug}_last_core_action`

### Options Stored

The SDK stores the following options in WordPress:

| Option Name | Purpose | When Stored | When Deleted |
|------------|---------|-------------|--------------|
| `{plugin-slug}_activated_time` | Timestamp of plugin activation | On activation | On deactivation |
| `{plugin-slug}_last_core_action` | Last significant action performed | When action occurs | Never (updated) |
| `coderex_telemetry_site_profile_id` | Unique site identifier (shared) | First event | Never |
| `{plugin-slug}_allow_tracking` | User opt-in status | User consent | Never |

## Plugin Integration

### No Code Required

Plugins using the SDK get lifecycle tracking automatically. Just initialize the SDK:

```php
use CodeRex\Telemetry\Client;

$telemetry = new Client(
    'your-api-key',
    'your-api-secret',
    'Your Plugin Name',
    __FILE__  // Your main plugin file
);
```

That's it! The SDK will automatically:
- ✅ Track `plugin_activated` on activation
- ✅ Track `plugin_deactivated` on deactivation
- ✅ Calculate usage duration
- ✅ Include last core action

### Tracking Core Actions (Optional but Recommended)

To make deactivation data more useful, update the last core action when users perform important activities:

```php
// When user creates their first feed
coderex_telemetry_update_last_action( __FILE__, 'first_feed_created' );

// When user configures settings
coderex_telemetry_update_last_action( __FILE__, 'settings_saved' );

// When user uses an advanced feature
coderex_telemetry_update_last_action( __FILE__, 'advanced_feature_used' );
```

### Example Integration (best-woocommerce-feed)

```php
// In class-rex-product-telemetry.php

public function track_first_feed_published( $new_status, $old_status, $post ) {
    if ( $new_status === 'publish' && in_array($old_status, ['auto-draft', 'draft']) ) {
        // Update last core action
        coderex_telemetry_update_last_action( WPFM__FILE__, 'first_feed_generated' );
        
        // Track the event
        coderex_telemetry_track(
            WPFM__FILE__,
            'first_feed_generated',
            array(
                'format' => $feed_format,
                'merchant' => $merchant
            )
        );
    }
}
```

## Benefits

### For Plugin Developers

1. **Less Code**: No need to write activation/deactivation tracking logic
2. **Consistency**: Same implementation across all plugins
3. **Automatic**: Works immediately upon SDK integration
4. **Complete Data**: Includes usage duration and user context

### For Analytics

1. **Accurate Metrics**: Capture every activation/deactivation
2. **User Journey**: Understand what led to deactivation
3. **Usage Patterns**: Calculate actual usage duration
4. **Retention Analysis**: Track plugin lifecycle

## Migration Guide

If your plugin currently tracks activation/deactivation manually:

### Before (Manual Tracking)
```php
// OLD CODE - Can be removed
public function __construct() {
    add_action( 'plugin_activated', array( $this, 'track_activation' ));
    add_action( 'plugin_deactivated', array( $this, 'track_deactivation' ));
}

public function track_activation() {
    coderex_telemetry_track( __FILE__, 'plugin_activated', [] );
}

public function track_deactivation() {
    coderex_telemetry_track( __FILE__, 'plugin_deactivated', [] );
}
```

### After (Automatic Tracking)
```php
// NEW CODE - Nothing needed!
// Lifecycle events are tracked automatically by the SDK

// Just add last_core_action updates for better context:
public function track_important_action() {
    coderex_telemetry_update_last_action( __FILE__, 'action_name' );
    coderex_telemetry_track( __FILE__, 'custom_event', [] );
}
```

## Version History

- **v1.0.0** (2026-01-22): Added automatic lifecycle event tracking
  - `plugin_activated` event with site_url
  - `plugin_deactivated` event with usage_duration and last_core_action
  - Option tracking for activation time and last action
  - Helper function `coderex_telemetry_update_last_action()`

## Custom Plugin Events (Plugin-Level)

While lifecycle events are automatically tracked by the SDK, plugins can implement additional custom events for specific user journeys. Here are common events implemented in plugins:

### `paywall_hit`

**When**: Triggered when a free user attempts to access a pro-only feature.

**Properties**:
- `feature_name` - Name of the premium feature attempted
- `time` - Timestamp of the event

**Example Implementation**:
```javascript
// When user clicks on a disabled pro feature
$(document).on("click", ".pro-feature-disabled", function() {
    var feature_name = $(this).data('feature-name') || 'Unknown Feature';
    
    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'track_paywall_hit',
            feature_name: feature_name
        }
    });
});
```

```php
// PHP AJAX handler
public function ajax_track_paywall_hit() {
    $feature_name = sanitize_text_field( $_POST['feature_name'] );
    
    coderex_telemetry_update_last_action( __FILE__, 'paywall_hit' );
    coderex_telemetry_track( __FILE__, 'paywall_hit', array(
        'feature_name' => $feature_name,
        'time' => current_time( 'mysql' )
    ));
}
```

### `upgrade_clicked`

**When**: Triggered when a user clicks an upgrade link, button, or prompt.

**Properties**:
- `button_text` - Text content of the clicked button/link
- `button_location` - Where the button was clicked (e.g., 'popup', 'settings', 'feature_list')
- `time` - Timestamp of the event

**Example Implementation**:
```javascript
// Track all upgrade button clicks
$(document).on("click", ".upgrade-button, a[href*='pricing']", function() {
    var button_text = $(this).text().trim();
    var button_location = $(this).data('location') || 'unknown';
    
    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'track_upgrade_clicked',
            button_text: button_text,
            button_location: button_location
        }
    });
});
```

```php
// PHP AJAX handler
public function ajax_track_upgrade_clicked() {
    $button_text = sanitize_text_field( $_POST['button_text'] );
    $button_location = sanitize_text_field( $_POST['button_location'] );
    
    coderex_telemetry_update_last_action( __FILE__, 'upgrade_clicked' );
    coderex_telemetry_track( __FILE__, 'upgrade_clicked', array(
        'button_text' => $button_text,
        'button_location' => $button_location,
        'time' => current_time( 'mysql' )
    ));
}
```

**Use Cases**:
- Track conversion funnel from free to paid
- Understand which upgrade prompts are most effective
- Identify features that drive upgrade interest
- Optimize pricing page placement

## Related Documentation

- [Main README](README.md) - Complete SDK documentation
- [Privacy Guidelines](PRIVACY_GUIDELINE.md) - Compliance requirements
- [Event Reference](https://docs.openpanel.dev/) - OpenPanel event documentation
