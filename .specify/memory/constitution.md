<!--
Sync Impact Report
Version change: template -> 1.0.0
Modified principles:
- Principle 1 -> I. Consent-First Telemetry
- Principle 2 -> II. Stable Library Contracts
- Principle 3 -> III. Non-Fatal Delivery
- Principle 4 -> IV. Compatibility Requires Tests
- Principle 5 -> V. Minimal WordPress Coupling
Added sections:
- Operational Constraints
- Delivery Workflow
Removed sections:
- Template placeholder sections only
Templates requiring updates:
- .specify/templates/plan-template.md: ✅ compatible
- .specify/templates/spec-template.md: ✅ compatible
- .specify/templates/tasks-template.md: ✅ compatible
Follow-up TODOs:
- None
-->

# Coderex Telemetry Constitution

## Core Principles

### I. Consent-First Telemetry

Telemetry features MUST preserve the SDK's privacy-first behavior. Consent-gated events MUST continue to respect opt-in state, and developer-facing documentation MUST remain aligned with [PRIVACY_GUIDELINE.md](/Users/sadi/Desktop/lab/coderex-telemetry/PRIVACY_GUIDELINE.md). Library-owned lifecycle events may remain exempt only when that behavior already exists and is explicitly documented.

### II. Stable Library Contracts

The SDK MUST behave as a reusable WordPress library with stable PHP and WordPress hook contracts. Feature work MUST preserve backward-compatible public entrypoints unless a specification explicitly authorizes a breaking change, and canonical event-name migrations MUST be documented in the feature spec, contracts, and examples.

### III. Non-Fatal Delivery

Telemetry MUST never take down host-plugin execution. Missing drivers, invalid optional trigger configuration, transport failures, and third-party service errors MUST degrade to logging and no-op behavior rather than uncaught exceptions during normal runtime paths.

### IV. Compatibility Requires Tests

Changes to lifecycle events, driver selection, hook contracts, consent behavior, or optional trigger modules MUST add or update automated regression coverage. When a feature claims compatibility across supported drivers or aliases, the tasks and verification steps MUST include coverage for those paths.

### V. Minimal WordPress Coupling

Core client initialization MUST stay decoupled from plugin-specific onboarding flows and optional milestone instrumentation. Optional trigger modules MAY integrate with WordPress hooks, but they MUST remain configuration-driven, disabled by omission, and separate from the SDK's minimum boot path.

## Operational Constraints

- Supported runtime targets MUST remain compatible with PHP 7.4+ and standard WordPress plugin execution.
- The current driver abstraction is the extension boundary for analytics providers; new features SHOULD extend through driver implementations and configuration rather than bespoke dispatch paths.
- Event taxonomy changes MUST keep a single canonical emitted name per library-owned lifecycle or milestone event.
- Feature artifacts MUST identify logging, failure handling, and compatibility risks before implementation begins.

## Delivery Workflow

- Every feature spec MUST define independently testable user stories, concrete functional requirements, and measurable outcomes.
- Every implementation plan MUST include a constitution check that names the applicable principles and records a pass or a justified exception.
- Every task list MUST include the tests and verification steps needed to prove runtime safety, driver compatibility, and contract alignment for the feature.
- Documentation and example integrations MUST be updated when public event names, configuration expectations, or hook contracts change.

## Governance

This constitution overrides conflicting planning notes, ad hoc implementation preferences, and incomplete templates. Amendments require a documented rationale, an explicit version update, and a review of affected templates or project guidance. Feature reviews MUST verify compliance with all MUST statements in this file before implementation is considered ready.

**Version**: 1.0.0 | **Ratified**: 2026-04-02 | **Last Amended**: 2026-04-02
