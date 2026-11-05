<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\EmitIncidentReminders;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\DeliveryState;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
});

it('persists incident notes, reminder snooze and site pause through web routes', function (): void {
    $this->withoutVite();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $check = Check::factory()->create();
    $incident = Incident::factory()->for($check)->create();

    $this->actingAs($owner)->put('/incidents/'.$incident->id.'/note', ['note' => 'Private context'])->assertRedirect();
    expect($incident->refresh()->notes)->toBe('Private context')->and($incident->note_author_user_id)->toBe($owner->id);
    $this->post('/incidents/'.$incident->id.'/snooze', ['minutes' => 45])->assertRedirect();
    expect($incident->refresh()->snoozed_until)->not->toBeNull();
    $this->delete('/incidents/'.$incident->id.'/snooze')->assertRedirect();
    expect($incident->refresh()->snoozed_until)->toBeNull();

    $this->post('/sites/'.$check->site_id.'/pause')->assertRedirect();
    expect($check->site->refresh()->paused_at)->not->toBeNull();
    $this->post('/sites/'.$check->site_id.'/resume')->assertRedirect();
    expect($check->site->refresh()->paused_at)->toBeNull();
    expect(DB::table('unmonitored_periods')->where('site_id', $check->site_id)->whereNull('check_id')->whereNotNull('ended_at')->count())->toBe(1);
});

it('lets a scoped member snooze reminders, then resumes them at expiry and rejects hidden incidents', function (): void {
    $check = Check::factory()->create(['latest_run_result' => CheckResult::Failed]);
    $incident = Incident::factory()->for($check)->create(['opened_at' => now()->subHours(2)]);
    $opening = Event::factory()->create(['site_id' => $check->site_id, 'check_id' => $check->id,
        'incident_id' => $incident->id, 'type' => 'incident.opened', 'cause' => 'failed',
        'to_result' => CheckResult::Failed, 'occurred_at' => now()->subHours(2)]);
    $destination = notificationWebhook();
    Delivery::query()->create(['event_id' => $opening->id, 'destination_id' => $destination->id,
        'state' => DeliveryState::Sent, 'sent_at' => now()->subHours(2),
        'next_attempt_at' => now()->subHours(2), 'suppression_evaluated_at' => now()->subHours(2)]);
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($check->site->client_id);
    $token = $member->createToken('incident', ['checks:snooze'])->plainTextToken;
    $this->withToken($token)->postJson('/api/internal/incidents/'.$incident->id.'/snooze', ['minutes' => 90])->assertOk();
    $this->postJson('/api/internal/incidents/'.$incident->id.'/snooze', ['minutes' => 0])->assertUnprocessable();
    app(EmitIncidentReminders::class)();
    expect(Event::query()->where('type', 'incident.still_down')->count())->toBe(0);
    $this->postJson('/api/internal/incidents/'.Incident::factory()->create()->id.'/snooze', ['minutes' => 5])->assertNotFound();
    $this->travel(91)->minutes();
    app(EmitIncidentReminders::class)();
    expect(Event::query()->where('incident_id', $incident->id)->where('type', 'incident.still_down')->count())->toBe(1);
    $this->deleteJson('/api/internal/incidents/'.$incident->id.'/snooze')->assertOk()->assertJsonPath('snoozed_until', null);
});

it('keeps one internal note available to scoped team members but absent from downtime API', function (): void {
    $check = Check::factory()->create();
    $incident = Incident::factory()->for($check)->create();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($check->site->client_id);
    $token = $member->createToken('incident', ['incidents:note', 'incidents:read', 'downtime:read'])->plainTextToken;
    $this->withToken($token)->putJson('/api/internal/incidents/'.$incident->id.'/note', ['note' => 'Private investigation'])->assertOk()
        ->assertJsonPath('note', 'Private investigation')->assertJsonPath('note_author', $member->name);
    $this->getJson('/api/internal/incidents/'.$incident->id.'/note')->assertJsonPath('note', 'Private investigation');
    $this->getJson('/api/monitors/'.$check->site_id.'/downtime')->assertOk()->assertDontSee('Private investigation');
    $this->putJson('/api/internal/incidents/'.$incident->id.'/note', ['note' => str_repeat('x', 10001)])->assertUnprocessable();
    $this->putJson('/api/internal/incidents/'.Incident::factory()->create()->id.'/note', ['note' => 'hidden'])->assertNotFound();
    $this->putJson('/api/internal/incidents/'.$incident->id.'/note', ['note' => null])->assertOk()->assertJsonPath('note', null);
});
