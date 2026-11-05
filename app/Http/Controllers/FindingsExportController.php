<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\FindingsExportProvider;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\SecretRedactor;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Queries\IncidentsReader;
use Heptapod\Support\RedactionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FindingsExportController extends Controller
{
    public function __invoke(Request $request, int $site, int $check, SitePolicy $sites, IncidentsReader $incidents, CheckRegistry $registry): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $siteRow = $sites->visibleSites($user, includeArchived: true)->whereKey($site)->firstOrFail();
        $checkRow = Check::query()->where('site_id', $site)->whereKey($check)->firstOrFail();
        /** @var array{run: int|string, observed_at: string, incident?: int|string|null} $values */
        $values = Validator::make($request->query(), ['run' => ['required', 'integer', 'min:1'], 'observed_at' => ['required', 'date'], 'incident' => ['nullable', 'integer', 'min:1']])->validate();
        $run = new RunReference((int) $values['run'], CarbonImmutable::parse($values['observed_at']));
        $episode = isset($values['incident']) ? $incidents->scoped($user)->where('check_id', $check)->whereKey((int) $values['incident'])->firstOrFail() : null;
        // Match the check tab's New marker, or the explicitly scoped episode's opening time.
        if (! $episode instanceof Incident) {
            $open = Incident::query();
            $open->getQuery()->where('check_id', $check)->whereNull('resolved_at')->orderBy('first_failure_at')->limit(1);
            $episode = $open->first();
        }
        $since = $episode instanceof Incident ? $episode->first_failure_at : null;
        abort_unless(in_array($checkRow->type, $registry->keys(), true), 404);
        $provider = $registry->get($checkRow->type)->persistence();
        abort_unless($provider instanceof FindingsExportProvider, 404);
        abort_if($provider->exportFindings($checkRow->type, $check, $run) === null, 404);
        $redaction = RedactionContext::forSite($siteRow, $checkRow);
        $timezone = $user->timezone;

        return response()->streamDownload(static function () use ($provider, $checkRow, $run, $redaction, $timezone, $since): void {
            DB::transaction(static function () use ($provider, $checkRow, $run, $redaction, $timezone, $since): void {
                // Providers share-lock the immutable accepted snapshot until paging finishes, preventing retention from deleting it.
                $rows = $provider->exportFindings($checkRow->type, $checkRow->id, $run)
                    ?? throw new \RuntimeException('Findings snapshot expired before the export started.');
                $output = fopen('php://output', 'w');
                if ($output === false) {
                    throw new \RuntimeException('Cannot open findings export stream.');
                }
                if (fwrite($output, "\xEF\xBB\xBF") !== 3 || fputcsv($output, array_map(__(...), ['URL', 'Result', 'Found on', 'Link text', 'Since', 'New']), ',', '"', '', "\r\n") === false) {
                    throw new \RuntimeException('Cannot write findings export header.');
                }
                foreach ($rows as $row) {
                    $firstSeen = $row['first_seen_at'] === null ? null : CarbonImmutable::parse($row['first_seen_at']);
                    // Match the page's second-precision retained-history New marker.
                    $values = [$row['url'], $row['result'], $row['found_on'] ?? '', $row['link_text'] ?? '',
                        $firstSeen instanceof CarbonImmutable ? $firstSeen->setTimezone($timezone)->format('Y-m-d H:i').' '.$timezone : __('Not retained'),
                        $row['after_first_complete_crawl'] && $firstSeen instanceof CarbonImmutable && $since !== null && $firstSeen->startOfSecond()->greaterThan($since->startOfSecond()) ? __('Yes') : __('No')];
                    $cells = array_map(static function (string $value) use ($redaction): string {
                        $text = SecretRedactor::redact($value, $redaction->secrets, $redaction->urls);

                        return preg_match('/^(?:[\x00-\x20]*[=+@-]|[\t\r\n])/u', $text) === 1 ? "'".$text : $text;
                    }, $values);
                    if (fputcsv($output, $cells, ',', '"', '', "\r\n") === false) {
                        throw new \RuntimeException('Cannot write findings export row.');
                    }
                }
                if (! fclose($output)) {
                    throw new \RuntimeException('Cannot close findings export stream.');
                }
            });
        }, 'findings.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Accel-Buffering' => 'no']);
    }
}
