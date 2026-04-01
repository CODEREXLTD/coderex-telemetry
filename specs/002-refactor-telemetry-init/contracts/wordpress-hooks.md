# WordPress Hook Contract

## Custom Telemetry Action

The feature requires a WordPress action entrypoint for custom telemetry events in addition to the direct PHP API.

## Proposed Contract

```php
do_action('<plugin-slug>_telemetry_track', string $eventName, array $properties = []);
```

Example canonical library-owned event names passed through this contract are `activation/onboarding_completed` and `activation/aha_reached` when plugin code wants to trigger those flows explicitly.

## Contract Rules

- The first argument is the event name.
- The second argument is an optional associative properties array.
- The client must register the action during initialization or during explicit telemetry bootstrapping.
- The action handler must route the event through the same custom-event dispatch path as the public PHP API and preserve the supplied event name and properties unchanged.
- Missing drivers and driver failures must be logged and dropped without breaking the surrounding WordPress request.

## Compatibility Notes

- Activation and deactivation hook behavior remains unchanged and is not replaced by this action.
- Library-owned lifecycle and milestone telemetry now uses the canonical `activation/*` namespace instead of legacy literal event names.
- Optional onboarding and `kui` or `aha` modules may continue to use their own hooks, but they are separate from this generic custom-event hook.