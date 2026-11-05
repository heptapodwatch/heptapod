<?php

declare(strict_types=1);

namespace Heptapod\Incidents;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\AvailabilityFailure;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\IncidentCause;
use Heptapod\Events\EventOutbox;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Incident;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** One site's matching target-availability failures form one notification problem, not one incident. */
final class UptimeDependencies
{
    public const string SuppressionReason = 'uptime_dependency';

    public const array DependentChecks = [CheckKey::Lighthouse, CheckKey::Sitemap, CheckKey::BrokenLinks, CheckKey::MixedContent];

    public static function parentId(?Incident $incident): ?int
    {
        $id = $incident?->evidence['uptime_parent_incident_id'] ?? null;
        if ($id !== null && ! is_int($id)) {
            throw new \LogicException('Uptime dependency must retain an integer incident ID.');
        }

        return $id;
    }

    public static function isGrouped(?Incident $incident): bool
    {
        return self::parentId($incident) !== null && ! self::hasOwnOpening($incident);
    }

    private static function hasOwnOpening(?Incident $incident): bool
    {
        return $incident instanceof Incident && (isset($incident->evidence['uptime_dependency_released_at'])
            || ($incident->evidence['notification_opening_accepted'] ?? false) === true);
    }

    /**
     * The headline that replaces an open incident's own reason while the uptime outage explains it; null keeps its own.
     * Every surface (app rows, check strips, API) renders this one key.
     */
    public static function headlineKey(bool $open, bool $heldAfterRecovery, ?int $parentIncidentId): ?string
    {
        return match (true) {
            ! $open => null,
            $heldAfterRecovery => 'Re-checking after uptime recovery',
            $parentIncidentId !== null => 'Affected by the uptime outage',
            default => null,
        };
    }

    public static function recheckAfter(?Incident $incident): ?string
    {
        $at = $incident?->evidence['uptime_recheck_after'] ?? null;
        if ($at !== null && ! is_string($at)) {
            throw new \LogicException('Uptime re-check requires a recovery timestamp.');
        }

        return $at;
    }

    /** @param literal-string $alias
     * @return literal-string
     */
    public static function heldAfterRecoverySql(string $alias): string
    {
        return "{$alias}.resolved_at IS NULL AND {$alias}.evidence->>'uptime_parent_incident_id' IS NOT NULL"
            ." AND {$alias}.evidence->>'uptime_dependency_released_at' IS NULL"
            ." AND {$alias}.evidence->>'uptime_recheck_after' IS NOT NULL"
            ." AND NOT ({$alias}.evidence @> '{\"notification_opening_accepted\":true}'::jsonb)";
    }

    /** @param list<int> $incidentIds
     * @return array<int, true>
     */
    public static function heldAfterRecovery(array $incidentIds): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('incidents')->whereIntegerInRaw('id', $incidentIds)->whereRaw(self::heldAfterRecoverySql('incidents'))->pluck('id')->all();

