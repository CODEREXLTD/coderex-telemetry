<?php

namespace LinnoSDK\Telemetry\Tests;

use LinnoSDK\Telemetry\EventDispatcher;
use LinnoSDK\Telemetry\Drivers\DriverInterface;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * EventDispatcherTest
 *
 * Covers:
 *  - T007  Missing-driver warning logs (dispatch with NullDriver)
 *  - T010  Send-failure logging
 *  - T012  Lifecycle dispatch assertions (minimal dispatch) (US1)
 */
class EventDispatcherTest extends TestCase
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

    private function makeConfig( array $overrides = [] ): array
    {
        return array_merge( [
            'pluginName' => 'My Plugin',
            'version'    => '1.0.0',
            'unique_id'  => 'test-uid-123',
            'slug'       => 'my-plugin',
        ], $overrides );
    }

    private function makeDriver( bool $sendResult = true, ?string $lastError = null ): DriverInterface
    {
        $driver = Mockery::mock( DriverInterface::class );
        $driver->shouldReceive( 'getLastError' )->andReturn( $lastError )->zeroOrMoreTimes();
        // byDefault() makes this a fallback; explicit ->with() expectations take priority.
        $driver->shouldReceive( 'send' )->andReturn( $sendResult )->zeroOrMoreTimes()->byDefault();
        return $driver;
    }

    // -----------------------------------------------------------------------
    // T010 — Send-failure logging
    // -----------------------------------------------------------------------

    public function testDispatchReturnsFalseOnSendFailure(): void
    {
        $driver     = $this->makeDriver( false, 'Connection refused' );
        $dispatcher = new EventDispatcher( $driver, $this->makeConfig() );

        $result = $dispatcher->dispatch( 'some_event', [
            'site_url'       => 'https://example.com',
            'unique_id'      => 'uid',
            'plugin_name'    => 'My Plugin',
            'plugin_version' => '1.0.0',
            'timestamp'      => '2026-01-01T00:00:00+00:00',
        ] );

        $this->assertFalse( $result );
    }

    public function testDispatchMinimalReturnsFalseOnSendFailure(): void
    {
        $driver     = $this->makeDriver( false, 'Timeout' );
        $dispatcher = new EventDispatcher( $driver, $this->makeConfig() );

        $result = $dispatcher->dispatch_minimal( 'activation/plugin_activated', [
            'site_url'  => 'https://example.com',
            'unique_id' => 'uid',
        ] );

        $this->assertFalse( $result );
    }

    // -----------------------------------------------------------------------
    // T012 — Lifecycle dispatch minimal assertions (US1)
    // -----------------------------------------------------------------------

    public function testDispatchMinimalSendsLifecycleEventName(): void
    {
        $driver = $this->makeDriver( true );
        $driver->shouldReceive( 'send' )
               ->with( 'activation/plugin_activated', Mockery::type( 'array' ) )
               ->once()
               ->andReturn( true );

        $dispatcher = new EventDispatcher( $driver, $this->makeConfig() );
        $result     = $dispatcher->dispatch_minimal( 'activation/plugin_activated', [
            'site_url'  => 'https://example.com',
            'unique_id' => 'uid',
        ] );

        $this->assertTrue( $result );
    }

    public function testDispatchMinimalSendsDeactivationEventName(): void
    {
        $driver = $this->makeDriver( true );
        $driver->shouldReceive( 'send' )
               ->with( 'activation/plugin_deactivated', Mockery::type( 'array' ) )
               ->once()
               ->andReturn( true );

        $dispatcher = new EventDispatcher( $driver, $this->makeConfig() );
        $result     = $dispatcher->dispatch_minimal( 'activation/plugin_deactivated', [
            'site_url'  => 'https://example.com',
            'unique_id' => 'uid',
            'reason'    => 'none',
        ] );

        $this->assertTrue( $result );
    }

    // -----------------------------------------------------------------------
    // T008 — Plugin version and name correctly handed off to dispatcher
    // -----------------------------------------------------------------------

    public function testDispatchPopulatesPluginMetadataFromConfig(): void
    {
        $driver = Mockery::mock( DriverInterface::class );
        $driver->shouldReceive( 'send' )->andReturnUsing(
            function ( string $event, array $properties ) {
                // Assert metadata was properly populated from config
                \PHPUnit\Framework\Assert::assertEquals( 'My Plugin', $properties['plugin_name'] );
                \PHPUnit\Framework\Assert::assertEquals( '2.3.1', $properties['plugin_version'] );
                return true;
            }
        );
        $driver->shouldReceive( 'getLastError' )->andReturn( null );

        $dispatcher = new EventDispatcher( $driver, $this->makeConfig( [
            'pluginName' => 'My Plugin',
            'version'    => '2.3.1',
            'unique_id'  => 'uid',
        ] ) );

        $dispatcher->dispatch( 'test_event', [
            'site_url'  => 'https://example.com',
            'unique_id' => 'uid',
            'timestamp' => '2026-01-01T00:00:00+00:00',
        ] );
    }
}
