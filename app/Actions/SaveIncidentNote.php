<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\Incident;
use Heptapod\Models\User;

final class SaveIncidentNote
{
    public function __invoke(Incident $incident, User $actor, ?string $text): Incident
    {
        return NotificationLocks::transaction(static function () use ($incident, $actor, $text): Incident {
            // NotificationLocks: the author's key (the note names it) before the incident row the update writes.
            NotificationLocks::users([$actor->id]);
            $incident->update(['notes' => $text, 'note_author_user_id' => $text === null ? null : $actor->id,
                'note_updated_at' => $text === null ? null : now()]);

            return $incident;
        });
    }
}
