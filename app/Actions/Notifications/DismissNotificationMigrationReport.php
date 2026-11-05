<?php

declare(strict_types=1);

namespace Heptapod\Actions\Notifications;

use Heptapod\Models\User;
use Heptapod\Queries\NotificationSettingsReader;
use Illuminate\Support\Facades\DB;

/** The Owner hides the stored migration report on the team page for the instance; it stays in the audit log. */
final readonly class DismissNotificationMigrationReport
{
    public function __invoke(User $owner): void
    {
        DB::transaction(static function () use ($owner): void {
            // Locking the newest report row serializes concurrent dismissals: one dismissal per shown report is recorded.
            $report = DB::table('activity_log')->where('event', 'notifications.migrated')->orderByDesc('id')->lockForUpdate()->value('id');
            if ($report === null) {
                return;
            }
            $dismissed = DB::table('activity_log')->where('event', NotificationSettingsReader::MigrationReportDismissed)->where('id', '>', $report)->exists();
            if (! $dismissed) {
                activity('audit')->causedBy($owner)->event(NotificationSettingsReader::MigrationReportDismissed)->log(NotificationSettingsReader::MigrationReportDismissed);
            }
        });
    }
}
