# Feature Specification: Refactor Telemetry Initialization

**Feature Branch**: `002-refactor-telemetry-init`  
**Created**: 2026-04-01  
**Status**: Draft  
**Input**: User description: "Already include posthog driver. But the initialization is depend on openpanel, like first strike, kui etc. need to remove this. This will be now action based. if any event occured I will send event to posthog. Plugin activation and deactivation will be same as before. I want to make first strike, kui optional. setup is okay. I am not sure if first strike, kui is optional or required. also need to use aha as kui. I mean kui and aha will be same. Also need to connfirm that data can be sent on custom events other than this. Like on a custom hook of the plugin, I can sent data easily to driver, whether it is posthog or openpanel."

## Clarifications

### Session 2026-04-01

- Q: What should happen when telemetry is called without a configured driver? → A: Log a warning and no-op.
- Q: How should custom events be triggered? → A: Support both a public PHP method and a WordPress action hook.
- Q: How should driver send failures be handled? → A: Log the failure and drop the event.
- Q: What custom event payload contract should the system support? → A: Any event name plus an optional associative properties map.
- Q: What canonical event names should the library-owned lifecycle and milestone events use? → A: Use `activation/plugin_activated`, `activation/plugin_deactivated`, `activation/onboarding_completed`, and `activation/aha_reached`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Core Event Tracking (Priority: P1)

As a plugin developer, I want plugin lifecycle telemetry to emit canonical activation event names, so that downstream analytics receives a stable event taxonomy.

**Why this priority**: This is the most fundamental requirement for the telemetry system.

**Independent Test**: This can be tested by activating and deactivating the plugin and verifying that the corresponding events are sent to the configured driver.

**Acceptance Scenarios**:

1. **Given** the telemetry system is initialized with a configured driver, **When** the plugin is activated, **Then** an `activation/plugin_activated` event is sent to the driver.
2. **Given** the telemetry system is initialized with a configured driver, **When** the plugin is deactivated, **Then** an `activation/plugin_deactivated` event is sent to the driver.

---

### User Story 2 - Custom Event Tracking (Priority: P2)

As a plugin developer, I want to send telemetry data for custom application events, so that I can track specific user interactions within my plugin.

**Why this priority**: This allows for flexible and detailed tracking of application-specific events.

**Independent Test**: This can be tested by calling a dedicated method or firing the supported action hook to send a custom event and verifying that the event is received by the configured driver with the same event name and properties.

**Acceptance Scenarios**:

1. **Given** the telemetry system is initialized, **When** a custom event is triggered via the public API, **Then** the event data is sent to the configured driver.
2. **Given** the telemetry system is initialized, **When** a custom event is triggered via the supported WordPress action hook, **Then** the event data is sent to the configured driver.
3. **Given** the telemetry system is initialized, **When** a custom event is sent with any event name and an optional associative properties map, **Then** the driver receives the same event name and properties.

---

### User Story 3 - Decoupled Initialization (Priority: P3)

As a plugin developer, I want to initialize the telemetry system without being dependent on OpenPanel-specific setup or milestone concepts, so that the telemetry module is self-contained and easier to maintain while still emitting the renamed activation events.

**Why this priority**: This improves the modularity and maintainability of the codebase.

**Independent Test**: This can be tested by initializing the telemetry system with only required configuration and verifying that lifecycle or custom events still dispatch, then enabling onboarding and `kui`/`aha` trigger definitions and verifying that the canonical optional event names are emitted.

**Acceptance Scenarios**:

1. **Given** onboarding and `kui`/`aha` trigger definitions are omitted, **When** the telemetry system is initialized, **Then** the initialization is successful and lifecycle or custom events can still be sent.
2. **Given** an onboarding trigger definition is configured, **When** the configured onboarding hook fires, **Then** the driver receives `activation/onboarding_completed`.
3. **Given** a `kui` or `aha` trigger definition is configured, **When** the configured milestone hook or threshold fires, **Then** the driver receives `activation/aha_reached`.

---

### Edge Cases

