<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Heptapod\Actions\RecordDatabaseSize;
use Heptapod\Checks\History\RetentionPolicy;
use Heptapod\Enums\DatabaseDataClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SYNTHETIC demo history for the System health growth chart: records today's real sample, then invents the 74 days
 * before it as if the instance had been installed 75 days ago at today's check volume. Retention-bound classes fill their
 * enforced window and level off; the other intake classes grow linearly from zero. Tables without intake stay constant.
 */
final class DatabaseGrowthDemoSeeder extends Seeder
{
    private const int InstalledDaysAgo = 75;

    public function run(): void
    {
        DB::statement('ANALYZE');
        app(RecordDatabaseSize::class)(CarbonImmutable::now('UTC'));
        /** @var object{sampled_on: string, sampled_at: string, total_bytes: int, tables: string}|null $today */
        $today = DB::selectOne('SELECT sampled_on, sampled_at, total_bytes, tables FROM database_size_samples ORDER BY sampled_on DESC LIMIT 1');
        if ($today === null) {
            throw new \LogicException('The database size sample was not recorded.');
        }
        /** @var array<string, array{bytes: int, rows: int}> $tables */
        $tables = json_decode($today->tables, true, flags: JSON_THROW_ON_ERROR);
        $policy = RetentionPolicy::fromConfig();
        $fillDays = [];
        $intake = [];
        foreach (DatabaseDataClass::cases() as $class) {
            $column = $class->intakeColumn();
            if ($column !== null) {
                $fillDays[$class->value] = min(self::InstalledDaysAgo, $class->enforcedDays($policy) ?? self::InstalledDaysAgo);
                $intake[$class->value] = intdiv($tables[$column[0]]['rows'] ?? 0, $fillDays[$class->value]);
            }
        }
        $samples = [];
        for ($daysAgo = self::InstalledDaysAgo - 1; $daysAgo >= 0; $daysAgo--) {
            $day = CarbonImmutable::parse($today->sampled_on, 'UTC')->subDays($daysAgo);
            $dayTables = $tables;
            $missingBytes = 0;
            foreach (DatabaseDataClass::cases() as $class) {
                if (! isset($fillDays[$class->value])) {
                    continue;
                }
                $share = min(1, (self::InstalledDaysAgo - $daysAgo) / $fillDays[$class->value]);
                foreach ($class->coreTables() as $table) {
                    if (isset($tables[$table])) {
                        $dayTables[$table] = ['bytes' => (int) ($tables[$table]['bytes'] * $share), 'rows' => (int) ($tables[$table]['rows'] * $share)];
                        $missingBytes += $tables[$table]['bytes'] - $dayTables[$table]['bytes'];
                    }
                }
            }
            $samples[] = ['sampled_on' => $day->toDateString(), 'sampled_at' => $day->setTimeFrom(CarbonImmutable::parse($today->sampled_at))->format('Y-m-d H:i:s.uP'),
                'total_bytes' => $today->total_bytes - $missingBytes, 'tables' => json_encode($dayTables, JSON_THROW_ON_ERROR),
                'intake' => json_encode($intake, JSON_THROW_ON_ERROR)];
        }
        DB::table('database_size_samples')->upsert($samples, ['sampled_on'], ['sampled_at', 'total_bytes', 'tables', 'intake']);
    }
}
