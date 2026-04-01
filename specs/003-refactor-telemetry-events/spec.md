# Feature Specification: Refactor Telemetry Events

**Feature Branch**: `003-refactor-telemetry-events`
**Created**: 2026-04-02
**Status**: Draft
**Input**: User description: "first_strike and activation/onboarding_completed is not same. so for first strike activation/onboarding_completed will not be called. this is written in readme. I am not sure this is implemented on code or not. lets remove first_strike completely. Also, I need to introduce another common event retention/feature_used. this will be called if core feature is used. after core feature used by the user, hook will be declared. and on that hook event will be sent. retention/feature_used will be same for all plugin, only feature name, and action hook will be different. it can also support params or not. Need to update readme as well."

## 1. Overview

This specification outlines the refactoring of the telemetry event system. The key changes are the complete removal of the `first_strike` event and the introduction of a new, common event named `retention/feature_used`. This new event will be used to track the usage of core features within plugins.

## 2. User Scenarios & Testing

### Scenario 1: Removal of `first_strike`

- **As a**: Developer
- **I want**: To have the `first_strike` event and its related logic completely removed from the codebase.
- **So that**: The telemetry system is simplified and no longer uses this confusing and redundant event.

**Acceptance Criteria:**
- A search for "first_strike" in the entire codebase yields no results related to event triggering or handling.
- The `activation/onboarding_completed` event is not affected and continues to function as before.
- The `README.md` file is updated to remove any mention of `first_strike`.

### Scenario 2: Introduction of `retention/feature_used`

- **As a**: Developer
- **I want**: To be able to trigger a `retention/feature_used` event when a user utilizes a core feature of a plugin.
- **So that**: We can track user engagement and retention based on feature adoption.

**Acceptance Criteria:**
- A new event `retention/feature_used` is available.
- This event can be triggered by a WordPress action hook.
- The event can optionally accept additional parameters (`params`) to provide more context about the feature usage.
- The `README.md` file is updated to document the new `retention/feature_used` event, including how to use it with action hooks and parameters.
- An example of how to use the new event is provided in the `examples` directory.

## 3. Functional Requirements

### FR-1: Remove `first_strike` Event
- All code related to the `first_strike` event must be deleted. This includes any conditions, function calls, or documentation.
- The system must not trigger `activation/onboarding_completed` as part of the `first_strike` logic.

### FR-2: Implement `retention/feature_used` Event
- A new event `retention/feature_used` will be created.
- The system must provide a helper function or method that allows developers to register a WordPress action hook which, when fired, triggers the `retention/feature_used` event.
- The registration function should take the action hook name, a feature name, and optionally, an array of parameters to be sent with the event.

### FR-3: Update Documentation
- The `README.md` file must be updated to reflect the removal of `first_strike` and the addition of `retention/feature_used`.
- The documentation for `retention/feature_used` must be clear and provide a code example.

## 4. Out of Scope
- This refactor will not change the underlying telemetry driver or the way events are dispatched.
- No other events will be modified in this task.

## 5. Assumptions
- Developers using this library are familiar with WordPress action hooks.
- The `retention/feature_used` event will be generic enough to be used across multiple plugins with different core features.

- **[Entity 2]**: [What it represents, relationships to other entities]

## 6. Clarifications

### Session 2026-04-02
- Q: What should be the data type for the `params` field for the `retention/feature_used` event? → A: A key-value map (object/dictionary) with string keys and string/number values.
- Q: Where should the new function to register the `retention/feature_used` event hook be located? → A: A new public static method on the `Client` class.

## 7. Success Criteria *(mandatory)*

<!--
  ACTION REQUIRED: Define measurable success criteria.
  These must be technology-agnostic and measurable.
-->

### Measurable Outcomes

- **SC-001**: The `first_strike` event is completely removed from the codebase; a global search for the term yields zero results in functional code.
- **SC-002**: The `retention/feature_used` event can be successfully triggered via a WordPress action hook, and the event data is verifiably received by the telemetry backend with the correct structure.
- **SC-003**: The refactoring introduces no new fatal errors or uncaught exceptions in the telemetry system, maintaining a 100% error-free operation under normal conditions.
- **SC-004**: The updated `README.md` and example plugin provide clear, actionable guidance, enabling a developer to implement the `retention/feature_used` event in under 10 minutes.

## Assumptions

<!--
  ACTION REQUIRED: The content in this section represents placeholders.
  Fill them out with the right assumptions based on reasonable defaults
  chosen when the feature description did not specify certain details.
-->

- [Assumption about target users, e.g., "Users have stable internet connectivity"]
- [Assumption about scope boundaries, e.g., "Mobile support is out of scope for v1"]
- [Assumption about data/environment, e.g., "Existing authentication system will be reused"]
- [Dependency on existing system/service, e.g., "Requires access to the existing user profile API"]
