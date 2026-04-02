# Quickstart: Constructor Backward Compatibility

**Feature Branch**: `005-constructor-backward-compat`

## What Changed

The `Client` constructor now accepts both the new array config format AND the old 4-positional-parameter format. This prevents fatal errors when multiple WordPress plugins on the same site bundle different versions of the Linno Telemetry library.

## For Plugin Developers Using the New Format (No Action Needed)

Your existing code continues to work without changes:

```php
$client = new \Linno\Telemetry\Client([
    'pluginFile' => __FILE__,
    'slug'       => 'my-plugin',
    'pluginName' => 'My Plugin',
    'version'    => '1.0.0',
    'driver'     => 'open_panel',
    'apiKey'     => 'op_CLIENT_ID',
    'apiSecret'  => 'sec_API_SECRET',
]);
```

## For Plugin Developers Using the Old Format (Migration Recommended)

The old calling convention still works but emits a deprecation notice:

```php
// OLD — still works, but deprecated
$client = new \Linno\Telemetry\Client(
    'op_CLIENT_ID',
    'sec_API_SECRET',
    'My Plugin',
    __FILE__
);
```

### How to Migrate

Replace the positional parameters with an array config:

```php
// NEW — recommended
$client = new \Linno\Telemetry\Client([
    'pluginFile' => __FILE__,
    'slug'       => 'my-plugin',       // was auto-derived from plugin name
    'pluginName' => 'My Plugin',
    'apiKey'     => 'op_CLIENT_ID',
    'apiSecret'  => 'sec_API_SECRET',
    'driver'     => 'open_panel',       // was implicitly hardcoded
    'version'    => '1.0.0',           // was auto-detected from file headers
]);
```

**Key differences**:
- `slug` is now explicit (was derived via `sanitize_title()`)
- `driver` is now explicit (was hardcoded to `'open_panel'`)
- `version` can be explicit (was auto-detected — auto-detection still works if omitted)

## Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run only Client tests
vendor/bin/phpunit --filter ClientTest
```

## Timeline

The legacy 4-parameter constructor will be **removed in the next major version**. Migrate at your convenience — there is no rush, but updating before the next major release is recommended.
