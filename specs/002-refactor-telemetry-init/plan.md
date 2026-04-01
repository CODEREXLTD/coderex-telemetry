# Implementation Plan: Refactor Telemetry Initialization

**Branch**: `002-refactor-telemetry-init` | **Date**: 2026-04-01 | **Spec**: `/Users/sadi/Desktop/lab/coderex-telemetry/specs/002-refactor-telemetry-init/spec.md`
**Input**: Feature specification from `/specs/002-refactor-telemetry-init/spec.md`

## Summary

Refactor the telemetry client so lifecycle and milestone telemetry emit a canonical `activation/*` event namespace. The implementation will rename lifecycle events to `activation/plugin_activated` and `activation/plugin_deactivated`, normalize onboarding and milestone events to `activation/onboarding_completed` and `activation/aha_reached`, keep those optional modules decoupled from initialization, preserve the current driver abstraction, and harden no-driver and driver-failure behavior to log and no-op instead of throwing.

## Technical Context

**Language/Version**: PHP >= 7.4  
**Primary Dependencies**: WordPress runtime APIs, `posthog/posthog-php` ^2.1, Composer PSR-4 autoloading  
**Storage**: WordPress options and a custom queue table for queued telemetry events  
**Testing**: PHPUnit 9.5 with Mockery  
**Target Platform**: WordPress plugins running on PHP 7.4+  
**Project Type**: Composer library for WordPress plugins  
**Performance Goals**: Preserve the current dispatch model: canonical lifecycle events stay on the existing immediate path, and consented custom events continue to use the existing queue path  
**Constraints**: No hard dependency on OpenPanel-specific onboarding concepts during client initialization; activation/deactivation trigger timing must remain backward compatible while the emitted event names change; missing drivers and driver send failures must not interrupt plugin execution  
**Scale/Scope**: Single library package refactor touching client initialization, event taxonomy naming, trigger registration, driver selection, optional trigger configuration, and unit coverage for custom-event and failure-handling paths

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Applicable constitution principles:

- **I. Consent-First Telemetry**: The design preserves consent-gated custom-event behavior and keeps privacy guidance in scope for public documentation updates.
- **II. Stable Library Contracts**: The feature stays inside the existing PHP API, WordPress hook, and driver abstraction boundaries while documenting canonical event-name migrations.
- **III. Non-Fatal Delivery**: Missing drivers and transport failures are treated as logged no-op paths, not runtime exceptions.
- **IV. Compatibility Requires Tests**: The task plan includes explicit regression coverage for no-driver behavior, send failures, optional trigger configuration, and both supported drivers.
- **V. Minimal WordPress Coupling**: Optional onboarding and `kui`/`aha` flows remain disabled by omission and separate from the minimum client boot path.

Pre-research gate result: PASS against constitution v1.0.0.

Post-design gate result: PASS. The proposed design keeps the refactor inside the existing library package, preserves driver compatibility for both PostHog and OpenPanel, and adds the regression coverage required by the constitution.

## Project Structure

### Documentation (this feature)

```text
specs/002-refactor-telemetry-init/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── php-api.md
│   └── wordpress-hooks.md
└── tasks.md
```

### Source Code (repository root)

```text
src/
├── Client.php
├── EventDispatcher.php
├── TriggerManager.php
├── Queue.php
├── Consent.php
├── Deactivation.php
├── Drivers/
│   ├── DriverInterface.php
│   ├── OpenPanelDriver.php
│   └── PostHogDriver.php
└── Helpers/
    └── Utils.php

tests/
└── Drivers/
    └── PostHogDriverTest.php

examples/
└── test-plugin/
    └── test-telemetry-plugin.php
```

**Structure Decision**: Keep the current single-package Composer library layout. Implement the refactor primarily in `src/Client.php`, `src/TriggerManager.php`, and `src/EventDispatcher.php`, with targeted unit tests added under `tests/` and example usage updated in `examples/test-plugin/` if needed during implementation.

## Complexity Tracking

No constitution violations or exceptional complexity justifications are currently required.
