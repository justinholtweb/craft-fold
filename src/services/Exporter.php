<?php

declare(strict_types=1);

namespace justinholtweb\fold\services;

use Craft;
use craft\base\Component;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\models\Edition;
use justinholtweb\fold\models\OpeningHours;
use justinholtweb\fold\Plugin;

/**
 * CSV and JSON export (Pro).
 *
 * The columns match the ones {@see Importer} reads, so an export is a round trip: pull the shops
 * out, fix them in a spreadsheet, put them back. That is the actual workflow for a chain with a
 * few hundred branches, and an export that cannot be re-imported is a report rather than a tool.
 */
class Exporter extends Component
{
    public const COLUMNS = [
        'id', 'title', 'slug', 'addressLine1', 'addressLine2', 'locality',
        'administrativeArea', 'postalCode', 'countryCode', 'lat', 'lng',
        'phone', 'email', 'websiteUrl', 'timezone',
    ];

    /** @param Location[] $locations */
    public function toCsv(array $locations): string
    {
        if (!Edition::allowsImportExport(Plugin::getInstance()->isPro())) {
            return '';
        }

        $handle = fopen('php://temp', 'r+');

        // A byte-order mark, because Excel opens a UTF-8 CSV without one as Latin-1 and turns
        // every accented town name into mojibake. The importer strips it again.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, array_merge(self::COLUMNS, OpeningHours::DAYS));

        foreach ($locations as $location) {
            fputcsv($handle, $this->row($location));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string)$csv;
    }

    /** @param Location[] $locations */
    public function toJson(array $locations): string
    {
        if (!Edition::allowsImportExport(Plugin::getInstance()->isPro())) {
            return '[]';
        }

        $rows = [];

        foreach ($locations as $location) {
            $address = $location->getAddress();

            $rows[] = [
                'id' => $location->id,
                'title' => $location->title,
                'slug' => $location->slug,
                'group' => $location->getGroup()->handle,
                'address' => [
                    'addressLine1' => $address?->addressLine1,
                    'addressLine2' => $address?->addressLine2,
                    'locality' => $address?->locality,
                    'administrativeArea' => $address?->administrativeArea,
                    'postalCode' => $address?->postalCode,
                    'countryCode' => $address?->countryCode,
                ],
                'lat' => $location->lat,
                'lng' => $location->lng,
                'phone' => $location->phone,
                'email' => $location->email,
                'websiteUrl' => $location->websiteUrl,
                'timezone' => $location->timezone,
                'hours' => $location->getHours()->toArray(),
                'commerceInventoryLocationId' => $location->commerceInventoryLocationId,
            ];
        }

        return (string)json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function row(Location $location): array
    {
        $address = $location->getAddress();

        $row = [
            $location->id,
            $location->title,
            $location->slug,
            $address?->addressLine1,
            $address?->addressLine2,
            $address?->locality,
            $address?->administrativeArea,
            $address?->postalCode,
            $address?->countryCode,
            $location->lat,
            $location->lng,
            $location->phone,
            $location->email,
            $location->websiteUrl,
            $location->timezone,
        ];

        // One cell per day, as `09:00-17:00`, because that is the shape a person can read and
        // edit in a spreadsheet — and the shape the importer parses back.
        foreach (OpeningHours::DAYS as $day) {
            $ranges = array_map(
                static fn(array $range) => $range['open'] . '-' . $range['close'],
                $location->getHours()->forDay($day),
            );

            $row[] = implode(', ', $ranges);
        }

        return $row;
    }
}