- When no telemetry driver is configured, the system logs a warning and does not send the event.
- When a driver send attempt fails, the system logs the failure and drops that event without interrupting plugin execution.
- When legacy setup or `kui`/`aha` instrumentation is configured, the emitted event names are normalized to the canonical `activation/*` namespace.
- When `OpenPanel` is the configured driver and optional trigger modules are omitted, lifecycle and custom events still dispatch through the selected driver.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST send the event `activation/plugin_activated` when the plugin is activated.
- **FR-002**: The system MUST send the event `activation/plugin_deactivated` when the plugin is deactivated.
- **FR-003**: The system MUST provide a public method to send custom events to the configured telemetry driver.
- **FR-004**: The telemetry initialization MUST NOT have a hard dependency on `openpanel` or on optional setup or `kui`/`aha` milestone instrumentation.
- **FR-005**: The system MUST treat `kui` and `aha` as the same entity for any related logic and event-name migration.
- **FR-006**: Onboarding-completion and `kui`/`aha` milestone telemetry MUST remain configuration-driven optional modules; omitting their trigger definitions disables those flows by default, and providing their trigger definitions enables canonical event emission.
- **FR-007**: The system MUST support both `PostHog` and `OpenPanel` as telemetry drivers.
- **FR-008**: The system MUST preserve a driver interface-based integration model so additional drivers can be added without changing the public telemetry API or core event-calling code paths.
- **FR-009**: When telemetry is invoked without a configured driver, the system MUST log a warning and return without throwing an exception.
- **FR-010**: The system MUST expose a WordPress action hook for custom events in addition to the public PHP method.
- **FR-011**: When a driver fails to send an event, the system MUST log the failure, drop that event, and continue execution without throwing an exception.
- **FR-012**: The custom event API and action hook MUST accept any event name and an optional associative properties map, and pass both to the configured driver unchanged.
- **FR-013**: The onboarding completion event MUST be emitted as `activation/onboarding_completed` instead of a legacy `setup` or `first_strike` event name.
- **FR-014**: The `kui` or `aha` milestone event MUST be emitted as `activation/aha_reached`.
- **FR-015**: When `OpenPanel` is the configured driver, lifecycle events, optional canonical milestone events, and custom events MUST continue to dispatch through that driver after initialization is decoupled from optional modules.

### Key Entities

- **Event**: Represents a telemetry event, containing a free-form event name and an optional associative properties map passed through to the configured driver. Library-owned lifecycle and milestone events use canonical names in the `activation/*` namespace.
- **Driver**: Represents a telemetry service provider (e.g., PostHog, OpenPanel) and is responsible for sending events to the service.
- **Trigger Configuration**: Represents the optional onboarding and `kui`/`aha` trigger definitions supplied by plugin code. Omitted definitions leave those flows disabled, while provided definitions register canonical `activation/*` milestone emissions.

## Assumptions

- Existing trigger timing and delivery behavior for activation and deactivation are preserved while only the emitted event names change.
- A mechanism to configure the desired driver (PostHog/OpenPanel) is already in place.
- Existing plugin integrations may still refer to `setup`, `first_strike`, `kui`, or `aha`, but the library will normalize the emitted analytics event names to the canonical `activation/*` forms.
- Omitting onboarding or `kui`/`aha` trigger definitions is the default way to disable those optional flows.

## Out of Scope

- Implementation of new drivers other than PostHog and OpenPanel.
- UI for configuring telemetry settings.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Plugin activation and deactivation emit `activation/plugin_activated` and `activation/plugin_deactivated` respectively whenever those lifecycle hooks fire in a configured environment.
- **SC-002**: Plugin developers can submit custom events through either the PHP API or the WordPress action hook with any event name and optional associative payload, without runtime exceptions caused by telemetry.
- **SC-003**: The telemetry client initializes successfully and can dispatch lifecycle or custom events even when optional onboarding or `kui`/`aha` trigger definitions are omitted.
- **SC-004**: Library-owned onboarding and milestone telemetry use only the canonical event names `activation/onboarding_completed` and `activation/aha_reached`, eliminating legacy emitted event names for those flows.
