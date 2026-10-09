<?php

declare(strict_types=1);

namespace justinholtweb\fold\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craft\helpers\Template;
use craft\web\View;
use DateTime;
use DateTimeZone;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\events\DefineSchemaEvent;
use justinholtweb\fold\models\OpeningHours;
use justinholtweb\fold\Plugin;
use Throwable;
use Twig\Markup;

/**
 * LocalBusiness structured data — what Google's local panel, Maps and the answer engines read.
 *
 * A store page is the one page on a site whose facts are already structured: Fold holds the
 * address as an address, the position as coordinates and the hours as a week of ranges, so the
 * JSON-LD is a translation rather than a guess. The rules that matter, and are enforced here:
 *
 * - **Days that share hours share a specification.** Monday to Friday 9–5 is one
 *   `OpeningHoursSpecification` with five days, which is what Google's own examples do and what
 *   keeps the block readable.
 * - **Closed is `00:00`–`00:00`.** That is Google's documented way to say a day is closed, and
 *   the only way a dated exception can say "closed on Christmas Day".
 * - **Overnight ranges stay as they are.** `22:00`–`02:00` is valid as written: a `closes` before
 *   `opens` means the next day, the same rule {@see OpeningHours} uses.
 * - **Only exceptions that have not passed** are published. Last Christmas's hours are noise.
 */
class Schema extends Component
{
    /**
     * @event DefineSchemaEvent After a location's structured data is built, before it is encoded.
     * Change `$event->schema` to add an `image`, a `priceRange`, a `parentOrganization` — anything
     * Fold has no field for.
     */
    public const EVENT_DEFINE_SCHEMA = 'defineSchema';

    /** @var int[] Locations whose block was injected into this request's `<head>`. */
    private array $injectedIds = [];

