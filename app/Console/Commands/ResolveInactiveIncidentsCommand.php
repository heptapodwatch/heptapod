<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Heptapod\Actions\ResolveInactiveCheckIncidents;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\IncidentResolutionReason;
use Heptapod\Enums\SiteStatus;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

#[Signature('heptapod:incidents:resolve-inactive {--dry-run : Preview resolutions without saving changes} {--actor= : Existing user ID to retain as the audit actor}')]
final class ResolveInactiveIncidentsCommand extends Command
{
    public function getDescription(): string
    {
        return __('incidents.resolve_inactive.description');
    }

    public function handle(ResolveInactiveCheckIncidents $resolve): int
    {
        $actor = null;
        $actorId = $this->option('actor');
        if ($actorId !== null) {
            if (! is_string($actorId) || preg_match('/^[1-9][0-9]*$/D', $actorId) !== 1) {
                $this->error(__('incidents.resolve_inactive.unknown_actor'));

                return self::FAILURE;
            }
            $actor = User::query()->find($actorId);
            if (! $actor instanceof User) {
                $this->error(__('incidents.resolve_inactive.unknown_actor'));

                return self::FAILURE;
            }
        }
        $dryRun = $this->option('dry-run') === true;
        /** @var int|null $maxId */
        $maxId = DB::table('incidents')->max('id');
        $maxId ??= 0;
        $lastId = 0;
        $count = 0;
        $visitedChecks = [];
        do {
            $query = Incident::query()->with(['check', 'site']);
            $query->getQuery()->where('id', '>', $lastId)->where('id', '<=', $maxId)
                ->where(static function (QueryBuilder $state): void {
                    $state->whereNull('resolved_at')->orWhereExists(static fn (QueryBuilder $waiting): QueryBuilder => $waiting->selectRaw('1')
                        ->from('events')->join('deliveries', 'deliveries.event_id', '=', 'events.id')
                        ->whereColumn('events.incident_id', 'incidents.id')->whereColumn('events.check_id', 'incidents.check_id')->where('events.type', 'incident.resolved')
                        ->whereIn('deliveries.state', [DeliveryState::Pending->value, DeliveryState::Failed->value])->where('deliveries.suppressed', false)
                        ->whereNotNull('incidents.alert_episode_started_at'));
                });
            $query
                ->where(static function (Builder $eligible): void {
                    $eligible->whereHas('check', static fn (Builder $check): Builder => $check->where('enabled', false))
                        ->orWhereHas('site', static function (Builder $site): void {
                            $site->getQuery()->whereNotNull('paused_at')->orWhereNotNull('marked_for_deletion_at');
                        });
                });
            $query->getQuery()->orderBy('id')->limit(100);
            $incidents = $query->get();
            $rows = [];
            foreach ($incidents as $incident) {
                $lastId = $incident->id;
                $check = $incident->check;
                $site = $incident->site;
                // Hard deletion can remove the candidate's relationships after the scan.
                if (! $check instanceof Check || ! $site instanceof Site || isset($visitedChecks[$check->id])) {
                    continue;
                }
                $visitedChecks[$check->id] = true;
                $reason = IncidentResolutionReason::forCheck($check, $site);
                // Eligibility can change between the candidate scan and eager loading.
                if (! $reason instanceof IncidentResolutionReason) {
                    continue;
                }
                $resolved = [];
                if ($dryRun) {
                    if ($incident->resolved_at === null) {
                        $resolved[] = $incident;
                    }
                } else {
                    $resolvedIds = $resolve($check, actor: $actor);
                    if ($resolvedIds !== []) {
                        $resolved = Incident::query()->whereKey($resolvedIds)->get()->all();
                    }
                }
                foreach ($resolved as $closed) {
                    $rows[] = [$closed->id, $site->displayName(), __('checks.'.$check->type->value.'.label'), __(SiteStatus::forIncident($check->type, $closed->cause)->labelKey()), __($reason->labelKey())];
                    $count++;
                }
            }
            if ($rows !== []) {
                $this->table([__('ID'), __('Site'), __('Check'), __('Cause'), __('Resolution reason')], $rows);
            }
        } while ($incidents->count() === 100);
        $this->info(trans_choice($dryRun ? 'incidents.resolve_inactive.would_resolve' : 'incidents.resolve_inactive.resolved', $count, ['count' => $count]));

        return self::SUCCESS;
    }
}