        return array_fill_keys($ids, true);
    }

    public static function failure(?Incident $incident): ?AvailabilityFailure
    {
        $value = $incident?->evidence['availability_failure'] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new \LogicException('Availability failure must be a named class.');
        }

        return $value === null ? null : AvailabilityFailure::from($value);
    }

    /**
     * The checks an uptime recording on this site may insert a released opening for: every dependent check of the site.
     * A check never changes its site, so this is read before any lock.
     *
     * @return list<int>
     */
    public static function dependentCheckIds(int $siteId): array
    {
        /** @var list<int> */
        return DB::table('checks')->where('site_id', $siteId)
            ->whereIn('type', array_map(static fn (CheckKey $type): string => $type->value, self::DependentChecks))->orderBy('id')->pluck('id')->all();
    }

    /** @return Collection<int, Incident> */
    public function openForSite(int $siteId): Collection
    {
        $query = Incident::query()->with('check');
        $query->getQuery()->where('site_id', $siteId)->whereNull('resolved_at');

        return $query->get();
    }

    public function synchronize(Check $check, CheckRun $run, Incident $incident, EventOutbox $outbox): void
    {
        if ($check->type !== CheckKey::Uptime && ! in_array($check->type, self::DependentChecks, true)) {
            return;
        }
        $incident->update(['evidence' => [...$incident->evidence, 'availability_failure' => $run->evidence['availability_failure'] ?? null]]);
        $open = $this->openForSite($check->site_id);
        $uptime = $open->first(static fn (Incident $candidate): bool => $candidate->check?->type === CheckKey::Uptime);
        if ($check->type === CheckKey::Uptime) {
            foreach ($open as $dependent) {
                if (! in_array($dependent->check?->type, self::DependentChecks, true)) {
                    continue;
                }
                if (self::isGrouped($dependent) && self::parentId($dependent) === $incident->id) {
                    if ($incident->cause === IncidentCause::Errored) {
                        $this->release($dependent, $run->ended_at, $outbox);
                    } elseif ($incident->resolved_at !== null) {
                        $dependent->update(['evidence' => [...$dependent->evidence,
                            'uptime_recheck_after' => $incident->resolved_at->toISOString()]]);
                    }
                } elseif ($uptime instanceof Incident && $this->shouldGroupWith($uptime, $dependent)) {
                    $this->group($dependent, $uptime, $run->ended_at);
                }
            }
        } elseif (in_array($check->type, self::DependentChecks, true) && $incident->resolved_at === null) {
            if ($uptime instanceof Incident && $this->shouldGroupWith($uptime, $incident)) {
                $this->group($incident, $uptime, $run->ended_at);
            } elseif (self::isGrouped($incident)) {
                $after = self::recheckAfter($incident);
                if ($after === null || ($run->started_at ?? throw new \LogicException('A re-check observation needs its start time.'))->greaterThan(CarbonImmutable::parse($after))) {
                    $this->release($incident, $run->ended_at, $outbox);
                }
            }
        }
    }

    private function shouldGroupWith(Incident $uptime, Incident $dependent): bool
    {
        return $uptime->cause !== IncidentCause::Errored && ! self::hasOwnOpening($dependent) && ($this->matches($uptime, $dependent) || self::isGrouped($dependent)
            && (self::parentId($dependent) === $uptime->id || self::recheckAfter($dependent) !== null));
    }

    private function matches(Incident $uptime, Incident $dependent): bool
    {
        return $uptime->site_id === $dependent->site_id && $uptime->resolved_at === null
            && $uptime->check?->type === CheckKey::Uptime && in_array($dependent->check?->type, self::DependentChecks, true)
            && self::failure($uptime) instanceof AvailabilityFailure && self::failure($uptime) === self::failure($dependent);
    }

    private function group(Incident $dependent, Incident $uptime, CarbonImmutable $at): void
    {
        if (self::hasOwnOpening($dependent) || self::isGrouped($dependent) && self::parentId($dependent) === $uptime->id) {
            return;
        }
        $evidence = [...$dependent->evidence, 'uptime_parent_incident_id' => $uptime->id];
        unset($evidence['uptime_recheck_after']);
        $dependent->update(['evidence' => $evidence]);
        // Provider I/O already admitted cannot be recalled; everything still queued is suppressed.
        $eventIds = DB::table('events')->where('site_id', $dependent->site_id)->where('check_id', $dependent->check_id)->where('incident_id', $dependent->id)
            ->where('occurred_at', '>=', $dependent->opened_at->format('Y-m-d H:i:s.uP'))
            ->where('occurred_at', '<=', $at->format('Y-m-d H:i:s.uP'))->select('id');
        $queued = DB::table('deliveries')->whereIn('event_id', $eventIds)->where('suppressed', false)->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Failed->value]);
        // NotificationLocks: the deliveries in ascending id before the bulk update (archive and claim take them in that order).
        $locked = NotificationLocks::deliveries((clone $queued)->pluck('id'));
        $queued->whereIn('id', $locked)->update(['suppressed' => true,
            'suppression_reason' => self::SuppressionReason, 'suppression_evaluated_at' => $at->format('Y-m-d H:i:s.uP')]);
    }

    private function release(Incident $dependent, CarbonImmutable $at, EventOutbox $outbox): void
    {
        if (self::hasOwnOpening($dependent)) {
            return;
        }
        $parentId = self::parentId($dependent) ?? throw new \LogicException('A deferred opening needs its uptime parent.');
        $dependent->update(['evidence' => [...$dependent->evidence, 'uptime_dependency_released_at' => $at->toISOString()]]);
        $check = $dependent->check ?? throw new \LogicException('A dependent incident must have a check.');
        $outbox->record($check, $at, 'incident.opened', CheckResult::Pending, $check->latest_run_result,
            ['summary' => $check->summary, 'summary_params' => $check->summary_params,
                'uptime_parent_incident_id' => $parentId], $dependent, $dependent->cause,
            'uptime-release:'.$dependent->id.':'.$parentId.':'.$at->format('U.u'));
    }

    /** @return list<string> */
    public function rechecking(Incident $uptime): array
    {
        return array_values($this->openForSite($uptime->site_id)->filter(static fn (Incident $dependent): bool => self::isGrouped($dependent)
            && self::parentId($dependent) === $uptime->id && self::recheckAfter($dependent) !== null)
            ->map(static fn (Incident $dependent): string => ($dependent->check ?? throw new \LogicException('Dependent check missing.'))->type->value)->all());
    }

    /** @return list<string> */
    public function affected(Incident $uptime): array
    {
        if ($uptime->resolved_at !== null) {
            return [];
        }

        return array_values($this->openForSite($uptime->site_id)->filter(static fn (Incident $dependent): bool => self::isGrouped($dependent)
            && self::parentId($dependent) === $uptime->id)
            ->map(static fn (Incident $dependent): string => ($dependent->check ?? throw new \LogicException('Dependent check missing.'))->type->value)
            ->all());
    }
}
