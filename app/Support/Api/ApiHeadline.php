<?php

declare(strict_types=1);

namespace Heptapod\Support\Api;

use Heptapod\Contracts\CheckHeadline;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\SummaryKey;

/**
 * Agent-facing headline from the shared check presenter.
 * `headline` is controlled text: every untrusted parameter (declared by the presenter) reads as a reference to a named
 * `headline_data` field. `display_headline` is the exact app text and is flagged when it quotes untrusted strings.
 */
final class ApiHeadline
{
    /** Query rule for the optional headline language; without it the token owner's locale applies. */
    public const array LocaleRule = ['sometimes', 'in:en,de'];

    public static function applyLocale(?string $locale): void
    {
        if ($locale !== null) {
            app()->setLocale($locale);
        }
    }

    /**
     * @param  string  $legacy  Generic text for retained data without a presenter or with a legacy free-text summary.
     * @param  bool  $checkError  The run or incident is a check error (we could not check), not a check finding.
     * @return array{headline: string, headline_status: 'presented'|'legacy_generic', headline_parts: array<string, mixed>|null, headline_data: object, display_headline: string|null, display_headline_untrusted: bool}
     */
    public static function project(?CheckRunPresentationData $presentation, string $legacy, CheckKey $check, bool $checkError): array
    {
        if (! $presentation instanceof CheckRunPresentationData) {
            return ['headline' => $legacy, 'headline_status' => 'legacy_generic', 'headline_parts' => null, 'headline_data' => (object) [],
                'display_headline' => null, 'display_headline_untrusted' => false];
        }
        $locale = app()->getLocale();
        if (self::isLegacy($presentation)) {
            // Retained free text is untrusted: the controlled headline is the generic label; the app text stays display-only.
            return ['headline' => $legacy, 'headline_status' => 'legacy_generic', 'headline_parts' => null, 'headline_data' => (object) [],
                'display_headline' => CheckHeadline::format($presentation, $locale), 'display_headline_untrusted' => true];
        }
        $controlled = CheckHeadline::controlled($presentation, $locale, $check, $checkError);
        $data = $controlled['data'];
        // Change and detail parts are not in the headline, but their untrusted values belong to the same named data.
        foreach ([...$presentation->changes, ...$presentation->details] as $part) {
            foreach ($part->untrustedFields() as $name => $field) {
                $data[$field][] = (string) $part->params[$name];
            }
        }

        return ['headline' => $controlled['text'], 'headline_status' => 'presented', 'headline_parts' => self::parts($presentation),
            'headline_data' => (object) $data, 'display_headline' => CheckHeadline::format($presentation, $locale),
            'display_headline_untrusted' => $controlled['data'] !== []];
    }

    /** Legacy rows retained free text as their summary key; see SummaryKey for the shared rule. */
    public static function isLegacy(CheckRunPresentationData $presentation): bool
    {
        return array_any(array_slice($presentation->parts, 0, $presentation->headline_count),
            static fn (CheckRunPresentationPartData $part): bool => SummaryKey::isFreeText($part->key));
    }

    /** @return array{reasons: list<array<string, mixed>>, more_reasons: int, names_check: bool, changes: list<array<string, mixed>>, details: list<array<string, mixed>>} */
    public static function parts(CheckRunPresentationData $presentation): array
    {
        return ['reasons' => array_map(self::part(...), array_slice($presentation->parts, 0, $presentation->headline_count)),
            'more_reasons' => $presentation->more_reasons, 'names_check' => $presentation->names_check,
            'changes' => array_map(self::part(...), $presentation->changes), 'details' => array_map(self::part(...), $presentation->details)];
    }

    /** @return array{key: string, params: object, lists?: object, labels: object, untrusted_fields: object} */
    private static function part(CheckRunPresentationPartData $part): array
    {
        $untrusted = $part->untrustedFields();
        // Preserve v1's canonical comma-joined parameters independently of headline localization.
        $params = array_map(static fn (array $values): string => implode(', ', $values), $part->lists)
            + array_diff_key($part->params, $untrusted);

        return ['key' => $part->key, 'params' => (object) $params, 'labels' => (object) $part->labels,
            'untrusted_fields' => (object) $untrusted,
            ...($part->lists === [] ? [] : ['lists' => (object) $part->lists])];
    }
}
