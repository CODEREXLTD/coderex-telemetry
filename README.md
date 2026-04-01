# Linno Telemetry SDK

Privacy-first telemetry SDK for Linno WordPress plugins.

## Overview

The Linno Telemetry SDK is a Composer package that provides privacy-first telemetry tracking for WordPress plugins. It enforces user consent, standardizes event payloads, and integrates directly with OpenPanel analytics platform.

## Compliance and Development Guidelines (MUST READ)

The SDK's core purpose is to handle data transmission securely and ethically. Developers using this SDK **must** adhere to strict consent and disclosure requirements.

*   **Internal Compliance Mandates:** For a complete list of requirements regarding PII collection, opt-in placement, and WordPress.org submission rules, please see our detailed **[Privacy Implementation Guideline](PRIVACY_GUIDELINE.md)**.
    *(This document details mandatory steps for GDPR/WP.org compliance when implementing the SDK.)*


## Features

-   **Privacy-First**: Enforces user consent before sending most data (lifecycle events do not require consent).
-   **Easy Integration**: Simple API with just a few lines of code.
-   **Lifecycle Events**: Tracks plugin activation via a standard WordPress hook and automatically handles the deactivation feedback form.
-   **Automatic PLG Tracking**: Define triggers once, library automatically tracks setup, first strike, and KUI events.
-   **Threshold-Based KUI**: Automatically track when users hit usage thresholds (e.g., 2 orders per week).
-   **Custom Events**: Track plugin-specific events with custom properties.
-   **Asynchronous Sending**: Events are queued and sent via WP-Cron to prevent performance impact.
-   **WordPress Native**: Uses WordPress APIs and follows WordPress coding standards.
-   **Secure**: HTTPS-only transmission, nonce verification, input sanitization.
-   **Internationalized**: All user-facing strings are translatable.

## Requirements

-   PHP 7.4 or higher
-   WordPress 5.0 or higher

## Installation

### Step 1: Configure Composer

