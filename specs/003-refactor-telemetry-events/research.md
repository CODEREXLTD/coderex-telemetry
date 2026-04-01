# Research: Refactor Telemetry Events

## Performance Goals

- **Decision**: The event tracking function call should execute in under 50ms.
- **Rationale**: To ensure the telemetry library does not add any noticeable performance overhead to the host plugin or the user's website. This is a standard performance target for non-blocking background tasks.
- **Alternatives considered**: A stricter target (e.g., < 20ms) was considered but deemed unnecessary for the current scope, as the event dispatching is already asynchronous.

## Constraints

- **Decision**: The implementation will only use PHP features and extensions that are commonly available on typical WordPress hosting environments.
- **Rationale**: To ensure maximum compatibility and avoid issues for users on shared or managed hosting platforms. This means sticking to core PHP functionality and avoiding things like `pcntl` for forking or custom C extensions.
- **Alternatives considered**: Using more advanced features like a message queue (e.g., RabbitMQ) was rejected as it would add significant complexity and external dependencies, violating the principle of minimal WordPress coupling.

## Scale/Scope

- **Decision**: The system will be designed to handle up to 100 registered `retention/feature_used` hooks per plugin.
- **Rationale**: This is a generous estimate that should cover even the most complex plugins. It provides a clear target for any potential performance testing or optimization work.
- **Alternatives considered**: Not defining a specific limit was considered, but having a target helps in making design decisions and setting expectations.
