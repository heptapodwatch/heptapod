<?php

declare(strict_types=1);

namespace Heptapod\Models;

use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\NotificationMode;
use Heptapod\Enums\RecipientKind;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One grid cell. Every channel has one row per category; the channel-creating action writes them in its transaction.
 * The table key is (destination_id, category): write through query builders, never save() on a loaded row.
 *
 * @property int $destination_id
 * @property NotificationCategory $category
 * @property NotificationMode $mode
 */
#[Guarded([])]
#[WithoutTimestamps]
#[WithoutIncrementing]
final class NotificationPreference extends Model
{
    #[\Override]
    protected $primaryKey = 'destination_id';

    protected function casts(): array
    {
        return ['category' => NotificationCategory::class, 'mode' => NotificationMode::class];
    }

    /**
     * Defaults for new channels (design §1.3). Clients hear nothing automatically; Changes is off for everyone (Q7).
     * New users get Outage, Failure and Recovery (Q2); System is on for Owners and visible but off for Admins and the team.
     *
     * @return array<string, NotificationMode>
     */
    public static function defaults(RecipientKind $kind, ?string $role = null, bool $firstOwner = false): array
    {
        $now = NotificationMode::Immediate;
        $off = NotificationMode::Off;
        $cells = match (true) {
            $kind === RecipientKind::Client => array_fill_keys(self::gridKeys(), $off),
            $kind === RecipientKind::Team, $firstOwner => [...array_fill_keys(self::gridKeys(), $now), 'changes' => $off, 'system' => $off],
            default => [...array_fill_keys(self::gridKeys(), $off), 'outage' => $now, 'failure' => $now, 'recovery' => $now],
        };
        $cells['system'] = $kind === RecipientKind::User && $role === 'Owner' ? $now : $off;

        return $cells;
    }

    /** @param array<string, NotificationMode> $cells */
    public static function writeGrid(int $destinationId, array $cells): void
    {
        if (array_diff(self::gridKeys(), array_keys($cells)) !== []) {
            throw new \LogicException('A channel grid needs one cell per category.');
        }
        DB::table('notification_preferences')->upsert(array_map(static fn (string $category, NotificationMode $mode): array => [
            'destination_id' => $destinationId, 'category' => $category, 'mode' => $mode->value,
        ], array_keys($cells), $cells), ['destination_id', 'category'], ['mode']);
    }

    /**
     * A new channel's first grid, also kept as the channel's baseline: RecipientSettings marks cells that differ from it
     * and "Reset to defaults" returns to it.
     *
     * @param  array<string, NotificationMode>  $cells
     */
    public static function writeInitialGrid(int $destinationId, array $cells): void
    {
        self::writeGrid($destinationId, $cells);
        DB::table('notification_destinations')->where('id', $destinationId)->update(['default_preferences' => json_encode(
            array_map(static fn (NotificationMode $mode): string => $mode->value, $cells), JSON_THROW_ON_ERROR)]);
    }

    /** @return list<string> */
    private static function gridKeys(): array
    {
        return array_map(static fn (NotificationCategory $category): string => $category->value, NotificationCategory::grid());
    }
}
