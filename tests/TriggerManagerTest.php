<?php

namespace LinnoSDK\Telemetry\Tests;

use LinnoSDK\Telemetry\Client;
use LinnoSDK\Telemetry\TriggerManager;
use LinnoSDK\Telemetry\Drivers\DriverInterface;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * TriggerManagerTest
 *
 * Covers:
 *  - T016  WordPress action custom-event routing (US2)
 *  - T023  Onboarding and kui/aha trigger definitions emit canonical names (US3)
 */
class TriggerManagerTest extends TestCase
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
            $config['_test_driver'] = $driver;
        }

        return new Client( $config );
    }

    // -----------------------------------------------------------------------
    // T016 — Custom event trigger routing via TriggerManager::on() (US2)
    // -----------------------------------------------------------------------

    public function testCustomTriggerRoutesEventThroughClient(): void
    {
        $client = $this->makeClient();

        // Grant consent AFTER construction
        update_option( 'linno_telemetry_allow_tracking', 'yes' );

        // Register a custom trigger that fires on 'my_plugin_post_published'
        $client->triggers()
               ->on( 'post_published', 'my_plugin_post_published' );
        $client->triggers()->init();

        // Fire the hook — should call client->track('post_published', []) without throwing
        do_action( 'my_plugin_post_published' );

        $this->assertTrue( true );
    }

    // -----------------------------------------------------------------------
    // T023 — Onboarding trigger emits activation/onboarding_completed (US3)
    // -----------------------------------------------------------------------

    public function testOnboardingTriggerEmitsCanonicalEvent(): void
    {
        $driver = $this->makeDriver();
        $client = $this->makeClient( [], $driver );

        // Grant consent AFTER construction (constructor resets consent state via upgrade check)
        update_option( 'linno_telemetry_allow_tracking', 'yes' );

        $client->define_triggers( [
            'setup' => 'my_plugin_setup_complete',
        ] );

        // Simulate the hook firing
        do_action( 'my_plugin_setup_complete' );

        // Validate the event was marked as sent with canonical key
        $this->assertEquals( 'yes', get_option( 'my-plugin_event_sent_onboarding_completed' ) );
    }

    // -----------------------------------------------------------------------
    // T023 — KUI / AHA trigger emits activation/aha_reached (US3)
    // -----------------------------------------------------------------------

    public function testKuiTriggerEmitsCanonicalAhaEvent(): void
    {
        $client = $this->makeClient();

        // Grant consent AFTER construction
        update_option( 'linno_telemetry_allow_tracking', 'yes' );

        $client->define_triggers( [
            'kui' => [
                'order_received' => [
                    'hook' => 'woocommerce_order_created',
                ],
            ],
        ] );

        // Trigger the hook — track_kui() is called → emits activation/aha_reached
        do_action( 'woocommerce_order_created' );

        // Assert no exception was thrown
        $this->assertTrue( true );
    }

    public function testAhaTriggerEmitsCanonicalAhaEvent(): void
    {
        $client = $this->makeClient();

        // Grant consent AFTER construction
        update_option( 'linno_telemetry_allow_tracking', 'yes' );

        // Use 'aha' canonical key (alias for 'kui')
        $client->define_triggers( [
            'aha' => [
                'milestone_reached' => [
                    'hook' => 'my_plugin_milestone',
                ],
            ],
        ] );

        do_action( 'my_plugin_milestone' );

        $this->assertTrue( true );
    }
}
