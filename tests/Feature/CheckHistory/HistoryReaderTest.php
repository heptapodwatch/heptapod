<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\History\DatabaseHistoryReader;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\HistoryReader;
use Heptapod\Contracts\PreviousResult;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
    app()->bind(HistoryReader::class, DatabaseHistoryReader::class);
});

it('reads only the requested accepted observations in a precise bounded chronological window', function (): void {
    $from = CarbonImmutable::now('UTC')->startOfHour()->addMicroseconds(500000)->setTimezone('Europe/Berlin');
    $check = Check::factory()->create();
    $otherType = Check::factory()->create(['site_id' => $check->site_id, 'type' => CheckKey::Dns]);
    $otherSite = Check::factory()->create();
    $makeRun = fn (Check $owner, CarbonImmutable $ended, array $extra = []): CheckRun => CheckRun::factory()->create([
        'check_id' => $owner->id, 'started_at' => $ended->subSecond(), 'ended_at' => $ended, ...$extra,
    ]);
    $makeRun($check, $from->subMicrosecond());
    $second = $makeRun($check, $from->addSecond(), ['result' => RunResult::Warning, 'metrics' => ['total_time_in_seconds' => 0.5]]);
    $first = $makeRun($check, $from, ['result' => RunResult::Failed, 'metrics' => []]);
    $tie = $makeRun($check, $from->addSecond());
    $makeRun($check, $from->addSecond(), ['result' => RunResult::Errored, 'metrics' => null]);
    $makeRun($check, $from->addSeconds(2));
    $makeRun($otherType, $from);
    $makeRun($otherSite, $from);

    $reader = app(HistoryReader::class);
    $runs = $reader->read($check->site_id, CheckKey::Uptime, $from, $from->addSeconds(2), 10000);
    expect(array_map(fn (PreviousResult $run): int => $run->run->id, $runs))->toBe([$first->id, $second->id, $tie->id]);
    expect($runs[0]->result)->toBe(RunResult::Failed)
        ->and($runs[0]->run->endedAt->format('u'))->toBe('500000')
        ->and($runs[1]->metrics)->toBe(['total_time_in_seconds' => 0.5]);
    expect(array_map(fn (PreviousResult $run): int => $run->run->id,
        $reader->read($check->site_id, CheckKey::Uptime, $from, $from->addSeconds(2), 4)))->toBe([$first->id, $second->id, $tie->id]);
    foreach ([2, 3] as $limit) {
        expect(fn (): array => $reader->read($check->site_id, CheckKey::Uptime, $from, $from->addSeconds(2), $limit))
            ->toThrow(OverflowException::class, 'History window 0 reached its '.$limit.' observation limit; refusing incomplete history.');
    }
    expect($reader->read($check->site_id, CheckKey::Uptime, $from, $from, 10))->toBe([]);
    expect($reader->read($check->site_id, CheckKey::Performance, $from, $from->addSeconds(2), 10))->toBe([]);
});

it('rejects unbounded limits and reversed windows', function (int $limit, bool $reverse): void {
    $from = CarbonImmutable::now('UTC');
    expect(fn () => app(HistoryReader::class)->read(1, CheckKey::Uptime, $from, $reverse ? $from->subMicrosecond() : $from->addMinute(), $limit))
        ->toThrow(InvalidArgumentException::class);
})->with([[0, false], [10001, false], [10, true]]);
