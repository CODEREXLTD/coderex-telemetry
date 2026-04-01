# Tasks: Refactor Telemetry Initialization

**Input**: Design documents from `/specs/002-refactor-telemetry-init/`
**Prerequisites**: plan.md (required), spec.md (required for user stories), research.md, data-model.md, contracts/

**Tests**: PHPUnit coverage is required for this feature because the specification defines independent test criteria for each user story and the plan explicitly calls for updated unit coverage.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Add the minimal test and execution scaffolding needed to implement and verify the refactor safely.

- [ ] T001 Create PHPUnit configuration for library-level client tests in /Users/sadi/Desktop/lab/coderex-telemetry/phpunit.xml.dist
- [ ] T002 [P] Add reusable WordPress function stubs and test bootstrap helpers in /Users/sadi/Desktop/lab/coderex-telemetry/tests/bootstrap.php
- [ ] T003 [P] Create client and trigger regression test shells in /Users/sadi/Desktop/lab/coderex-telemetry/tests/ClientTest.php and /Users/sadi/Desktop/lab/coderex-telemetry/tests/TriggerManagerTest.php
- [ ] T004 [P] Create dispatcher-focused regression test shell in /Users/sadi/Desktop/lab/coderex-telemetry/tests/EventDispatcherTest.php

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Establish shared runtime behavior required by every story before feature-specific work begins.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [ ] T005 Add a safe no-op driver and driver resolution fallback in /Users/sadi/Desktop/lab/coderex-telemetry/src/Drivers/NullDriver.php and /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php
- [ ] T006 Add shared warning and failure logging for missing drivers and failed sends in /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php and /Users/sadi/Desktop/lab/coderex-telemetry/src/EventDispatcher.php
- [ ] T007 Add regression coverage for missing-driver warning logs and non-throw behavior in /Users/sadi/Desktop/lab/coderex-telemetry/tests/ClientTest.php and /Users/sadi/Desktop/lab/coderex-telemetry/tests/EventDispatcherTest.php
- [ ] T008 Normalize plugin version and driver configuration handoff in /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php and /Users/sadi/Desktop/lab/coderex-telemetry/src/EventDispatcher.php
- [ ] T009 Register the generic `<slug>_telemetry_track` custom-event bootstrap during client initialization in /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php
- [ ] T010 Add regression coverage for OpenPanel driver selection and send-failure logging in /Users/sadi/Desktop/lab/coderex-telemetry/tests/ClientTest.php and /Users/sadi/Desktop/lab/coderex-telemetry/tests/EventDispatcherTest.php

**Checkpoint**: Driver selection, logging, foundational regression coverage, dispatcher metadata, and the generic custom-event hook are ready for story work.

---

## Phase 3: User Story 1 - Core Event Tracking (Priority: P1) 🎯 MVP

**Goal**: Emit canonical activation lifecycle events without changing activation and deactivation timing semantics.

**Independent Test**: Activate and deactivate the plugin with a configured driver and verify the driver receives `activation/plugin_activated` and `activation/plugin_deactivated` with the existing minimal lifecycle payload rules.

### Tests for User Story 1

- [ ] T011 [P] [US1] Add activation and deactivation lifecycle coverage in /Users/sadi/Desktop/lab/coderex-telemetry/tests/ClientTest.php
- [ ] T012 [P] [US1] Add minimal lifecycle dispatch assertions in /Users/sadi/Desktop/lab/coderex-telemetry/tests/EventDispatcherTest.php

### Implementation for User Story 1

- [ ] T013 [US1] Rename activation and deactivation emissions to `activation/plugin_activated` and `activation/plugin_deactivated` in /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php
- [ ] T014 [US1] Preserve minimal lifecycle payload handling and successful send bookkeeping in /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php and /Users/sadi/Desktop/lab/coderex-telemetry/src/EventDispatcher.php

**Checkpoint**: Lifecycle tracking emits only canonical activation events and remains independently testable.

