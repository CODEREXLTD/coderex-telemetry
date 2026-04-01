# Data Model: retention/feature_used Event

## Entity: `retention/feature_used` Event

- **Description**: Represents a user's interaction with a core feature of a plugin.
- **Fields**:
    - `event`: (string) The name of the event, always "retention/feature_used".
    - `feature`: (string) The name of the feature that was used.
    - `params`: (object, optional) A key-value map of additional data related to the event. Keys are strings, and values can be strings or numbers.
- **Relationships**: This event is not directly related to other data entities within the telemetry system, but it is triggered by a WordPress action hook.
