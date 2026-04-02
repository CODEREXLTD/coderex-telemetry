# Tasks: Constructor Backward Compatibility

**Input**: Design documents from `/specs/005-constructor-backward-compat/`
**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/ ✓, quickstart.md ✓

**Tests**: Tests are included as they are integral to verifying backward compatibility — the core goal of this feature.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- IDs use `BC-` prefix to avoid collision with existing test IDs in `tests/ClientTest.php`
- Include exact file paths in descriptions

## Phase 1: Setup

**Purpose**: Test infrastructure needed for backward-compatibility testing

- [X] BC-001 Add `sanitize_title()` stub to tests/bootstrap.php with simplified slug derivation: `strtolower(preg_replace('/[^a-z0-9\-_]/', '', preg_replace('/\s+/', '-', $title)))`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core constructor changes that ALL user stories depend on

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [X] BC-002 Change `Client::__construct()` signature from `array $config` to `$configOrApiKey, string $apiSecret = '', string $pluginName = '', string $pluginFile = ''` in src/Client.php
- [X] BC-003 Add `is_array($configOrApiKey)` branch in `__construct()` that passes through to existing array-based initialization logic in src/Client.php
- [X] BC-004 Add `is_string($configOrApiKey)` branch in `__construct()` that calls a private `buildLegacyConfig()` method in src/Client.php
- [X] BC-005 Add `else` branch that throws `InvalidArgumentException` with message "First argument must be a configuration array or a string API key" in src/Client.php
- [X] BC-006 Implement private `buildLegacyConfig(string $apiKey, string $apiSecret, string $pluginName, string $pluginFile): array` method skeleton in src/Client.php — returns array with direct field assignments (`apiKey`, `apiSecret`, `pluginName`, `pluginFile`) per data-model.md. Derived fields (`slug`, `driver`, `version`, `unique_id`) are added in Phase 4 tasks.

**Checkpoint**: Constructor accepts both signatures — user story implementation can now begin

---

## Phase 3: User Story 1 — Mixed-Version Coexistence Without Fatal Errors (Priority: P1) 🎯 MVP

**Goal**: Both old-style (4-param) and new-style (array) constructor calls succeed without PHP fatal errors

**Independent Test**: Instantiate Client with both calling conventions in the same test suite and confirm no errors

### Tests for User Story 1

- [X] BC-007 [P] [US1] Add test `testArrayConstructorContinuesToWork` verifying `new Client(array $config)` works identically to current behavior in tests/ClientTest.php
- [X] BC-008 [P] [US1] Add test `testLegacyFourParamConstructorDoesNotThrow` verifying `new Client('key', 'secret', 'My Plugin', '/path/plugin.php')` creates a valid Client instance in tests/ClientTest.php
- [X] BC-009 [P] [US1] Add test `testInvalidFirstArgThrowsException` verifying `new Client(42)` throws `InvalidArgumentException` with expected message in tests/ClientTest.php
- [X] BC-010 [P] [US1] Add test `testTooFewPositionalParamsThrowsException` verifying `new Client('only-one-string')` throws `InvalidArgumentException` with message "Legacy constructor requires exactly 4 string parameters" in tests/ClientTest.php
- [X] BC-011 [P] [US1] Add test `testEmptyApiKeyThrowsException` verifying `new Client('', 'secret', 'Name', '/path.php')` throws `InvalidArgumentException` in tests/ClientTest.php
- [X] BC-012 [P] [US1] Add test `testEmptyPluginFileThrowsException` verifying `new Client('key', 'secret', 'Name', '')` throws `InvalidArgumentException` in tests/ClientTest.php
- [X] BC-013 [P] [US1] Add test `testEmptyPluginNameThrowsException` verifying `new Client('key', 'secret', '', '/path.php')` throws `InvalidArgumentException` in tests/ClientTest.php

### Implementation for User Story 1

- [X] BC-014 [US1] Add param-count guard at the top of the `is_string` branch in `__construct()`: if `$apiSecret`, `$pluginName`, or `$pluginFile` are all empty-string defaults, throw `InvalidArgumentException("Legacy constructor requires exactly 4 string parameters")` in src/Client.php
- [X] BC-015 [US1] Add legacy-path validation in `buildLegacyConfig()`: reject empty `$apiKey` with `InvalidArgumentException("API key must not be empty")` in src/Client.php
- [X] BC-016 [US1] Add legacy-path validation in `buildLegacyConfig()`: reject empty `$pluginFile` with `InvalidArgumentException("Plugin file path must not be empty")` in src/Client.php
- [X] BC-017 [US1] Add legacy-path validation in `buildLegacyConfig()`: reject empty `$pluginName` with `InvalidArgumentException("Plugin name must not be empty")` in src/Client.php
- [X] BC-018 [US1] Verify all existing tests in tests/ClientTest.php still pass without modification (run `vendor/bin/phpunit --filter ClientTest`)

**Checkpoint**: Both constructor forms work; all validation enforced; existing tests pass

---

## Phase 4: User Story 2 — Telemetry Functions Correctly for Both Plugins (Priority: P2)

**Goal**: Legacy callers produce correctly-mapped config so telemetry events contain accurate metadata (plugin name, version, unique ID, driver)

**Independent Test**: Instantiate Client with legacy params, inspect internal config fields to confirm correct mapping

### Tests for User Story 2

