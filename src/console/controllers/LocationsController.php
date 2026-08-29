<?php

declare(strict_types=1);

namespace justinholtweb\fold\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\models\Edition;
use justinholtweb\fold\Plugin;
use yii\console\ExitCode;

/**
 * `php craft fold/locations/…`
 *
 * The commands a store locator actually needs on the command line: get several hundred shops in,
 * get them geocoded without a browser timing out, and get them back out again.
 */
class LocationsController extends Controller
{
    /** The location group to import into, by handle. */
    public ?string $group = null;

    /** Parse and report without writing anything. */
    public bool $dryRun = false;

    /** Re-geocode even locations whose address has not changed. */
    public bool $force = false;

    /** `csv` or `json`. */
    public string $format = 'csv';

    /** Where to write an export. Omitted, it goes to stdout. */
    public ?string $file = null;

    public function options($actionID): array
    {
        return match ($actionID) {
            'import' => array_merge(parent::options($actionID), ['group', 'dryRun']),
            'export' => array_merge(parent::options($actionID), ['group', 'format', 'file']),
            'geocode' => array_merge(parent::options($actionID), ['force', 'group']),
            default => parent::options($actionID),
        };
    }

    /**
     * Lists the location groups and how many shops are in each.
     */
    public function actionGroups(): int
    {
        $plugin = Plugin::getInstance();
        $groups = $plugin->groups->getAllGroups();

        if ($groups === []) {
            $this->stdout("No location groups yet.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        foreach ($groups as $group) {
            $count = Location::find()->groupId($group->id)->status(null)->siteId('*')->unique()->count();
            $this->stdout(sprintf("  %-24s %-16s %s\n", $group->handle, $group->name, $count));
        }

        return ExitCode::OK;
    }

    /**
     * Imports locations from a CSV file.
     *
     * php craft fold/locations/import stores.csv --group=retail
     */
    public function actionImport(string $path): int
    {
        if (!Edition::allowsImportExport(Plugin::getInstance()->isPro())) {
            $this->stderr("Importing is a Pro feature.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $group = $this->resolveGroup();

        if ($group === null) {
            return ExitCode::USAGE;
        }

        $result = Plugin::getInstance()->importer->importCsv($path, [
            'groupId' => $group->id,
            'dryRun' => $this->dryRun,
        ]);

        foreach ($result['errors'] as $error) {
            $this->stderr("  $error\n", Console::FG_RED);
        }

        $this->stdout(sprintf(
            "%s%d imported, %d updated, %d skipped.\n",
            $this->dryRun ? '[dry run] ' : '',
            $result['imported'],
            $result['updated'],
            $result['skipped'],
        ), $result['errors'] === [] ? Console::FG_GREEN : Console::FG_YELLOW);

        if (!$this->dryRun && ($result['imported'] || $result['updated'])) {
            $this->stdout("Run `php craft fold/locations/geocode` to place the new ones on the map.\n");
        }

        return $result['errors'] === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Exports locations as CSV or JSON.
     */
    public function actionExport(): int
    {
        if (!Edition::allowsImportExport(Plugin::getInstance()->isPro())) {
            $this->stderr("Exporting is a Pro feature.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $query = Location::find()->status(null)->siteId('*')->unique();

        if ($this->group !== null) {
            $group = $this->resolveGroup();

            if ($group === null) {
                return ExitCode::USAGE;
            }

            $query->groupId($group->id);
        }

        $locations = $query->all();
        $exporter = Plugin::getInstance()->exporter;
        $output = $this->format === 'json' ? $exporter->toJson($locations) : $exporter->toCsv($locations);

        if ($this->file !== null) {
            file_put_contents($this->file, $output);
            $this->stdout(sprintf("Wrote %d locations to %s.\n", count($locations), $this->file), Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout($output);

        return ExitCode::OK;
    }

    /**
     * Geocodes every location that needs it.
     *
     * Runs inline rather than through the queue, because on the command line there is nothing to
     * time out and watching the progress is the point. The one-request-per-second pause that
     * Nominatim's policy requires is applied here as well — 340 shops is a six-minute command,
     * and that is the honest cost of a free geocoder.
     */
    public function actionGeocode(): int
    {
        $plugin = Plugin::getInstance();
        $query = Location::find()->status(null)->siteId('*')->unique();

        if ($this->group !== null) {
            $group = $this->resolveGroup();

            if ($group === null) {
                return ExitCode::USAGE;
            }

            $query->groupId($group->id);
        }

        $locations = $query->all();
        $pending = array_values(array_filter(
            $locations,
            fn(Location $location) => $this->force || $location->needsGeocoding(),
        ));

        if ($pending === []) {
            $this->stdout("Nothing to geocode.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("Geocoding %d locations…\n", count($pending)));

        $placed = 0;
        $failed = 0;
        $throttle = $plugin->getSettings()->geocoderDriver === 'nominatim';

        foreach ($pending as $i => $location) {
            if ($i > 0 && $throttle) {
                usleep(1100000);
            }

            if ($plugin->geocoder->geocodeLocation($location, $this->force)) {
                $placed++;
                $this->stdout(sprintf("  ✓ %s  %.5f, %.5f\n", $location->title, $location->lat, $location->lng));
            } else {
                $failed++;
                $this->stdout(sprintf("  ✗ %s  %s\n", $location->title, $location->geocodeError ?? 'not found'), Console::FG_YELLOW);
            }

            Craft::$app->getElements()->saveElement($location, false, false, false);
        }

        $this->stdout(sprintf("\n%d placed, %d could not be found.\n", $placed, $failed),
            $failed === 0 ? Console::FG_GREEN : Console::FG_YELLOW);

        return ExitCode::OK;
    }

    /**
     * Queues geocoding rather than running it, for sites that would rather the queue did the
     * waiting.
     */
    public function actionQueueGeocoding(): int
    {
        $queued = Plugin::getInstance()->locations->queueAllPending($this->force);
        $this->stdout("Queued $queued locations.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function resolveGroup(): ?\justinholtweb\fold\models\LocationGroup
    {
        $groups = Plugin::getInstance()->groups;

        if ($this->group === null) {
            $all = $groups->getAllGroups();

            // With exactly one group there is no ambiguity, so requiring `--group` would be
            // pedantry. With several, guessing would be worse than asking.
            if (count($all) === 1) {
                return $all[0];
            }

            $this->stderr("Say which group with --group=<handle>. Run `fold/locations/groups` to list them.\n", Console::FG_RED);

            return null;
        }

        $group = $groups->getGroupByHandle($this->group);

        if ($group === null) {
            $this->stderr("No location group with the handle “{$this->group}”.\n", Console::FG_RED);
        }

        return $group;
    }
}
