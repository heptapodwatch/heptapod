<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The team default gets the two new sensitivity settings. People chose a preset label, not its numbers: a timing equal
 * to a former preset (Strict 2/1, Balanced 5/3, Relaxed 10/3, Patient 30/3) moves to that preset's current settings.
 * Any other (Custom) timing keeps its numbers and its former behaviour: no confirmation, standard reminders.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $checks = null;
        $this->migrator->update('instance.alert_recovery_checks', static function (int $stored) use (&$checks): int {
            return $checks = $stored;
        });
        $confirms = false;
        $reminders = 'standard';
        $this->migrator->update('instance.alert_after_minutes', static function (int $minutes) use ($checks, &$confirms, &$reminders): int {
            [$minutes, $confirms, $reminders] = match ([$minutes, $checks]) {
                [5, 3] => [5, true, 'standard'],
                [10, 3] => [30, true, 'daily'],
                [30, 3] => [60, true, 'daily'],
                default => [$minutes, false, 'standard'],
            };

            return $minutes;
        });
        $this->migrator->add('instance.alert_confirms_other_checks', $confirms);
        $this->migrator->add('instance.alert_reminders', $reminders);
    }

    public function down(): void {}
};
