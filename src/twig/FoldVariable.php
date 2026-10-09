<?php

declare(strict_types=1);

namespace justinholtweb\fold\twig;

use Craft;
use craft\helpers\Json;
use justinholtweb\fold\elements\db\LocationQuery;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\helpers\Geo;
use justinholtweb\fold\models\Edition;
use justinholtweb\fold\models\LocationGroup;
use justinholtweb\fold\models\SearchResult;
use justinholtweb\fold\Plugin;
use justinholtweb\fold\web\assets\locator\LocatorAsset;
use Twig\Markup;
use yii\base\Behavior;

/**
 * `craft.fold.*`.
 *
 * Deliberately thin — everything here hands back either an element query or a service result, so
 * a template is never limited to what this class thought of. `craft.fold.locations` is an
 * ordinary `LocationQuery`, which means every element-query method a Craft developer already
 * knows works on it.
 */
class FoldVariable extends Behavior
{
    /**
     * An unfiltered location query.
     *
     * ```twig
     * {% set stores = craft.fold.locations.group('retail').nearby({ lat: lat, lng: lng, radius: 25 }).all() %}
     * ```
     */
    public function getLocations(array $criteria = []): LocationQuery
    {
        $query = Location::find();

        if ($criteria !== []) {
            Craft::configure($query, $criteria);
        }

        return $query;
    }

    /**
     * A full locator search: geocode the term, find what is near it, sort by distance.
     *
     * ```twig
     * {% set results = craft.fold.search({ q: craft.app.request.getParam('q'), radius: 25 }) %}
     * ```
     */
    public function search(array $params = []): SearchResult
    {
        return Plugin::getInstance()->search->search($params);
    }

    /**
     * LocalBusiness structured data for a location, as a ready `<script type="application/ld+json">`.
     *
     * ```twig
     * {% block head %}{{ craft.fold.schema(location) }}{% endblock %}
     * ```
     *
     * Not needed on a location's own page when automatic injection is on — Fold adds it there
     * already. For a page that renders a location some other way, or with SEOmatic installed.
     */
    public function schema(Location $location, array $overrides = []): Markup
    {
        return Plugin::getInstance()->schema->render($location, $overrides);
    }

    /**
     * The same structured data as an array, to change or to hand to another SEO plugin.
     *
     * @return array<string, mixed>
     */
    public function schemaData(Location $location, array $overrides = []): array
    {
        return Plugin::getInstance()->schema->build($location, $overrides);
    }

    /** @return LocationGroup[] */
    public function getGroups(): array
    {
        return Plugin::getInstance()->groups->getAllGroups();
    }

    public function group(string $handle): ?LocationGroup
    {
        return Plugin::getInstance()->groups->getGroupByHandle($handle);
    }

    public function getSettings(): \justinholtweb\fold\models\Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    public function distance(float $lat1, float $lng1, float $lat2, float $lng2, ?string $unit = null): float
    {
        return Geo::distance($lat1, $lng1, $lat2, $lng2, $unit ?? $this->getSettings()->distanceUnit);
    }

    /**
     * A complete, working locator: search box, results list, and map.
     *
     * ```twig
     * {{ craft.fold.locator({ group: 'retail', radius: 25 }) }}
     * ```
     *
     * The results are rendered **server-side** before the JavaScript ever runs, so the page is a
     * working directory of shops with scripting off and an indexable one for a search engine.
     * The runtime then enhances it. Everything here is a normal Twig template a site can copy
     * into its own `templates/` and change.
     */
    public function locator(array $options = []): Markup
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(LocatorAsset::class);

        // The initial state comes from the query string, so a locator URL is shareable and the
        // back button works — which an entirely JavaScript-driven widget throws away. The query
        // string is a visitor's to write, so it goes through the same gate as the JSON endpoint;
        // the template's own options are trusted and win.
        $params = array_merge(
            Plugin::getInstance()->search->requestParams(['q', 'lat', 'lng', 'radius']),
            $options,
        );

        $result = Plugin::getInstance()->search->search(array_filter(
            $params,
            static fn($value) => $value !== null && $value !== '',
        ));

        $variables = [
            'result' => $result,
            'config' => $this->mapConfig($options['map'] ?? []),
            'settings' => $this->getSettings(),
            'options' => $options,
            'term' => (string)($params['q'] ?? ''),
            'radius' => $params['radius'] ?? $this->getSettings()->defaultRadius,
        ];

