<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Number;

/** Localized presenter text for context-free deliveries and mail subjects. */
final class CheckHeadline
{
    public static function format(CheckRunPresentationData $presentation, string $locale, ?CheckKey $check = null): string
    {
        return self::compose($presentation, $locale, $check, null);
    }

    /**
     * Headline without untrusted text, always in check context ("Uptime: Check error: …"): each untrusted parameter reads
     * as a reference to the named data field that carries it. A check error whose only cause is a remote message names
     * the error class and points to that field instead of a bare reference.
     *
     * @return array{text: string, data: array<string, list<string>>}
     */
    public static function controlled(CheckRunPresentationData $presentation, string $locale, CheckKey $check, bool $checkError): array
    {
        /** @var array<string, list<string>> $data */
        $data = [];
        $reasons = array_slice($presentation->parts, 0, $presentation->headline_count);
        $remote = $checkError && $presentation->more_reasons === 0 && count($reasons) === 1 && $reasons[0]->key === 'causes.error' ? $reasons[0] : null;
        if ($remote instanceof CheckRunPresentationPartData) {
            $field = $remote->untrustedFields()['message'] ?? throw new \LogicException('A remote check error needs its message.');
            $data[$field][] = (string) $remote->params['message'];
            $text = self::translate('checks.presentation.check_error_remote', ['field' => $field], $locale);
        } else {
            $text = self::compose($presentation, $locale, null, function (CheckRunPresentationPartData $part, array $params) use (&$data, $locale): array {
                foreach ($part->untrustedFields() as $name => $field) {
                    $data[$field][] = (string) $part->params[$name];
                    $params[$name] = self::translate('checks.presentation.untrusted_reference', ['field' => $field], $locale);
                }

                return $params;
            });
            if ($checkError) {
                $text = self::translate('checks.presentation.check_error', ['headline' => $text], $locale);
            }
        }

        return ['text' => $presentation->names_check ? $text : self::prefix($check, $text, $locale), 'data' => $data];
    }

    /** @param (callable(CheckRunPresentationPartData, array<string, string|int|float|bool|null>): array<string, string|int|float|bool|null>)|null $replace */
    private static function compose(CheckRunPresentationData $presentation, string $locale, ?CheckKey $check, ?callable $replace): string
    {
        $parts = [];
        foreach (array_slice($presentation->parts, 0, $presentation->headline_count) as $index => $part) {
            if (SummaryKey::isFreeText($part->key)) {
                // Retained legacy free text renders verbatim, as the app does; dotted keys stay strict below.
                $parts[] = preg_replace('/\.$/', '', $part->key) ?? throw new \LogicException('Could not format legacy headline punctuation.');

                continue;
            }
            $params = $part->localizedParams($locale);
            if ($replace !== null) {
                $params = $replace($part, $params);
            }
            $count = $part->params['count'] ?? null;
            $key = $part->key;
            if (is_int($count)) {
                $plural = $key.($count === 1 ? '_one' : '_other');
                if (Lang::hasForLocale($plural, $locale)) {
                    $key = $plural;
                }
                $params['formattedCount'] = self::count($count, $locale);
            }
            foreach ($part->labels as $name => $label) {
                $plural = $label.($count === 1 ? '_one' : '_other');
                $params[$name] = self::translate($count !== null && Lang::hasForLocale($plural, $locale) ? $plural : $label, [], $locale);
            }
            if ($index > 0 && isset($part->labels['subject'])) {
                $params['subject'] = '';
            }
            $parts[] = preg_replace('/\.$/', '', self::translate($key, $params, $locale))
                ?? throw new \LogicException('Could not format check headline punctuation.');
        }
        if ($presentation->more_reasons > 0) {
            $parts[] = self::translate('checks.presentation.more_'.($presentation->more_reasons === 1 ? 'one' : 'other'),
                ['formattedCount' => self::count($presentation->more_reasons, $locale)], $locale);
        }
        $headline = implode(', ', $parts).'.';

        return ! $check instanceof CheckKey || $presentation->names_check ? $headline : self::prefix($check, $headline, $locale);
    }

    public static function prefix(CheckKey $check, string $headline, string $locale): string
    {
        return self::translate('{{check}}: {{headline}}', ['check' => self::translate('checks.'.$check->value.'.label', [], $locale), 'headline' => $headline], $locale);
    }

    private static function count(int $value, string $locale): string
    {
        $formatted = Number::format($value, locale: $locale);
        if ($formatted === false) {
            throw new \LogicException('Could not format check headline count.');
        }

        return $formatted;
    }

    /** @param array<string, string|int|float|bool|null> $params */
    private static function translate(string $key, array $params, string $locale): string
    {
        $template = __($key, locale: $locale);
        if (! is_string($template) || ! Lang::hasForLocale($key, $locale)) {
            throw new \LogicException('Missing check headline translation: '.$key);
        }
        // Resolve the catalog's tokens once; braces and colons in target-supplied values remain data.
        preg_match_all('/\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}|:([A-Za-z_][A-Za-z0-9_]*)/', $template, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $replacements = [];
        foreach ($matches as $match) {
            $name = $match[1] ?? $match[2] ?? throw new \LogicException('Invalid check headline token.');
            if (! array_key_exists($name, $params)) {
                throw new \LogicException('Missing check headline parameter: '.$key.' '.$name);
            }
            $replacements[$match[0]] = (string) $params[$name];
        }

        return strtr($template, $replacements);
    }
}
