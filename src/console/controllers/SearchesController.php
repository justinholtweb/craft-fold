<?php

declare(strict_types=1);

namespace justinholtweb\fold\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\fold\Plugin;
use yii\console\ExitCode;

/**
 * `php craft fold/searches/…`
 */
class SearchesController extends Controller
{
    /** Days to keep. Defaults to the `searchLogRetentionDays` setting. */
    public ?int $days = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['days']);
    }

    /**
     * Deletes search log rows older than `--days`.
     *
     * Garbage collection already does this on the setting's schedule; this is for a site that
     * wants it done now, or on a cron of its own.
     */
    public function actionPurge(): int
    {
        $days = $this->days ?? Plugin::getInstance()->getSettings()->searchLogRetentionDays;

        if ($days <= 0) {
            $this->stderr("Retention is set to keep searches forever. Pass --days to purge anyway.\n", Console::FG_YELLOW);

            return ExitCode::USAGE;
        }

        $deleted = Plugin::getInstance()->search->purgeSearchLog($days);
        $this->stdout("Deleted $deleted search log rows older than $days days.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
