<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var bool Snapshot transactions must release locks before processing the next retained dataset. */
    public $withinTransaction = false;

    public function up(): void
    {
        // Each legacy snapshot is atomic and restartable. Its JSON/UI/CSV projection stays untouched.
        DB::table('sitemap_snapshots')->whereNull('findings_coverage')->orderBy('id')->chunkById(100, static function ($snapshots): void {
            foreach ($snapshots as $snapshot) {
                DB::transaction(static function () use ($snapshot): void {
                    $row = DB::table('sitemap_snapshots')->where('id', $snapshot->id)->whereNull('findings_coverage')->lockForUpdate()->first(['id']);
                    if ($row === null) {
                        return;
                    }
                    DB::statement("INSERT INTO sitemap_findings (snapshot_id, position, identity, identity_hash, status, issue_code, http_status, kind, url, reason, carried_forward)
                        SELECT snapshot.id, finding.position, finding.issue, encode(sha256(convert_to(finding.issue::text, 'UTF8')), 'hex'),
                            CASE WHEN finding.issue->>'type' IN ('sitemap.issue.time_limit', 'sitemap.issue.url_limit', 'sitemap.issue.recursion_limit') THEN 'coverage_limit'
                                WHEN jsonb_exists(finding.issue, 'reason') THEN 'not_verifiable' ELSE 'broken' END,
                            finding.issue->>'type', (finding.issue->>'status')::smallint, finding.issue->>'kind', finding.issue->>'url', finding.issue->>'reason', finding.carried_forward
                        FROM sitemap_snapshots snapshot
                        CROSS JOIN LATERAL (
                            SELECT original.issue, original.position, NULL::boolean AS carried_forward
                            FROM jsonb_array_elements(COALESCE(snapshot.snapshot->'issues', '[]'::jsonb) || COALESCE(snapshot.snapshot->'not_verifiable', '[]'::jsonb)) WITH ORDINALITY original(issue, position)
                            UNION ALL
                            SELECT retained.issue, jsonb_array_length(COALESCE(snapshot.snapshot->'issues', '[]'::jsonb) || COALESCE(snapshot.snapshot->'not_verifiable', '[]'::jsonb)) + row_number() OVER (ORDER BY retained.issue), true
                            FROM (
                                SELECT value AS issue FROM jsonb_array_elements(COALESCE(snapshot.comparison_issues, '[]'::jsonb))
                                EXCEPT SELECT value FROM jsonb_array_elements(COALESCE(snapshot.snapshot->'issues', '[]'::jsonb))
                            ) retained
                        ) finding
                        WHERE snapshot.id = ? ON CONFLICT (snapshot_id, position) DO NOTHING", [$row->id]);
                    DB::table('sitemap_snapshots')->where('id', $row->id)->update(['findings_coverage' => json_encode([
                        'discovery_complete' => null, 'crawl_truncated' => null, 'page_cap_reached' => null, 'resource_cap_reached' => null,
                        'time_limit_reached' => null, 'comparison_limit_reached' => null, 'stored_rows_complete' => null,
                        'stored_not_verifiable_complete' => null, 'unchecked_rows_available' => false, 'legacy' => true,
                        'counters' => null, 'limits' => null,
                    ], JSON_THROW_ON_ERROR)]);
                });
            }
        });
    }

    public function down(): void
    {
        // Derived legacy rows can be rebuilt; the original JSON remains the authority for UI/CSV.
        DB::table('sitemap_findings')->whereIn('snapshot_id', DB::table('sitemap_snapshots')->select('id')->where('findings_coverage->legacy', true))->delete();
        DB::table('sitemap_snapshots')->where('findings_coverage->legacy', true)->update(['findings_coverage' => null]);
    }
};
