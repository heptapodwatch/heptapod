<?php

declare(strict_types=1);

namespace Heptapod\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** The upgrade preflight runs this against the old schema before writers are frozen. */
#[Description('Read-only preview of the notification channel conversion report')]
#[Signature('heptapod:notifications:migration-preview')]
final class PreviewNotificationMigrationCommand extends Command
{
    public const string Migration = '2026_10_30_000001_unify_notification_recipients';

    public function handle(): int
    {
        if (! Schema::hasTable('notification_rules')) {
            $this->line('Notification channels: already converted.');

            return self::SUCCESS;
        }
        $migration = require database_path('migrations/'.self::Migration.'.php');
        if (! is_object($migration) || ! method_exists($migration, 'preview')) {
            throw new \LogicException('The notification migration lost its read-only preview.');
        }
        /** @var list<string> $lines */
        $lines = $migration->preview();
        $this->line('Notification channel conversion (read-only preview):');
        foreach ($lines as $line) {
            $this->line('- '.$line);
        }

        return self::SUCCESS;
    }
}
