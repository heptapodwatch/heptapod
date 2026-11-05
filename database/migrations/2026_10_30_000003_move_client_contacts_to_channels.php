<?php

declare(strict_types=1);

use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * clients.contact_email becomes a client mail channel with every category off (design 2026-10-29 rev. 3 §5 item 7).
 * The address never received automatic alerts, so nothing changes for the contact; the column is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE clients, notification_destinations IN SHARE ROW EXCLUSIVE MODE');
        $now = now();
        $contacts = DB::table('clients')->whereNotNull('contact_email')->where('contact_email', '<>', '')->orderBy('id')
            ->get(['id', 'contact_name', 'contact_email']);
        foreach ($contacts as $contact) {
            $address = is_string($contact->contact_email) ? trim($contact->contact_email) : throw new LogicException('A contact address is text.');
            $name = is_string($contact->contact_name) && trim($contact->contact_name) !== '' ? trim($contact->contact_name) : $address;
            $id = DB::table('notification_destinations')->insertGetId(['recipient_kind' => 'client', 'client_id' => $contact->id,
                'label' => $name, 'channel' => 'mail', 'locale' => null, 'enabled' => true, 'account_mail' => false,
                'config' => json_encode(['email' => $address], JSON_THROW_ON_ERROR), 'config_secrets' => null,
                'created_at' => $now, 'updated_at' => $now]);
            DB::table('notification_preferences')->insert(array_map(static fn (NotificationCategory $category): array => [
                'destination_id' => $id, 'category' => $category->value, 'mode' => NotificationMode::Off->value,
            ], NotificationCategory::grid()));
        }
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('contact_email');
        });
    }

    public function down(): never
    {
        throw new LogicException('Client contacts are client mail channels now. Roll back by restoring the pre-upgrade backup (scripts/rollback.sh --restore-backup).');
    }
};