---

## Phase 4: User Story 2 - Custom Event Tracking (Priority: P2)

**Goal**: Let plugin developers submit arbitrary custom telemetry events through both the PHP API and a WordPress action hook.

**Independent Test**: Trigger a custom event through `Client::track()` and through `<plugin-slug>_telemetry_track`, then verify the configured driver receives the original event name and properties unchanged.

### Tests for User Story 2

- [ ] T015 [P] [US2] Add public custom-event API coverage for strict pass-through event names and properties in /Users/sadi/Desktop/lab/coderex-telemetry/tests/ClientTest.php
- [ ] T016 [P] [US2] Add WordPress action custom-event routing coverage for strict pass-through event names and properties in /Users/sadi/Desktop/lab/coderex-telemetry/tests/TriggerManagerTest.php
- [ ] T017 [US2] Add consent-path regression coverage proving opt-in gated custom events continue to follow the existing queued dispatch path in /Users/sadi/Desktop/lab/coderex-telemetry/tests/ClientTest.php and /Users/sadi/Desktop/lab/coderex-telemetry/tests/EventDispatcherTest.php

### Implementation for User Story 2

- [ ] T018 [US2] Route `Client::track()` through the unchanged event-name and properties contract in /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php and /Users/sadi/Desktop/lab/coderex-telemetry/src/EventDispatcher.php
- [ ] T019 [US2] Preserve the existing consent-gated queued dispatch path for custom events while keeping canonical lifecycle events on the immediate path in /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php and /Users/sadi/Desktop/lab/coderex-telemetry/src/EventDispatcher.php
- [ ] T020 [US2] Implement the `<slug>_telemetry_track` action handler in /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php
- [ ] T021 [US2] Ensure custom trigger callbacks reuse the same dispatch path as direct custom events in /Users/sadi/Desktop/lab/coderex-telemetry/src/TriggerManager.php and /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php

**Checkpoint**: Plugin code can send arbitrary custom events through either entrypoint without needing story 3 behavior.

---

## Phase 5: User Story 3 - Decoupled Initialization (Priority: P3)

**Goal**: Allow client initialization to succeed without OpenPanel-specific onboarding or milestone configuration while normalizing optional module events to canonical names.

**Independent Test**: Initialize the client with only required config and verify it boots successfully with optional trigger definitions omitted, then separately enable onboarding and `kui` or `aha` trigger definitions and verify they emit `activation/onboarding_completed` and `activation/aha_reached`.

### Tests for User Story 3

- [ ] T022 [P] [US3] Add initialization coverage proving optional trigger definitions are disabled by omission in /Users/sadi/Desktop/lab/coderex-telemetry/tests/ClientTest.php
- [ ] T023 [P] [US3] Add trigger coverage proving configured onboarding and `kui` or `aha` definitions emit canonical event names in /Users/sadi/Desktop/lab/coderex-telemetry/tests/TriggerManagerTest.php

### Implementation for User Story 3

- [ ] T024 [US3] Remove required OpenPanel-only initialization assumptions and allow safe no-driver boot in /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php
- [ ] T025 [US3] Treat omitted onboarding and `kui`/`aha` trigger definitions as disabled-by-default optional modules in /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php and /Users/sadi/Desktop/lab/coderex-telemetry/src/TriggerManager.php
- [ ] T026 [US3] Normalize setup and first_strike telemetry to `activation/onboarding_completed` in /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php and /Users/sadi/Desktop/lab/coderex-telemetry/src/TriggerManager.php
- [ ] T027 [US3] Normalize kui and aha milestone telemetry to `activation/aha_reached` while keeping trigger registration optional in /Users/sadi/Desktop/lab/coderex-telemetry/src/Client.php and /Users/sadi/Desktop/lab/coderex-telemetry/src/TriggerManager.php

**Checkpoint**: Initialization is decoupled from optional modules, and optional onboarding and milestone flows emit only canonical activation events.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Update public examples and documentation, then verify the integrated behavior.

