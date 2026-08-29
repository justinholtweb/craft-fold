<?php

declare(strict_types=1);

namespace justinholtweb\fold\web\assets\locator;

use craft\web\AssetBundle;

/**
 * The front-end locator: the search box, the results list, and the map.
 *
 * No build step, in keeping with the rest of the family — `fold.js` is a plain script and
 * `fold.css` is plain CSS, both readable in the browser's sources panel by whoever has to debug
 * a site three years from now.
 *
 * **Leaflet is vendored, not linked.** A default Fold install therefore makes exactly one
 * third-party request — for map tiles — and none at all for code. A CDN link would be smaller in
 * the repository and worse everywhere else: a third-party script on every page of the site, a
 * dependency on somebody else's uptime, and, in the EU, a personal-data disclosure to make about
 * a store locator. Sites that would rather self-host or use a CDN point the `leafletJsUrl` and
 * `leafletCssUrl` settings wherever they like, and this bundle stands aside.
 */
class LocatorAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $css = ['fold.css'];

    public $js = ['fold.js'];

    /** @var string[] The Leaflet files, published but only loaded when the driver needs them. */
    public const LEAFLET_JS = 'vendor/leaflet/leaflet.js';
    public const LEAFLET_CSS = 'vendor/leaflet/leaflet.css';
    public const LEAFLET_IMAGES = 'vendor/leaflet/images/';
}
