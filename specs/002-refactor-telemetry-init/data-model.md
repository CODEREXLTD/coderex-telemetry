# Data Model

## Event

**Purpose**: Represents a telemetry message emitted by the client and handed to a configured driver.

**Fields**:

- `name` (string, required): Any event name supplied by lifecycle tracking, optional modules, or the custom-event API. Canonical library-owned examples are `activation/plugin_activated`, `activation/plugin_deactivated`, `activation/onboarding_completed`, and `activation/aha_reached`.
- `properties` (array<string, mixed>, optional): Associative payload passed through unchanged to the configured driver.
- `mode` (enum: `minimal`, `standard`): Determines whether the payload is sent as a lifecycle event with minimal metadata or through the normal metadata-enriched path.
- `source` (enum: `activation`, `deactivation`, `public_api`, `wordpress_action`, `trigger_module`): Identifies where the event originated for implementation reasoning and tests.
- `legacyAlias` (string|null): Optional legacy label such as `setup`, `first_strike`, `kui`, or `aha` that is normalized to a canonical emitted event name.

**Validation rules**:

- `name` must be a non-empty string.
- `properties` must be an associative array when provided.
- Custom events must preserve the caller-provided event name and properties unchanged.
- Missing drivers must not prevent event creation; they only affect dispatch outcome.

## Driver

**Purpose**: Encapsulates transport-specific event delivery.

**Fields**:

- `type` (string): Current supported values are `open_panel` and `posthog`.
- `config` (array): Driver-specific configuration, such as PostHog host or OpenPanel credentials.
- `lastError` (string|null): Most recent failure message returned by the driver.

**Validation rules**:

- A driver may be absent or not fully configured; the client must then log and no-op.
- Driver `send()` must return `bool` and expose the last error through `getLastError()`.

## Trigger Module

**Purpose**: Optional configuration that connects WordPress hooks to telemetry events.

**Fields**:

- `kind` (enum: `onboarding_completed`, `aha_reached`, `custom`): Canonical emitted module type.
- `alias` (enum: `setup`, `first_strike`, `kui`, `aha`, nullable): Legacy label accepted for compatibility and normalized to a canonical event name when applicable.
- `hook` (string|null): WordPress action name to subscribe to.
- `callback` (callable|null): Optional argument-to-properties mapper.
- `threshold` (array|null): KUI threshold configuration for periodic checks.
- `eventName` (string|null): Explicit event name for fully custom trigger registrations.
- `enabled` (bool, derived): `true` when plugin code supplies a trigger definition for the module; otherwise the module remains disabled by omission.

**State transitions**:

- `defined` -> `initialized` when the client wires the trigger.
- `initialized` -> `fired` for one-shot onboarding-completion events.
- `initialized` -> `counting` -> `fired` for threshold-based AHA milestone events.

**Validation rules**:

- Omitted onboarding or `kui`/`aha` trigger definitions mean the module remains disabled.
- Provided legacy aliases must normalize to canonical emitted event names before dispatch.

## Telemetry Client Configuration

**Purpose**: Defines the runtime behavior of the SDK for a plugin.

**Fields**:

- `pluginFile` (string, required)
- `slug` (string, required)
- `pluginName` (string, optional)
- `version` or `pluginVersion` (string, optional; current implementation should normalize this inconsistency)
- `driver` (string, optional)
- `driver_config` (array, optional)
- `customEventHook` (string, derived or configurable): WordPress action used to accept externally triggered custom events.
- `triggerDefinitions` (array, optional): Onboarding and `kui`/`aha` trigger configuration supplied through `define_triggers()`; omitted keys leave optional modules disabled.

**Validation rules**:

- `pluginFile` and `slug` are required constructor inputs.
- Driver selection must resolve to a supported driver or to a safe no-driver path.
- Optional module configuration must not be required for successful client initialization.
- Driver resolution must preserve parity across both supported drivers: `posthog` and `open_panel`.