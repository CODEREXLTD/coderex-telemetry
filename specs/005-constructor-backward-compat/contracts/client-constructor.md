# Contract: Client Constructor API

**Feature Branch**: `005-constructor-backward-compat`  
**Date**: 2026-04-02  
**Type**: PHP Public API (Library)

## Constructor Signatures

### Form 1: Array Config (current, preferred)

```php
new Client(array $config)
```

**Required keys**: `pluginFile`, `slug`  
**Optional keys**: `apiKey`, `apiSecret`, `pluginName`, `version`, `unique_id`, `driver`, `driver_config`

**Example**:
```php
$client = new Client([
    'pluginFile' => __FILE__,
    'slug'       => 'my-plugin',
    'pluginName' => 'My Plugin',
    'version'    => '1.0.0',
    'driver'     => 'open_panel',
    'apiKey'     => 'op_CLIENT_ID',
    'apiSecret'  => 'sec_API_SECRET',
]);
```

**Behavior**: No deprecation notice. All existing behavior unchanged.

### Form 2: Positional Parameters (legacy, deprecated)

```php
new Client(string $apiKey, string $apiSecret, string $pluginName, string $pluginFile)
```

**All 4 parameters required**. `$apiKey` and `$pluginFile` must not be empty.

**Example**:
```php
$client = new Client('op_CLIENT_ID', 'sec_API_SECRET', 'My Plugin', __FILE__);
```

**Behavior**:
- Emits `E_USER_DEPRECATED` notice with migration guidance
- Defaults driver to `'open_panel'`
- Derives `slug` from `$pluginName` via `sanitize_title()`
- Auto-detects `version` from plugin file headers
- Auto-generates `unique_id` from WordPress options

## Error Responses

| Condition | Exception | Message Pattern |
|---|---|---|
| First arg is not `array` or `string` | `InvalidArgumentException` | First argument must be a configuration array or a string API key |
| Array missing `pluginFile` or `slug` | `InvalidArgumentException` | The "pluginFile" and "slug" parameters are required |
| Legacy path: empty `$apiKey` | `InvalidArgumentException` | API key must not be empty |
| Legacy path: empty `$pluginFile` | `InvalidArgumentException` | Plugin file path must not be empty |
| Legacy path: empty `$pluginName` | `InvalidArgumentException` | Plugin name must not be empty |
| Legacy path: wrong param count | `InvalidArgumentException` | Legacy constructor requires exactly 4 string parameters |

## Deprecation Notice

**When**: Every legacy-form constructor call.  
**Mechanism**: `trigger_error($message, E_USER_DEPRECATED)`  
**Message**: `"Passing positional parameters to Linno\Telemetry\Client::__construct() is deprecated. Use an array configuration instead. See https://github.com/user/coderex-telemetry#migration for details. This will be removed in the next major version."`

## Backward Compatibility Guarantees

1. All existing `new Client(array $config)` calls continue to work identically — no changes required.
2. All existing public methods (`track()`, `define_triggers()`, `getDispatcher()`, etc.) are unaffected.
3. The internal `$this->config` array has the same shape regardless of constructor form.
4. The legacy constructor form will be removed in the next major version (semver).