        // The site's own copy wins. Fold's templates live in the CP template mode, so a site that
        // wants to change the markup copies `_locator/locator.twig` into its `templates/fold/`
        // and edits it there — no overrides file, no theme layer, and no reason to fork.
        $template = 'fold/_locator/locator';
        $mode = $view->doesTemplateExist($template, \craft\web\View::TEMPLATE_MODE_SITE)
            ? \craft\web\View::TEMPLATE_MODE_SITE
            : \craft\web\View::TEMPLATE_MODE_CP;

        return new Markup($view->renderTemplate($template, $variables, $mode), Craft::$app->charset);
    }

    /**
     * Everything the front-end runtime needs to draw a map, as a JSON string.
     *
     * Built here rather than in the JavaScript so that the API keys, the tile URL, the units and
     * the edition's downgrades are all decided by PHP — the runtime is then a renderer with no
     * opinions, and a lapsed licence changes what it is handed rather than what it does.
     */
    public function mapConfig(array $overrides = []): string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $isPro = $plugin->isPro();
        $driver = Edition::mapDriverFor($settings->mapDriver, $isPro);

        $config = [
            'driver' => $driver,
            'unit' => Geo::normalizeUnit($settings->distanceUnit),
            'center' => ['lat' => $settings->defaultLat, 'lng' => $settings->defaultLng],
            'zoom' => $settings->defaultZoom,
            'radiusOptions' => $settings->getRadiusOptions(),
            'defaultRadius' => $settings->defaultRadius,
            // Leaflet only. Clustering Google or Mapbox markers would mean a second third-party
            // library on the page (or, for Mapbox, redrawing every pin as a map layer), so for those
            // drivers the flag is simply false and the runtime draws one pin per shop.
            'cluster' => $driver === 'leaflet' && $settings->clusterMarkers && Edition::allowsClustering($isPro),
            'requestBrowserLocation' => $settings->requestBrowserLocation,
            'endpoint' => \craft\helpers\UrlHelper::siteUrl('fold/search.json'),
        ];

        if ($driver === 'leaflet') {
            $config['tileUrl'] = $settings->leafletTileUrl;
            $config['attribution'] = $settings->leafletAttribution;
            $config += $this->leafletUrls($config['cluster']);
        }

        // Only the key the chosen driver actually needs is emitted. A Mapbox token in the page
        // source of a site that renders Google maps is a leaked credential for nothing.
        if ($driver === 'google') {
            $config['apiKey'] = $settings->getGoogleApiKey();
        } elseif ($driver === 'mapbox') {
            $config['accessToken'] = $settings->getMapboxAccessToken();
        }

        return Json::encode(array_merge($config, $overrides));
    }

    /**
     * Where Leaflet's own files are.
     *
     * The bundled copy by default, published through Craft's asset manager; the settings override
     * either URL for a site that self-hosts or wants a CDN.
     *
     * `publishedUrl` is passed `true` for the timestamp: Craft's published-directory hash is
     * computed from the path plus the *directory's* mtime, and editing a file inside a directory
     * does not change that — so a cached URL keeps pointing at the old contents after an update.
     *
     * The clustering plugin's URLs are only emitted when clustering is on, so a page that does not
     * cluster never names — let alone fetches — the files.
     *
     * @return array<string, mixed>
     */
    private function leafletUrls(bool $cluster = false): array
    {
        $settings = $this->getSettings();
        $base = Craft::$app->getAssetManager()->getPublishedUrl(
            (new LocatorAsset())->sourcePath,
            true,
        );

        $urls = [
            'leafletJsUrl' => $settings->leafletJsUrl ?: $base . '/' . LocatorAsset::LEAFLET_JS,
            'leafletCssUrl' => $settings->leafletCssUrl ?: $base . '/' . LocatorAsset::LEAFLET_CSS,
            'leafletImagePath' => $base . '/' . LocatorAsset::LEAFLET_IMAGES,
        ];

        if ($cluster) {
            $urls['clusterJsUrl'] = $settings->leafletClusterJsUrl ?: $base . '/' . LocatorAsset::CLUSTER_JS;
            $urls['clusterCssUrls'] = [
                $settings->leafletClusterCssUrl ?: $base . '/' . LocatorAsset::CLUSTER_CSS,
                $settings->leafletClusterDefaultCssUrl ?: $base . '/' . LocatorAsset::CLUSTER_DEFAULT_CSS,
            ];
        }

        return $urls;
    }
}
