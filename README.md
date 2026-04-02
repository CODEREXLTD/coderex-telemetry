# Linno Telemetry SDK

Privacy-first telemetry SDK for Linno WordPress plugins.

## Overview

The Linno Telemetry SDK is a Composer package that provides privacy-first telemetry tracking for WordPress plugins. It enforces user consent, standardizes event payloads, and supports both the PostHog and OpenPanel analytics platforms.

## Compliance and Development Guidelines (MUST READ)

The SDK's core purpose is to handle data transmission securely and ethically. Developers using this SDK **must** adhere to strict consent and disclosure requirements.

*   **Internal Compliance Mandates:** For a complete list of requirements regarding PII collection, opt-in placement, and WordPress.org submission rules, please see our detailed **[Privacy Implementation Guideline](PRIVACY_GUIDELINE.md)**.
    *(This document details mandatory steps for GDPR/WP.org compliance when implementing the SDK.)*


## Features

-   **Privacy-First**: Enforces user consent before sending most data (lifecycle events do not require consent).
-   **Easy Integration**: Simple config-array constructor — only `pluginFile` and `slug` are required.
-   **Canonical Event Taxonomy**: Library-owned events are emitted under a stable `activation/*` namespace.
-   **Lifecycle Events**: Tracks plugin activation and deactivation via the standard WordPress hook system.
-   **Optional PLG Triggers**: Define `setup` / `onboarding`, and `aha` / `kui` triggers only when needed — omitting them leaves those modules disabled by default.
-   **Custom Events**: Send arbitrary events with any name and optional properties through a PHP API _or_ a WordPress action hook.
-   **Non-Fatal Telemetry**: Missing drivers and send failures are logged and silently dropped — they never interrupt plugin execution.
-   **Multi-Driver Support**: Works with PostHog and OpenPanel; falls back to a safe NullDriver when no driver is configured.
-   **Asynchronous Sending**: Consented custom events are queued and sent via WP-Cron to prevent performance impact.
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
 */

if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/vendor/autoload.php';

use LinnoSDK\Telemetry\Client;

// Optional display customizations
Client::set_text_domain( 'my-awesome-plugin' );
Client::set_privacy_url( 'https://your-site.com/privacy-policy/' );
Client::set_consent_service_name( 'My Analytics' );

// Initialize the client — only 'pluginFile' and 'slug' are required.
$telemetry_client = new Client([
    'pluginFile' => __FILE__,
    'slug'       => 'my-awesome-plugin',
    'pluginName' => 'My Awesome Plugin',
    'version'    => '1.0.0',

    // Choose a driver.  Omit to run with no driver (events silently dropped).
    'driver'     => 'open_panel',  // or 'posthog'
    'apiKey'     => 'op_YOUR_CLIENT_ID',
    'apiSecret'  => 'sec_YOUR_API_SECRET',
]);

