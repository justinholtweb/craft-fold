<?php

declare(strict_types=1);

namespace justinholtweb\fold\services;

use Craft;
use craft\base\Component;
use craft\elements\Address;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\helpers\Geo;
use justinholtweb\fold\models\Edition;
use justinholtweb\fold\models\OpeningHours;
use justinholtweb\fold\Plugin;
use Throwable;

/**
 * CSV and JSON import (Pro).
 *
 * The realistic shape of the job: somebody has a spreadsheet of 340 shops exported from whatever
 * the last site ran on, and the columns are named whatever that system called them. So the
 * importer is generous about column names, strict about what it will write, and never guesses at
 * a coordinate — a row with no coordinates is imported and queued for geocoding rather than
 * dropped or placed at 0,0.
 */
class Importer extends Component
{
    /**
     * Column aliases, so an export from somebody else's system usually just works.
     *
     * Matching is on a normalised name — lowercase, no spaces, no punctuation — because the same
     * column is `Address 1`, `address_1` and `ADDRESS1` in three different exports.
     */
    private const ALIASES = [
        'title' => ['title', 'name', 'storename', 'location', 'locationname', 'store'],
        'addressLine1' => ['addressline1', 'address1', 'address', 'street', 'streetaddress'],
        'addressLine2' => ['addressline2', 'address2', 'street2'],
        'locality' => ['locality', 'city', 'town'],
        'administrativeArea' => ['administrativearea', 'state', 'province', 'region', 'county'],
        'postalCode' => ['postalcode', 'postcode', 'zip', 'zipcode'],
        'countryCode' => ['countrycode', 'country'],
        'lat' => ['lat', 'latitude'],
        'lng' => ['lng', 'lon', 'long', 'longitude'],
        'phone' => ['phone', 'telephone', 'tel', 'phonenumber'],
        'email' => ['email', 'emailaddress'],
        'websiteUrl' => ['websiteurl', 'website', 'url', 'web'],
        'timezone' => ['timezone', 'tz'],
        'slug' => ['slug'],
    ];

