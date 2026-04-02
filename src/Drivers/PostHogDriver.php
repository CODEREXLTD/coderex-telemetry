<?php

namespace LinnoSDK\Telemetry\Drivers;

use LinnoSDK\Telemetry\Helpers\Utils;
use PostHog\PostHog;

class PostHogDriver implements DriverInterface
{
    protected $host;
    protected $apiKey;
    protected $lastError;

    public function __construct(string $host)
    {
        $this->host = $host;
    }

    public function send(string $event, array $properties): bool
    {
        try {
            PostHog::init($this->apiKey, ['host' => $this->host]);

            $identify = $properties['__identify'] ?? [];
            unset($properties['__identify']);

            PostHog::capture([
                'distinctId' => Utils::getUniqueSiteId(),
                'event' => $event,
                'properties' => $properties,
                '$set' => $identify,
            ]);

            PostHog::flush();

            return true;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
