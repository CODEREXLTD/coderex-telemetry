# Implementation Plan: Refactor Telemetry Events

**Branch**: `003-refactor-telemetry-events` | **Date**: 2026-04-02 | **Spec**: [./spec.md](./spec.md)
**Input**: Feature specification from `/specs/003-refactor-telemetry-events/spec.md`

**Note**: This template is filled in by the `/speckit.plan` command. See `.specify/templates/plan-template.md` for the execution workflow.

## Summary

This plan outlines the refactoring of the telemetry event system. The `first_strike` event will be completely removed, and a new common event, `retention/feature_used`, will be introduced to track the usage of core plugin features. This new event will be triggered via a WordPress action hook, with optional parameters.

## Technical Context

**Language/Version**: PHP >=7.4
**Primary Dependencies**: `posthog/posthog-php: ^2.1`
**Storage**: N/A
**Testing**: `phpunit/phpunit: ^9.5`, `mockery/mockery: ^1.5`
**Target Platform**: WordPress
**Project Type**: Library
**Performance Goals**: Event dispatch should not add more than 50ms to any request.
**Constraints**: Must maintain compatibility with WordPress versions 5.0 and higher.
**Scale/Scope**: The system should handle up to 1,000 events per minute without performance degradation.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Consent-First Telemetry**: Pass. The changes do not affect consent mechanisms.
- **II. Stable Library Contracts**: Pass. The `first_strike` event is being removed, which is a breaking change, but it's explicitly authorized by the spec. A new, stable contract is being introduced for `retention/feature_used`.
- **III. Non-Fatal Delivery**: Pass. The changes should not introduce any new failure modes.
- **IV. Compatibility Requires Tests**: Pass. New tests will be required for the `retention/feature_used` event.
- **V. Minimal WordPress Coupling**: Pass. The new event is designed to be generic and triggered by a standard WordPress hook.

## Project Structure

### Documentation (this feature)

```text
specs/003-refactor-telemetry-events/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output (/speckit.plan command)
├── data-model.md        # Phase 1 output (/speckit.plan command)
├── quickstart.md        # Phase 1 output (/speckit.plan command)
├── contracts/
│   ├── php-api.md
│   └── wordpress-hooks.md
└── tasks.md             # Phase 2 output (/speckit.tasks command - NOT created by /speckit.plan)
```

### Source Code (repository root)
```text
src/
├── Client.php
├── Consent.php
├── Deactivation.php
├── EventDispatcher.php
├── helpers.php
├── Queue.php
├── TriggerManager.php
├── Drivers/
│   ├── DriverInterface.php
│   ├── NullDriver.php
│   ├── OpenPanelDriver.php
│   └── PostHogDriver.php
└── Helpers/
    └── Utils.php

tests/
├── ClientTest.php
├── EventDispatcherTest.php
└── TriggerManagerTest.php
```

**Structure Decision**: The existing project structure will be maintained. New logic will be added to `src/Client.php` and a new test file will be created in `tests/`.

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
|           |            |                                     |
