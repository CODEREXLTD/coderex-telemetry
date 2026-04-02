# Data Model: Constructor Backward Compatibility

**Feature Branch**: `005-constructor-backward-compat`  
**Date**: 2026-04-02

## Entities

### Client (modified)

The `Client` class is the only entity modified. No new classes, tables, or storage structures are introduced.

**Constructor Signature Change**:

```
Before:  __construct(array $config)
After:   __construct($configOrApiKey, string $apiSecret = '', string $pluginName = '', string $pluginFile = '')
```

The first parameter loses its `array` type hint to accept both arrays and strings. Default values on params 2–4 allow the array-only call (`new Client($config)`) to continue working.

### Config Array (internal, unchanged structure)

The internal `$this->config` array retains its existing shape regardless of which constructor form is used:

| Key | Type | Required | Source (Array) | Source (Legacy) |
|---|---|---|---|---|
| `apiKey` | string | no | `$config['apiKey']` | `$apiKey` (1st param) |
| `apiSecret` | string | no | `$config['apiSecret']` | `$apiSecret` (2nd param) |
| `pluginName` | string | no | `$config['pluginName']` | `$pluginName` (3rd param) |
| `pluginFile` | string | **yes** | `$config['pluginFile']` | `$pluginFile` (4th param) |
| `slug` | string | **yes** | `$config['slug']` | derived: `sanitize_title($pluginName)` |
| `version` | string | no | `$config['version']` | derived: `Utils::getPluginVersion($pluginFile)` |
| `unique_id` | string | no | `$config['unique_id']` | derived: `get_or_create_unique_id()` |
| `driver` | string | no | `$config['driver']` | hardcoded: `'open_panel'` |
| `driver_config` | array | no | `$config['driver_config']` | default: `[]` |

### Validation Rules

| Rule | Applies To | Error |
|---|---|---|
| First arg must be `array` or `string` | Both paths | `InvalidArgumentException` |
| `pluginFile` must not be empty | Both paths | `InvalidArgumentException` |
| `slug` must not be empty | Array path only | `InvalidArgumentException` |
| `apiKey` must not be empty | Legacy path | `InvalidArgumentException` |
| `pluginName` must not be empty | Legacy path | `InvalidArgumentException` |
| Exactly 4 positional params when first is string | Legacy path | `InvalidArgumentException` |

### State Transitions

No state machine changes. The `Client` lifecycle (construct → init → track → deactivate) is unchanged. The only difference is how `$this->config` gets populated in the constructor.

## Relationships

```
Client 1──1 Config Array (internal)
Client 1──1 EventDispatcher (unchanged)
Client 1──1 Consent (unchanged)
Client 1──1 Deactivation (unchanged)
Client 1──1 Queue (unchanged)
Client 1──* TriggerManager (unchanged)
```

No new relationships introduced. The backward-compatible constructor is purely a translation layer that normalizes legacy positional parameters into the existing config array format.
