<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Contracts\AlertPreviewRenderer;
use Heptapod\Contracts\CheckDetailData;
use Heptapod\Contracts\CheckHistoryData;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Data\Ui\ClientData;
use Heptapod\Data\Ui\IncidentData;
use Heptapod\Data\Ui\IncidentDetailPageData;
use Heptapod\Data\Ui\IncidentEvidenceData;
use Heptapod\Data\Ui\IncidentNotificationPageData;
use Heptapod\Data\Ui\MaintenanceSiteHeaderData;
use Heptapod\Data\Ui\SiteIdentityData;
use Heptapod\Data\Ui\SiteRowData;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Incident;
use Heptapod\Models\User;
use Heptapod\Queries\IncidentsReader;
use Heptapod\Queries\SiteDetailReader;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\Lazy;

final class IncidentDetailController extends Controller
{
    public function __invoke(Request $request, int $incident, IncidentsReader $reader, SiteDetailReader $details, CheckRunPresentations $presentations): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $row = $reader->scoped($user)->with('noteAuthor')->whereKey($incident)->firstOrFail();
        $getIncident = static fn (): Incident => $row;
        $check = $row->check ?? throw new \LogicException('Incident check missing.');
        $site = $row->site ?? throw new \LogicException('Incident site missing.');
        $open = $row->resolved_at === null;
        Validator::make($request->query(), [
            'notification_group' => ['nullable', 'string', 'regex:/^(delivery|event):[1-9][0-9]*$/D', 'required_with:notification_before'],
            'notification_before' => ['nullable', 'date', 'required_with:notification_before_id'],
            'notification_before_id' => ['nullable', 'integer', 'min:1', 'required_with:notification_before'],
        ])->validate();
        $groupId = $request->filled('notification_group') ? $request->string('notification_group')->toString() : null;
        $cursor = $request->filled('notification_before') ? $request->string('notification_before')->toString().'|'.$request->integer('notification_before_id') : null;
        $explicitExpansion = $request->header('X-Inertia-Partial-Component') === 'incidents/show'
            && in_array('expanded_notifications', explode(',', $request->header('X-Inertia-Partial-Data', '')), true);

        return Inertia::render('incidents/show', new IncidentDetailPageData(
            site: Lazy::closure(static fn (): SiteRowData => $details->site($user, $getIncident()->site_id)),
            header: Lazy::closure(static fn (): SiteIdentityData => $details->header($user, $getIncident()->site_id)),
            client: Lazy::closure(static fn (): ClientData => $details->client($user, $details->site($user, $getIncident()->site_id))),
            incident: Lazy::closure(static fn (): IncidentData => $reader->presentedRows(collect([$getIncident()]))[0]),
            runs: Lazy::closure(static fn (): array => $details->runs($getIncident()->check_id, $getIncident()->first_failure_at, $getIncident()->resolved_at)),
            internal_note: Lazy::closure(static fn (): ?string => $getIncident()->notes),
            note_author: Lazy::closure(static fn (): ?string => $getIncident()->noteAuthor?->name),
            note_updated_at: Lazy::closure(static fn (): ?string => $getIncident()->note_updated_at?->toIso8601String()),
            other_deliveries: Lazy::closure(static fn (): array => $reader->deliveries($user, $getIncident(), IncidentsReader::TimelineEventTypes)),
            evidence: Lazy::closure(static fn (): IncidentEvidenceData => $reader->evidence($getIncident())),
            alert_preview_available: Lazy::closure(static fn (): bool => $user->hasAnyRole(['Owner', 'Admin']) && app()->bound(AlertPreviewRenderer::class)),
            maintenance: Lazy::closure(static fn (): ?MaintenanceSiteHeaderData => $details->maintenance($user, $getIncident()->site_id)),
            opened_during_maintenance: Lazy::closure(static fn (): bool => $getIncident()->opened_during_maintenance_window_id !== null),
            opening_presentation: Lazy::closure(static fn (): ?CheckRunPresentationData => $presentations->atOpening($getIncident())),
            // A resolved incident shows only episode-scoped evidence, never the check's current state.
            current_presentation: Lazy::closure(static fn (): ?CheckRunPresentationData => $open ? $details->currentPresentation($check) : null),
            current_evidence: Lazy::closure(static fn (): ?CheckDetailData => $open ? $details->detail($check) : null),
            current_findings: Lazy::closure(static fn (): ?CheckHistoryData => $open ? $details->checkHistory($user, $check) : null),
            timeline: Lazy::closure(static fn (): array => $reader->timeline($user, $row)),
            related_incidents: Lazy::closure(static fn (): array => $reader->relatedIncidents($user, $row)),
            expanded_notifications: Lazy::closure(static fn (): ?IncidentNotificationPageData => $groupId !== null ? $reader->expandedNotifications($user, $row, $groupId, $cursor, requireGroup: $explicitExpansion) : null),
            can_run: Lazy::closure(static fn (): bool => Gate::forUser($user)->allows('update', $check)),
            can_snooze: Lazy::closure(static fn (): bool => $open && $check->type === CheckKey::Uptime && $row->cause === IncidentCause::Failed && $check->enabled && $check->latest_run_result === CheckResult::Failed && $site->paused_at === null && $site->marked_for_deletion_at === null && config('notifications.renotify_intervals_seconds') !== null && Gate::forUser($user)->allows('snooze', $check)),
            can_unsnooze: Lazy::closure(static fn (): bool => $open && $row->snoozed_until !== null && $row->snoozed_until->isFuture() && Gate::forUser($user)->allows('snooze', $check)),
            opening_delivery_explanation: Lazy::closure(static fn (): ?string => $reader->openingDeliveryExplanation($getIncident())),
            mail_design: Lazy::closure(static fn (): MailDesign => app(InstanceSettings::class)->mail_design),
        ));
    }
}
