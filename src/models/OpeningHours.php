<?php

declare(strict_types=1);

namespace justinholtweb\fold\models;

use Craft;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;

/**
 * When a location is open.
 *
 * A week of day ranges plus dated exceptions, stored as one JSON document on the location. Hours
 * are read whole every single time they are read at all — nobody asks "what time does it close on
 * Tuesdays" without also drawing the rest of the week — so a row per interval would buy nothing.
 *
 * Two things this gets right that most opening-hours code does not:
 *
 * - **Overnight ranges.** A bar open `22:00–02:00` is open at midnight. A range whose close is
 *   not after its open is read as running into the following day, and {@see self::isOpenAt()}
 *   therefore has to look at *yesterday's* ranges as well as today's.
 * - **The location's own timezone.** "Open now" for a shop in Los Angeles is a question about
 *   Los Angeles, not about the server, and not about the visitor.
 */
class OpeningHours implements JsonSerializable
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @var array<string, array<int, array{open: string, close: string}>> */
    private array $week = [];

    /** @var array<string, array<int, array{open: string, close: string}>> Keyed by `Y-m-d`. */
    private array $exceptions = [];

    /** A free-text override — "By appointment", "Closed for renovation" — shown instead of a grid. */
    public ?string $note = null;

    public function __construct(array $config = [])
    {
        foreach (self::DAYS as $day) {
            $this->week[$day] = self::normalizeRanges($config[$day] ?? []);
        }

        foreach ((array)($config['exceptions'] ?? []) as $date => $ranges) {
            $key = self::normalizeDate((string)$date);

            if ($key !== null) {
                // An exception with no ranges means *closed that day* — an empty array is
                // meaningful here, unlike in the week, where it means the same thing anyway.
                $this->exceptions[$key] = self::normalizeRanges($ranges);
            }
        }

        // In date order, whatever order they were entered in — a "holiday hours" list that puts
        // Christmas Day before Christmas Eve looks like a mistake on the shop's own page. The keys
        // are `Y-m-d`, so a string sort is a date sort.
        ksort($this->exceptions);

        $note = trim((string)($config['note'] ?? ''));
        $this->note = $note !== '' ? $note : null;
    }

    public static function fromJson(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return new self(is_array($value) ? $value : []);
    }

    /** @return array<int, array{open: string, close: string}> */
    public function forDay(string $day): array
    {
        return $this->week[strtolower($day)] ?? [];
    }

    /** @return array<string, array<int, array{open: string, close: string}>> */
    public function week(): array
    {
        return $this->week;
    }

    /** @return array<string, array<int, array{open: string, close: string}>> */
    public function exceptions(): array
    {
        return $this->exceptions;
    }

    public function isEmpty(): bool
    {
        foreach ($this->week as $ranges) {
            if ($ranges !== []) {
                return false;
            }
        }

        return $this->exceptions === [] && $this->note === null;
    }

    /**
     * The ranges that apply on a given date — the exception if there is one, otherwise the
     * weekday.
     *
     * @return array<int, array{open: string, close: string}>
     */
    public function forDate(DateTimeInterface $date): array
    {
        $key = $date->format('Y-m-d');

        if (array_key_exists($key, $this->exceptions)) {
            return $this->exceptions[$key];
        }

        return $this->week[strtolower($date->format('D'))] ?? [];
    }

    /**
     * Whether the location is open at a moment, in its own timezone.
     *
     * Yesterday is consulted as well as today, because an overnight range belongs to the day it
     * *started*: at 00:30 on Saturday, the thing that makes the bar open is Friday's 22:00–02:00.
     */
    public function isOpenAt(?DateTimeInterface $when = null, ?string $timezone = null): bool
    {
        $moment = self::inZone($when, $timezone);
        $minutes = ((int)$moment->format('G')) * 60 + (int)$moment->format('i');

        foreach ($this->forDate($moment) as $range) {
            [$open, $close] = self::rangeMinutes($range);

            if ($close > $open) {
                if ($minutes >= $open && $minutes < $close) {
                    return true;
                }
            } elseif ($minutes >= $open) {
                // Runs past midnight; the rest of it is checked as yesterday's, below.
                return true;
            }
        }

        $yesterday = (clone $moment)->modify('-1 day');

        foreach ($this->forDate($yesterday) as $range) {
            [$open, $close] = self::rangeMinutes($range);

            if ($close <= $open && $minutes < $close) {
                return true;
            }
        }

        return false;
    }

    /**
     * The next time the location opens, or null if it never does.
     *
     * Searched a fortnight ahead: long enough to step over Christmas week at a shop that closes
     * for it, short enough that a location with no hours at all fails fast rather than looping.
     */
    public function nextOpeningAt(?DateTimeInterface $when = null, ?string $timezone = null): ?DateTime
    {
        $moment = self::inZone($when, $timezone);
        $cursor = clone $moment;

        for ($day = 0; $day < 14; $day++) {
            $ranges = $this->forDate($cursor);
            usort($ranges, static fn($a, $b) => strcmp($a['open'], $b['open']));

            foreach ($ranges as $range) {
                $opensAt = (clone $cursor)->setTime(
                    (int)substr($range['open'], 0, 2),
                    (int)substr($range['open'], 3, 2),
                    0,
                );

                if ($opensAt > $moment) {
                    return $opensAt;
                }
            }

            $cursor = (clone $cursor)->modify('+1 day')->setTime(0, 0, 0);
        }

        return null;
    }

    public function jsonSerialize(): array
    {
        $out = [];

        foreach (self::DAYS as $day) {
            if ($this->week[$day] !== []) {
                $out[$day] = $this->week[$day];
            }
        }

        if ($this->exceptions !== []) {
            $out['exceptions'] = $this->exceptions;
        }

        if ($this->note !== null) {
            $out['note'] = $this->note;
        }

        return $out;
    }

    public function toArray(): array
    {
        return $this->jsonSerialize();
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * Squares up whatever the editor, an import, or a hand-written seed handed over.
     *
     * Anything unparseable is dropped rather than defaulted: a range Fold cannot read is better
     * missing from the grid, where somebody notices, than shown as `00:00–00:00`, where it reads
     * as a deliberate "closed".
     *
     * @return array<int, array{open: string, close: string}>
     */
    private static function normalizeRanges(mixed $ranges): array
    {
        if (!is_array($ranges)) {
            return [];
        }

        // A single range may arrive unwrapped — `{"open": "09:00", "close": "17:00"}`.
        if (isset($ranges['open']) || isset($ranges['close'])) {
            $ranges = [$ranges];
        }

        $out = [];

        foreach ($ranges as $range) {
            if (is_string($range)) {
                // "09:00-17:00", the form a CSV import produces.
                $parts = preg_split('/\s*(?:-|–|to)\s*/u', $range, 2);
                $range = ['open' => $parts[0] ?? '', 'close' => $parts[1] ?? ''];
            }

            if (!is_array($range)) {
                continue;
            }

            $open = self::normalizeTime($range['open'] ?? null);
            $close = self::normalizeTime($range['close'] ?? null);

            if ($open === null || $close === null || $open === $close) {
                continue;
            }

            $out[] = ['open' => $open, 'close' => $close];
        }

        usort($out, static fn($a, $b) => strcmp($a['open'], $b['open']));

        return $out;
    }

    /** `9:00`, `09:00`, `0900`, `9am`, `17:30:00` → `HH:MM`; anything else → null. */
    private static function normalizeTime(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string)$value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim(strtolower($value));

        if ($value === '') {
            return null;
        }

        // "24:00" is a legitimate way to say "closes at the end of the day", and every other
        // representation of that hour is midnight, which would read as an empty range.
        if (in_array($value, ['24:00', '2400', '24'], true)) {
            return '23:59';
        }

        if (preg_match('/^(\d{1,2})(?::(\d{2}))?(?::\d{2})?\s*(am|pm)?$/', $value, $m)) {
            $hour = (int)$m[1];
            $minute = (int)($m[2] ?? 0);
            $meridiem = $m[3] ?? null;

            if ($meridiem === 'pm' && $hour < 12) {
                $hour += 12;
            } elseif ($meridiem === 'am' && $hour === 12) {
                $hour = 0;
            }

            if ($hour > 23 || $minute > 59) {
                return null;
            }

            return sprintf('%02d:%02d', $hour, $minute);
        }

        if (preg_match('/^(\d{2})(\d{2})$/', $value, $m)) {
            $hour = (int)$m[1];
            $minute = (int)$m[2];

            return $hour <= 23 && $minute <= 59 ? sprintf('%02d:%02d', $hour, $minute) : null;
        }

        return null;
    }

    private static function normalizeDate(string $date): ?string
    {
        $date = trim($date);

        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return $date;
    }

    /** @return array{0: int, 1: int} Minutes past midnight for a range's two ends. */
    private static function rangeMinutes(array $range): array
    {
        return [
            (int)substr($range['open'], 0, 2) * 60 + (int)substr($range['open'], 3, 2),
            (int)substr($range['close'], 0, 2) * 60 + (int)substr($range['close'], 3, 2),
        ];
    }

    private static function inZone(?DateTimeInterface $when, ?string $timezone): DateTime
    {
        $tz = new DateTimeZone($timezone ?: Craft::$app->getTimeZone());

        $moment = $when instanceof DateTimeInterface
            ? DateTime::createFromFormat('U', (string)$when->getTimestamp())
            : new DateTime('now');

        return $moment->setTimezone($tz);
    }
}
