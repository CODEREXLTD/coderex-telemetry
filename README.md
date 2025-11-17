# CodeRex Telemetry SDK

Privacy-first telemetry SDK for Code Rex WordPress plugins.

## Overview

The CodeRex Telemetry SDK is a Composer package that provides privacy-first telemetry tracking for WordPress plugins. It enforces user consent, standardizes event payloads, and integrates directly with OpenPanel analytics platform.

## Features

- **Privacy-First**: No data is sent without explicit user consent
- **Easy Integration**: Simple API with just a few lines of code
- **Automatic Events**: Tracks install, deactivation, and weekly system info
- **Custom Events**: Track plugin-specific events with custom properties
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

1. **User Activation**: When a user activates your plugin, they'll see a consent notice from Appsero
2. **User Choice**: They can click "Allow" to opt in or "No thanks" to opt out
3. **Automatic Tracking**: If they opt in, the SDK automatically sends:
   - Install event (one time)
4. **Custom Events**: Your custom `coderex_telemetry_track()` calls will also be sent (only if opted in)

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
