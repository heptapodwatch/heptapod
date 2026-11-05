<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\Schema\ValidatedConfig;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunRollupDaily;
use Heptapod\Models\Site;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Check-detail review data for the attention scenario; requires DemoSeeder's demo-01 and demo-09 sites. */
final class CheckDetailReviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Review fixtures are restricted to local and testing environments.');
        }
        $site = Site::query()->where('url', 'https://demo-01.example')->first();
        if ($site === null) {
            $this->call(DemoSeeder::class);
            $site = Site::query()->where('url', 'https://demo-01.example')->firstOrFail();
        }
        if (in_array(CheckKey::Lighthouse, app(CheckRegistry::class)->keys(), true)) {
            $lighthouseSite = Site::query()->where('url', 'https://demo-09.example')->firstOrFail();
            $lighthouse = Check::query()->where('site_id', $lighthouseSite->id)->where('type', CheckKey::Lighthouse)->firstOrFail();
            if (! CheckRunRollupDaily::query()->where('check_id', $lighthouse->id)->getQuery()->exists()) {
                $query = CheckRun::query()->where('check_id', $lighthouse->id);
                $query->getQuery()->orderByDesc('ended_at');
                $run = $query->firstOrFail();
                $scores = array_filter($run->metrics ?? [], static fn (mixed $value, string $name): bool => str_ends_with($name, '_score') && is_numeric($value), ARRAY_FILTER_USE_BOTH);
                CheckRunRollupDaily::factory()->for($lighthouse)->create([
                    'bucket_start' => $run->ended_at->startOfDay(),
                    'metric_sums' => $scores, 'metric_counts' => array_fill_keys(array_keys($scores), 1),
                ]);
            }
        }
        if (Check::query()->where('site_id', $site->id)->where('type', CheckKey::CertificateHealth)->getQuery()->exists()) {
            return;
        }
        $now = CarbonImmutable::now('UTC');
        DB::transaction(function () use ($site, $now): void {
            $certificate = $this->check($site, CheckKey::CertificateHealth);
            if ($certificate instanceof Check) {
                foreach (['Example CA', 'Review CA'] as $index => $issuer) {
                    $at = $now->subDays(2 - $index);
                    $run = $this->recordRun($certificate, $at, RunResult::Warning, 'certificate_health.expires_soon');
                    $this->stage($certificate, $run, 'snapshot', [
                        'certificate_details' => [
                            'fingerprint' => str_repeat($index === 0 ? 'a' : 'b', 64), 'issuer' => $issuer,
                            'domain' => 'demo-01.example', 'additional_domains' => ['www.demo-01.example'],
                            'valid_from' => $now->subMonths(2)->toIso8601String(), 'valid_until' => $now->addDays(9)->toIso8601String(),
                            'signature_algorithm' => 'ECDSA P-256', 'is_valid' => true,
                        ],
                        'certificate_checks' => [['type' => 'trust', 'passed' => true, 'label' => 'certificate_health.trust']],
                        'certificate_chain_issuers' => [$issuer],
                    ]);
                }
            }
            $this->dns($site, $now);
            $this->crawl($site, $now, CheckKey::BrokenLinks);
            $this->crawl($site, $now, CheckKey::MixedContent);
            $this->health($site, $now);
            $sitemap = $this->check($site, CheckKey::Sitemap);
            if ($sitemap instanceof Check) {
                $this->recordRun($sitemap, $now->subHour(), RunResult::Succeeded, 'sitemap.succeeded');
            }
        });
    }

    private function dns(Site $site, CarbonImmutable $now): void
    {
        $check = $this->check($site, CheckKey::Dns);
        if (! $check instanceof Check) {
            return;
        }
        foreach (['192.0.2.1', '192.0.2.2'] as $index => $ip) {
            $at = $now->subHours(2 - $index);
            $run = $this->recordRun($check, $at, $index === 0 ? RunResult::Succeeded : RunResult::Warning, 'dns.changed');
            $record = ['host' => 'demo-01.example', 'class' => 'IN', 'type' => 'A', 'ttl' => 300, 'ip' => $ip];
            $this->stage($check, $run, 'snapshot', [
                'dns_records' => [$record], 'raw_dns_records' => [],
                'normalized_records' => ['demo-01.example|A' => [array_diff_key($record, ['ttl' => true])]],
                'issues' => [], 'authoritative_nameservers' => [], 'expected' => [], 'baseline_generation' => 0,
            ]);
        }
    }

    private function crawl(Site $site, CarbonImmutable $now, CheckKey $type): void
    {
        $check = $this->check($site, $type);
        if (! $check instanceof Check) {
            return;
        }
        $at = $now->subHours(2);
        $accepted = $this->recordRun($check, $at, RunResult::Warning,
            $type === CheckKey::BrokenLinks ? 'broken_links.failed' : 'mixed_content.failed');
        $this->stage($check, $accepted, 'artifact', [
            'site_url' => $site->url, 'target_fingerprint' => str_repeat('c', 64),
            'config_fingerprint' => hash('sha256', json_encode($check->executionConfig(), JSON_THROW_ON_ERROR)),
            'collected_at' => $at->toIso8601String(),
            'settings' => ['max_pages' => 1204], 'truncated' => true, 'page_cap_reached' => true, 'time_limit_reached' => false,
            'observed_urls' => [], 'observed_pages' => [], 'discovered_urls' => [], 'discovery_complete' => false, 'evaluated_finding_ids' => [],
            // Literal crawl-addon contract values: core seeders do not import addon classes.
            'classification_policy_version' => 1, 'notes' => [['key' => 'crawl.discovery.page_limit', 'params' => [], 'labels' => []]],
            'urls' => $type === CheckKey::BrokenLinks ? [[
                'crawled_url' => $site->url.'/missing', 'relative_crawled_url' => '/missing', 'status_code' => 404,
                'found_on_url' => $site->url, 'relative_found_on_url' => '/', 'link_text' => null,
                'type' => 'broken', 'error_message' => null, 'current_error_message' => null, 'internal' => true,
            ]] : [],
            'mixed_content' => $type === CheckKey::MixedContent ? [[
                'element_name' => 'img', 'mixed_content_url' => 'http://demo-01.example/logo.png',
                'found_on_url' => $site->url, 'source_url' => $site->url,
            ]] : [],
        ]);
        $this->recordRun($check, $now->subHour(), RunResult::Errored, 'checks.engine.errored', [
            'finding_ids' => [], 'truncated' => true, 'unchecked_count' => 0,
            'discovery_complete' => false, 'crawl_truncated' => true, 'page_cap' => 1204,
            'observed_pages' => 30, 'observed_urls' => 40, 'discovered_urls' => 50,
            'page_cap_reached' => $type === CheckKey::MixedContent, 'resource_cap_reached' => false, 'time_limit_reached' => false,
            'comparison_limit_reached' => $type === CheckKey::BrokenLinks, 'notes' => [],
        ]);
    }

    private function health(Site $site, CarbonImmutable $now): void
    {
        $registry = app(CheckRegistry::class);
        if (! in_array(CheckKey::ApplicationHealth, $registry->keys(), true)) {
            return;
        }
        $schema = $registry->get(CheckKey::ApplicationHealth)->definition()->configSchema;
        $check = $this->check($site, CheckKey::ApplicationHealth, $schema->validateConfig([
            'result_url' => $site->url.'/health', 'secret' => 'review-health-secret',
        ]));
        if (! $check instanceof Check) {
            return;
        }
        $at = $now->subHours(2);
        $componentId = DB::table('application_health_components')->insertGetId([
            'check_id' => $check->id, 'source_key' => hash('sha256', 'Database'), 'detected_at' => $at,
        ]);
        $accepted = $this->recordRun($check, $at, RunResult::Warning, 'application_health.summary', [],
            ['succeeded' => 0, 'warning' => 1, 'failed' => 0]);
        $this->stage($check, $accepted, 'components', [
            'components' => [['snapshot' => [
                'id' => $componentId, 'name' => 'Database', 'label' => 'Database', 'status' => 'warning',
                'notificationMessage' => 'Slow queries', 'shortSummary' => 'Queries are slow',
                'meta' => [], 'latest_result' => 'warning', 'unresolved_result' => 'warning',
            ], 'change_identity' => hash('sha256', 'Database warning')]],
        ]);
        $this->recordRun($check, $now->subHour(), RunResult::Errored, 'application_health.http_status',
            ['reason' => 'http_status', 'http_status' => 401]);
    }

    private function check(Site $site, CheckKey $type, ?ValidatedConfig $config = null): ?Check
    {
        $registry = app(CheckRegistry::class);
        if (! in_array($type, $registry->keys(), true)) {
            return null;
        }
        $definition = $registry->get($type)->definition();

        return Check::factory()->for($site)->create([
            'type' => $type, 'config' => $config ?? $definition->configSchema->validateConfig([]),
            'interval_seconds' => $definition->defaultInterval, 'next_run_at' => now('UTC')->addSeconds($definition->defaultInterval),
        ]);
    }

    /** @param array<string, mixed> $evidence
     * @param  array<string, int>  $summaryParams
     */
    private function recordRun(Check $check, CarbonImmutable $at, RunResult $result, string $summary, array $evidence = [], array $summaryParams = []): CheckRun
    {
        $run = CheckRun::factory()->for($check)->create([
            'started_at' => $at->subSeconds(12), 'ended_at' => $at,
            'result' => $result, 'summary' => $summary, 'summary_params' => $summaryParams, 'evidence' => $evidence,
        ]);
        $check->update([
            'latest_run_id' => $run->id, 'latest_run_started_at' => $run->started_at ?? throw new \LogicException('Review run has no start time.'),
            'latest_run_ended_at' => $at, 'latest_run_result' => CheckResult::from($result->value),
            'summary' => $summary, 'summary_params' => $summaryParams,
        ]);

        return $run;
    }

    /** @param array<string, mixed> $row */
    private function stage(Check $check, CheckRun $run, string $dataset, array $row): void
    {
        $token = (string) Str::uuid();
        $site = $check->site()->getQuery()->firstOrFail();
        $context = new CheckContext($check->site_id, $check->id, $run->id, $token, $site->url,
            $run->started_at ?? throw new \LogicException('Review run has no start time.'),
            $run->ended_at->addMinute(), $check->executionConfig(), [], null);
        $persistence = app(CheckRegistry::class)->get($check->type)->persistence()
            ?? throw new \LogicException('Review check requires an addon persistence contract.');
        $persistence->stage($context, $dataset, [$row]);
        $persistence->finalize(new RunReference($run->id, $run->ended_at), $token);
    }
}
