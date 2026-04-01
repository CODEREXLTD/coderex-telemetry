# Tasks: Refactor Telemetry Events

**Feature**: Refactor Telemetry Events
**Spec**: [./spec.md](./spec.md)

This document outlines the tasks required to refactor the telemetry event system, as specified in the implementation plan and feature specification.

## Phase 1: Setup

*No setup tasks are required for this feature.*

## Phase 2: Foundational Tasks

*No foundational tasks are required for this feature.*

## Phase 3: User Story 1: Remove `first_strike` Event

**Goal**: Completely remove the `first_strike` event and its related logic from the codebase.
**Independent Test Criteria**: A global search for "first_strike" in the project returns no functional code. The `README.md` no longer mentions the event.

### Implementation Tasks

- [X] T001 [US1] Search for and remove all occurrences of the `first_strike` event from the `src/` directory.
- [X] T002 [US1] Search for and remove any tests related to the `first_strike` event in the `tests/` directory.
- [X] T003 [US1] Update `README.md` to remove any mention of the `first_strike` event.

## Phase 4: User Story 2: Implement `retention/feature_used` Event

**Goal**: Introduce a new `retention/feature_used` event that can be triggered via a WordPress action hook.
**Independent Test Criteria**: A developer can use the new static method on the `Client` class to hook into a WordPress action and trigger a `retention/feature_used` event with custom parameters. The event is correctly formatted and sent to the telemetry backend.

### Implementation Tasks

- [X] T004 [US2] In `src/Client.php`, add a new public static method `add_feature_used_event` that accepts a hook name, a feature name, and an optional array of parameters.
- [X] T005 [US2] Inside `add_feature_used_event`, use `add_action` to register a callback function for the specified hook.
- [X] T006 [US2] The callback function should call the `track` method with the event name `retention/feature_used` and the provided feature name and parameters.
- [X] T007 [US2] Create a new test file `tests/ClientTest.php` if it doesn't exist, or add to the existing one.
- [X] T008 [US2] Write a unit test for the `add_feature_used_event` method to verify that `add_action` is called with the correct parameters.
- [X] T009 [US2] Write a unit test to verify that the callback function, when triggered, calls the `track` method with the correct event data.
- [X] T010 [US2] Update `README.md` to document the new `retention/feature_used` event and the `add_feature_used_event` method, including a clear code example.

## Phase 5: Polish & Cross-Cutting Concerns

**Goal**: Provide a working example of the new `retention/feature_used` event.
**Independent Test Criteria**: The example plugin in the `examples/` directory correctly uses the new event.

### Implementation Tasks

- [X] T011 [P] Update the example plugin in `examples/test-plugin/test-telemetry-plugin.php` to use the new `add_feature_used_event` method.
- [X] T012 [P] Update the `README.md` in `examples/test-plugin/` to reflect the changes.

## Dependencies

- **User Story 1** must be completed before starting User Story 2, as it simplifies the codebase.

## Parallel Execution

- Tasks within each user story phase can be executed in order.
- Tasks T011 and T012 can be done in parallel with other tasks after T004 is complete.

## Implementation Strategy

The implementation will follow an MVP approach. First, the `first_strike` event will be removed. Then, the new `retention/feature_used` event will be implemented and tested. Finally, the documentation and examples will be updated. This ensures that the core functionality is delivered and validated before the final polish.
