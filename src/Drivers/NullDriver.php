<?php
/**
 * NullDriver — safe no-op telemetry driver
 *
 * Used when no telemetry driver is configured. All send operations silently
 * succeed without transmitting data, allowing the client to remain operational.
 *
 * @package LinnoSDK\Telemetry\Drivers
 * @since 1.0.0
 */

namespace LinnoSDK\Telemetry\Drivers;

/**
 * Class NullDriver
 *
 * A do-nothing driver that satisfies the DriverInterface contract without
 * transmitting any data. The warning about a missing driver is logged by the
 * Client before it creates a NullDriver instance.
 *
 * @since 1.0.0
 */
class NullDriver implements DriverInterface {

    /**
     * API key placeholder — not used by this driver.
     *
     * @var string
     */
    private string $apiKey = '';

    /**
     * Always returns true (no-op send).
     *
     * @param string $event      Event name.
     * @param array  $properties Event properties.
     * @return bool Always true.
     */
    public function send( string $event, array $properties ): bool {
        return true;
    }

    /**
     * Accept an API key — stored but never used.
     *
     * @param string $apiKey The API key.
     * @return void
     */
    public function setApiKey( string $apiKey ): void {
        $this->apiKey = $apiKey;
    }

    /**
     * Always returns null — no errors can occur in a no-op driver.
     *
     * @return string|null
     */
    public function getLastError(): ?string {
        return null;
    }
}
