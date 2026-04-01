# Quickstart: Tracking Feature Usage

This guide explains how to track the usage of your plugin's core features using the new `retention/feature_used` event.

## 1. Initialize the Telemetry Client

First, make sure the telemetry client is initialized in your plugin. This is typically done once in your main plugin file.

```php
<?php
use Linno\Telemetry\Client;

function my_plugin_init_telemetry() {
    Client::init(
        'My Plugin',
        'my-plugin',
        __FILE__
    );
}

add_action('plugins_loaded', 'my_plugin_init_telemetry');
```

## 2. Register a Feature-Used Event

To track when a user uses a specific feature, you need to register an event. This is done by calling the `add_feature_used_event` method. You provide a WordPress action hook name and a name for your feature.

```php
<?php
use Linno\Telemetry\Client;

// Track usage of the 'export_settings' feature.
// The event will be sent when the 'my_plugin_settings_exported' action is fired.
Client::add_feature_used_event('my_plugin_settings_exported', 'Export Settings');
```

You can also include additional data with the event:

```php
<?php
use Linno\Telemetry\Client;

// Track usage of the 'import_settings' feature with extra data.
Client::add_feature_used_event('my_plugin_settings_imported', 'Import Settings', ['source' => 'file']);
```

## 3. Trigger the Event

Finally, in your plugin's code where the feature is used, you need to trigger the action hook you registered.

```php
<?php
function my_plugin_export_settings() {
    // ... your code to export settings ...

    // Trigger the telemetry event.
    do_action('my_plugin_settings_exported');
}

function my_plugin_import_settings_from_file() {
    // ... your code to import settings ...

    // Trigger the telemetry event.
    do_action('my_plugin_settings_imported');
}
```

That's it! Now, whenever a user exports or imports settings, a `retention/feature_used` event will be sent to your telemetry provider.
