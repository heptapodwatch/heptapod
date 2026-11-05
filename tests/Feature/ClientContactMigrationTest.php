<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\RecipientKind;
use Heptapod\Events\Notifications\NotificationRecipients;
use Heptapod\Events\Notifications\RoutingContext;
use Heptapod\Models\Client;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-30T09:00:00Z'));
});

function clientContactsPath(): string
{
    return 'database/migrations/2026_10_30_000003_move_client_contacts_to_channels.php';
}

/** The clients table of 2026-10-29: contact_email still a column. */
function restoreClientContactColumn(): void
{
    Schema::table('clients', function (Blueprint $table): void {
        $table->string('contact_email')->nullable();
    });
    DB::table('migrations')->where('migration', basename(clientContactsPath(), '.php'))->delete();
}

it('turns each client contact address into a client mail channel that hears nothing automatically and drops the column', function (): void {
    restoreClientContactColumn();
    $named = Client::factory()->create(['name' => 'Studio', 'contact_name' => 'Ada Lovelace', 'locale' => 'de']);
    $unnamed = Client::factory()->create(['name' => 'Shop', 'contact_name' => '  ']);
    $without = Client::factory()->create(['name' => 'Quiet']);
    $blank = Client::factory()->create(['name' => 'Blank']);
    DB::table('clients')->where('id', $named->id)->update(['contact_email' => 'ada@studio.test']);
    DB::table('clients')->where('id', $unnamed->id)->update(['contact_email' => ' orders@shop.test ']);
    DB::table('clients')->where('id', $blank->id)->update(['contact_email' => '']);
    $site = Site::factory()->create(['client_id' => $named->id]);

    expect(Artisan::call('migrate', ['--path' => clientContactsPath(), '--force' => true, '--no-interaction' => true]))->toBe(0);

    expect(Schema::hasColumn('clients', 'contact_email'))->toBeFalse();
    $channels = NotificationDestination::query()->where('recipient_kind', RecipientKind::Client)->orderBy('id')->get();
    expect($channels->map(fn (NotificationDestination $channel): array => [$channel->client_id, $channel->label, $channel->channel,
        $channel->enabled, $channel->account_mail, $channel->removed_at, $channel->destinationConfig()->string('email')])->all())->toBe([
            [$named->id, 'Ada Lovelace', 'mail', true, false, null, 'ada@studio.test'],
            [$unnamed->id, 'orders@shop.test', 'mail', true, false, null, 'orders@shop.test'],
        ]);
    // The channel speaks the client's language and every grid cell is off: a contact never heard automatic alerts.
    expect($channels[0]->effectiveLocale())->toBe('de');
    foreach ($channels as $channel) {
        expect(DB::table('notification_preferences')->where('destination_id', $channel->id)->orderBy('category')->pluck('mode', 'category')->all())
            ->toBe(array_fill_keys(['changes', 'check_error', 'expiring', 'failure', 'outage', 'recovery', 'system', 'warning'], 'off'));
    }
    expect(NotificationDestination::query()->whereIn('client_id', [$without->id, $blank->id])->exists())->toBeFalse();
    $outage = new RoutingContext($site, NotificationCategory::Outage, AlertSeverity::Down, true, null);
    $candidate = collect(app(NotificationRecipients::class)->candidates($outage))->firstWhere('channel.id', $channels[0]->id);
    expect($candidate['excluded'])->toBe('category_off');
    expect(fn () => (require base_path(clientContactsPath()))->down())->toThrow(LogicException::class);
});

it('refuses a contact address on the client form now that contacts are client channels', function (): void {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->postJson('/clients', ['name' => 'New Studio', 'contact_email' => 'studio@example.test'])
        ->assertUnprocessable()->assertJsonValidationErrors('contact_email');
    $this->postJson('/clients', ['name' => 'New Studio', 'contact_email' => ''])->assertCreated();
    expect(NotificationDestination::query()->where('recipient_kind', RecipientKind::Client)->exists())->toBeFalse();
});
