<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use cebe\openapi\Reader;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Spectator\Validation\ResponseValidator;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-27T12:00:00Z'));
    $this->withoutVite();
});

/** A resolved incident with its opening, a failed run, the recovery run and both incident events, as the recorder writes them. */
function resolvedEpisode(CheckKey $type, IncidentCause $cause): Incident
{
    $check = Check::factory()->create(['type' => $type, 'latest_run_result' => CheckResult::Succeeded]);
    $failed = CheckRun::factory()->for($check)->create(['started_at' => now()->subMinutes(30)->subSecond(), 'ended_at' => now()->subMinutes(30),
        'result' => $cause === IncidentCause::Errored ? RunResult::Errored : RunResult::Failed, 'summary' => 'causes.http', 'summary_params' => ['status' => 503]]);
    $recovery = CheckRun::factory()->for($check)->create(['started_at' => now()->subMinutes(20)->subSecond(), 'ended_at' => now()->subMinutes(20),
        'result' => RunResult::Succeeded, 'summary' => 'causes.http', 'summary_params' => ['status' => 200]]);
    $incident = Incident::factory()->for($check)->create(['cause' => $cause, 'first_failure_at' => now()->subMinutes(30), 'opened_at' => now()->subMinutes(30),
        'resolved_at' => $recovery->ended_at, 'evidence' => ['run_id' => $failed->id, 'run_ended_at' => $failed->ended_at->format('Y-m-d\TH:i:s.uP'),
            'summary' => 'causes.http', 'summary_params' => ['status' => 503], 'evidence' => [], 'metrics' => null,
            'error' => $cause === IncidentCause::Errored ? '{"class":"RuntimeException","message":"timeout"}' : null]]);
    Event::factory()->for($check)->for($incident)->create(['type' => 'incident.opened', 'cause' => $cause, 'occurred_at' => $failed->ended_at,
        'to_result' => $cause === IncidentCause::Errored ? CheckResult::Errored : CheckResult::Failed,
        'payload' => ['run_id' => $failed->id, 'run_ended_at' => $failed->ended_at->format('Y-m-d\TH:i:s.uP'), 'summary' => 'causes.http', 'summary_params' => ['status' => 503]]]);
    Event::factory()->for($check)->for($incident)->create(['type' => 'incident.resolved', 'cause' => $cause, 'occurred_at' => $recovery->ended_at,
        'from_result' => CheckResult::Failed, 'to_result' => CheckResult::Succeeded,
        'payload' => ['run_id' => $recovery->id, 'run_ended_at' => $recovery->ended_at->format('Y-m-d\TH:i:s.uP'), 'summary' => 'causes.http', 'summary_params' => ['status' => 200]]]);

    return $incident;
}

it('renders a resolved incident as the episode page with evidence at close and the recovery in its timeline', function (CheckKey $type, IncidentCause $cause): void {
    $owner = User::factory()->owner()->create();
    $incident = resolvedEpisode($type, $cause);

    $this->actingAs($owner)->get('/incidents/'.$incident->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('incidents/show')
        ->where('incident.resolved_at', $incident->resolved_at->toIso8601String())
        // The same presented headline as the incidents list and the API: the opening run's presentation.
        ->has('incident.presentation.parts', 1)
        // Evidence at close comes only from the episode: opening snapshot, recovery event and the runs between them.
        ->where('evidence.summary', 'causes.http')->where('evidence.recovered_at', $incident->resolved_at->toIso8601String())
        ->where('evidence.recovery_summary', 'causes.http')
        ->has('runs', 2)->where('runs.0.result', 'succeeded')->where('runs.1.result', $cause === IncidentCause::Errored ? 'errored-or-timed-out' : 'failed')
        // Never the check's current state on a resolved incident.
        ->where('current_evidence', null)->where('current_findings', null)->where('current_presentation', null)
        ->where('timeline', fn ($timeline): bool => collect($timeline)->pluck('event.type')->filter()->values()->all() === ['incident.opened', 'incident.resolved'])
        ->where('can_snooze', false)->where('can_unsnooze', false)
        ->missing('events')->missing('deliveries')->missing('sibling_sites')->missing('can_manage')->missing('episode_available'));
})->with([
    'resolved Down' => [CheckKey::Uptime, IncidentCause::Failed],
    'resolved Failed' => [CheckKey::Dns, IncidentCause::Failed],
    'resolved Check error' => [CheckKey::Uptime, IncidentCause::Errored],
]);

