<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Number;

/** Localizes presentation values without changing retained facts, with the same measurement policy as the app UI. */
final class SummaryParameters
{
    /** Response-time params rounded to whole milliseconds; recovery_below is floored separately. */
    public const array MillisecondKeys = ['average', 'median', 'baseline', 'p90', 'delta', 'relative_threshold'];

    /**
     * @template TValue of string|int|float|bool|null
     *
     * @param  array<string, TValue>  $params
     * @return array<string, TValue|string>
     */
    public static function localized(array $params, string $locale, bool $wholeMilliseconds = false): array
    {
        if (isset($params['date']) && is_string($params['date'])) {
            $date = CarbonImmutable::parse($params['date'])->utc();
            // Match the app's Intl dateStyle: medium, including German numeric dates.
            $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC');
            $formatted = $formatter->format($date->getTimestamp());
            if ($formatted === false) {
                throw new \LogicException('Could not format summary date.');
            }
            $params['date'] = $formatted;
        }

        // Keep the display-only floor in sync with summaryParams() in resources/js/hooks/use-format.ts.
        if (isset($params['recovery_below']) && (is_int($params['recovery_below']) || is_float($params['recovery_below']))) {
            $bound = Number::format(floor($params['recovery_below']), maxPrecision: 0, locale: $locale);
            if ($bound === false) {
                throw new \LogicException('Could not format recovery bound.');
            }
            $params['recovery_below'] = $bound;
        }

        if ($wholeMilliseconds) {
            $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 0);
            foreach (self::MillisecondKeys as $key) {
                if (isset($params[$key]) && (is_int($params[$key]) || is_float($params[$key]))) {
                    $value = $formatter->format(round($params[$key]));
                    if ($value === false) {
                        throw new \LogicException('Response-time formatting failed.');
                    }
                    $params[$key] = $value;
                }
            }
        }

        return $params;
    }

    /**
     * Mirrors the app's summary number formatting: HTTP status codes are identifiers, not measurements.
     *
     * @template TValue of string|int|float|bool|null
     *
     * @param  array<string, TValue>  $params
     * @return array<string, TValue|string>
     */
    public static function localizedMeasurements(array $params, string $locale): array
    {
        $localized = [];
        foreach (self::localized($params, $locale) as $key => $value) {
            if ((is_int($value) || is_float($value)) && $key !== 'status') {
                // Same policy as summaryParams() in resources/js/hooks/use-format.ts: whole response-time milliseconds,
                // one decimal for percentages, otherwise Intl's default of up to three fraction digits. round() first:
                // Intl rounds ties away from zero (122.5 → 123), ICU's NumberFormatter rounds them to even.
                $formatted = match (true) {
                    in_array($key, self::MillisecondKeys, true) => Number::format(round($value), maxPrecision: 0, locale: $locale),
                    $key === 'minimum_increase' || str_ends_with($key, '_percentage') => Number::format(round($value, 1), precision: 1, locale: $locale),
                    default => Number::format(round($value, 3), maxPrecision: 3, locale: $locale),
                };
                if ($formatted === false) {
                    throw new \LogicException('Could not format summary measurement.');
                }
                $localized[$key] = $formatted;
            } else {
                $localized[$key] = $value;
            }
        }

        return $localized;
    }
}
