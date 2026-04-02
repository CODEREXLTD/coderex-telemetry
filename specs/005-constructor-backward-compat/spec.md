# Feature Specification: Constructor Backward Compatibility

**Feature Branch**: `005-constructor-backward-compat`  
**Created**: 2025-04-02  
**Status**: Draft  
**Input**: User description: "Prevent fatal errors when multiple plugins on the same WordPress site use different versions of the Linno Telemetry library, specifically due to a breaking constructor signature change from positional parameters to an array config."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Mixed-Version Coexistence Without Fatal Errors (Priority: P1)

A WordPress site administrator has two plugins installed: Plugin A (using the updated library with `array $config` constructor) and Plugin B (using the older library with positional `string` parameters). Both plugins bundle the Linno Telemetry library via Composer. Only one version of the `Client` class gets autoloaded. Regardless of which version loads first, both plugins initialize without triggering a PHP fatal error.

**Why this priority**: This is the core problem — a fatal error crashes the entire WordPress site, affecting all site functionality, not just the telemetry plugins. This is an immediate, show-stopping issue for end users.

**Independent Test**: Can be fully tested by installing two plugins on a test site — one calling the old 4-param constructor and one calling the new array constructor — and confirming the site loads without fatal errors and both plugins activate successfully.

**Acceptance Scenarios**:

1. **Given** Plugin A uses the new array constructor and Plugin B uses the old 4-param constructor, **When** both plugins are active and the new library version is autoloaded, **Then** both plugins initialize without PHP fatal errors and the site functions normally.
2. **Given** Plugin A uses the new array constructor and Plugin B uses the old 4-param constructor, **When** both plugins are active and the old library version is autoloaded, **Then** Plugin A's initialization fails gracefully (logged warning) rather than causing a site-wide fatal error.
3. **Given** a single plugin using the new array constructor on a site with no version conflicts, **When** the plugin initializes, **Then** telemetry works identically to current behavior with no regressions.

---

### User Story 2 - Telemetry Functions Correctly for Both Plugins (Priority: P2)

When the new (backward-compatible) library version is loaded, both plugins — one using the old calling convention and one using the new — successfully send telemetry events. The old-style caller gets its events tracked correctly using the positional parameters mapped to the internal config structure.

**Why this priority**: Preventing fatal errors (P1) is useless if telemetry silently breaks for one of the plugins. Both callers must produce working telemetry sessions.

**Independent Test**: Can be tested by verifying that events dispatched from both plugins (old-style and new-style callers) appear in the configured analytics backend with correct metadata (plugin name, version, unique ID).

**Acceptance Scenarios**:

1. **Given** Plugin B calls the constructor with 4 positional strings (apiKey, apiSecret, pluginName, pluginFile), **When** the new library version is loaded, **Then** the Client initializes successfully and tracks events with correct plugin name, version, and unique ID.
2. **Given** Plugin A calls the constructor with an array config, **When** the new library version is loaded, **Then** the Client initializes and tracks events exactly as it does today.

---

### User Story 3 - Plugin Developer Migration Path (Priority: P3)

A plugin developer who currently uses the old 4-param constructor wants to migrate to the new array config format. They can update their constructor call at their own pace without coordinating a synchronized release with other plugins that also use the library.

**Why this priority**: Developers need a clear, non-breaking migration path. Without it, they cannot adopt the new features without risking breakage on user sites.

**Independent Test**: Can be tested by updating one test plugin's constructor from old-style to new-style and confirming that the plugin works on sites where other plugins still use the old-style call.

**Acceptance Scenarios**:

1. **Given** a plugin developer changes their constructor call from 4-param to array config, **When** deployed to a site with other plugins still using the old constructor, **Then** all plugins continue to function without errors.

---

### Edge Cases

