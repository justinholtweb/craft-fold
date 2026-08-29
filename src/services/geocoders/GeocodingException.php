<?php

declare(strict_types=1);

namespace justinholtweb\fold\services\geocoders;

use Exception;

/**
 * The provider was reached and said no — a bad key, a quota, a ban.
 *
 * Distinct from "no result", which is a `null` return and a perfectly ordinary answer to a
 * misspelt town. The difference decides whether Fold caches the outcome (a real miss) or retries
 * later (a failure), and whether the CP tells an admin to go and look at their API key.
 */
class GeocodingException extends Exception
{
}