- [ ] T028 [P] Update example plugin integration for config-array construction, optional trigger omission, and optional trigger usage in /Users/sadi/Desktop/lab/coderex-telemetry/examples/test-plugin/test-telemetry-plugin.php
- [ ] T029 [P] Update SDK documentation for canonical event names, custom hook usage, OpenPanel compatibility, consent-gated queued custom-event behavior, and non-fatal driver behavior in /Users/sadi/Desktop/lab/coderex-telemetry/README.md and /Users/sadi/Desktop/lab/coderex-telemetry/PRIVACY_GUIDELINE.md
- [X] T030 Run the verification flow documented in /Users/sadi/Desktop/lab/coderex-telemetry/specs/002-refactor-telemetry-init/quickstart.md

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1: Setup** has no dependencies and starts immediately.
- **Phase 2: Foundational** depends on Phase 1 and blocks all user stories.
- **Phase 3: User Story 1** depends on Phase 2 and is the recommended MVP slice.
- **Phase 4: User Story 2** depends on Phase 2 and can proceed independently of User Story 3.
- **Phase 5: User Story 3** depends on Phase 2 and can proceed independently of User Story 2.
- **Phase 6: Polish** depends on completion of the stories being shipped.

### User Story Dependencies

- **US1 (P1)**: Starts after Phase 2 and has no dependency on other user stories.
- **US2 (P2)**: Starts after Phase 2 and does not require US1 or US3 to be functionally complete.
- **US3 (P3)**: Starts after Phase 2 and does not require US1 or US2 to be functionally complete.

### Recommended Delivery Order

1. Finish Phase 1.
2. Finish Phase 2.
3. Deliver US1 as the MVP.
4. Deliver US2.
5. Deliver US3.
6. Finish polish and validation.

### Parallel Opportunities

- T002, T003, and T004 can run in parallel after T001.
- T015 and T016 can run in parallel within US2.
- T011 and T012 can run in parallel within US1.
- T022 and T023 can run in parallel within US3.
- T028 and T029 can run in parallel during polish.

---

## Parallel Example: User Story 1

```bash
# Run both US1 tests in parallel once the setup and foundation are ready:
Task: T011 Add activation and deactivation lifecycle coverage in tests/ClientTest.php
Task: T012 Add minimal lifecycle dispatch assertions in tests/EventDispatcherTest.php
```

## Parallel Example: User Story 2

```bash
# Run both US2 contract checks in parallel:
Task: T015 Add public custom-event API coverage in tests/ClientTest.php
Task: T016 Add WordPress action custom-event routing coverage in tests/TriggerManagerTest.php
```

## Parallel Example: User Story 3

```bash
# Run both US3 regression suites in parallel:
Task: T022 Add initialization coverage for omitted optional trigger definitions in tests/ClientTest.php
Task: T023 Add canonical optional-trigger coverage in tests/TriggerManagerTest.php
```

---

## Implementation Strategy

### MVP First

1. Complete Phase 1: Setup.
2. Complete Phase 2: Foundational.
3. Complete Phase 3: User Story 1.
4. Validate lifecycle event renaming before continuing.

### Incremental Delivery

1. Ship US1 to stabilize the canonical activation taxonomy.
2. Add US2 to expose the custom event contract.
3. Add US3 to decouple optional modules and normalize legacy aliases.
4. Finish with documentation, example updates, and quickstart verification.

### Team Strategy

1. One developer completes Phase 1 and Phase 2.
2. After Phase 2, split US1, US2, and US3 across developers if needed.
3. Rejoin for Phase 6 documentation and verification.

---

## Notes

- [P] tasks touch different files and do not depend on unfinished parallel tasks.
- Each user story phase is independently testable against the acceptance criteria in spec.md.
- The only active feature directory detected for this repository is `/Users/sadi/Desktop/lab/coderex-telemetry/specs/002-refactor-telemetry-init`.