- What happens when a caller passes a single string as the first argument (not an array and not matching the 4-param pattern)? The constructor should throw an `InvalidArgumentException` with a clear error message.
- What happens when a caller passes 4 positional parameters but the first is an empty string? The constructor should apply the same validation rules as the old constructor (reject empty API key).
- What happens when the old library version (without backward compatibility) is the one that gets autoloaded? The new-style callers will encounter a type error. This is outside the library's control, but should be documented as a known limitation with a recommended minimum version.
- What happens when 3 or 5 positional parameters are passed? The constructor should reject the call with a clear error message.

## Clarifications

### Session 2026-04-02

- Q: Which deprecation mechanism should FR-008 use — `error_log()`, `trigger_error(E_USER_DEPRECATED)`, or WordPress `_deprecated_argument()`? → A: `trigger_error($msg, E_USER_DEPRECATED)` — PHP-standard, integrates with WP_DEBUG, testable with PHPUnit.
- Q: When should the legacy positional-parameter constructor be removed? → A: Remove in the next major version (semver).
- Q: How should the `slug` be derived from positional parameters (old constructor has no slug param)? → A: Derive from `$pluginName` via `sanitize_title()` (e.g., "My Plugin" → "my-plugin").

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The `Client` constructor MUST accept both the new `array $config` calling convention and the legacy 4-positional-parameter calling convention (`string $apiKey, string $apiSecret, string $pluginName, string $pluginFile`).
- **FR-002**: When called with positional parameters, the constructor MUST map them to the internal config structure identically to how the old constructor populated `$this->config`.
- **FR-003**: When called with positional parameters, the constructor MUST auto-detect the plugin version, derive the slug from `$pluginName` via `sanitize_title()` (e.g., `"My Plugin"` → `"my-plugin"`), and create a unique ID — matching the old constructor's behavior.
- **FR-004**: The constructor MUST detect which calling convention is being used based on the type of the first argument (array vs. string).
- **FR-005**: The constructor MUST reject invalid calls (wrong number of positional params, empty required fields) with a descriptive `InvalidArgumentException`.
- **FR-006**: All existing public methods and behavior MUST remain unchanged regardless of which constructor form was used.
- **FR-007**: The backward-compatible constructor MUST default to the `open_panel` driver when called with the old positional parameters, matching the old constructor's hardcoded behavior.
- **FR-008**: The library MUST emit a deprecation notice via `trigger_error($msg, E_USER_DEPRECATED)` when the old positional-parameter form is used, guiding developers toward the array config format. This integrates with PHP's standard error handling and WordPress's `WP_DEBUG_LOG`.

### Key Entities

- **Client**: The main telemetry entry point. Holds config, driver, handlers. Must accept both calling conventions.
- **Config Array**: Internal normalized configuration structure that all code paths produce, regardless of constructor form used.
- **EventDispatcher**: Receives the driver and config from Client. Its constructor already accepts the array form — no changes needed.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A WordPress site with two plugins — one using the old constructor and one using the new constructor — loads without any PHP fatal errors, warnings, or notices (other than the deprecation notice for old-style callers).
- **SC-002**: 100% of existing unit tests pass without modification after the change.
- **SC-003**: Events tracked by a plugin using the old constructor format contain the same metadata fields (plugin name, version, unique ID, site URL) as events tracked by the new format.
- **SC-004**: The deprecation notice is emitted exactly once per old-style constructor call and includes a clear migration instruction.

## Assumptions

- Only two constructor signatures need to coexist: the old 4-param style and the new array style. No other historical signatures exist.
- The old constructor always hardcoded the OpenPanel driver; backward-compatible mode should replicate this default.
- WordPress's Composer autoloading means only one version of the `Client` class is loaded per request — the library cannot control which version is chosen, but the newest version should handle both call styles.
- Plugins using the old constructor are expected to eventually migrate. The backward compatibility is a transitional measure, not a permanent feature. The legacy positional-parameter constructor will be removed in the next major version (semver).
- The `EventDispatcher` constructor already accepts the array config format and does not need changes.
