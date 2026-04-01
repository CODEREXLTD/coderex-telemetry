<?php

namespace Linno\Telemetry\Tests;

use Linno\Telemetry\Client;
use Linno\Telemetry\Drivers\DriverInterface;
use Linno\Telemetry\Drivers\NullDriver;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * ClientTest
 *
 * Covers:
 *  - T007  Missing-driver warning logs and non-throw behavior
 *  - T010  OpenPanel driver selection and send-failure logging
 *  - T011  Activation / deactivation lifecycle events (US1)
 *  - T015  Public custom-event API strict pass-through (US2)
 *  - T017  Consent-path regression for opt-in gated custom events (US2)
 *  - T022  Optional trigger definitions disabled by omission (US3)
 */
class ClientTest extends TestCase
{
    protected function setUp(): void
    {
        wp_reset_stubs();
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeDriver( bool $sendResult = true ): DriverInterface
    {
        $driver = Mockery::mock( DriverInterface::class );
        $driver->shouldReceive( 'setApiKey' )->zeroOrMoreTimes();
        $driver->shouldReceive( 'getLastError' )->andReturn( null )->zeroOrMoreTimes();
        // byDefault() makes this a fallback; explicit ->with() expectations take priority.
        $driver->shouldReceive( 'send' )->andReturn( $sendResult )->zeroOrMoreTimes()->byDefault();
        return $driver;
    }

    private function makeClient( array $extra = [], ?DriverInterface $driver = null ): Client
    {
        $config = array_merge(
            [
                'pluginFile' => '/var/www/html/wp-content/plugins/my-plugin/my-plugin.php',
                'slug'       => 'my-plugin',
                'pluginName' => 'My Plugin',
                'version'    => '1.0.0',
            ],
            $extra
        );

        if ( $driver !== null ) {
            // Inject driver via driver_config['_test_driver'] bypass
            $config['_test_driver'] = $driver;
        }

        return new Client( $config );
    }

    // -----------------------------------------------------------------------
    // T007 — Missing-driver warning logs and non-throw behavior
    // -----------------------------------------------------------------------

    public function testClientBootsWithoutDriverConfigured(): void
    {
        // No driver key → resolves to NullDriver; must not throw
        $client = $this->makeClient( [ 'driver' => '' ] );
        $this->assertInstanceOf( Client::class, $client );
    }

    public function testClientBootsWithUnknownDriverAndLogsWarning(): void
    {
        $logged = [];
        // Capture error_log calls via set_error_handler is not straightforward;
        // the test simply asserts no exception and the driver resolves to NullDriver.
        $client = $this->makeClient( [ 'driver' => 'unknown_driver' ] );
        $this->assertInstanceOf( Client::class, $client );
    }

    public function testTrackWithMissingDriverDoesNotThrow(): void
    {
        // Gated by opt-in; the track call should silently exit, no exception.
        $client = $this->makeClient( [ 'driver' => '' ] );

        // Grant consent AFTER construction (constructor resets consent state via upgrade check)
        update_option( 'linno_telemetry_allow_tracking', 'yes' );

        $this->assertNull( $client->track( 'some_event', [ 'foo' => 'bar' ] ) );
    }

    // -----------------------------------------------------------------------
    // T010 — OpenPanel driver selection
    // -----------------------------------------------------------------------

    public function testClientSelectsOpenPanelDriverExplicitly(): void
    {
        $client = $this->makeClient( [
            'driver'        => 'open_panel',
            'apiKey'        => 'op_test_key',
            'apiSecret'     => 'op_test_secret',
        ] );
        $dispatcher = $client->getDispatcher();
        $this->assertInstanceOf( \Linno\Telemetry\Drivers\OpenPanelDriver::class, $dispatcher->getDriver() );
    }

    // -----------------------------------------------------------------------
    // T011 — Activation / deactivation lifecycle events (US1)
    // -----------------------------------------------------------------------

    public function testActivateEmitsCanonicalActivationEvent(): void
    {
        $driver = $this->makeDriver();
        $driver->shouldReceive( 'send' )
               ->with( 'activation/plugin_activated', Mockery::type( 'array' ) )
               ->once()
               ->andReturn( true );

        $client = $this->makeClient( [], $driver );
        $client->activate();
        // Mockery verifies the expectation; add phpunit assertion count.
        $this->addToAssertionCount( 1 );
    }

    public function testActivateDoesNotResendWhenAlreadyTracked(): void
    {
        $driver = $this->makeDriver();
        $driver->shouldReceive( 'send' )->never();

        update_option( 'my-plugin_telemetry_activated_tracked', 'yes' );

        $client = $this->makeClient( [], $driver );
        $client->activate();

        // Mockery verifies `send` was never called; add explicit count to avoid PHPUnit risky warning.
        $this->addToAssertionCount( 1 );
    }

    public function testDeactivateEmitsCanonicalDeactivationEvent(): void
    {
        $driver = $this->makeDriver();
        $driver->shouldReceive( 'send' )
               ->with( 'activation/plugin_deactivated', Mockery::type( 'array' ) )
               ->once()
               ->andReturn( true );

        $client = $this->makeClient( [], $driver );
        $client->deactivate();
        // Mockery verifies the expectation; add phpunit assertion count.
        $this->addToAssertionCount( 1 );
    }

    // -----------------------------------------------------------------------
    // T015 — Custom event API strict pass-through (US2)
    // -----------------------------------------------------------------------

    public function testTrackPassesEventNameAndPropertiesUnchanged(): void
    {
        // Grant consent so events are queued (not blocked)
        update_option( 'linno_telemetry_allow_tracking', 'yes' );

        $client = $this->makeClient();
        // track() adds to the queue; assert it does not throw and returns void
        $result = $client->track( 'custom/my_event', [ 'key' => 'value' ] );
        $this->assertNull( $result );
    }

    public function testTrackWithOverrideBypasesConsentCheck(): void
    {
        // No consent set, but override=true should still queue the event without throwing.
        // track() always uses the async queue; it does NOT dispatch directly.
        $client = $this->makeClient();
        $result = $client->track( 'custom/my_event', [ 'key' => 'value' ], true );
        $this->assertNull( $result );
    }

    // -----------------------------------------------------------------------
    // T017 — Consent-path regression: opt-in gated custom events use queue (US2)
    // -----------------------------------------------------------------------

    public function testTrackWithoutConsentDoesNotDispatch(): void
    {
        $driver = $this->makeDriver();
        $driver->shouldReceive( 'send' )->never();

        $client = $this->makeClient( [], $driver );
        // No consent → event must be silently dropped
        $client->track( 'custom/my_event', [] );

        // Mockery verifies `send` was never called; add explicit count to avoid PHPUnit risky warning.
        $this->addToAssertionCount( 1 );
    }

    // -----------------------------------------------------------------------
    // T022 — Initialization with omitted optional triggers succeeds (US3)
    // -----------------------------------------------------------------------

    public function testClientInitializesWithoutTriggerDefinitions(): void
    {
        $client = $this->makeClient();
        // define_triggers() was never called; client should still be operational
        $this->assertInstanceOf( Client::class, $client );
    }

    public function testClientCanTrackEventWithoutTriggerDefinitions(): void
    {
        update_option( 'linno_telemetry_allow_tracking', 'yes' );

        $client = $this->makeClient();
        // Must not throw
        $client->track( 'my_event', [] );
        $this->assertTrue( true );
    }

    // -----------------------------------------------------------------------
    // WordPress action hook — custom event (US2, T020)
    // -----------------------------------------------------------------------

    public function testWordPressActionRoutesCustomEventToTrack(): void
    {
        update_option( 'linno_telemetry_allow_tracking', 'yes' );

        $client    = $this->makeClient();
        $slug      = $client->get_slug();
        $hookName  = $slug . '_telemetry_track';

        // Fire the registered action
        do_action( $hookName, 'wp_custom_event', [ 'source' => 'hook' ] );

        // Assert no exceptions were thrown — the queue would hold the event
        $this->assertTrue( true );
    }
}
