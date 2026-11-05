<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Spatie\LaravelSettings\Migrations\SettingsMigration;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * The product is now called Heptapod. Only the untouched default name "Lynkeus" (exact match) is renamed;
 * a name the owner chose stays. Rerunning changes nothing; down() restores nothing, because the old default
 * is not worth bringing back and a rollback must not rename a deliberate choice.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $changed = 0;
        $this->migrator->update('instance.name', static function (mixed $name) use (&$changed): mixed {
            if ($name === 'Lynkeus') {
                $changed++;

                return 'Heptapod';
            }

            return $name;
        });
        $message = "Renamed the default instance name Lynkeus to Heptapod: {$changed} changed.";
        Log::info($message, ['changed' => $changed]);
        new ConsoleOutput()->writeln($message);
    }

    public function down(): void {}
};
