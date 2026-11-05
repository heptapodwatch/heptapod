<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
});

function morphAliasMigrationPath(): string
{
    return 'database/migrations/2026_10_16_150000_replace_class_names_with_morph_aliases.php';
}

/** @return array<string, list<array<string, mixed>>> */
function morphUpgradeSnapshot(): array
{
    $snapshot = [];
    foreach (['model_has_roles', 'model_has_permissions', 'personal_access_tokens', 'media', 'taggables', 'activity_log', 'migrations'] as $table) {
        $snapshot[$table] = DB::table($table)->orderByRaw('1, 2')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $snapshot;
}

/** @return array{User, Site} */
function seedLegacyMorphs(): array
{
    $user = User::factory()->owner()->create();
    $permission = Permission::findOrCreate('gate.upgrade', 'web');
    DB::table('model_has_permissions')->insert(['model_id' => $user->id, 'model_type' => 'Lynkeus\Models\User', 'permission_id' => $permission->id]);
    DB::table('model_has_roles')->where('model_id', $user->id)->update(['model_type' => 'Lynkeus\Models\User']);
    $user->createToken('upgrade');
    DB::table('personal_access_tokens')->update(['tokenable_type' => 'Lynkeus\Models\User']);
    $site = Site::factory()->create();
    $site->attachTag('upgrade');
    DB::table('taggables')->update(['taggable_type' => 'Lynkeus\Models\Site']);
    DB::table('media')->insert(['model_type' => 'Lynkeus\Models\Client', 'model_id' => $site->client_id, 'collection_name' => 'logo',
        'name' => 'Preserved logo', 'file_name' => 'logo.png', 'disk' => 'public', 'size' => 42,
        'manipulations' => '{}', 'custom_properties' => '{"caption":"Keep me"}', 'generated_conversions' => '{}', 'responsive_images' => '{}']);
    DB::table('activity_log')->delete();
    foreach ([
        'Lynkeus\Models\User', 'Lynkeus\Models\Site', 'Lynkeus\Models\Client', 'Lynkeus\Models\Check',
        'Lynkeus\Models\CheckSnooze', 'Lynkeus\Models\Incident', 'Lynkeus\Models\Invitation', 'Lynkeus\Models\MaintenancePeriod',
        'Lynkeus\Models\RecurringMaintenancePeriod', 'Lynkeus\Models\MaintenanceSiteExclusion', 'Lynkeus\Models\NotificationDestination',
    ] as $index => $class) {
        DB::table('activity_log')->insert(['description' => 'Historical deletion '.$index, 'subject_type' => $class, 'subject_id' => $site->id,
            'causer_type' => 'Lynkeus\Models\User', 'causer_id' => $user->id,
            'properties' => json_encode(['identity' => ['type' => $class, 'name' => 'Retained identity'], 'unchanged' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('activity_log')->insert(['description' => 'Already migrated', 'subject_type' => 'site', 'subject_id' => $site->id,
        'properties' => '{"identity":{"type":"site"}}']);
    DB::table('migrations')->where('migration', basename(morphAliasMigrationPath(), '.php'))->delete();

    return [$user, $site];
}

function migrateMorphAliases(): void
{
    Artisan::call('migrate', ['--path' => morphAliasMigrationPath(), '--force' => true, '--no-interaction' => true]);
}

it('upgrades every stored morph column and historical identity while preserving records and relations', function (): void {
    [$user, $site] = seedLegacyMorphs();
    $before = morphUpgradeSnapshot();

    migrateMorphAliases();

    foreach (['model_has_roles' => 'model_type', 'model_has_permissions' => 'model_type', 'personal_access_tokens' => 'tokenable_type', 'media' => 'model_type', 'taggables' => 'taggable_type'] as $table => $column) {
        $expected = $before[$table];
        foreach ($expected as &$row) {
            $row[$column] = match ($table) {
                'media' => 'client',
                'taggables' => 'site',
                default => 'user',
            };
        }
        unset($row);
        expect(morphUpgradeSnapshot()[$table])->toBe($expected);
    }
    $aliases = ['user', 'site', 'client', 'check', 'check_snooze', 'incident', 'invitation', 'maintenance_period',
        'recurring_maintenance_period', 'maintenance_site_exclusion', 'notification_destination', 'site'];
    $audits = DB::table('activity_log')->orderBy('id')->get();
    expect($audits->pluck('subject_type')->all())->toBe($aliases);
    foreach ($audits as $index => $audit) {
        $properties = json_decode($audit->properties, true, flags: JSON_THROW_ON_ERROR);
        expect($properties['identity']['type'])->toBe($aliases[$index]);
        if ($index < 11) {
            expect($audit->causer_type)->toBe('user')
                ->and($audit->description)->toBe('Historical deletion '.$index)
                ->and($properties['identity']['name'])->toBe('Retained identity')
                ->and($properties['unchanged'])->toBeTrue();
        }
    }
    expect($user->fresh()->hasRole('Owner'))->toBeTrue()
        ->and($user->tokens()->sole()->tokenable->is($user))->toBeTrue()
        ->and($site->fresh()->tags->pluck('name')->all())->toBe(['upgrade'])
        ->and($site->client->media->sole()->getCustomProperty('caption'))->toBe('Keep me');
    $after = morphUpgradeSnapshot();

    migrateMorphAliases();

    expect(morphUpgradeSnapshot())->toBe($after)
        ->and(DB::table('migrations')->where('migration', basename(morphAliasMigrationPath(), '.php'))->count())->toBe(1);
});

it('rolls back all earlier rewrites and keeps the migration pending on an unknown stored class', function (string $table, string $column): void {
    seedLegacyMorphs();
    if ($column === 'properties') {
        DB::table($table)->where('description', 'Historical deletion 0')->update(['properties' => json_encode(['identity' => ['type' => 'Lynkeus\Models\RemovedModel']], JSON_THROW_ON_ERROR)]);
    } else {
        DB::table($table)->update([$column => 'Lynkeus\Models\RemovedModel']);
    }
    $before = morphUpgradeSnapshot();

    expect(fn () => migrateMorphAliases())->toThrow(RuntimeException::class, 'Stored class names without a morph alias:');

    expect(morphUpgradeSnapshot())->toBe($before)
        ->and(DB::table('migrations')->where('migration', basename(morphAliasMigrationPath(), '.php'))->exists())->toBeFalse();
})->with([
    ['model_has_roles', 'model_type'], ['model_has_permissions', 'model_type'], ['personal_access_tokens', 'tokenable_type'],
    ['media', 'model_type'], ['taggables', 'taggable_type'], ['activity_log', 'subject_type'], ['activity_log', 'causer_type'], ['activity_log', 'properties'],
]);
