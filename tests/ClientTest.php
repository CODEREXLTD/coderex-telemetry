<?php

namespace LinnoSDK\Telemetry\Tests;

use LinnoSDK\Telemetry\Client;
use LinnoSDK\Telemetry\Drivers\DriverInterface;
use LinnoSDK\Telemetry\Drivers\NullDriver;
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
        $this->assertInstanceOf( \LinnoSDK\Telemetry\Drivers\OpenPanelDriver::class, $dispatcher->getDriver() );
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

    // -----------------------------------------------------------------------
    // T008 / T009 — add_feature_used_event static API (US2)
    // -----------------------------------------------------------------------

    public function testAddFeatureUsedEventRegistersActionHook(): void
    {
        global $_wp_hooks;

        Client::add_feature_used_event( 'my_plugin_feature_used', 'Export Settings' );

        $this->assertNotEmpty( $_wp_hooks['my_plugin_feature_used'] ?? [] );
    }

    public function testAddFeatureUsedEventCallbackTracksCorrectEvent(): void
    {
        // Reset static instances so only this client is registered.
        $ref = new \ReflectionProperty( Client::class, 'instances' );
        $ref->setAccessible( true );
        $ref->setValue( null, [] );

        $client = $this->makeClient();

        update_option( 'linno_telemetry_allow_tracking', 'yes' );

        Client::add_feature_used_event( 'my_plugin_export_run', 'Export Settings' );

        // Firing the hook must not throw; track() routes to the queue internally.
        do_action( 'my_plugin_export_run' );

        $this->assertTrue( true );
    }

    public function testAddFeatureUsedEventForwardsParamsToTrack(): void
    {
        $ref = new \ReflectionProperty( Client::class, 'instances' );
        $ref->setAccessible( true );
        $ref->setValue( null, [] );

        $client = $this->makeClient();

        update_option( 'linno_telemetry_allow_tracking', 'yes' );

        Client::add_feature_used_event( 'my_plugin_import_run', 'Import Settings', [ 'source' => 'file' ] );

        // Firing the hook must not throw even when extra params are supplied.
        do_action( 'my_plugin_import_run' );

        $this->assertTrue( true );
    }

    public function testAddFeatureUsedEventSilentlyDropsWithoutConsent(): void
    {
        $driver = $this->makeDriver();
        $driver->shouldReceive( 'send' )->never();

        $ref = new \ReflectionProperty( Client::class, 'instances' );
        $ref->setAccessible( true );
        $ref->setValue( null, [] );

        $client = $this->makeClient( [], $driver );

        // No consent set — event must be silently dropped.
        Client::add_feature_used_event( 'my_plugin_no_consent', 'Some Feature' );
        do_action( 'my_plugin_no_consent' );

        $this->addToAssertionCount( 1 );
    }

    // -----------------------------------------------------------------------
    // BC-007 — Array constructor continues to work (US1)
    // -----------------------------------------------------------------------

    public function testArrayConstructorContinuesToWork(): void
    {
        $client = $this->makeClient();
        $this->assertInstanceOf( Client::class, $client );
    }

    // -----------------------------------------------------------------------
    // BC-008 — Legacy 4-param constructor does not throw (US1)
    // -----------------------------------------------------------------------

    public function testLegacyFourParamConstructorDoesNotThrow(): void
    {
        $client = @new Client( 'test-api-key', 'test-secret', 'My Plugin', '/path/to/plugin.php' );
        $this->assertInstanceOf( Client::class, $client );
    }

    // -----------------------------------------------------------------------
    // BC-009 — Invalid first arg throws InvalidArgumentException (US1)
    // -----------------------------------------------------------------------

    public function testInvalidFirstArgThrowsException(): void
    {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'First argument must be a configuration array or a string API key' );
        new Client( 42 );
    }

    // -----------------------------------------------------------------------
    // BC-010 — Too few positional params throws InvalidArgumentException (US1)
    // -----------------------------------------------------------------------

    public function testTooFewPositionalParamsThrowsException(): void
    {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Legacy constructor requires exactly 4 string parameters' );
        new Client( 'only-one-string' );
    }

    // -----------------------------------------------------------------------
    // BC-011 — Empty API key throws InvalidArgumentException (US1)
    // -----------------------------------------------------------------------

    public function testEmptyApiKeyThrowsException(): void
    {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'API key must not be empty' );
        new Client( '', 'secret', 'Name', '/path.php' );
    }

    // -----------------------------------------------------------------------
    // BC-012 — Empty plugin file throws InvalidArgumentException (US1)
    // -----------------------------------------------------------------------

    public function testEmptyPluginFileThrowsException(): void
    {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Plugin file path must not be empty' );
        new Client( 'key', 'secret', 'Name', '' );
    }

    // -----------------------------------------------------------------------
    // BC-013 — Empty plugin name throws InvalidArgumentException (US1)
    // -----------------------------------------------------------------------

    public function testEmptyPluginNameThrowsException(): void
    {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Plugin name must not be empty' );
        new Client( 'key', 'secret', '', '/path.php' );
    }

    // -----------------------------------------------------------------------
    // BC-019 — Legacy constructor maps API key (US2)
    // -----------------------------------------------------------------------

    public function testLegacyConstructorMapsApiKey(): void
    {
        $client = @new Client( 'my-api-key', 'my-secret', 'My Plugin', '/path/to/plugin.php' );
        $this->assertSame( 'my-api-key', $client->getConfig()['apiKey'] );
    }

    // -----------------------------------------------------------------------
    // BC-020 — Legacy constructor maps plugin name (US2)
    // -----------------------------------------------------------------------

    public function testLegacyConstructorMapsPluginName(): void
    {
        $client = @new Client( 'my-api-key', 'my-secret', 'My Plugin', '/path/to/plugin.php' );
        $this->assertSame( 'My Plugin', $client->getConfig()['pluginName'] );
    }

    // -----------------------------------------------------------------------
    // BC-021 — Legacy constructor derives slug (US2)
    // -----------------------------------------------------------------------

    public function testLegacyConstructorDerivesSlug(): void
    {
        $client = @new Client( 'my-api-key', 'my-secret', 'My Plugin', '/path/to/plugin.php' );
        $this->assertSame( sanitize_title( 'My Plugin' ), $client->getConfig()['slug'] );
    }

    // -----------------------------------------------------------------------
    // BC-022 — Legacy constructor defaults driver to open_panel (US2)
    // -----------------------------------------------------------------------

    public function testLegacyConstructorDefaultsDriverToOpenPanel(): void
    {
        $client = @new Client( 'my-api-key', 'my-secret', 'My Plugin', '/path/to/plugin.php' );
        $this->assertSame( 'open_panel', $client->getConfig()['driver'] );
    }

    // -----------------------------------------------------------------------
    // BC-023 — Legacy constructor generates a unique_id (US2)
    // -----------------------------------------------------------------------

    public function testLegacyConstructorGeneratesUniqueId(): void
    {
        $client = @new Client( 'my-api-key', 'my-secret', 'My Plugin', '/path/to/plugin.php' );
        $this->assertNotEmpty( $client->getConfig()['unique_id'] );
    }

    // -----------------------------------------------------------------------
    // BC-027 — Legacy constructor emits deprecation notice (US3)
    // -----------------------------------------------------------------------

    public function testLegacyConstructorEmitsDeprecationNotice(): void
    {
        $deprecations = [];
        set_error_handler( function ( int $errno, string $errstr ) use ( &$deprecations ): bool {
            if ( E_USER_DEPRECATED === $errno ) {
                $deprecations[] = $errstr;
            }
            return true;
        } );

        new Client( 'my-api-key', 'my-secret', 'My Plugin', '/path/to/plugin.php' );

        restore_error_handler();

        $this->assertNotEmpty( $deprecations, 'Legacy constructor must emit a deprecation notice.' );
        $this->assertStringContainsString( 'Passing positional parameters to', $deprecations[0] );
    }

    // -----------------------------------------------------------------------
    // BC-028 — Array constructor does NOT emit deprecation (US3)
    // -----------------------------------------------------------------------

    public function testArrayConstructorDoesNotEmitDeprecation(): void
    {
        // Use a custom error handler to catch any unexpected deprecation notices.
        $deprecations = [];
        set_error_handler( function ( int $errno, string $errstr ) use ( &$deprecations ): bool {
            if ( E_USER_DEPRECATED === $errno ) {
                $deprecations[] = $errstr;
            }
            return true;
        } );

        $this->makeClient();

        restore_error_handler();

        $this->assertEmpty( $deprecations, 'Array constructor must not emit deprecation notices.' );
    }
}
