<?php

declare(strict_types=1);

namespace Heptapod\Addons\CertificateHealth;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\UpcomingEventSource;
use Heptapod\Contracts\UpcomingOccurrence;
use Heptapod\Contracts\UpcomingOccurrencePage;
use Heptapod\Contracts\UpcomingPosition;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class CertificateUpcomingSource implements UpcomingEventSource
{
    private const string Expiry = "(CASE WHEN snapshot->'certificate_details'->>'valid_until' ~ '(Z|[+-][0-9]{2}:[0-9]{2})$' THEN (snapshot->'certificate_details'->>'valid_until')::timestamptz ELSE (snapshot->'certificate_details'->>'valid_until')::timestamp AT TIME ZONE 'UTC' END)";

    private const string Key = "('certificate:' || check_id::text) COLLATE \"C\"";

    public function read(array $visibleCheckIds, CarbonImmutable $from, CarbonImmutable $until): array
    {
        if ($visibleCheckIds === []) {
            return [];
        }
        /** @var Collection<int, \stdClass&object{check_id: int, snapshot: string}> $rows */
        $rows = $this->datedSnapshots($visibleCheckIds, $from, $until)->get();
        $items = [];
        foreach ($rows as $row) {
            /** @var array{certificate_details?: array{valid_until?: string, issuer?: string}|null} $snapshot */
            $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $details = $snapshot['certificate_details'] ?? null;
            if ($details === null || ! isset($details['valid_until'])) {
                continue;
            }
            $date = CarbonImmutable::parse($details['valid_until']);
            $items[] = new UpcomingOccurrence($row->check_id, 'certificate:'.$row->check_id, $date, 'certificate', $details['issuer'] ?? '');
        }

        return $items;
    }

    public function count(array $visibleCheckIds, CarbonImmutable $from, CarbonImmutable $until): int
    {
        return $visibleCheckIds === [] ? 0 : $this->datedSnapshots($visibleCheckIds, $from, $until)->count();
    }

    public function page(array $visibleCheckIds, CarbonImmutable $from, CarbonImmutable $until, ?UpcomingPosition $after, int $limit): UpcomingOccurrencePage
    {
        if ($visibleCheckIds === []) {
            return new UpcomingOccurrencePage([], false);
        }
        $query = $this->datedSnapshots($visibleCheckIds, $from, $until)->select(['check_id', 'snapshot'])->selectRaw(self::Expiry.' AS at');
        if ($after instanceof UpcomingPosition) {
            $query->whereRaw('('.self::Expiry.', '.self::Key.') > (?::timestamptz, ? COLLATE "C")', [$after->at->format('Y-m-d H:i:s.uP'), $after->key]);
        }
        /** @var Collection<int, \stdClass&object{check_id: int, snapshot: string, at: string}> $rows */
        $rows = $query->orderByRaw(self::Expiry)->orderByRaw(self::Key)->limit($limit + 1)->get();
        $items = [];
        foreach ($rows->take($limit) as $row) {
            /** @var array{certificate_details: array{issuer?: string}} $snapshot */
            $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $items[] = new UpcomingOccurrence($row->check_id, 'certificate:'.$row->check_id, CarbonImmutable::parse($row->at)->utc(), 'certificate', $snapshot['certificate_details']['issuer'] ?? '');
        }

        return new UpcomingOccurrencePage($items, $rows->count() > $limit);
    }

    /** @param list<int> $visibleCheckIds */
    private function datedSnapshots(array $visibleCheckIds, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        $latest = DB::table('certificate_health_snapshots')->selectRaw('DISTINCT ON (check_id) check_id, snapshot')
            ->whereIntegerInRaw('check_id', $visibleCheckIds)->whereNotNull('run_ended_at')->whereNotNull('snapshot->certificate_details->valid_until')
            ->orderBy('check_id')->orderByDesc('run_ended_at')->orderByDesc('run_id');

        return DB::query()->fromSub($latest, 'latest')->whereRaw(self::Expiry.' BETWEEN ? AND ?', [
            $from->format('Y-m-d H:i:s.uP'), $until->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
