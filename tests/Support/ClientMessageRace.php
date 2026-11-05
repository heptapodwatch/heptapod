<?php

declare(strict_types=1);

use Heptapod\Actions\SendClientMessage;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\RecipientKind;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationPreference;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;

/** Committed client-message fixtures for the non-Gate multi-process tests; each returns its own cleanup. */
/** @return array{owner: User, client: Client, payload: array<string, mixed>, cleanup: Closure(): void} */
function clientMessageRaceFixture(int $alreadySent): array
{
    $roleIds = Role::query()->pluck('id')->all();
    $owner = User::factory()->create();
    $owner->assignRole('Owner');
    $client = Client::factory()->create();
    $site = Site::factory()->create(['client_id' => $client->id, 'url' => 'https://race-'.$client->id.'.example.test']);
    $check = Check::factory()->create(['site_id' => $site->id, 'type' => CheckKey::BrokenLinks, 'latest_run_result' => CheckResult::Failed]);
    $run = CheckRun::factory()->for($check)->create(['result' => RunResult::Failed, 'summary' => 'broken_links.failed',
        'started_at' => now()->subMinutes(10)->startOfSecond(), 'ended_at' => now()->subMinutes(8)->startOfSecond()]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at]);
    $snapshot = DB::table('crawl_snapshots')->insertGetId(['site_id' => $site->id, 'check_id' => $check->id, 'run_id' => $run->id,
        'claim_token' => (string) Str::uuid(), 'run_ended_at' => $run->ended_at, 'collected_at' => $run->ended_at, 'settings' => '{}',
        'truncated' => false, 'observed_urls' => '[]', 'observed_pages' => '[]', 'discovered_urls' => '[]', 'discovery_complete' => true,
        'evaluated_finding_ids' => '[]', 'site_url' => $site->url, 'target_fingerprint' => str_repeat('a', 64), 'classification_policy_version' => null]);
    DB::table('crawl_findings')->insert(['snapshot_id' => $snapshot, 'crawled_url' => $site->url.'/gone', 'relative_crawled_url' => '/gone',
        'status_code' => 404, 'type' => 'link', 'internal' => true]);
    $office = NotificationDestination::query()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => $client->id, 'channel' => 'mail',
        'label' => 'Office', 'enabled' => true, 'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => 'office@race.test'])]);
    NotificationPreference::writeInitialGrid($office->id, NotificationPreference::defaults(RecipientKind::Client));
    $payload = ['kind' => 'findings', 'design' => 'branded', 'check_id' => $check->id, 'channel_ids' => [$office->id]];
    for ($i = 0; $i < $alreadySent; $i++) {
        app(SendClientMessage::class)->send($owner, $client, $payload, 'seed-'.$i);
    }
    DB::commit();

    return ['owner' => $owner, 'client' => $client, 'payload' => $payload, 'cleanup' => static function () use ($client, $owner, $roleIds): void {
        $events = DB::table('events')->where('client_id', $client->id)->pluck('id');
        $paths = DB::table('client_message_attachments')->whereIn('event_id', $events)->pluck('path');
        DB::table('deliveries')->whereIn('event_id', $events)->delete();
        DB::table('client_message_attachments')->whereIn('event_id', $events)->delete();
        DB::table('activity_log')->where('causer_id', $owner->id)->delete();
        DB::table('events')->whereIn('id', $events)->delete();
        DB::table('notification_preferences')->whereIn('destination_id', DB::table('notification_destinations')->where('client_id', $client->id)->select('id'))->delete();
        DB::table('notification_destinations')->where('client_id', $client->id)->delete();
        foreach (DB::table('checks')->whereIn('site_id', DB::table('sites')->where('client_id', $client->id)->select('id'))->pluck('id') as $checkId) {
            DB::table('crawl_findings')->whereIn('snapshot_id', DB::table('crawl_snapshots')->where('check_id', $checkId)->select('id'))->delete();
            DB::table('crawl_snapshots')->where('check_id', $checkId)->delete();
        }
        Site::query()->where('client_id', $client->id)->get()->each->delete();
        $client->delete();
        $owner->delete();
        Role::query()->whereNotIn('id', $roleIds)->delete();
        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }
    }];
}