it('shows an open incident of every type with the same current evidence as its check page', function (CheckKey $type): void {
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->create(['type' => $type, 'latest_run_result' => CheckResult::Failed]);
    $incident = Incident::factory()->for($check)->create(['first_failure_at' => now()->subMinutes(5), 'opened_at' => now()->subMinutes(5)]);
    Event::factory()->for($check)->for($incident)->create(['type' => 'incident.opened', 'cause' => 'failed', 'occurred_at' => $incident->opened_at, 'to_result' => CheckResult::Failed]);
    $checkPage = $this->actingAs($owner)->get('/sites/'.$check->site_id.'/checks/'.$check->id)->assertOk()->inertiaProps();

    $props = $this->get('/incidents/'.$incident->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('incidents/show')->where('incident.resolved_at', null)->where('timeline.0.event.type', 'incident.opened')
        ->missing('events')->missing('episode_available'))->inertiaProps();

    expect($props['current_findings'])->toEqual($checkPage['history'])
        ->and($props['current_evidence'])->toEqual($checkPage['detail']);
})->with([CheckKey::Cron, CheckKey::Dns, CheckKey::ApplicationHealth, CheckKey::Domain, CheckKey::MixedContent, CheckKey::Performance]);

it('lists mail of incident events outside the timeline once, with the event named and the preview for Owners only', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron, 'latest_run_result' => CheckResult::Failed]);
    $incident = Incident::factory()->for($check)->create(['first_failure_at' => now()->subMinutes(5), 'opened_at' => now()->subMinutes(5)]);
    $opened = Event::factory()->for($check)->for($incident)->create(['type' => 'incident.opened', 'cause' => 'failed', 'occurred_at' => now()->subMinutes(5), 'to_result' => CheckResult::Failed]);
    $missed = Event::factory()->for($check)->for($incident)->create(['type' => 'cron.task_result_changed', 'component_type' => 'cron', 'component_id' => 1,
        'occurred_at' => now()->subMinutes(4), 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Failed,
        'payload' => ['component_name' => 'Nightly backup', 'from_result' => 'succeeded', 'result' => 'failed', 'summary' => 'cron.task_failed', 'summary_params' => ['presentation' => 'direct']]]);
    $destination = NotificationDestination::factory()->create();
    foreach ([$opened, $missed] as $event) {
        Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $destination->id, 'state' => 'sent', 'sent_at' => $event->occurred_at,
            'next_attempt_at' => $event->occurred_at, 'suppression_evaluated_at' => $event->occurred_at]);
    }
    $owner = User::factory()->owner()->create();
    $member = User::factory()->member()->create();

    $this->actingAs($owner)->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page
        ->has('other_deliveries', 1)->where('other_deliveries.0.event_id', $missed->id)
        ->where('other_deliveries.0.event_label', 'Nightly backup: Failed')->where('other_deliveries.0.state', 'accepted')
        ->where('other_deliveries.0.can_preview', false)->where('alert_preview_available', true)
        // The opening mail stays in its timeline group, not in the other list.
        ->where('timeline.1.notification_group.event_type', 'incident.opened')->where('timeline.1.notification_group.accepted_count', 1));
    $this->actingAs($member)->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('alert_preview_available', false)->where('other_deliveries.0.address', 'Hidden recipient'));
});

it('names an active maintenance window and an opening during muted maintenance', function (): void {
    $owner = User::factory()->owner()->create();
    $check = Check::factory()->create(['type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Failed]);
    $window = MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'mute_alerts' => true]);
    $incident = Incident::factory()->for($check)->create(['first_failure_at' => now()->subMinutes(5), 'opened_at' => now()->subMinutes(5),
        'opened_during_maintenance_window_id' => $window->id]);

    $this->actingAs($owner)->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('maintenance.id', $window->id)->where('maintenance.state', 'active')->where('maintenance.muteAlerts', true)
        ->where('opened_during_maintenance', true));
});

