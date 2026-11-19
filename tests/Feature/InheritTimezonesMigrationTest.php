<?php

declare(strict_types=1);

use Heptapod\Models\Client;
use Heptapod\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function inheritTimezonesPath(): string
{
    return 'database/migrations/2026_11_19_120000_inherit_timezones.php';
}

function runInheritTimezones(): void
{
    DB::table('migrations')->where('migration', basename(inheritTimezonesPath(), '.php'))->delete();
    expect(Artisan::call('migrate', ['--path' => inheritTimezonesPath(), '--force' => true, '--no-interaction' => true]))->toBe(0);
}

it('turns a stored UTC into the default, keeps explicit zones and changes nothing when run again', function (): void {
    // The schema of 2026-11-18: required zones defaulting to UTC, a client contact name, no channel zone.
    foreach (['users', 'clients'] as $table) {
        DB::table($table)->whereNull('timezone')->update(['timezone' => 'UTC']);
        DB::statement("ALTER TABLE {$table} ALTER COLUMN timezone SET DEFAULT 'UTC', ALTER COLUMN timezone SET NOT NULL");
    }
    Schema::table('clients', function (Blueprint $table): void {
        $table->string('contact_name')->nullable();
    });
    Schema::table('notification_destinations', function (Blueprint $table): void {
        $table->dropColumn('timezone');
    });
    $utcUser = User::factory()->create(['timezone' => 'UTC']);
    $berlinUser = User::factory()->create(['timezone' => 'Europe/Berlin']);
    $utcClient = Client::factory()->create(['timezone' => 'UTC']);
    $newYorkClient = Client::factory()->create(['timezone' => 'America/New_York']);
    $zones = static fn (): array => [
        DB::table('users')->where('id', $utcUser->id)->value('timezone'), DB::table('users')->where('id', $berlinUser->id)->value('timezone'),
        DB::table('clients')->where('id', $utcClient->id)->value('timezone'), DB::table('clients')->where('id', $newYorkClient->id)->value('timezone'),
        DB::table('clients')->where('is_internal', true)->value('timezone'),
    ];

    runInheritTimezones();

    expect($zones())->toBe([null, 'Europe/Berlin', null, 'America/New_York', null])
        ->and(Schema::hasColumn('clients', 'contact_name'))->toBeFalse()
        ->and(Schema::hasColumn('notification_destinations', 'timezone'))->toBeTrue();
    $columns = collect(Schema::getColumns('users'))->merge(Schema::getColumns('clients'))->where('name', 'timezone');
    expect($columns->pluck('nullable')->all())->toBe([true, true])->and($columns->pluck('default')->all())->toBe([null, null]);

    runInheritTimezones();

    expect($zones())->toBe([null, 'Europe/Berlin', null, 'America/New_York', null]);
});
