# CodeRex Telemetry SDK

Privacy-first telemetry SDK for Code Rex WordPress plugins.

## Overview

The CodeRex Telemetry SDK is a Composer package that provides privacy-first telemetry tracking for WordPress plugins. It enforces user consent, standardizes event payloads, and integrates directly with OpenPanel analytics platform.

## Compliance and Development Guidelines (MUST READ)

The SDK's core purpose is to handle data transmission securely and ethically. Developers using this SDK **must** adhere to strict consent and disclosure requirements.

* **Internal Compliance Mandates:** For a complete list of requirements regarding PII collection, opt-in placement, and WordPress.org submission rules, please see our detailed **[Privacy Implementation Guideline](PRIVACY_GUIDELINE.md)**.
  *(This document details mandatory steps for GDPR/WP.org compliance when implementing the SDK.)*


## Features

- **Privacy-First**: No data is sent without explicit user consent (except lifecycle events)
- **Easy Integration**: Simple API with just a few lines of code
- **Automatic Lifecycle Events**: Automatically tracks plugin activation and deactivation
- **Custom Events**: Track plugin-specific events with custom properties
- **Usage Metrics**: Automatic calculation of usage duration and last action tracking
- **WordPress Native**: Uses WordPress APIs and follows WordPress coding standards
- **Secure**: HTTPS-only transmission, nonce verification, input sanitization

## Requirements

- PHP 7.4 or higher
- WordPress 5.0 or higher
- PHP cURL extension (for API communication)

## Installation

### Step 1: Configure Composer

Add the VCS repository to your `composer.json`:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/CODEREXLTD/coderex-telemetry"
  }
]
```

### Step 2: Install via Composer

In your WordPress plugin directory, run:

```bash
composer require coderexltd/telemetry:dev-master
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
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Require Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

use CodeRex\Telemetry\Client;

// Initialize the telemetry client
$telemetry = new Client(
    'your-openpanel-client-id-here',
    'your-openpanel-secret-key-here',
    'My Awesome Plugin',
    __FILE__                         // Plugin file path
);

// Track custom events anywhere in your plugin
add_action('my_plugin_course_created', function($course_id) {
    coderex_telemetry_track('course_created', [
        'course_id' => $course_id,
        'course_type' => 'video'
        ....
    ]);
});
```

### What Happens Next?

1. **Automatic Lifecycle Tracking**: The SDK immediately starts tracking activation/deactivation
2. **User Consent Notice**: When a user activates your plugin, they'll see a consent notice from Appsero
3. **User Choice**: They can click "Allow" to opt in or "No thanks" to opt out
4. **Custom Events**: Your custom `coderex_telemetry_track()` calls will be sent only if opted in

### Automatic Lifecycle Events

The SDK automatically tracks these events **without requiring opt-in**:

- **`plugin_activated`**: When the plugin is activated
  - Includes: `site_url`, `activation_time`
  
- **`plugin_deactivated`**: When the plugin is deactivated
  - Includes: `usage_duration`, `last_core_action`, `deactivation_time`

**Why no opt-in required?** Lifecycle events are essential for understanding plugin adoption and uninstallation reasons. They contain no personal data.

For detailed information, see [Lifecycle Events Documentation](LIFECYCLE_EVENTS.md).

### Tracking Core Actions (Recommended)

To make deactivation analytics more meaningful, update the last core action when users perform important activities:

```php
// When user creates their first item
coderex_telemetry_update_last_action( __FILE__, 'first_item_created' );

// When user configures settings
coderex_telemetry_update_last_action( __FILE__, 'settings_saved' );

// Before tracking any core event
coderex_telemetry_update_last_action( __FILE__, 'event_name' );
coderex_telemetry_track( __FILE__, 'event_name', $properties );
```

This helps understand what users were doing before they deactivated your plugin.

## What Data is Collected?

The SDK collects only technical information with user consent:

- Site URL
- Plugin name and version
- PHP version
- WordPress version
- MySQL version
- Server software
- Event timestamps

**No personal data** emails is collected by default. Each site is automatically assigned a unique profile ID for analytics.

## License
GPL-2.0-or-later

## Support
For support, please contact support@coderex.co