- [X] BC-019 [P] [US2] Add test `testLegacyConstructorMapsApiKey` verifying `$client->getConfig()['apiKey']` equals the passed API key in tests/ClientTest.php
- [X] BC-020 [P] [US2] Add test `testLegacyConstructorMapsPluginName` verifying `$client->getConfig()['pluginName']` equals the passed plugin name in tests/ClientTest.php
- [X] BC-021 [P] [US2] Add test `testLegacyConstructorDerivesSlug` verifying `$client->getConfig()['slug']` equals `sanitize_title('My Plugin')` (i.e., `'my-plugin'`) in tests/ClientTest.php
- [X] BC-022 [P] [US2] Add test `testLegacyConstructorDefaultsDriverToOpenPanel` verifying `$client->getConfig()['driver']` equals `'open_panel'` in tests/ClientTest.php
- [X] BC-023 [P] [US2] Add test `testLegacyConstructorGeneratesUniqueId` verifying `$client->getConfig()['unique_id']` is a non-empty string in tests/ClientTest.php

### Implementation for User Story 2

- [X] BC-024 [US2] Implement `slug` derivation in `buildLegacyConfig()`: call `sanitize_title($pluginName)` in src/Client.php
- [X] BC-025 [US2] Implement `driver` default in `buildLegacyConfig()`: hardcode `'open_panel'` in src/Client.php
- [X] BC-026 [US2] Add public `getConfig(): array` accessor method returning `$this->config` in src/Client.php (needed for test assertions; returns a copy to prevent mutation)

**Checkpoint**: Legacy constructor produces identical config structure; telemetry metadata is correct

---

## Phase 5: User Story 3 — Plugin Developer Migration Path (Priority: P3)

**Goal**: The legacy constructor emits a clear deprecation notice guiding developers to migrate to the array config format

**Independent Test**: Verify that `E_USER_DEPRECATED` is triggered with the correct message when using the legacy constructor

### Tests for User Story 3

- [X] BC-027 [P] [US3] Add test `testLegacyConstructorEmitsDeprecationNotice` using PHPUnit `$this->expectDeprecation()` and `$this->expectDeprecationMessage('Passing positional parameters to')` in tests/ClientTest.php
- [X] BC-028 [P] [US3] Add test `testArrayConstructorDoesNotEmitDeprecation` verifying no deprecation is emitted when using the array form in tests/ClientTest.php

### Implementation for User Story 3

- [X] BC-029 [US3] Add `trigger_error()` call with `E_USER_DEPRECATED` and message `"Passing positional parameters to Linno\Telemetry\Client::__construct() is deprecated. Use an array configuration instead. See https://github.com/user/coderex-telemetry#migration for details. This will be removed in the next major version."` in the legacy branch of `__construct()` in src/Client.php

**Checkpoint**: Deprecation notice works; array callers are unaffected

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Validation, documentation, and cleanup

- [X] BC-030 [P] Update constructor docblock in src/Client.php to document both calling conventions with `@param` tags for the new signature
- [X] BC-031 [P] Update quickstart.md in specs/005-constructor-backward-compat/quickstart.md: correct any code samples after implementation AND add a "Known Limitations" section documenting that if the old library version (without backward compat) is autoloaded first, new-style callers will get a TypeError — recommend minimum library version
- [X] BC-032 Run full test suite via `vendor/bin/phpunit` to confirm zero regressions across all test files
- [X] BC-033 Run quickstart.md validation: manually verify both code examples from quickstart.md work against the implementation

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — can start immediately
- **Foundational (Phase 2)**: Depends on Setup (BC-001) — BLOCKS all user stories
- **US1 (Phase 3)**: Depends on Foundational (Phase 2) completion
- **US2 (Phase 4)**: Depends on Foundational (Phase 2) completion; can run in parallel with US1
- **US3 (Phase 5)**: Depends on Foundational (Phase 2) completion; can run in parallel with US1/US2
- **Polish (Phase 6)**: Depends on all user stories being complete

### User Story Dependencies

- **User Story 1 (P1)**: Can start after Phase 2 — no dependencies on other stories
- **User Story 2 (P2)**: Can start after Phase 2 — independent of US1 (but BC-026 `getConfig()` is needed for its own tests)
- **User Story 3 (P3)**: Can start after Phase 2 — independent of US1 and US2

### Within Each User Story

- Tests MUST be written and FAIL before implementation
- Validation before field mapping
- Core implementation before integration
- Story complete before moving to next priority

### Parallel Opportunities

- **Phase 3 (US1)**: All test tasks BC-007–BC-013 can run in parallel
- **Phase 4 (US2)**: All test tasks BC-019–BC-023 can run in parallel
- **Phase 5 (US3)**: Test tasks BC-027–BC-028 can run in parallel
- **Phase 6**: BC-030 and BC-031 can run in parallel
- **Cross-story**: US1, US2, and US3 can all begin in parallel after Phase 2 completes (if team capacity allows)

---

## Parallel Example: User Story 1

```text
BC-007 ──┐
BC-008 ──┤
BC-009 ──┤
BC-010 ──┼──► All tests written & failing
BC-011 ──┤
BC-012 ──┤
BC-013 ──┘
            ↓
BC-014 ──► BC-015 ──► BC-016 ──► BC-017 ──► BC-018 (sequential: param-count + validation + regression)
```

## Parallel Example: Cross-Story (after Phase 2)

```text
Phase 2 complete
       ↓
US1 (Phase 3) ──┐
US2 (Phase 4) ──┼──► All stories complete ──► Phase 6 (Polish)
US3 (Phase 5) ──┘
```

---

## Implementation Strategy

1. **MVP (Phase 1 + 2 + 3)**: Setup + Foundational constructor change + US1 validation = both constructor forms work without fatal errors. This alone solves the critical site-crash bug.
2. **Increment 2 (Phase 4)**: US2 ensures telemetry data is correct for legacy callers — essential for production use.
3. **Increment 3 (Phase 5)**: US3 adds the deprecation notice — provides the migration path for developers.
4. **Finalize (Phase 6)**: Polish, documentation, full regression test.
