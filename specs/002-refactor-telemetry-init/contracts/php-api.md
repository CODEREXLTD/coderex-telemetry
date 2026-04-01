# PHP API Contract

## Scope

Public client-facing methods involved in telemetry initialization and event dispatch for this feature.

## Canonical Library-Owned Event Names

- Plugin activation: `activation/plugin_activated`
- Plugin deactivation: `activation/plugin_deactivated`
- Onboarding completion: `activation/onboarding_completed`
- AHA milestone reached: `activation/aha_reached`

## Constructor

```php
new Client(array $config)
```

### Required configuration

- `pluginFile`: string
- `slug`: string

### Optional configuration

- `driver`: string (`open_panel`, `posthog`, or equivalent supported alias)
- `driver_config`: array
- `pluginName`: string
- `version` or normalized plugin version key
- `unique_id`: string

### Contract

- Construction must succeed when optional trigger modules are absent.
- Construction must not require onboarding completion or `kui` or `aha` milestone configuration.
- If the selected driver is missing or not usable, the client must remain operational and log rather than throw during normal event submission.

## Custom Event Method

```php
$client->track(string $eventName, array $properties = [], bool $override = false): void
```

### Contract

- Accept any non-empty event name.
- Accept an optional associative array of properties.
- Preserve caller-supplied event name and properties through to the dispatcher and driver unchanged.
- Continue to reserve the canonical `activation/*` event names for library-owned lifecycle and milestone telemetry.
- Respect the existing consent behavior unless explicitly overridden.
- Never throw because of a missing driver or driver send failure; those conditions must be logged and dropped.

## Optional Trigger Configuration

```php
$client->define_triggers(array $config): self
```

### Contract

- Optional onboarding and milestone keys remain configuration-driven rather than required for initialization.
- Omitted onboarding and `kui`/`aha` trigger definitions leave those optional modules disabled by default.
- Legacy setup, `first_strike`, `kui`, and `aha` instrumentation must normalize to the canonical emitted event names instead of sending legacy literal event names.
- Trigger registration must not be required for client initialization.
- Triggered events must flow through the same custom-event dispatch rules as direct API calls.