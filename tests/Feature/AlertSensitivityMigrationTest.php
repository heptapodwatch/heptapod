<?php

declare(strict_types=1);

use Heptapod\Models\Site;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const SitesSensitivityMigration = 'database/migrations/2026_11_14_090000_add_alert_confirmation_and_reminders_to_sites.php';
const InstanceSensitivityMigration = 'database/settings/2026_11_14_090000_add_alert_confirmation_and_reminders_to_instance.php';

function instanceSetting(string $name): mixed
{
    return json_decode((string) DB::table('settings')->where('group', 'instance')->where('name', $name)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
}

it('moves site overrides equal to a former preset to that preset and keeps custom timings with the former behaviour', function (): void {
    // The sites table before this migration: a timing pair only.
    DB::statement('ALTER TABLE sites DROP CONSTRAINT sites_alert_sensitivity_override');
    DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_alert_sensitivity_pair CHECK ((alert_after_minutes IS NULL) = (alert_recovery_checks IS NULL))');
    Schema::table('sites', function (Blueprint $table): void {
        $table->dropColumn(['alert_confirms_other_checks', 'alert_reminders']);
    });
    DB::table('migrations')->where('migration', basename(SitesSensitivityMigration, '.php'))->delete();
    $pairs = ['strict' => [2, 1], 'balanced' => [5, 3], 'relaxed' => [10, 3], 'patient' => [30, 3], 'custom' => [15, 3], 'custom strict-like' => [2, 3], 'inherit' => [null, null]];
    $ids = [];
    foreach ($pairs as $name => [$minutes, $checks]) {
        $ids[$name] = Site::factory()->create()->id;
        DB::table('sites')->where('id', $ids[$name])->update(['alert_after_minutes' => $minutes, 'alert_recovery_checks' => $checks]);
    }

    expect(Artisan::call('migrate', ['--path' => SitesSensitivityMigration, '--force' => true, '--no-interaction' => true]))->toBe(0);

    $stored = DB::table('sites')->whereIn('id', $ids)->get(['id', 'alert_after_minutes', 'alert_recovery_checks', 'alert_confirms_other_checks', 'alert_reminders'])->keyBy('id');
    expect(array_map(static fn (int $id): array => [$stored[$id]->alert_after_minutes, $stored[$id]->alert_recovery_checks,
        $stored[$id]->alert_confirms_other_checks, $stored[$id]->alert_reminders], $ids))->toBe([
            'strict' => [2, 1, false, 'standard'],
            'balanced' => [5, 3, true, 'standard'],
            'relaxed' => [30, 3, true, 'daily'],
            'patient' => [60, 3, true, 'daily'],
            'custom' => [15, 3, false, 'standard'],
            'custom strict-like' => [2, 3, false, 'standard'],
            'inherit' => [null, null, null, null],
        ]);
});

it('moves a team default equal to a former preset to that preset and keeps a custom timing', function (array $before, array $after): void {
    DB::table('settings')->where('group', 'instance')->whereIn('name', ['alert_confirms_other_checks', 'alert_reminders'])->delete();
    DB::table('settings')->where('group', 'instance')->where('name', 'alert_after_minutes')->update(['payload' => json_encode($before[0])]);
    DB::table('settings')->where('group', 'instance')->where('name', 'alert_recovery_checks')->update(['payload' => json_encode($before[1])]);
    DB::table('migrations')->where('migration', basename(InstanceSensitivityMigration, '.php'))->delete();

    expect(Artisan::call('migrate', ['--path' => InstanceSensitivityMigration, '--force' => true, '--no-interaction' => true]))->toBe(0);

    expect([instanceSetting('alert_after_minutes'), instanceSetting('alert_recovery_checks'), instanceSetting('alert_confirms_other_checks'), instanceSetting('alert_reminders')])
        ->toBe($after);
})->with([
    'Relaxed' => [[10, 3], [30, 3, true, 'daily']],
    'Patient' => [[30, 3], [60, 3, true, 'daily']],
    'Strict' => [[2, 1], [2, 1, false, 'standard']],
    'custom' => [[20, 5], [20, 5, false, 'standard']],
]);
