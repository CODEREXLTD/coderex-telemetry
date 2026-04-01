# Quickstart

## Goal

Implement the telemetry refactor so initialization is independent from optional OpenPanel-style modules, while library-owned events are emitted under the canonical `activation/*` namespace and custom events remain available through both PHP and WordPress hooks.

## Implementation Steps

1. Update `Client` initialization so driver selection can safely resolve to PostHog, OpenPanel, or a no-driver path that logs and no-ops.
2. Preserve activation and deactivation hook registration and timing, but rename the emitted lifecycle event names to `activation/plugin_activated` and `activation/plugin_deactivated`.
3. Separate optional trigger modules from core initialization, keeping onboarding completion and `kui`/`aha` milestones behind explicit trigger definitions that are disabled by omission.
4. Normalize legacy setup, `first_strike`, `kui`, and `aha` concepts onto the canonical emitted event names `activation/onboarding_completed` and `activation/aha_reached` where applicable.
5. Add a public custom-event entrypoint and a WordPress action listener that both accept `(string $eventName, array $properties = [])` and preserve those values unchanged through driver dispatch.
6. Ensure missing drivers and send failures are logged without throwing.
7. Add or extend PHPUnit coverage for client-level dispatch behavior, event-name normalization, and failure handling.

## Suggested Verification

1. Run the test suite.

```bash
./vendor/bin/phpunit
```

2. Add focused tests if the full suite is too broad during implementation.

```bash
./vendor/bin/phpunit --filter PostHogDriverTest
```

3. In the example plugin or a fixture plugin, verify these runtime flows:

- Client initializes without onboarding or `kui`/`aha` trigger definitions.
- Activation emits `activation/plugin_activated`.
- Deactivation emits `activation/plugin_deactivated`.
- Explicit onboarding trigger definitions can be enabled and then emit `activation/onboarding_completed`.
- Optional onboarding completion emits `activation/onboarding_completed`.
- Explicit `kui` or `aha` trigger definitions can be enabled and then emit `activation/aha_reached`.
- Optional `kui` or `aha` milestone emits `activation/aha_reached`.
- A direct PHP custom event call reaches the configured driver.
- A WordPress action-triggered custom event reaches the configured driver.
- A configured OpenPanel driver receives lifecycle and custom events after initialization decoupling.
- No configured driver produces a warning log and no fatal error.
- A driver send failure produces a failure log and no fatal error.

## Expected Changed Areas

- `src/Client.php`
- `src/EventDispatcher.php`
- `src/TriggerManager.php`
- `src/Drivers/*`
- `tests/*`
- `examples/test-plugin/*` if example usage needs to reflect the new custom-event contract