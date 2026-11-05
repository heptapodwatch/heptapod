<?php

declare(strict_types=1);

use Heptapod\Models\Check;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('removes legacy acknowledgement events and deliveries without losing unrelated incident history', function (): void {
    $migration = require database_path('migrations/2026_10_03_160000_remove_incident_acknowledgement.php');
    $migration->down();
    $check = Check::factory()->create();
    $incident = Incident::factory()->for($check)->create();
    $ack = Event::factory()->create(['check_id' => $check->id, 'site_id' => $check->site_id, 'incident_id' => $incident->id, 'type' => 'incident.acknowledged']);
    $other = Event::factory()->create(['check_id' => $check->id, 'site_id' => $check->site_id, 'incident_id' => $incident->id]);
    $destination = NotificationDestination::factory()->create();
    DB::table('deliveries')->insert([
        ['event_id' => $ack->id, 'destination_id' => $destination->id, 'digest_event_id' => null, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()],
        ['event_id' => $other->id, 'destination_id' => $destination->id, 'digest_event_id' => $ack->id, 'next_attempt_at' => now(), 'suppression_evaluated_at' => now()],
    ]);
    DB::table('incidents')->where('id', $incident->id)->update(['acknowledged_at' => now(), 'acknowledged_by' => User::factory()->create()->id]);

    $migration->up();

    expect(Schema::hasColumn('incidents', 'acknowledged_at'))->toBeFalse()
        ->and(Schema::hasColumn('incidents', 'acknowledged_by'))->toBeFalse()
        ->and(Event::query()->whereKey($ack->id)->exists())->toBeFalse()
        ->and(Event::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(Incident::query()->whereKey($incident->id)->exists())->toBeTrue()
        ->and(DB::table('deliveries')->where('event_id', $ack->id)->exists())->toBeFalse()
        ->and(DB::table('deliveries')->where('event_id', $other->id)->value('digest_event_id'))->toBeNull();
});