Add the VCS repository to your `composer.json`:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "git@github.com:CODEREXLTD/linno-telemetry.git"
  }
]
```

### Step 2: Install via Composer

In your WordPress plugin directory, run:

```bash
composer require linno/telemetry:dev-master
```

### Step 3: Require Autoloader

In your main plugin file, require the Composer autoloader:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

That's it! You're ready to use the SDK.

## Quick Start

Here's a complete example of integrating the SDK into your WordPress plugin:

```php
<?php
/**
 * Plugin Name: My Awesome Plugin
 * Description: An awesome WordPress plugin with telemetry
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: my-awesome-plugin
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Require Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

use Linno\Telemetry\Client;

// Optional: Set text domain for i18n (defaults to plugin slug)
Client::set_text_domain( 'my-awesome-plugin' );

// Optional: Set privacy policy URL for "Learn more" (default: https://rextheme.com/privacy-policy/)
Client::set_privacy_url( 'https://your-site.com/privacy-policy/' );

// Optional: Set analytics service label shown in consent notice (default: "our analytics service")
Client::set_consent_service_name( 'RexTheme Analytics' );

// Initialize the telemetry client with only 4 arguments
$telemetry_client = new Client(
    'your-openpanel-client-id-here',    // Your OpenPanel API Key
    'your-openpanel-secret-key-here',   // Your OpenPanel API Secret
    'My Awesome Plugin',                // Human-readable plugin name
    __FILE__                            // Path to the main plugin file
);

// Define automatic triggers for PLG events (recommended)
// The SDK will automatically track these events based on your configuration
$telemetry_client->define_triggers([
    // Setup: Fire when user completes setup wizard
    // Developer fires: do_action('my_plugin_setup_complete')
    'setup' => 'my_plugin_setup_complete',
    
    // First Strike: Fire when user experiences core value for first time
    // Developer fires: do_action('my_plugin_first_funnel_created')
    'first_strike' => 'my_plugin_first_funnel_created',
    
    // KUI (Key Usage Indicators): Fire when user gets sufficient value
    'kui' => [
        // Threshold-based: fires when condition is met (e.g., 2 orders per week)
        'order_received' => [
            'hook' => 'woocommerce_order_created',
            'threshold' => ['count' => 2, 'period' => 'week'],
            'callback' => function( $order_id ) {
                return ['order_id' => $order_id];
            }
        ],
        // Simple hook-based: fires every time the hook is triggered
        'funnel_published' => [
            'hook' => 'my_plugin_funnel_published'
        ]
    ]
]);

// Initialize all hooks for consent, deactivation, and triggers
// IMPORTANT: This now INTERNALLY registers activation and deactivation hooks.
// You no longer need to call register_activation_hook manually.
$telemetry_client->init();


// Note on deactivation:
// The deactivation feedback modal is handled automatically by the library.
// You do NOT need to call register_deactivation_hook for it to work.

```

### What Happens Next?

1.  **Plugin Activation**: When the plugin is activated, the SDK (which internally registered the activation hook during `$telemetry_client->init()`) triggers the `activate` method and tracks the `plugin_activated` event.
2.  **Global Consent Notice (One Time)**: On the first Linno plugin installation, an admin notice asks for telemetry consent.
3.  **Shared Consent Across Linno Plugins**: Once allowed (or declined), the choice is reused for all other Linno plugins on that same site.
4.  **Table Creation After Consent**: The telemetry queue table is created only after consent is allowed, and only once per site.
5.  **Deactivation Feedback**: Upon deactivation, a modal will prompt the user for a reason, which is tracked. This is handled automatically by the library's internal deactivation hook.
6.  **Asynchronous Sending**: All events are added to a local queue and sent to OpenPanel in batches via a daily WP-Cron job.

### Onboarding Consent Flow (Important)

If your plugin asks for consent inside a custom onboarding wizard (instead of using the default admin notice), activation happens first, so `plugin_activated` is initially marked as pending.

When the user allows tracking in onboarding, call:

```php
$telemetry_client->set_optin_state( 'yes' );
```

This now automatically:

- creates the queue table (if needed), and
- flushes pending `plugin_activated` tracking exactly once.

If your onboarding stores consent in your own option first, call this right after saving to keep telemetry state in sync:

```php
$telemetry_client->sync_consent_state();
```

Or use the global helper (no direct client call needed):

```php
linno_telemetry_sync_consent_state( __FILE__ );
```

No manual `plugin_activated` tracking is needed in your plugin. The SDK now also recovers this event when telemetry is initialized after activation (common in setup-wizard-driven bootstraps).

If your wizard writes the consent option directly (without calling SDK methods), the SDK will still detect consent on `init()` and flush pending `plugin_activated` on the next request.

## Trigger System

The SDK provides a unified way to configure automatic event tracking. Developers define **when** to trigger events, and the library handles the rest.

### Setup Trigger

Fires once when the user completes your plugin's setup wizard.

```php
// In your plugin, fire this action when setup is complete:
do_action('my_plugin_setup_complete');
```

### First Strike Trigger

Fires once when the user experiences the core value of your product for the first time.

```php
// In your plugin, fire this action on first core value moment:
do_action('my_plugin_first_funnel_created');
```

### KUI (Key Usage Indicator) Trigger

Fires when the user gets sufficient value from your plugin. Supports two modes:

**Threshold-Based** (recommended):
```php
// Track when user receives 2+ orders per week
'kui' => [
    'order_received' => [
        'hook' => 'woocommerce_order_created',
        'threshold' => ['count' => 2, 'period' => 'week']
    ]
]
```

**Simple Hook-Based**:
```php
// Track every time the hook fires
'kui' => [
    'funnel_published' => [
        'hook' => 'my_plugin_funnel_published'
    ]
]
```

### Custom Event Triggers

Track any custom event:

```php
$telemetry_client->triggers()
    ->on('page_created', 'my_plugin_page_created', function( $page_id ) {
        return ['page_id' => $page_id, 'type' => get_post_type( $page_id )];
    });
```

## Events Not Requiring Consent

The SDK automatically tracks these events **without requiring user consent**:

-   **`plugin_activated`**: When the plugin is activated.
    -   Includes: `site_url`, `unique_id`.
-   **`plugin_deactivated`**: When the plugin is deactivated.
    -   Includes: `site_url`, `unique_id`, `reason`.

**Why no opt-in required?** These lifecycle events are essential for understanding plugin adoption and uninstallation reasons. They are designed to contain no personal data (no email, name, avatar, or user profile fields).

## Data Collected (with User Consent)

With user consent, the SDK collects:

-   Site URL
-   Plugin name and version
-   Event timestamps
-   Unique site profile ID (anonymous)
-   Custom event properties (as defined by developer)

**No sensitive personal data** is collected beyond what is strictly necessary for anonymous usage analytics and product improvement, and only with explicit user consent.

## Appsero Consent Compatibility

The SDK supports migration from Appsero consent keys so existing users are not prompted again.

- Primary key: `linno_telemetry_allow_tracking`
- Legacy pattern: `{plugin_slug}_allow_tracking`
- Also checks known legacy keys:
    - `best-woocommerce-feed_allow_tracking`
    - `wpvr_allow_tracking`
    - `wpfunnels_allow_tracking`
    - `cart-lift_allow_tracking`
    - `creatorlms_allow_tracking`
    - `mail-mint_allow_tracking`

If a legacy key exists with `yes` or `no` and `linno_telemetry_allow_tracking` is not set, the value is automatically reused and migrated to the Linno key.

## Using the PostHog Driver

To use the PostHog driver, you need to install the `posthog/posthog-php` package:

```bash
composer require posthog/posthog-php
```

Then, when you initialize the `Client`, specify the `posthog` driver and provide the host in the `driver_config`:

```php
$client = new Client([
    'pluginFile' => __FILE__,
    'slug' => 'my-awesome-plugin',
    'driver' => 'posthog',
    'driver_config' => [
        'host' => 'https://app.posthog.com'
    ]
]);
```

## License
GPL-2.0-or-later

## Support
For support, please contact support@linno.co
