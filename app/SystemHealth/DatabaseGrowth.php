<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Checks\History\RetentionPolicy;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\DiskSpaceReader;
use Heptapod\Enums\DatabaseDataClass;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Projects the database size from the daily samples. Each data class grows by its daily intake times its current bytes per
 * row; a class whose retention maintenance enforces stops at intake × retention days, every other class grows linearly.
 * Sizes never shrink in the projection: deleted rows leave reusable space that PostgreSQL does not return to the disk.
 */
final readonly class DatabaseGrowth
{
    public const int MeasuredSampleCount = 7;

    public const int HorizonDays = 365;

    public function __construct(private CheckRegistry $registry, private DiskSpaceIssues $disks, private DiskSpaceReader $reader) {}

    public function read(): ?DatabaseGrowthData
    {
        /** @var list<object{sampled_on: string, total_bytes: int}> $history */
        $history = array_reverse(DB::select('SELECT sampled_on, total_bytes FROM database_size_samples ORDER BY sampled_on DESC LIMIT ?', [self::HorizonDays]));
        if ($history === []) {
            return null;
        }
        /** @var non-empty-list<object{sampled_on: string, sampled_at: string, total_bytes: int, tables: string, intake: string}> $recent */
        $recent = array_reverse(DB::select('SELECT sampled_on, sampled_at, total_bytes, tables, intake FROM database_size_samples ORDER BY sampled_on DESC LIMIT 31'));
        $latest = $recent[count($recent) - 1];
        $latestOn = CarbonImmutable::parse($latest->sampled_on, 'UTC');
        /** @var array<string, array{bytes: int, rows: int}> $tables */
        $tables = json_decode($latest->tables, true, flags: JSON_THROW_ON_ERROR);
        $policy = RetentionPolicy::fromConfig();
        $classTables = $this->classTables(array_keys($tables));

        /** @var array<string, array{class: DatabaseDataClass, bytes: int, bytesPerDay: float, retentionDays: int|null}> $classes */
        $classes = [];
        foreach (DatabaseDataClass::cases() as $class) {
            $bytes = $class === DatabaseDataClass::Other ? $this->otherBytes($latest->total_bytes, $tables, $classTables) : self::bytesOf($tables, $classTables[$class->value]);
            $classes[$class->value] = ['class' => $class, 'bytes' => $bytes,
                'bytesPerDay' => $this->bytesPerDay($class, $bytes, $tables, $recent, $latestOn, $classTables),
                'retentionDays' => $class->enforcedDays($policy)];
        }
        $total = static fn (int $day): int => (int) round($latest->total_bytes + array_sum(array_map(static fn (array $class): float => self::size($class, $day) - $class['bytes'], $classes)));

        // Piecewise linear: the slope changes only on the day a bounded class reaches its plateau.
        $levelOffDays = [];
        foreach ($classes as $class) {
            if ($class['retentionDays'] !== null && $class['bytesPerDay'] > 0) {
                $levelOffDays[] = (int) ceil(max(0, $class['bytesPerDay'] * $class['retentionDays'] - $class['bytes']) / $class['bytesPerDay']);
            }
        }
        $breakpoints = array_values(array_unique([0, ...array_filter($levelOffDays, static fn (int $day): bool => $day < self::HorizonDays), self::HorizonDays]));
        sort($breakpoints);
        $unboundedPerDay = array_sum(array_map(static fn (array $class): float => $class['retentionDays'] === null ? $class['bytesPerDay'] : 0.0, $classes));
        $plateauDay = $levelOffDays === [] ? 0 : max($levelOffDays);
        $levelsOff = $unboundedPerDay <= 0.0;

        return new DatabaseGrowthData(
            CarbonImmutable::parse($latest->sampled_at)->utc()->toIso8601String(),
            count($history),
            count($history) >= self::MeasuredSampleCount,
            array_map(static fn (object $sample): array => ['date' => $sample->sampled_on, 'bytes' => $sample->total_bytes], $history),
            array_map(static fn (int $day): array => ['date' => $latestOn->addDays($day)->toDateString(), 'bytes' => $total($day)], $breakpoints),
            $total(30),
            $total(90),
            $total(self::HorizonDays),
            $levelsOff ? $total($plateauDay) : null,
            $levelsOff ? $latestOn->addDays($plateauDay)->toDateString() : null,
            $levelsOff ? null : (int) round($unboundedPerDay * 30),
            array_values(array_map(static fn (array $class): DatabaseGrowthClassData => new DatabaseGrowthClassData(
                $class['class'], $class['bytes'], (int) round(self::size($class, self::HorizonDays)), $class['class']->configuredDays($policy),
                $class['retentionDays'] !== null, $class['class']->retentionVariable(),
            ), $classes)),
            $this->disk($latestOn, $total),
        );
    }

    /** @param array{class: DatabaseDataClass, bytes: int, bytesPerDay: float, retentionDays: int|null} $class */
    private static function size(array $class, int $day): float
    {
        $grown = $class['bytes'] + $class['bytesPerDay'] * $day;

        return $class['retentionDays'] === null ? $grown : min($grown, max($class['bytes'], $class['bytesPerDay'] * $class['retentionDays']));
    }

    /**
     * @param  list<string>  $present
     * @return array<string, list<string>> each intake class's tables in the sample; Other is the rest of the database
     */
    private function classTables(array $present): array
    {
        $addonTables = [];
        foreach ($this->registry->keys() as $key) {
            $addonTables = [...$addonTables, ...($this->registry->get($key)->persistence()?->prunedTables() ?? [])];
        }
        $result = [];
        foreach (DatabaseDataClass::cases() as $class) {
            if ($class !== DatabaseDataClass::Other) {
                $result[$class->value] = array_values(array_intersect($present, $class === DatabaseDataClass::CheckResults ? [...$class->coreTables(), ...$addonTables] : $class->coreTables()));
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array{bytes: int, rows: int}>  $tables
     * @param  list<string>  $names
     */
    private static function bytesOf(array $tables, array $names): int
    {
        return array_sum(array_map(static fn (string $table): int => $tables[$table]['bytes'] ?? 0, $names));
    }

    /**
     * Everything outside the intake classes, including indexes of small tables and the catalog, so the classes add up to the total.
     *
     * @param  array<string, array{bytes: int, rows: int}>  $tables
     * @param  array<string, list<string>>  $classTables
     */
    private function otherBytes(int $totalBytes, array $tables, array $classTables): int
    {
        return $totalBytes - array_sum(array_map(static fn (array $names): int => self::bytesOf($tables, $names), $classTables));
    }

    /**
     * Intake rows per day (mean of the last week's samples) times the current bytes per row of the intake table's class.
     * Other has no intake column; its rate is its measured growth over the recent samples once a week of them exists.
     *
     * @param  array<string, array{bytes: int, rows: int}>  $tables
     * @param  non-empty-list<object{sampled_on: string, total_bytes: int, tables: string, intake: string}>  $recent
     * @param  array<string, list<string>>  $classTables
     */
    private function bytesPerDay(DatabaseDataClass $class, int $bytes, array $tables, array $recent, CarbonImmutable $latestOn, array $classTables): float
    {
        $column = $class->intakeColumn();
        if ($column === null) {
            $first = $recent[0];
            $days = (int) CarbonImmutable::parse($first->sampled_on, 'UTC')->diffInDays($latestOn);
            if ($days < self::MeasuredSampleCount) {
                return 0.0;
            }
            /** @var array<string, array{bytes: int, rows: int}> $firstTables */
            $firstTables = json_decode($first->tables, true, flags: JSON_THROW_ON_ERROR);

            return max(0, $bytes - $this->otherBytes($first->total_bytes, $firstTables, $classTables)) / $days;
        }
        $rows = $tables[$column[0]]['rows'] ?? 0;
        $week = array_filter($recent, static fn (object $sample): bool => CarbonImmutable::parse($sample->sampled_on, 'UTC')->greaterThan($latestOn->subDays(self::MeasuredSampleCount)));
        $intake = array_map(static function (object $sample) use ($class): int {
            /** @var array<string, int> $intake */
            $intake = json_decode($sample->intake, true, flags: JSON_THROW_ON_ERROR);

            return $intake[$class->value] ?? 0;
        }, $week);

        return $rows > 0 ? array_sum($intake) / count($intake) * $bytes / $rows : 0.0;
    }

    /**
     * Free space is read now, so only growth after today counts against it, whatever the age of the latest sample.
     *
     * @param  callable(int): int  $total  projected size n days after the latest sample
     */
    private function disk(CarbonImmutable $latestOn, callable $total): DatabaseDiskData
    {
        $path = $this->disks->postgresDataPath();
        if ($path === null) {
            return new DatabaseDiskData(null, null, null, null, null);
        }
        try {
            $free = $this->reader->read($path)['free'];
        } catch (RuntimeException $exception) {
            return new DatabaseDiskData($path, $exception instanceof DiskReadException ? $exception->reason : DiskReadFailure::ReadFailed, null, null, null);
        }
        $today = max(0, (int) $latestOn->diffInDays(CarbonImmutable::now('UTC')->startOfDay()));
        $sizeToday = $total($today);
        for ($day = $today + 1; $day <= $today + self::HorizonDays; $day++) {
            if ($total($day) - $sizeToday > $free) {
                return new DatabaseDiskData($path, null, $free, $latestOn->addDays($day)->toDateString(), null);
            }
        }

        return new DatabaseDiskData($path, null, $free, null, $free - ($total($today + self::HorizonDays) - $sizeToday));
    }
}