    /** schema.org day names, in {@see OpeningHours::DAYS} order. */
    private const DAY_NAMES = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];

    /**
     * The `<script type="application/ld+json">` block for a location.
     *
     * ```twig
     * {% block head %}{{ craft.fold.schema(location) }}{% endblock %}
     * ```
     */
    public function render(Location $location, array $overrides = []): Markup
    {
        // Already in this page's head, put there automatically. Printing it again would give the
        // page two descriptions of one business, so a template written before injection existed —
        // or for a site with it switched off — stays correct either way. Overrides are the
        // exception: they are a request for *this* version.
        if ($overrides === [] && in_array($location->id, $this->injectedIds, true)) {
            return Template::raw('');
        }

        return Template::raw($this->scriptTag($this->build($location, $overrides)));
    }

    /**
     * The LocalBusiness as an array — for a template that wants to change it, or to hand it to
     * another SEO plugin rather than print it.
     *
     * @return array<string, mixed>
     */
    public function build(Location $location, array $overrides = []): array
    {
        $url = $location->getUrl() ?: $location->websiteUrl;

        $data = [
            '@context' => 'https://schema.org',
            '@type' => $location->groupId !== null ? $location->getGroup()->getSchemaType() : 'LocalBusiness',
            // A stable identifier, so the business on the store page and the same business named
            // elsewhere on the site are recognised as one thing rather than two.
            '@id' => $url !== null ? $url . '#location' : null,
            'name' => (string)$location->title,
            'url' => $url,
            'telephone' => $location->phone ?: null,
            'email' => $location->email ?: null,
            'address' => $this->address($location),
            'geo' => $location->hasCoordinates() ? [
                '@type' => 'GeoCoordinates',
                'latitude' => $location->lat,
                'longitude' => $location->lng,
            ] : null,
            'openingHoursSpecification' => $this->weekSpecifications($location->getHours()),
            'specialOpeningHoursSpecification' => $this->exceptionSpecifications($location),
        ];

        // The shop's own website, when the store page is the `url`: it is the same business.
        if ($location->websiteUrl && $location->websiteUrl !== $url) {
            $data['sameAs'] = $location->websiteUrl;
        }

        $data = array_merge($this->clean($data), $overrides);

        if ($this->hasEventHandlers(self::EVENT_DEFINE_SCHEMA)) {
            $event = new DefineSchemaEvent(['location' => $location, 'schema' => $data]);
            $this->trigger(self::EVENT_DEFINE_SCHEMA, $event);
            $data = $event->schema;
        }

        return $data;
    }

    /**
     * Encodes structured data into a script tag.
     *
     * `</script>` inside a JSON string — a shop called "</script><script>…" — is the one thing
     * that would break out of the block, and JSON_HEX_TAG makes it impossible. Slashes and
     * Unicode stay readable.
     */
    public function scriptTag(array $data): string
    {
        $json = Json::encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

        return sprintf('<script type="application/ld+json">%s</script>', $json);
    }

    /**
     * Whether automatic injection should run at all.
     *
     * Off while SEOmatic is installed: it owns the page's JSON-LD, and two blocks describing the
     * same page in different words is exactly what makes a search engine trust neither.
     */
    public function shouldInject(): bool
    {
        return Plugin::getInstance()->getSettings()->injectSchema
            && !Craft::$app->getPlugins()->isPluginEnabled('seomatic');
    }

    /**
     * Adds the block to the `<head>` when the page being rendered is a location's own page.
     *
     * Hooked to `View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE`, which only fires for the page template
     * of a site request — never for a partial, a CP page or an email — and asks the URL manager
     * which element the request matched, so a template that happens to have a `location` variable
     * is not mistaken for a store page.
     */
    public function injectForMatchedElement(): void
    {
        $request = Craft::$app->getRequest();

        if (!$request->getIsSiteRequest() || $request->getIsActionRequest() || !$this->shouldInject()) {
            return;
        }

        $element = Craft::$app->getUrlManager()->getMatchedElement();

        if ($element instanceof Location) {
            $this->inject($element);
        }
    }

    /**
     * Registers a location's block in the `<head>` of the page being rendered.
     *
     * Returns false, and leaves the page alone, if the block cannot be built: structured data is
     * an extra, and a store page that fails to render because of it is far worse than a store
     * page without it.
     */
    public function inject(Location $location): bool
    {
        try {
            $html = $this->scriptTag($this->build($location));
        } catch (Throwable $e) {
            Craft::warning('Could not build structured data for location ' . $location->id . ': ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return false;
        }

        Craft::$app->getView()->registerHtml($html, View::POS_HEAD, 'fold-schema');
        $this->injectedIds[] = $location->id;

        return true;
    }

    // Internals
    // -------------------------------------------------------------------------

    /** @return array<string, string>|null */
    private function address(Location $location): ?array
    {
        $address = $location->getAddress();

        if ($address === null) {
            return null;
        }

        $street = implode(', ', array_filter([
            trim((string)$address->addressLine1),
            trim((string)$address->addressLine2),
        ]));

        $out = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $street,
            'addressLocality' => trim((string)$address->locality),
            'addressRegion' => trim((string)$address->administrativeArea),
            'postalCode' => trim((string)$address->postalCode),
            'addressCountry' => trim((string)$address->countryCode),
        ], static fn($value) => $value !== '');

        return count($out) > 1 ? $out : null;
    }

    /**
     * The weekly hours, with days that share the same ranges folded into one specification.
     *
     * @return array<int, array<string, mixed>>
     */
    private function weekSpecifications(OpeningHours $hours): array
    {
        /** @var array<string, array{days: string[], open: string, close: string}> $byRange */
        $byRange = [];

        foreach ($hours->week() as $day => $ranges) {
            foreach ($ranges as $range) {
                $key = $range['open'] . '-' . $range['close'];
                $byRange[$key] ??= ['days' => [], 'open' => $range['open'], 'close' => $range['close']];
                $byRange[$key]['days'][] = self::DAY_NAMES[$day];
            }
        }

        $specs = [];

        foreach ($byRange as $group) {
            $specs[] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => count($group['days']) === 1 ? $group['days'][0] : $group['days'],
                'opens' => $group['open'],
                'closes' => $group['close'],
            ];
        }

        return $specs;
    }

    /**
     * Dated exceptions from today on, in the location's own timezone.
     *
     * @return array<int, array<string, mixed>>
     */
    private function exceptionSpecifications(Location $location): array
    {
        $today = (new DateTime('now', new DateTimeZone($location->getTimezone())))->format('Y-m-d');
        $specs = [];

        foreach ($location->getHours()->exceptions() as $date => $ranges) {
            if ($date < $today) {
                continue;
            }

            // No ranges is "closed all day", which schema.org spells as a zero-length day.
            foreach ($ranges !== [] ? $ranges : [['open' => '00:00', 'close' => '00:00']] as $range) {
                $specs[] = [
                    '@type' => 'OpeningHoursSpecification',
                    'validFrom' => $date,
                    'validThrough' => $date,
                    'opens' => $range['open'],
                    'closes' => $range['close'],
                ];
            }
        }

        return $specs;
    }

    /**
     * Drops nulls, empty strings and empty lists — an empty `telephone` is a validator warning,
     * an absent one is not.
     */
    private function clean(array $data): array
    {
        return array_filter($data, static fn($value) => $value !== null && $value !== '' && $value !== []);
    }
}
