<?php

declare(strict_types=1);

namespace justinholtweb\fold\services\geocoders;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use justinholtweb\fold\models\Settings;

/**
 * What the HTTP-backed drivers share: a client, a timeout, and one place that turns transport
 * failures into {@see GeocodingException}.
 */
abstract class BaseGeocoder implements GeocoderInterface
{
    /**
     * Six seconds.
     *
     * A geocode can happen inside a front-end request when a visitor searches, so the timeout is
     * a promise to that visitor as much as a setting: a slow provider should give them "we could
     * not find that" quickly, not a page that hangs until PHP gives up.
     */
    protected const TIMEOUT = 6.0;

    public function __construct(protected Settings $settings)
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    protected function client(): Client
    {
        return Craft::createGuzzleClient([
            'timeout' => self::TIMEOUT,
            'connect_timeout' => 3.0,
        ]);
    }

    /**
     * @throws GeocodingException
     */
    protected function getJson(string $url, array $query = [], array $headers = []): array
    {
        try {
            $response = $this->client()->get($url, [
                'query' => $query,
                'headers' => $headers,
            ]);
        } catch (GuzzleException $e) {
            throw new GeocodingException(sprintf('%s: %s', static::driverName(), $e->getMessage()), 0, $e);
        }

        $decoded = json_decode((string)$response->getBody(), true);

        if (!is_array($decoded)) {
            throw new GeocodingException(sprintf('%s returned a response that is not JSON.', static::driverName()));
        }

        return $decoded;
    }
}
