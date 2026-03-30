<?php
/**
 * PostHog Driver Implementation
 *
 * @package Linno\Telemetry
 * @since 1.0.0
 */

namespace Linno\Telemetry\Drivers;

use PostHog\Client as PostHogClient;

/**
 * Class PostHogDriver
 *
 * Implements DriverInterface for the PostHog analytics platform using the
 * official PostHog PHP SDK. Supports PostHog Cloud (US & EU) and self-hosted
 * instances.
 *
 * Usage:
 *   $driver = new PostHogDriver();                           // US Cloud (default)
 *   $driver = new PostHogDriver('https://eu.i.posthog.com'); // EU Cloud
 *   $driver = new PostHogDriver('https://posthog.example.com'); // Self-hosted
 *   $driver->setApiKey('phc_your_project_api_key');
 *
 * @since 1.0.0
 */
class PostHogDriver implements DriverInterface {

	/**
	 * PostHog US Cloud host URL (default).
	 *
	 * @since 1.0.0
	 */
	private const DEFAULT_HOST = 'https://app.posthog.com';

	/**
	 * PostHog Project API Key.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $apiKey = '';

	/**
	 * PostHog host URL.
	 *
	 * Override to use EU cloud (https://eu.i.posthog.com) or a self-hosted instance.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $host;

	/**
	 * Last error message.
	 *
	 * @var string|null
	 * @since 1.0.0
	 */
	private ?string $lastError = null;

	/**
	 * PostHog SDK client instance (lazily created).
	 *
	 * Using a per-instance client rather than the PostHog static facade avoids
	 * global-state conflicts when multiple plugins initialise different API keys.
	 *
	 * @var PostHogClient|null
	 * @since 1.0.0
	 */
	private ?PostHogClient $posthogClient = null;

	/**
	 * Constructor.
	 *
	 * @param string $host PostHog host URL. Defaults to https://app.posthog.com.
	 * @since 1.0.0
	 */
	public function __construct( string $host = self::DEFAULT_HOST ) {
		$this->host = rtrim( $host, '/' );
	}

	/**
	 * Set the PostHog Project API Key.
	 *
	 * Resets the SDK client so it is re-created with the new key on the next send.
	 *
	 * @param string $apiKey PostHog project API key (e.g. phc_xxxx).
	 * @return void
	 * @since 1.0.0
	 */
	public function setApiKey( string $apiKey ): void {
		$this->apiKey        = $apiKey;
		$this->posthogClient = null;
	}

	/**
	 * Set a custom PostHog host URL.
	 *
	 * Use this for EU Cloud (https://eu.i.posthog.com) or a self-hosted instance.
	 * Resets the SDK client so it is re-created with the new host on the next send.
	 *
	 * @param string $host The PostHog host URL.
	 * @return void
	 * @since 1.0.0
	 */
	public function setHost( string $host ): void {
		$this->host          = rtrim( $host, '/' );
		$this->posthogClient = null;
	}

	/**
	 * Get the last error message.
	 *
	 * @return string|null The last error message, or null if no error occurred.
	 * @since 1.0.0
	 */
	public function getLastError(): ?string {
		return $this->lastError;
	}

	/**
	 * Send an event to PostHog.
	 *
	 * When the properties contain a populated __identify block with user data
	 * beyond the profile ID, a PostHog identify call is sent first so that the
	 * person record is enriched before the event is associated with it.
	 *
	 * @param string $event      The event name.
	 * @param array  $properties The event properties (may include __identify).
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public function send( string $event, array $properties ): bool {
		$this->lastError = null;

		if ( empty( $this->apiKey ) ) {
			$this->lastError = 'PostHog API key is not set.';
			return false;
		}

		try {
			$client     = $this->getClient();
			$distinctId = $this->extractDistinctId( $properties );
			$identify   = $properties['__identify'] ?? null;

			// Send identify call first so person properties are set before the event.
			if ( $this->shouldIdentify( $identify ) ) {
				$client->identify( [
					'distinctId' => $distinctId,
					'properties' => $this->buildIdentifyProperties( (array) $identify ),
				] );
			}

			$client->capture( [
				'distinctId' => $distinctId,
				'event'      => $event,
				'properties' => $this->prepareProperties( $properties ),
			] );

			// Flush immediately so the event is sent within the current request.
			$client->flush();

		} catch ( \Throwable $e ) {
			$this->lastError = $e->getMessage();
			return false;
		}

		return true;
	}

	/**
	 * Get or lazily create the PostHog SDK client instance.
	 *
	 * @return PostHogClient
	 * @since 1.0.0
	 */
	private function getClient(): PostHogClient {
		if ( null === $this->posthogClient ) {
			$this->posthogClient = new PostHogClient(
				$this->apiKey,
				[
					'host' => $this->host,
					'ssl'  => true,
				]
			);
		}

		return $this->posthogClient;
	}

	/**
	 * Extract a PostHog distinct ID from event properties.
	 *
	 * Priority order:
	 *   1. __identify.profileId  (site-level UUID managed by Utils::getSiteProfileId)
	 *   2. unique_id             (site-level UUID managed by Client)
	 *   3. 'anonymous'           (fallback)
	 *
	 * @param array $properties Event properties.
	 * @return string PostHog distinct ID.
	 * @since 1.0.0
	 */
	private function extractDistinctId( array $properties ): string {
		if ( ! empty( $properties['__identify']['profileId'] ) ) {
			return (string) $properties['__identify']['profileId'];
		}

		if ( ! empty( $properties['unique_id'] ) ) {
			return (string) $properties['unique_id'];
		}

		return 'anonymous';
	}

	/**
	 * Determine whether a PostHog identify call should be made.
	 *
	 * Returns true only when the __identify block contains person data beyond
	 * the profileId (e.g. email, firstName, lastName, avatar).
	 *
	 * @param mixed $identify The __identify value extracted from properties.
	 * @return bool
	 * @since 1.0.0
	 */
	private function shouldIdentify( $identify ): bool {
		if ( ! is_array( $identify ) || empty( $identify ) ) {
			return false;
		}

		// Only worth identifying when there is more than just a profileId.
		return ! empty( array_diff_key( $identify, [ 'profileId' => true ] ) );
	}

	/**
	 * Build PostHog person properties for the identify call.
	 *
	 * Removes profileId — it is already used as the distinctId — and forwards
	 * the remaining fields (email, firstName, lastName, avatar) as PostHog
	 * person properties.
	 *
	 * @param array $identify Raw __identify data from event properties.
	 * @return array PostHog person properties.
	 * @since 1.0.0
	 */
	private function buildIdentifyProperties( array $identify ): array {
		unset( $identify['profileId'] );
		return $identify;
	}

	/**
	 * Prepare event properties for PostHog capture.
	 *
	 * Strips __identify (already handled via a separate identify call) and
	 * forwards all standard properties (site_url, plugin_name, etc.) as PostHog
	 * event properties.
	 *
	 * @param array $properties Original properties from EventDispatcher.
	 * @return array Cleaned properties ready for PostHog::capture.
	 * @since 1.0.0
	 */
	private function prepareProperties( array $properties ): array {
		unset( $properties['__identify'] );
		return $properties;
	}
}
