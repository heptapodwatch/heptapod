<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

/** A broken-links check whose latest accepted snapshot holds three broken links and one blocked (not verifiable) link. */
function findingsExportCheck(Client $client): Check
{
    $site = Site::factory()->create(['client_id' => $client->id, 'url' => 'https://shop.example.test', 'friendly_name' => 'Shop']);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::BrokenLinks, 'latest_run_result' => CheckResult::Failed]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'summary' => 'broken_links.failed',
        'started_at' => CarbonImmutable::parse('2026-10-28T21:38:00Z'), 'ended_at' => CarbonImmutable::parse('2026-10-28T21:40:00Z')]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    $snapshot = DB::table('crawl_snapshots')->insertGetId(['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $run->id,
        'claim_token' => '00000000-0000-4000-8000-000000000001', 'run_ended_at' => $run->ended_at, 'collected_at' => $run->ended_at,
        'settings' => '{}', 'truncated' => false, 'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]',
        'discovery_complete' => true, 'evaluated_finding_ids' => '[]', 'site_url' => $site->url, 'target_fingerprint' => str_repeat('a', 64),
        'classification_policy_version' => null]);
    foreach (['=Part 1', "Part 2 | spare\nparts", '＋Part 3'] as $index => $text) {
        $path = '/missing-'.($index + 1);
        DB::table('crawl_findings')->insert(['snapshot_id' => $snapshot, 'crawled_url' => 'https://shop.example.test'.$path, 'relative_crawled_url' => $path,
            'status_code' => 404, 'found_on_url' => 'https://shop.example.test/catalog', 'link_text' => $text, 'type' => 'link', 'internal' => true]);
    }
    DB::table('crawl_findings')->insert(['snapshot_id' => $snapshot, 'crawled_url' => 'https://shop.example.test/blocked', 'relative_crawled_url' => '/blocked',
        'status_code' => 403, 'error_message' => 'bot_protection', 'type' => 'link', 'internal' => true]);

    return $check;
}

/** @param array<string, int> $query */
function findingsExportUrl(Check $check, string $format, array $query = []): string
{
    $ended = $check->latest_run_ended_at ?? throw new LogicException('The check needs a run.');

    return route('sites.checks.findings', ['site' => $check->site_id, 'check' => $check->id, 'format' => $format,
        'run' => $check->latest_run_id, 'observed_at' => $ended->utc()->format('Y-m-d\TH:i:s.uP'), ...$query]);
}

it('exports a findings snapshot as CSV, Markdown and plain text, limits it to the shown rows and keeps other clients out', function (): void {
    $client = Client::factory()->create();
    $check = findingsExportCheck($client);
    $owner = User::factory()->create(['timezone' => 'Europe/Berlin']);
    $owner->assignRole('Owner');
    $this->actingAs($owner);

    $csv = $this->get(findingsExportUrl($check, 'csv'))->assertOk()->assertDownload('broken-links-shop-example-test-2026-10-28.csv');
    expect($csv->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($csv->headers->get('Cache-Control'))->toContain('no-store');
    $content = $csv->streamedContent();
    expect($content)->toStartWith("\xEF\xBB\xBFURL,Result,\"Found on\",\"Link text\",Since,New\r\n")
        ->and(explode("\r\n", rtrim($content, "\r\n")))->toHaveCount(5)
        ->and($content)->toContain(",\"'=Part 1\",", ",\"'＋Part 3\",", "\"Part 2 | spare\nparts\"", 'https://shop.example.test/blocked');

    $markdown = $this->get(findingsExportUrl($check, 'md'))->assertOk()->assertDownload('broken-links-shop-example-test-2026-10-28.md')->streamedContent();
    expect($markdown)->toStartWith("# Broken links findings · Shop\n\n- Site: Shop (https://shop.example.test)\n- Check: Broken links\n")
        ->toContain("- Checked at: 2026-10-28 22:40 Europe/Berlin\n\n| URL | Result | Found on | Link text | Since | New |\n| --- | --- | --- | --- | --- | --- |\n")
        ->toContain('| https://shop.example.test/missing-1 | 404 | https://shop.example.test/catalog | =Part 1 | 2026-10-28 22:40 Europe/Berlin | No |')
        ->toContain('| Part 2 \| spare<br>parts |')
        ->not->toContain('Findings from', 'Holds the first');

    $text = $this->get(findingsExportUrl($check, 'txt'))->assertOk()->streamedContent();
    expect($text)->toContain("\n\nhttps://shop.example.test/missing-2\nResult: 404\nFound on: https://shop.example.test/catalog\nLink text: Part 2 | spare parts\nSince: 2026-10-28 22:40 Europe/Berlin\nNew: No\n\n");

    // The card lists the first row of each group: one broken link and the blocked one.
    $shown = $this->get(findingsExportUrl($check, 'md', ['shown' => 1]))->assertOk()->streamedContent();
    expect($shown)->toContain('/missing-1 ', '/blocked ')->not->toContain('/missing-2', '/missing-3');

    $other = Client::factory()->create();
    $restricted = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $restricted->assignRole('Member');
    $restricted->clients()->attach($other);
    $this->actingAs($restricted)->get(findingsExportUrl($check, 'csv'))->assertNotFound();
    $this->get(findingsExportUrl($check, 'md'))->assertNotFound();
    $this->actingAs($owner)->get(findingsExportUrl($check, 'pdf'))->assertNotFound();
});