it('ends the API incident timeline of a resolved incident with its recovery, valid against the published contract', function (): void {
    $owner = User::factory()->owner()->create();
    $incident = resolvedEpisode(CheckKey::Uptime, IncidentCause::Failed);
    $token = $owner->createToken('read', ['incidents:read'])->plainTextToken;

    // ApiTestCase validates against the pinned Oh Dear spec, which has no Heptapod incident endpoints; validate against ours.
    $response = app(HttpKernel::class)->handle(Request::create('/api/incidents/'.$incident->id, server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']));
    ResponseValidator::validate($response, Reader::readFromJsonFile(base_path('docs/openapi.json'))->paths['/incidents/{incident}']->get, '3.1.2');

    $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    expect($response->getStatusCode())->toBe(200)->and($data['state'])->toBe('resolved')->and($data['timeline_total'])->toBe(2)
        ->and(array_column($data['timeline'], 'event_type'))->toBe(['incident.opened', 'incident.resolved'])
        ->and(array_column($data['timeline'], 'kind'))->toBe(['event', 'event']);
});

it('names an open uptime-dependent incident by the outage on the incident page, the sites strip and the API alike', function (): void {
    $owner = User::factory()->owner()->create();
    $uptime = Check::factory()->create(['type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Failed]);
    $outage = Incident::factory()->for($uptime)->create(['first_failure_at' => now()->subMinutes(10), 'opened_at' => now()->subMinutes(10)]);
    $sitemap = Check::factory()->for($uptime->site)->create(['type' => CheckKey::Sitemap, 'latest_run_result' => CheckResult::Errored]);
    $dependent = Incident::factory()->for($sitemap)->create(['cause' => IncidentCause::Errored, 'first_failure_at' => now()->subMinutes(5),
        'opened_at' => now()->subMinutes(5), 'evidence' => ['uptime_parent_incident_id' => $outage->id]]);

    $this->actingAs($owner)->get('/incidents/'.$dependent->id)->assertInertia(fn (Assert $page): Assert => $page
        ->where('incident.dependency_headline_key', 'Affected by the uptime outage'));
    $this->get('/sites')->assertInertia(fn (Assert $page): Assert => $page
        ->where('needs_attention.0.checks', fn ($checks): bool => collect($checks)->firstWhere('id', $sitemap->id)['dependency_headline_key'] === 'Affected by the uptime outage'));
    $response = app(HttpKernel::class)->handle(Request::create('/api/incidents/'.$dependent->id, server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$owner->createToken('read', ['incidents:read'])->plainTextToken, 'HTTP_ACCEPT' => 'application/json']));
    $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    expect($data['headline_status'])->toBe('uptime_dependency')->and($data['headline'])->toBe('Affected by the uptime outage');

    $dependent->update(['resolved_at' => now()]);
    $this->get('/incidents/'.$dependent->id)->assertInertia(fn (Assert $page): Assert => $page->where('incident.dependency_headline_key', null));
});

it('lets a scoped Member snooze and clear reminders and write the note but not run checks, and hides other clients incidents on both routes', function (): void {
    $member = User::factory()->member()->create(['client_scope' => ClientScope::Restricted]);
    $check = Check::factory()->create(['type' => CheckKey::Uptime, 'latest_run_result' => CheckResult::Failed]);
    $member->clients()->attach($check->site->client_id);
    $visible = Incident::factory()->for($check)->create(['first_failure_at' => now()->subMinutes(5), 'opened_at' => now()->subMinutes(5),
        'snoozed_until' => now()->addHour()]);
    $hidden = Incident::factory()->create(['first_failure_at' => now()->subMinutes(5), 'opened_at' => now()->subMinutes(5)]);

    // The decided H2 policy: Members with client access may snooze, clear a snooze and write the internal note; only Owner/Admin run checks.
    $this->actingAs($member)->get('/incidents/'.$visible->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('can_run', false)->where('can_snooze', true)->where('can_unsnooze', true)->where('alert_preview_available', false));
    $this->put('/incidents/'.$visible->id.'/note', ['note' => 'Member note'])->assertRedirect();
    expect($visible->refresh()->notes)->toBe('Member note');

    $this->get('/incidents/'.$hidden->id)->assertNotFound();
    $this->put('/incidents/'.$hidden->id.'/note', ['note' => 'leak'])->assertNotFound();
    expect($hidden->refresh()->notes)->toBeNull();
    $response = app(HttpKernel::class)->handle(Request::create('/api/incidents/'.$hidden->id, server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$member->createToken('read', ['incidents:read'])->plainTextToken, 'HTTP_ACCEPT' => 'application/json']));
    expect($response->getStatusCode())->toBe(404)->and((string) $response->getContent())->not->toContain((string) $hidden->site?->url);
});
