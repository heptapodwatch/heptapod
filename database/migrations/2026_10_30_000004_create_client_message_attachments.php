<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messages sent to a client by hand (design §4). The CSV of a findings message is stored at approval and lives as long
 * as its event. A sender's idempotency key is looked up among that sender's messages of the last 24 hours (SendClientMessage
 * serializes same-key submits with an advisory lock), so this index keeps that lookup bounded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained('events')->restrictOnDelete();
            $table->string('disk');
            $table->string('path')->unique();
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('rows');
            $table->char('sha256', 64);
            $table->timestampTz('created_at', 6);
        });
        DB::statement("CREATE INDEX events_client_message_key ON events ((payload->>'idempotency_key'), occurred_at)"
            ." WHERE type IN ('client_message.findings', 'client_message.incident')");
        // The send quotas count a sender's last hour and a client's last day of messages (SendClientMessage::reserveQuota).
        DB::statement("CREATE INDEX events_client_message_sender ON events ((payload->>'actor_id'), occurred_at)"
            ." WHERE type IN ('client_message.findings', 'client_message.incident')");
        DB::statement('CREATE INDEX events_client_message_client ON events (client_id, occurred_at)'
            ." WHERE type IN ('client_message.findings', 'client_message.incident')");
    }

    public function down(): never
    {
        throw new LogicException('Roll back by restoring the pre-upgrade backup (scripts/rollback.sh --restore-backup).');
    }
};
