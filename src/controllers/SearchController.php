<?php

declare(strict_types=1);

namespace justinholtweb\fold\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\models\SearchResult;
use justinholtweb\fold\Plugin;
use yii\web\Response;

/**
 * The public search endpoint.
 *
 * Everything the built-in front-end runtime knows how to ask, and a documented API for anyone
 * building their own. It is deliberately read-only, anonymous, and `GET`-shaped, which is what
 * lets it be cached at the edge.
 */
class SearchController extends Controller
{
    public array|bool|int $allowAnonymous = ['index'];

    public function actionIndex(): Response
    {
        // No `requireAcceptsJson()`: the answer is JSON whatever was asked for, and a documented
        // `.json` URL that 400s when pasted into a browser or curl is a support ticket.

        $plugin = Plugin::getInstance();

        if (!$this->withinRateLimit()) {
            $response = $this->asJson([
                'message' => Craft::t('fold', 'Too many searches. Please wait a moment and try again.'),
            ]);
            $response->setStatusCode(429);
            $response->getHeaders()->set('Retry-After', (string)(60 - time() % 60));

            return $response;
        }

        $params = $plugin->search->requestParams([
            'q', 'lat', 'lng', 'radius', 'unit', 'limit', 'offset', 'group', 'openNow', 'countryCode',
        ]);

        // `inStockOf` is only honoured when Commerce is actually in play. Accepting it silently
        // otherwise would let a front end believe it had filtered by stock when it had not, which
        // is worse than an ignored parameter — it is a wrong answer with a confident shape.
        $inStockOf = $plugin->search->requestParams(['inStockOf'])['inStockOf'] ?? null;

        if ($inStockOf && $plugin->commerce->isEnabled()) {
            $params['inStockOf'] = $inStockOf;
        }

        $result = $plugin->search->search($params);

        return $this->asJson($this->shape($result, $params['inStockOf'] ?? null));
    }

    /**
     * The JSON shape.
     *
     * Composed here rather than by serializing the elements, so that adding a custom field to a
     * location group cannot change the endpoint's contract, and so that nothing on an element
     * that happens to be public in PHP leaks into a public response.
     */
    private function shape(SearchResult $result, mixed $inStockOf): array
    {
        $commerce = Plugin::getInstance()->commerce;
        $exposeLevels = Plugin::getInstance()->getSettings()->exposeStockLevels;

        $locations = array_map(function(Location $location) use ($commerce, $inStockOf, $exposeLevels) {
            $data = [
                'id' => $location->id,
                'title' => $location->title,
                'url' => $location->getUrl(),
                'group' => $location->getGroup()->handle,
                'color' => $location->getGroup()->getMarkerColor(),
                'lat' => $location->lat,
                'lng' => $location->lng,
                'distance' => $location->distance !== null ? round($location->distance, 2) : null,
                'address' => $location->getFormattedAddress(['html' => false]),
                'addressLines' => $this->addressLines($location),
                'phone' => $location->phone,
                'email' => $location->email,
                'websiteUrl' => $location->websiteUrl,
                'directionsUrl' => $location->getDirectionsUrl(),
                'hours' => $location->getHours()->toArray(),
                'openNow' => $location->getHours()->isEmpty() ? null : $location->isOpenNow(),
                'timezone' => $location->getTimezone(),
            ];

            if ($inStockOf && $commerce->isEnabled()) {
                $stock = $commerce->availableStock($location, $inStockOf);
                $data['inStock'] = $stock > 0;

                // Exact counts only when the site has opted in — see `exposeStockLevels`.
                if ($exposeLevels) {
                    $data['availableStock'] = $stock;
                }
            }

            return $data;
        }, $result->locations);

        return $result->toArray() + ['locations' => $locations];
    }

    /**
     * A fixed-window counter per visitor IP, in Craft's cache.
     *
     * Not atomic — two requests in the same millisecond can both read 29 — and it does not need to
     * be. It is here to stop a loop, not to meter a quota, and a site that needs a hard limit has
     * one at its CDN.
     */
    private function withinRateLimit(): bool
    {
        $limit = Plugin::getInstance()->getSettings()->searchRateLimit;

        if ($limit <= 0) {
            return true;
        }

        $cache = Craft::$app->getCache();
        $key = sprintf('fold:search-rate:%s:%d', sha1((string)$this->request->getUserIP()), intdiv(time(), 60));
        $count = (int)$cache->get($key) + 1;
        $cache->set($key, $count, 60);

        return $count <= $limit;
    }

    /** @return string[] */
    private function addressLines(Location $location): array
    {
        $formatted = $location->getFormattedAddress(['html' => false]);

        return array_values(array_filter(array_map('trim', explode("\n", $formatted))));
    }
}
