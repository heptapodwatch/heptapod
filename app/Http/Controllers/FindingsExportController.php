<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Contracts\CheckHeadline;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\Export\Column;
use Heptapod\Contracts\Export\Table;
use Heptapod\Contracts\Findings\FindingStatus;
use Heptapod\Contracts\FindingsExportProvider;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\SecretRedactor;
use Heptapod\Enums\ExportFormat;
use Heptapod\Exceptions\ExpectedRefusal;
use Heptapod\Export\ExportResponse;
use Heptapod\Http\ListPageSize;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Incident;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Queries\IncidentsReader;
use Heptapod\Support\RedactionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FindingsExportController extends Controller
{
    /** GET sites/{site}/checks/{check}/findings.{format}: one retained findings snapshot; each group's `<group>_page` and `<group>_per_page` (This page) limit it to the rows that page lists. */
    public function __invoke(Request $request, int $site, int $check, ExportFormat $format, SitePolicy $sites, IncidentsReader $incidents, CheckRegistry $registry, CheckRunPresentations $presentations): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $siteRow = $sites->visibleSites($user, includeArchived: true)->whereKey($site)->firstOrFail();
        $checkRow = Check::query()->where('site_id', $site)->whereKey($check)->firstOrFail();
        $checkRow->setRelation('site', $siteRow);
        $window = ['sometimes', 'integer', 'min:1'];
        $size = ['sometimes', 'integer', Rule::in(ListPageSize::Sizes)];
        /** @var array{run: int|string, observed_at: string, incident?: int|string|null, findings_page?: int|string, findings_per_page?: int|string, not_verifiable_page?: int|string, not_verifiable_per_page?: int|string} $values */
        $values = Validator::make($request->query(), ['run' => ['required', 'integer', 'min:1'], 'observed_at' => ['required', 'date'],
            'incident' => ['nullable', 'integer', 'min:1'], 'findings_page' => $window, 'findings_per_page' => $size,
            'not_verifiable_page' => $window, 'not_verifiable_per_page' => $size])->validate();
        $run = new RunReference((int) $values['run'], CarbonImmutable::parse($values['observed_at']));
        $scoped = isset($values['incident']) ? $incidents->scoped($user)->where('check_id', $check)->whereKey((int) $values['incident'])->firstOrFail() : null;
        // Match the check tab's New marker, or the explicitly scoped episode's opening time.
        $episode = $scoped;
        if (! $episode instanceof Incident) {
            $open = Incident::query();
            $open->getQuery()->where('check_id', $check)->whereNull('resolved_at')->orderBy('first_failure_at')->limit(1);
            $episode = $open->first();
        }
        $since = $episode instanceof Incident ? $episode->first_failure_at : null;
        abort_unless(in_array($checkRow->type, $registry->keys(), true), 404);
        $provider = $registry->get($checkRow->type)->persistence();
        abort_unless($provider instanceof FindingsExportProvider, 404);
        $redaction = RedactionContext::forSite($siteRow, $checkRow);
        $redact = static fn (string $value): string => SecretRedactor::redact($value, $redaction->secrets, $redaction->urls);
        $timezone = $user->resolvedTimezone();
        $time = static fn (CarbonImmutable $at): string => $at->setTimezone($timezone)->format('Y-m-d H:i').' '.$timezone;
        // This page: each paged group's [first, last] row position on the listed page; an unpaged request takes every row.
        $pages = [];
        foreach (['finding' => 'findings', 'not_verifiable' => 'not_verifiable'] as $group => $list) {
            if (isset($values[$list.'_page']) || isset($values[$list.'_per_page'])) {
                $perPage = (int) ($values[$list.'_per_page'] ?? ListPageSize::Default);
                $first = ((int) ($values[$list.'_page'] ?? 1) - 1) * $perPage + 1;
                $pages[$group] = [$first, $first + $perPage - 1];
            }
        }
        $paged = $pages !== [];

        $latest = $checkRow->latest_run_id === null || $checkRow->latest_run_ended_at === null ? null
            : CheckRun::forRun(new RunReference($checkRow->latest_run_id, $checkRow->latest_run_ended_at))->where('check_id', $check)->first();
        $presentation = $presentations->forRun($checkRow, $latest);
        $checkLabel = (string) __('checks.'.$checkRow->type->value.'.label');
        // The monitored URL can carry a credential query parameter; the redactor masks it like every other exported URL.
        $facts = [['label' => __('Site'), 'value' => $redact($siteRow->displayName().' ('.$siteRow->url.')')], ['label' => __('Check'), 'value' => $checkLabel],
            ...($presentation instanceof CheckRunPresentationData ? [['label' => __('Status'), 'value' => $redact(CheckHeadline::format($presentation, app()->getLocale(), $checkRow->type))]] : []),
            ...($scoped instanceof Incident ? [['label' => __('Incident'), 'value' => '#'.$scoped->id]] : []),
            ...($latest instanceof CheckRun ? [['label' => __('Checked at'), 'value' => $time($latest->ended_at)]] : []),
            ...($latest?->id === $run->id ? [] : [['label' => __('Findings from'), 'value' => $time($run->endedAt)]])];
        $rows = (static function () use ($provider, $checkRow, $site, $check, $run, $redact, $time, $since, $pages, $paged): \Generator {
            // ExportResponse runs this generator inside one share-locked transaction before sending HTTP headers, so
            // an expired snapshot always becomes HTTP 410 without a TOCTOU window between pre-check and stream start.
            $rows = $provider->exportFindings($checkRow->type, $checkRow->id, $run);
            if ($rows === null) {
                throw new ExpectedRefusal(
                    status: 410,
                    messageKey: 'errors.findings.expired',
                    errorCode: 'findings_snapshot_expired',
                    titleKey: 'errors.findings.expired_title',
                    recoveryRoute: 'sites.checks.show',
                    recoveryRouteParams: ['site' => $site, 'check' => $check],
                    recoveryLabelKey: 'Back to check',
                );
            }
            // The page lists each group (findings, not verifiable) in this order; once every group is past its listed page,
            // the provider's remaining pages are not read. A group without a page window has no rows on This page.
            $counts = ['finding' => 0, 'not_verifiable' => 0];
            foreach ($rows as $row) {
                $group = $row['status'] === FindingStatus::NotVerifiable ? 'not_verifiable' : 'finding';
                $counts[$group]++;
                if ($paged && ! isset($pages[$group])) {
                    continue;
                }
                if ($paged && ($counts[$group] < $pages[$group][0] || $counts[$group] > $pages[$group][1])) {
                    if (array_all($pages, static fn (array $page, string $name): bool => $counts[$name] >= $page[1])) {
                        return;
                    }

                    continue;
                }
                $firstSeen = $row['first_seen_at'] === null ? null : CarbonImmutable::parse($row['first_seen_at']);
                // Match the page's second-precision retained-history New marker.
                $new = $row['after_first_complete_crawl'] && $firstSeen instanceof CarbonImmutable && $since !== null && $firstSeen->startOfSecond()->greaterThan($since->startOfSecond());
                yield array_map($redact, [$row['url'], $row['result'], $row['found_on'] ?? '', $row['link_text'] ?? '',
                    $firstSeen instanceof CarbonImmutable ? $time($firstSeen) : (string) __('Not retained'), (string) __($new ? 'Yes' : 'No')]);
            }
        })();

        return ExportResponse::make(new Table(
            $redact((string) __('export.findings.title', ['check' => $checkLabel, 'site' => $siteRow->displayName()])),
            $facts,
            array_map(static fn (string $label): Column => new Column((string) __($label)), ['URL', 'Result', 'Found on', 'Link text', 'Since', 'New']),
            $rows,
            Str::slug($checkLabel.' '.str_replace('.', ' ', (string) parse_url($siteRow->url, PHP_URL_HOST)).' '.$run->endedAt->setTimezone($timezone)->format('Y-m-d'), language: app()->getLocale()),
        ), $format);
    }
}
