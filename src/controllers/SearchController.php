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
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();

        $params = [
            'q' => $request->getParam('q'),
            'lat' => $request->getParam('lat'),
            'lng' => $request->getParam('lng'),
            'radius' => $request->getParam('radius'),
            'unit' => $request->getParam('unit'),
            'limit' => $request->getParam('limit'),
            'offset' => $request->getParam('offset'),
            'group' => $request->getParam('group'),
            'openNow' => $request->getParam('openNow'),
            'countryCode' => $request->getParam('country'),
        ];

        // `inStockOf` is only honoured when Commerce is actually in play. Accepting it silently
        // otherwise would let a front end believe it had filtered by stock when it had not, which
        // is worse than an ignored parameter — it is a wrong answer with a confident shape.
        $inStockOf = $request->getParam('inStockOf');

        if ($inStockOf && Plugin::getInstance()->commerce->isEnabled()) {
            $params['inStockOf'] = $inStockOf;
        }

        $result = Plugin::getInstance()->search->search(array_filter(
            $params,
            static fn($value) => $value !== null && $value !== '',
        ));

        return $this->asJson($this->shape($result));
    }

    /**
     * The JSON shape.
     *
     * Composed here rather than by serializing the elements, so that adding a custom field to a
     * location group cannot change the endpoint's contract, and so that nothing on an element
     * that happens to be public in PHP leaks into a public response.
     */
    private function shape(SearchResult $result): array
    {
        $commerce = Plugin::getInstance()->commerce;
        $inStockOf = Craft::$app->getRequest()->getParam('inStockOf');

        $locations = array_map(function(Location $location) use ($commerce, $inStockOf) {
            $data = [
                'id' => $location->id,
                'title' => $location->title,
                'url' => $location->getUrl(),
                'group' => $location->getGroup()->handle,
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
                $data['availableStock'] = $commerce->availableStock($location, $inStockOf);
            }

            return $data;
        }, $result->locations);

        return $result->toArray() + ['locations' => $locations];
    }

    /** @return string[] */
    private function addressLines(Location $location): array
    {
        $formatted = $location->getFormattedAddress(['html' => false]);

        return array_values(array_filter(array_map('trim', explode("\n", $formatted))));
    }
}
