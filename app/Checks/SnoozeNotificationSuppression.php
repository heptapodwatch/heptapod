<?php

namespace Heptapod\Checks;

use Carbon\CarbonImmutable;
use Closure;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Contracts\SuppressionDecision;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Models\Check;
use Heptapod\Models\MaintenancePeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class SnoozeNotificationSuppression implements NotificationSuppression
{
    /** @var array<int, int> */
    private array $siteIds = [];

    /** @var array<int, \stdClass> */
    private array $maintenance = [];

    /** @var array<int, list<\stdClass>> */
    private array $snoozes = [];

    private ?CarbonImmutable $preloadedAt = null;

    /**
     * Reuse the individual site's targeting query in one page-bounded statement, not a second targeting rule.
     *
     * @template T
     *
     * @param  Collection<int, Check>  $checks
     * @param  Closure(): T  $read
     * @return T
     */
    public function withPreloadedChecks(Collection $checks, CarbonImmutable $at, Closure $read): mixed
    {
        if ($checks->isEmpty()) {
            return $read();
        }
        $previous = [$this->siteIds, $this->maintenance, $this->snoozes, $this->preloadedAt];
        try {
            $this->siteIds = [];
            foreach ($checks as $check) {
                $this->siteIds[$check->id] = $check->site_id;
            }
            $this->maintenance = [];
            $this->snoozes = [];
            $query = null;
            foreach (array_unique($this->siteIds) as $siteId) {
                $arm = DB::query()->selectRaw('?::bigint as site_id', [$siteId])
                    ->selectSub($this->maintenanceQuery($siteId, $at)->select('starts_at')->limit(1), 'starts_at');
                $query = $query === null ? $arm : $query->unionAll($arm);
            }
            /** @var \stdClass&object{site_id: int, starts_at: string|null} $source */
            foreach ($query?->get() ?? [] as $source) {
                if ($source->starts_at !== null) {
                    $this->maintenance[$source->site_id] = $source;
                }
            }
            $whole = $this->activeSnoozes(DB::table('check_snoozes')->whereIn('check_id', $checks->modelKeys())->whereNull('component_type'), $at);
            $component = $this->activeSnoozes(DB::table('check_snoozes')->whereIn('check_id', $checks->modelKeys())->whereNotNull('component_type'), $at);
            /** @var \stdClass&object{check_id: int} $source */
            foreach (DB::query()->fromSub($whole->unionAll($component), 'active_snoozes')->orderBy('starts_at')->orderBy('id')->get() as $source) {
                $this->snoozes[$source->check_id][] = $source;
            }
            $this->preloadedAt = $at;

            return $read();
        } finally {
            [$this->siteIds, $this->maintenance, $this->snoozes, $this->preloadedAt] = $previous;
        }
    }

    public function isSuppressed(SuppressionSubject $subject, CarbonImmutable $at): SuppressionDecision
    {
        $preloaded = $this->preloadedAt?->equalTo($at) === true && array_key_exists($subject->checkId, $this->siteIds);
        $siteId = $preloaded ? $this->siteIds[$subject->checkId] : DB::table('checks')->where('id', $subject->checkId)->value('site_id');
        $maintenance = is_int($siteId) ? ($preloaded ? ($this->maintenance[$siteId] ?? null)
            : $this->maintenanceQuery($siteId, $at)->first(['starts_at'])) : null;
        if ($maintenance !== null) {
            return new SuppressionDecision(true, 'maintenance', $at, $this->sourceStart($maintenance));
        }
        if ($preloaded) {
            $active = null;
            foreach ($this->snoozes[$subject->checkId] ?? [] as $source) {
                if ($source->component_type === null || ($subject->componentType instanceof SuppressionComponent
                    && $source->component_type === $subject->componentType->value && $source->component_id === $subject->componentId)) {
                    $active = $source;
                    break;
                }
            }
        } else {
            $active = $this->activeSnoozes(DB::table('check_snoozes')->where('check_id', $subject->checkId)
                ->where(function (Builder $query) use ($subject): void {
                    $query->whereNull('component_type');
                    if ($subject->componentType instanceof SuppressionComponent) {
                        $query->orWhere(fn (Builder $component) => $component
                            ->where('component_type', $subject->componentType->value)
                            ->where('component_id', $subject->componentId));
                    }
                }), $at)->orderBy('starts_at')->orderBy('id')->first(['starts_at']);
        }

        return new SuppressionDecision($active !== null, $active !== null ? 'snooze' : null, $at,
            $active === null ? null : $this->sourceStart($active));
    }

    private function maintenanceQuery(int $siteId, CarbonImmutable $at): Builder
    {
        return MaintenancePeriod::forSite($siteId, $at)->getQuery()->where('mute_alerts', true)
            ->where('starts_at', '<=', $at->format('Y-m-d H:i:s.uP'))->where('ends_at', '>', $at->format('Y-m-d H:i:s.uP'))
            ->orderBy('starts_at')->orderBy('id');
    }

    private function activeSnoozes(Builder $query, CarbonImmutable $at): Builder
    {
        $timestamp = $at->format('Y-m-d H:i:s.uP');

        return $query->where('starts_at', '<=', $timestamp)->where('ends_at', '>', $timestamp)
            ->where(fn (Builder $active) => $active->whereNull('unsnoozed_at')->orWhere('unsnoozed_at', '>', $timestamp));
    }

    private function sourceStart(\stdClass $source): CarbonImmutable
    {
        if (! is_string($source->starts_at)) {
            throw new \LogicException('A suppression source must retain its start timestamp.');
        }

        return CarbonImmutable::parse($source->starts_at);
    }
}
