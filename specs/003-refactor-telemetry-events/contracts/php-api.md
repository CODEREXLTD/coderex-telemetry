# PHP API Contract

## Class: `Linno\Telemetry\Client`

### New Static Method: `add_feature_used_event`

- **Description**: Registers a WordPress action hook that, when triggered, sends a `retention/feature_used` event.
- **Signature**: `public static function add_feature_used_event(string $hook_name, string $feature_name, array $params = []): void`
- **Parameters**:
    - `$hook_name` (string): The name of the WordPress action hook to listen for.
    - `$feature_name` (string): The name of the feature associated with this event.
    - `$params` (array, optional): An associative array of key-value pairs to be sent with the event.
- **Returns**: `void`
- **Usage**:
  ```php
  <?php
  use Linno\Telemetry\Client;

  // Register an event to be sent when the 'my_plugin_feature_used' action is fired.
  Client::add_feature_used_event('my_plugin_feature_used', 'My Awesome Feature');

  // With additional parameters
  Client::add_feature_used_event('my_plugin_another_feature', 'Another Feature', ['plan' => 'premium', 'value' => 123]);
  ```
