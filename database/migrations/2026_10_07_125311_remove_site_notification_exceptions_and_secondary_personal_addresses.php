<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\LazyCollection;

return new class extends Migration
{
    public function up(): void
    {
        // Migrator holds the PostgreSQL transaction. Prevent writes until every guard and schema change finishes.
        DB::statement('LOCK TABLE users, notification_destinations, site_notification_preferences, site_notification_recipients, site_notification_mutes IN SHARE ROW EXCLUSIVE MODE');
        $counts = [];
        foreach (['site_notification_preferences', 'site_notification_recipients', 'site_notification_mutes'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        foreach (['non_account_personal', 'invalid_config', 'invalid_address', 'non_mail_personal', 'duplicate_personal', 'orphan_personal', 'contradictory_marker'] as $category) {
            $counts['notification_destinations.'.$category] = 0;
        }
        $seen = [];
        /** @var LazyCollection<int, stdClass&object{user_id: int|null, channel: string, config: string, config_secrets: string|null, is_primary: bool, verified_at: string|null, account_email: string|null}> $destinations */
        $destinations = DB::table('notification_destinations as destinations')
            ->leftJoin('users', 'users.id', '=', 'destinations.user_id')
            ->select('destinations.user_id', 'destinations.channel', 'destinations.config', 'destinations.config_secrets',
                'destinations.is_primary', 'destinations.verified_at', 'users.email as account_email')->orderBy('destinations.id')->cursor();
        foreach ($destinations as $destination) {
            if ($destination->user_id === null) {
                if ($destination->is_primary || $destination->verified_at !== null) {
                    $counts['notification_destinations.contradictory_marker']++;
                }

                continue;
            }
            if (isset($seen[$destination->user_id])) {
                $counts['notification_destinations.duplicate_personal']++;
            }
            $seen[$destination->user_id] = true;
            if ($destination->account_email === null) {
                $counts['notification_destinations.orphan_personal']++;
            }
            if (! $destination->is_primary || $destination->verified_at !== null) {
                $counts['notification_destinations.contradictory_marker']++;
            }
            if ($destination->channel !== 'mail') {
                $counts['notification_destinations.non_mail_personal']++;
            }
            // Only this boundary handles encrypted input. Never attach payloads or the original exception to diagnostics.
            try {
                $public = json_decode($destination->config, true, flags: JSON_THROW_ON_ERROR);
                $secrets = $destination->config_secrets === null ? []
                    : json_decode(Crypt::decryptString($destination->config_secrets), true, flags: JSON_THROW_ON_ERROR);
            } catch (DecryptException|JsonException) {
                $counts['notification_destinations.invalid_config']++;

                continue;
            }
            if (! is_array($public) || ! is_array($secrets)
                || ($public !== [] && array_is_list($public)) || ($secrets !== [] && array_is_list($secrets))) {
                $counts['notification_destinations.invalid_config']++;

                continue;
            }
            $effective = array_replace_recursive($public, $secrets);
            $address = $effective['email'] ?? null;
            if (! is_string($address) || Validator::make(['email' => trim($address)], ['email' => ['required', 'email:rfc', 'max:254']])->fails()) {
                $counts['notification_destinations.invalid_address']++;

                continue;
            }
            if ($destination->account_email !== null && mb_strtolower(trim($address)) !== mb_strtolower(trim($destination->account_email))) {
                $counts['notification_destinations.non_account_personal']++;
            }
        }
        $blocked = array_filter($counts, static fn (int $count): bool => $count > 0);
        if ($blocked !== []) {
            throw new LogicException('Removal blocked: '.implode('; ', array_map(
                static fn (string $category, int $count): string => $category.'='.$count, array_keys($blocked), array_values($blocked))));
        }

        Schema::drop('site_notification_recipients');
        Schema::drop('site_notification_mutes');
        Schema::drop('site_notification_preferences');
        DB::statement('DROP INDEX personal_email_address');
        DB::statement('DROP INDEX personal_primary_destination');
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->dropColumn(['is_primary', 'verified_at']);
            $table->unique('user_id');
        });
    }

    public function down(): never
    {
        throw new LogicException('This migration removes site notification exceptions and secondary personal addresses. Roll back by restoring the pre-upgrade backup (scripts/upgrade.sh creates one).');
    }
};
