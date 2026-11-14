<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;

require_once __DIR__.'/../Support/NotificationChannels.php';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
});

function firstOwnerAlertsCommand(bool $ifMissing = false): string
{
    $command = Artisan::all()['heptapod:user:create-owner'];
    $input = new ArrayInput(['command' => 'heptapod:user:create-owner', '--email' => 'owner@example.test', '--name' => 'First Owner', '--password-stdin' => true, '--if-missing' => $ifMissing]);
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, "Local-test-password-2026!\n");
    rewind($stream);
    $input->setStream($stream);
    $outputStream = fopen('php://temp', 'w+');
    $output = new StreamOutput($outputStream);
    try {
        expect($command->run($input, $output))->toBe(0);
        rewind($outputStream);

        return stream_get_contents($outputStream);
    } finally {
        fclose($stream);
        fclose($outputStream);
    }
}

/** @return array<string, string> */
function firstOwnerGrid(int $channelId): array
{
    /** @var array<string, string> */
    return DB::table('notification_preferences')->where('destination_id', $channelId)->orderBy('category')->pluck('mode', 'category')->all();
}

it('sets up all-site alerts on the first owners account email and leaves later edits alone', function (): void {
    Mail::fake();
    expect(firstOwnerAlertsCommand())->toBe("Owner created: owner@example.test\n");
    $owner = User::query()->sole();
    $destination = NotificationDestination::query()->sole();
    expect($destination->user_id)->toBe($owner->id);
    expect($destination->channel)->toBe('mail');
    expect($destination->enabled)->toBeTrue();
    expect($destination->account_mail)->toBeTrue();
    expect($destination->destinationConfig()->string('email'))->toBe('owner@example.test');
    // Every site (no mutes); everything immediate except Changes (off for everyone), Check error (digest) and System (on for Owners).
    expect(firstOwnerGrid($destination->id))->toBe(['changes' => 'off', 'check_error' => 'digest', 'expiring' => 'immediate', 'failure' => 'immediate',
        'outage' => 'immediate', 'recovery' => 'immediate', 'system' => 'immediate', 'warning' => 'immediate']);
    expect(DB::table('notification_site_mutes')->count())->toBe(0);
    // The shell's coverage signal speaks about the sites the viewer can see.
    Site::factory()->create();
    $this->actingAs($owner)->get('/sites')->assertOk()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('overallHealth.notifications_covered', true));
    expect(firstOwnerAlertsCommand(true))->toBe("Owner already exists; no account or password changed.\n");
    DB::table('notification_preferences')->where('destination_id', $destination->id)->where('category', 'warning')->update(['mode' => 'off']);
    firstOwnerAlertsCommand(true);
    expect(firstOwnerGrid($destination->id)['warning'])->toBe('off');
    expect(NotificationDestination::query()->count())->toBe(1);
    DB::table('notification_preferences')->where('destination_id', $destination->id)->update(['mode' => 'off']);
    firstOwnerAlertsCommand(true);
    expect(array_unique(firstOwnerGrid($destination->id)))->toBe(['changes' => 'off']);
    $this->get('/sites')->assertOk()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('overallHealth.notifications_covered', false));
    Mail::assertNothingSent();
});

it('preserves preconfigured routing when the first owner is created', function (): void {
    Mail::fake();
    $destination = notificationRule(NotificationDestination::factory()->create());
    $before = firstOwnerGrid($destination->id);
    firstOwnerAlertsCommand();
    expect(firstOwnerGrid($destination->id))->toBe($before);
    $account = NotificationDestination::query()->where('account_mail', true)->sole();
    expect($account->user_id)->toBe(User::query()->sole()->id);
    expect(firstOwnerGrid($account->id))->toBe(['changes' => 'off', 'check_error' => 'off', 'expiring' => 'off', 'failure' => 'off',
        'outage' => 'off', 'recovery' => 'off', 'system' => 'immediate', 'warning' => 'off']);
    Mail::assertNothingSent();
});