// Optional: define automatic triggers for onboarding and AHA milestones.
// Every key is optional — omitting a key disables that module.
$telemetry_client->define_triggers([

    // Fires activation/onboarding_completed once — use 'setup' or 'onboarding'
    'setup' => 'my_plugin_setup_complete',

    // Fires retention/feature_used for each defined feature
    'feature_used' => [
        'funnel_created' => [
            'hook' => 'my_plugin_funnel_created',
        ],
    ],

    // Fires activation/aha_reached — use 'aha' (canonical) or 'kui' (legacy alias)
    'aha' => [
        'order_received' => [
            'hook'      => 'woocommerce_order_created',
            'threshold' => ['count' => 2, 'period' => 'week'],
            'callback'  => function( $order_id ) {
                return ['order_id' => $order_id];
            },
        ],
        'funnel_published' => [
            'hook' => 'my_plugin_funnel_published',
        ],
    ],
]);
// Initialization, activation/deactivation hooks, and the custom-event action
// hook are all registered inside the constructor — no extra init() call needed.
```

### What Happens Next?

1.  **Plugin Activation**: The SDK internally registers the activation hook. When the plugin activates, it emits `activation/plugin_activated`.
2.  **Global Consent Notice (One Time)**: On the first Linno plugin installation, an admin notice asks for telemetry consent.
3.  **Shared Consent Across Linno Plugins**: Once allowed (or declined), the choice is reused for all other Linno plugins on that same site.
4.  **Table Creation After Consent**: The telemetry queue table is created only after consent is allowed, and only once per site.
5.  **Deactivation Feedback**: Upon deactivation, a modal will prompt the user for a reason, which triggers `activation/plugin_deactivated`. Handled automatically.
6.  **Asynchronous Sending**: Consented custom events are added to a local queue and sent via a daily WP-Cron job.

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

## Canonical Event Names

The SDK emits all library-owned events under the `activation/*` namespace for a stable analytics taxonomy:

| Trigger | Emitted Event Name |
|---|---|
| Plugin activation | `activation/plugin_activated` |
| Plugin deactivation | `activation/plugin_deactivated` |
| Onboarding / setup | `activation/onboarding_completed` |
| Feature Used | `retention/feature_used` |
| AHA / KUI milestone | `activation/aha_reached` |

Custom events submitted via `Client::track()` or the `<slug>_telemetry_track` WordPress action are passed through **unchanged** — the SDK never alters caller-supplied event names.

## Custom Events

### PHP API

```php
// Any event name; optional associative properties array; optional consent override.
$telemetry_client->track( 'post_published', [ 'post_id' => 42 ] );
```

### WordPress Action Hook

The SDK registers `<slug>_telemetry_track` during initialization. Fire it from anywhere:

```php
do_action( 'my-awesome-plugin_telemetry_track', 'post_published', [ 'post_id' => 42 ] );
```

Both paths accept any event name and an optional associative properties array, and route through the same consent-gated queue path.

## Trigger System

### Setup / Onboarding (fires `activation/onboarding_completed` once)

```php
$telemetry_client->define_triggers([
    'setup'      => 'my_plugin_setup_complete',       // legacy key
    // 'onboarding' => 'my_plugin_setup_complete',    // canonical alias — same behavior
]);
```

### Feature Used (fires `retention/feature_used`)

```php
$telemetry_client->define_triggers([
    'feature_used' => [
        'funnel_created' => [
            'hook' => 'my_plugin_funnel_created',
            'callback'  => function( $funnel_id ) {
                return ['funnel_id' => $funnel_id];
            },
        ],
    ],
]);
```

Alternatively, use the static convenience method to register a feature-used event from anywhere in your codebase after the client is initialized:

```php
use LinnoSDK\Telemetry\Client;

// Fires retention/feature_used with feature='Export Settings' when the hook is triggered.
Client::add_feature_used_event( 'my_plugin_settings_exported', 'Export Settings' );

// With optional extra parameters.
Client::add_feature_used_event( 'my_plugin_settings_imported', 'Import Settings', [ 'source' => 'file' ] );
```

Then trigger the corresponding WordPress action in your plugin code:

```php
function my_plugin_export_settings() {
    // ... export logic ...
    do_action( 'my_plugin_settings_exported' );
}
```

### AHA / KUI Milestones (fires `activation/aha_reached`)

```php
$telemetry_client->define_triggers([
    // 'aha' is the canonical key; 'kui' is the legacy alias — both work.
    'aha' => [
        'order_received' => [
            'hook'      => 'woocommerce_order_created',
            'threshold' => ['count' => 2, 'period' => 'week'],
        ],
        'funnel_published' => [
            'hook' => 'my_plugin_funnel_published',   // fires every time
        ],
    ],
]);
```

`activation/aha_reached` events include an `indicator` property with the milestone name for downstream filtering.

### Custom Trigger (pass-through event name)

Register a trigger that fires a developer-supplied event name on any hook:

```php
$telemetry_client->triggers()
    ->on( 'page_created', 'my_plugin_page_created', function( $page_id ) {
        return ['page_id' => $page_id];
    });
```

## Events Not Requiring Consent

The SDK automatically tracks these events **without requiring user consent**:

-   **`activation/plugin_activated`**: When the plugin is activated.
    -   Includes: `site_url`, `unique_id`.
-   **`activation/plugin_deactivated`**: When the plugin is deactivated.
    -   Includes: `site_url`, `unique_id`, `reason`.

**Why no opt-in required?** These lifecycle events contain no personal data (no email, name, or user profile fields).

## Non-Fatal Driver Behavior

The SDK is designed to never interrupt plugin execution:

-   **No driver configured** → a warning is written to `error_log` and events are silently dropped.
-   **Unrecognized driver name** → same as above.
-   **Driver `send()` fails** → the failure is logged to `error_log` and the event is dropped.

No exceptions are thrown during normal event submission.

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

```bash
composer require posthog/posthog-php
```

```php
$client = new Client([
    'pluginFile'    => __FILE__,
    'slug'          => 'my-awesome-plugin',
    'driver'        => 'posthog',
    'driver_config' => [
        'host'    => 'https://app.posthog.com',
        'api_key' => 'phc_YOUR_POSTHOG_API_KEY',
    ],
]);
```

## Using the OpenPanel Driver

```php
$client = new Client([
    'pluginFile' => __FILE__,
    'slug'       => 'my-awesome-plugin',
    'driver'     => 'open_panel',
    'apiKey'     => 'op_YOUR_CLIENT_ID',
    'apiSecret'  => 'sec_YOUR_API_SECRET',
]);
```

## License
GPL-2.0-or-later

## Support
For support, please contact support@linno.co
