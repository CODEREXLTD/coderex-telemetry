# Implementation Plan: Constructor Backward Compatibility

**Branch**: `005-constructor-backward-compat` | **Date**: 2026-04-02 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/005-constructor-backward-compat/spec.md`

## Summary

Make the `Client` constructor accept both the current `array $config` signature and the legacy 4-positional-parameter signature (`$apiKey, $apiSecret, $pluginName, $pluginFile`) to prevent fatal errors when multiple WordPress plugins bundle different versions of the Linno Telemetry library. The constructor detects the calling convention by inspecting the first argument's type, maps positional params to the internal config array, defaults to the `open_panel` driver, and emits a `E_USER_DEPRECATED` notice for legacy callers.

## Technical Context

**Language/Version**: PHP >=7.4  
**Primary Dependencies**: posthog/posthog-php ^2.1  
**Storage**: WordPress options table (`wp_options`) — no schema changes needed  
**Testing**: PHPUnit ^9.5, Mockery ^1.5  
**Target Platform**: WordPress (PHP 7.4+ on any hosting environment)  
**Project Type**: Library (Composer package)  
**Performance Goals**: N/A — constructor-level change with negligible runtime cost  
**Constraints**: Must not break existing public API; must handle WordPress autoloading conflicts where only one `Client` class is loaded per request  
**Scale/Scope**: Single class (`Client.php`) constructor modification + new tests

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

The project constitution (`/.specify/memory/constitution.md`) contains only unfilled template placeholders — no project-specific principles, constraints, or governance rules have been ratified.

**Result**: PASS — no gates to evaluate. Proceeding to Phase 0.

## Project Structure

### Documentation (this feature)

```text
specs/[###-feature]/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output (/speckit.plan command)
├── data-model.md        # Phase 1 output (/speckit.plan command)
├── quickstart.md        # Phase 1 output (/speckit.plan command)
├── contracts/           # Phase 1 output (/speckit.plan command)
└── tasks.md             # Phase 2 output (/speckit.tasks command - NOT created by /speckit.plan)
```

### Source Code (repository root)

```text
src/
├── Client.php           # Primary change target — constructor modification
├── Consent.php
├── Deactivation.php
├── EventDispatcher.php  # No changes needed (already accepts array config)
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
├── bootstrap.php
├── ClientTest.php       # New backward-compat test cases added here
├── EventDispatcherTest.php
├── TriggerManagerTest.php
└── Drivers/
    └── PostHogDriverTest.php
```

**Structure Decision**: Single-project library layout. All source lives under `src/`, all tests under `tests/`. Changes are scoped to `src/Client.php` (constructor) and `tests/ClientTest.php` (new test cases).

## Complexity Tracking

No constitution violations — table not applicable.