    /**
     * @param string $path A readable CSV file.
     * @param array $options `groupId` (required), `dryRun`, `geocode`
     * @return array{imported: int, updated: int, skipped: int, errors: string[]}
     */
    public function importCsv(string $path, array $options = []): array
    {
        $result = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        if (!Edition::allowsImportExport(Plugin::getInstance()->isPro())) {
            $result['errors'][] = Craft::t('fold', 'Importing is a Pro feature.');

            return $result;
        }

        if (!is_readable($path)) {
            $result['errors'][] = Craft::t('fold', 'Could not read {path}.', ['path' => $path]);

            return $result;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $result['errors'][] = Craft::t('fold', 'Could not open {path}.', ['path' => $path]);

            return $result;
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false) {
                $result['errors'][] = Craft::t('fold', 'The file is empty.');

                return $result;
            }

            // A UTF-8 byte-order mark on the first cell makes the first column name unmatchable,
            // and Excel writes one every time. Stripped once, here, rather than defended against
            // in every alias.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);

            $map = $this->mapColumns($header);

            if (!isset($map['title'])) {
                $result['errors'][] = Craft::t('fold', 'The file needs a name column.');

                return $result;
            }

            $line = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $line++;

                if ($this->isBlankRow($row)) {
                    continue;
                }

                try {
                    $outcome = $this->importRow($this->readRow($row, $map), $options);
                    $result[$outcome]++;
                } catch (Throwable $e) {
                    $result['skipped']++;
                    $result['errors'][] = Craft::t('fold', 'Line {line}: {message}', [
                        'line' => $line,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            fclose($handle);
        }

        return $result;
    }

    /**
     * @return 'imported'|'updated'|'skipped'
     */
    private function importRow(array $data, array $options): string
    {
        $plugin = Plugin::getInstance();
        $groupId = (int)($options['groupId'] ?? 0);

        if ($groupId === 0) {
            throw new \RuntimeException('No location group was given.');
        }

        $title = trim((string)($data['title'] ?? ''));

        if ($title === '') {
            throw new \RuntimeException('The row has no name.');
        }

        // Matched on slug when the file gives one, otherwise on the title within the group. An
        // import run twice must update rather than duplicate — which is the difference between a
        // repeatable sync and a one-shot you can never re-run.
        $existing = Location::find()
            ->groupId($groupId)
            ->status(null)
            ->siteId('*')
            ->unique()
            ->slug($data['slug'] ?? null)
            ->title($data['slug'] ?? null ? null : $title)
            ->one();

        $isNew = $existing === null;
        $location = $existing ?? new Location();

        if ($isNew) {
            $location->groupId = $groupId;
        }

        $location->title = $title;

        if (!empty($data['slug'])) {
            $location->slug = $data['slug'];
        }

        foreach (['phone', 'email', 'websiteUrl', 'timezone'] as $attribute) {
            if (isset($data[$attribute]) && trim((string)$data[$attribute]) !== '') {
                $location->$attribute = trim((string)$data[$attribute]);
            }
        }

        $address = $location->getAddressOrNew();

        foreach (['addressLine1', 'addressLine2', 'locality', 'administrativeArea', 'postalCode'] as $attribute) {
            if (array_key_exists($attribute, $data)) {
                $address->$attribute = trim((string)$data[$attribute]) ?: null;
            }
        }

        if (!empty($data['countryCode'])) {
            $address->countryCode = strtoupper(substr(trim((string)$data['countryCode']), 0, 2));
        }

        $location->setAddress($address);

        // Coordinates in the file are trusted and marked manual, so the geocoder does not
        // immediately overwrite somebody's carefully corrected pin with its own guess.
        if (isset($data['lat'], $data['lng']) && is_numeric($data['lat']) && is_numeric($data['lng'])) {
            $location->lat = (float)$data['lat'];
            $location->lng = (float)$data['lng'];
            $location->geocodeState = Location::GEOCODE_MANUAL;
            $location->geocodeHash = $location->computeGeocodeHash();
        }

        if (isset($data['hours'])) {
            $location->setHours($data['hours']);
        }

        if (!empty($options['dryRun'])) {
            return $isNew ? 'imported' : 'updated';
        }

        if (!$plugin->locations->saveLocation($location)) {
            throw new \RuntimeException(implode('; ', array_merge(...array_values($location->getErrors()))));
        }

        return $isNew ? 'imported' : 'updated';
    }

    /** @return array<string, int> Attribute name to column index. */
    private function mapColumns(array $header): array
    {
        $map = [];

        foreach ($header as $index => $name) {
            $normalized = preg_replace('/[^a-z0-9]/', '', strtolower((string)$name));

            foreach (self::ALIASES as $attribute => $aliases) {
                if (in_array($normalized, $aliases, true) && !isset($map[$attribute])) {
                    $map[$attribute] = $index;
                }
            }

            // `hours_mon`, `monday`, `mon` — one column per day, which is how every spreadsheet
            // of opening hours in the world is actually laid out.
            foreach (OpeningHours::DAYS as $day) {
                if (in_array($normalized, [$day, 'hours' . $day, $day . 'hours'], true)) {
                    $map['hours.' . $day] = $index;
                }
            }
        }

        return $map;
    }

    private function readRow(array $row, array $map): array
    {
        $data = [];
        $hours = [];

        foreach ($map as $attribute => $index) {
            $value = $row[$index] ?? null;

            if (str_starts_with($attribute, 'hours.')) {
                $day = substr($attribute, 6);

                if (trim((string)$value) !== '') {
                    $hours[$day] = [$value];
                }

                continue;
            }

            $data[$attribute] = $value;
        }

        if ($hours !== []) {
            $data['hours'] = $hours;
        }

        return $data;
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }

        return true;
    }
}
