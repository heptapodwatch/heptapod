<?php

declare(strict_types=1);

use Heptapod\Actions\StartMaintenance;
use Heptapod\Actions\StopMaintenance;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Models\Check;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\Client;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

it('audits public configuration changes with authenticated causer and excludes all encrypted secrets', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $client = Client::factory()->create();
    $site = Site::factory()->create(['client_id' => $client->id, 'crawler_headers' => [['name' => 'Authorization', 'value' => 'crawler-secret']]]);
    $check = Check::factory()->create(['site_id' => $site->id]);
    $schema = app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema;
    $check->update(['enabled' => false, 'config' => $schema->validateConfig(['http_client_headers' => [['name' => 'Authorization', 'value' => 'check-secret']]])]);
    $destination = NotificationDestination::factory()->create();
    $destination->update(['label' => 'Operator channel']);
    $destination->update(['config' => app(ChannelRegistry::class)->get('webhook')->configSchema()->validateConfig(['url' => 'https://hooks.example/destination-secret', 'secret' => 'destination-signature'])]);
    $site->update(['friendly_name' => 'Renamed', 'crawler_headers' => [['name' => 'X-Token', 'value' => 'new-secret']]]);
    $client->update(['name' => 'Changed']);
    $user->two_factor_secret = encrypt('2fa-secret');
    $user->fill(['name' => 'Operator', 'password' => 'password-secret'])->save();
    $ciphertext = $user->fresh()->getRawOriginal('two_factor_secret');
    expect($ciphertext)->toBeString();
    expect(decrypt($ciphertext))->toBe('2fa-secret');
    $rawUserAudit = json_encode(DB::table('activity_log')->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->get(), JSON_THROW_ON_ERROR);
    expect($rawUserAudit)->not->toContain('2fa-secret')->not->toContain($ciphertext);
    $snooze = CheckSnooze::factory()->create(['check_id' => $check->id]);
    $snooze->update(['unsnoozed_at' => now(), 'unsnoozed_by' => $user->id]);
    $period = app(StartMaintenance::class)($site);
    $this->travel(1)->seconds();
    app(StopMaintenance::class)($site);
    foreach ([$client, $site, $check, $user, $snooze, $period, $destination] as $subject) {
        expect(Activity::query()->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->where('event', 'updated')->where('causer_id', $user->id)->exists())->toBeTrue();
    }
    $json = Activity::query()->get()->toJson();
    foreach (['crawler-secret', 'check-secret', 'new-secret', 'password-secret', '2fa-secret', 'destination-secret', 'destination-signature'] as $secret) {
        expect($json)->not->toContain($secret);
    }
    $before = Activity::query()->count();
    $check->update(['next_run_at' => now()->addMinute()]);
    expect(Activity::query()->count())->toBe($before);
});

it('audits role attach and detach at the model event seam', function (): void {
    $operator = User::factory()->create();
    $this->actingAs($operator);
    $member = User::factory()->create();
    $member->assignRole('Member');
    $member->removeRole('Member');
    expect(Activity::query()->where('subject_type', $member->getMorphClass())->where('subject_id', $member->id)->where('causer_id', $operator->id)->whereIn('event', ['roles.attached', 'roles.detached'])->orderBy('id')->pluck('event')->all())->toBe(['roles.attached', 'roles.detached']);
});

it('records secret-only edits as field names and never persists plaintext or ciphertext', function (): void {
    $site = Site::factory()->create();
    $before = Activity::query()->count();
    $site->update(['crawler_headers' => [['name' => 'Authorization', 'value' => 'secret-only-value']]]);
    expect(Activity::query()->count())->toBe($before + 1);
    $activity = Activity::query()->latest('id')->firstOrFail();
    expect($activity->properties->get('changed_secret_fields'))->toBe(['crawler_headers']);
    $raw = json_encode(DB::table('activity_log')->where('id', $activity->id)->first(), JSON_THROW_ON_ERROR);
    expect($raw)->not->toContain('secret-only-value')->not->toContain($site->getRawOriginal('crawler_headers'));
});
