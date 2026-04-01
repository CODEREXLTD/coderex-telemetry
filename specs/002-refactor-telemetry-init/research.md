# Phase 0 Research

## Decision 1: Make onboarding-completion and `kui`/`aha` tracking optional modules instead of required initialization behavior

**Decision**: Keep activation and deactivation registration in `Client::init()`, but keep onboarding-completion and `kui`/`aha` milestone tracking behind explicit trigger registration and an action-based custom-event entrypoint.

**Rationale**: The feature requires telemetry initialization to succeed without OpenPanel-specific lifecycle concepts. Activation and deactivation are existing core lifecycle events and already work independently. Onboarding completion and AHA milestone tracking are higher-level product instrumentation concerns and should not be coupled to driver initialization.

**Alternatives considered**:

- Keep the current trigger wiring and only rename methods. Rejected because the coupling problem remains.
- Remove trigger support entirely. Rejected because optional automatic tracking is still a supported use case.

## Decision 2: Normalize library-owned telemetry to the canonical `activation/*` event namespace

**Decision**: Rename lifecycle events to `activation/plugin_activated` and `activation/plugin_deactivated`, emit onboarding completion as `activation/onboarding_completed`, and emit `kui` or `aha` milestones as `activation/aha_reached`.

**Rationale**: The user wants a stable event taxonomy rather than legacy literal event names such as `plugin_activated`, `plugin_deactivated`, `setup`, `first_strike`, or `kui`. A single canonical namespace reduces downstream analytics branching and makes driver behavior consistent.

**Alternatives considered**:

- Keep separate `aha` and `kui` implementations. Rejected because it introduces ambiguous semantics the spec explicitly wants to remove.
- Rename everything to `aha`. Rejected because the codebase and documentation already use `kui`, so aliasing is the least disruptive path.

## Decision 3: Support custom events through both a public PHP method and a WordPress action hook

**Decision**: Preserve the direct PHP event path through the client and add a documented WordPress action contract that accepts an event name plus an optional associative properties array.

**Rationale**: The user explicitly wants custom events to be easy to send from plugin code and from plugin hooks. WordPress actions are the lowest-friction integration point for plugin authors, while a PHP method gives direct programmatic control.

**Alternatives considered**:

- Support only a PHP method. Rejected because it fails FR-010.
- Support only a WordPress hook. Rejected because it makes internal programmatic dispatch less discoverable and harder to test directly.

## Decision 4: Missing drivers and send failures should log and no-op

**Decision**: Introduce a safe dispatch path where missing driver configuration and send failures are logged and dropped without throwing exceptions during normal event submission.

**Rationale**: The clarified behavior requires non-fatal telemetry failures. Telemetry must not break plugin execution.

**Alternatives considered**:

- Throw exceptions on missing drivers. Rejected because it violates FR-009 and FR-011.
- Silently ignore failures. Rejected because the spec explicitly requires logging warnings or failures.

## Decision 5: Keep the existing driver abstraction and add tests around the new contract points

**Decision**: Retain `DriverInterface`, `OpenPanelDriver`, and `PostHogDriver`, and expand test coverage around client-level event routing, alias normalization, and error handling rather than redesigning the driver layer.

**Rationale**: The current driver abstraction already supports the stated requirement for easy future extension. The refactor is about initialization and event routing, not a new transport model.

**Alternatives considered**:

- Replace drivers with a factory or container abstraction. Rejected as unnecessary complexity for this feature.
- Only test drivers. Rejected because the new behavior is centered in the client and trigger orchestration layers.

## Decision 6: Use the queue path for consented custom events and retain immediate dispatch for canonical lifecycle events

**Decision**: Keep activation and deactivation on the existing immediate minimal-dispatch path, while custom events continue to use the queued or existing consent-gated flow.

**Rationale**: This preserves the package's current behavior and avoids introducing latency or delivery regressions for lifecycle events. It also minimizes the amount of change required for the refactor.

**Alternatives considered**:

- Move all events to immediate dispatch. Rejected because it changes consented-event behavior and request cost.
- Move lifecycle events to the queue. Rejected because activation and deactivation are already expected to fire independently of the queueing lifecycle.