<?php

namespace LinnoSDK\Telemetry\Tests\Drivers;

use LinnoSDK\Telemetry\Drivers\PostHogDriver;
use Mockery;
use PostHog\PostHog;
use PHPUnit\Framework\TestCase;

class PostHogDriverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSendReturnsTrueOnSuccess()
    {
        $driver = new PostHogDriver('host');
        $driver->setApiKey('key');

        Mockery::mock('alias:' . PostHog::class)
            ->shouldReceive('init')->once()
            ->shouldReceive('capture')->once()
            ->shouldReceive('flush')->once();

        $this->assertTrue($driver->send('event', []));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSendReturnsFalseOnFailure()
    {
        $driver = new PostHogDriver('host');
        $driver->setApiKey('key');

        Mockery::mock('alias:' . PostHog::class)
            ->shouldReceive('init')->once()->andThrow(new \Exception('error'));

        $this->assertFalse($driver->send('event', []));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetLastErrorReturnsErrorMessage()
    {
        $driver = new PostHogDriver('host');
        $driver->setApiKey('key');

        Mockery::mock('alias:' . PostHog::class)
            ->shouldReceive('init')->once()->andThrow(new \Exception('error message'));

        $driver->send('event', []);

        $this->assertEquals('error message', $driver->getLastError());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSendWithMalformedHostUrl()
    {
        $driver = new PostHogDriver('malformed-url');
        $driver->setApiKey('key');

        Mockery::mock('alias:' . PostHog::class)
            ->shouldReceive('init')->once()->andThrow(new \Exception('cURL error'));

        $this->assertFalse($driver->send('event', []));
        $this->assertStringContainsString('cURL error', $driver->getLastError());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSendWithLargePayload()
    {
        $driver = new PostHogDriver('host');
        $driver->setApiKey('key');

        Mockery::mock('alias:' . PostHog::class)
            ->shouldReceive('init')->once()
            ->shouldReceive('capture')->once()
            ->shouldReceive('flush')->once();

        $largePayload = array_fill(0, 1000, 'a');
        $this->assertTrue($driver->send('event', ['large_payload' => $largePayload]));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSendWithNonJsonErrorResponse()
    {
        $driver = new PostHogDriver('host');
        $driver->setApiKey('key');

        Mockery::mock('alias:' . PostHog::class)
            ->shouldReceive('init')->once()->andThrow(new \Exception('not a json response'));

        $this->assertFalse($driver->send('event', []));
        $this->assertEquals('not a json response', $driver->getLastError());
    }
}
