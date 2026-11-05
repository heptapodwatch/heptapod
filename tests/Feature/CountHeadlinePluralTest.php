<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Channels\RenderedAlert;
use Heptapod\Contracts\CheckHeadline;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\RunResult;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\User;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-27T12:00:00Z'));
    $this->withoutVite();
});

it('pluralizes task and day counts in the current check headline', function (CheckKey $type, RunResult $result, string $summary, array $params, array $headlines): void {
    $user = User::factory()->owner()->create();
    $check = Check::factory()->create(['type' => $type]);
    $run = CheckRun::factory()->for($check)->create(['check_type' => $type, 'result' => $result, 'summary' => $summary, 'summary_params' => $params]);
    $check->update(['latest_run_id' => $run->id, 'latest_run_ended_at' => $run->ended_at, 'summary' => $summary, 'summary_params' => $params]);

    $strip = collect($this->actingAs($user)->get('/sites/'.$check->site_id)->assertOk()->inertiaProps()['site']['checks'])->firstWhere('key', $type->value);
    $presentation = new CheckRunPresentationData(array_map(static fn (array $part): CheckRunPresentationPartData => new CheckRunPresentationPartData(
        $part['key'], $part['params'], $part['labels'], $part['shortKey'], $part['untrusted'], $part['trusted'], $part['lists']), $strip['presentation']['parts']));

    foreach ($headlines as $locale => $headline) {
        expect(CheckHeadline::format($presentation, $locale))->toBe($headline);
    }
})->with([
    'one task' => [CheckKey::Cron, RunResult::Succeeded, 'cron.tasks_completed_late',
        ['late' => 1, 'total' => 1, 'failed' => 0, 'pending' => 0, 'warning' => 0, 'succeeded' => 1],
        ['en' => '1 task · completed after the grace period.', 'de' => '1 Aufgabe · nach der Toleranzzeit abgeschlossen.']],
    'two tasks' => [CheckKey::Cron, RunResult::Succeeded, 'cron.tasks_on_time',
        ['total' => 2, 'failed' => 0, 'pending' => 0, 'warning' => 0, 'succeeded' => 2],
        ['en' => '2 tasks · all on time.', 'de' => '2 Aufgaben · alle pünktlich.']],
    'one day' => [CheckKey::CertificateHealth, RunResult::Warning, 'causes.certificate_expiry', ['days' => 1, 'presentation' => 'direct'],
        ['en' => 'Certificate expires in 1 day.', 'de' => 'Zertifikat läuft in 1 Tag ab.']],
    'nine days' => [CheckKey::CertificateHealth, RunResult::Warning, 'causes.certificate_expiry', ['days' => 9, 'presentation' => 'direct'],
        ['en' => 'Certificate expires in 9 days.', 'de' => 'Zertifikat läuft in 9 Tagen ab.']],
]);

it('pluralizes digest counts in rendered alerts', function (int $count, string $locale, bool $markdown, string $line): void {
    $event = Event::factory()->create(['type' => 'notification.digest', 'site_id' => null, 'check_id' => null,
        'payload' => ['count' => $count, 'sites' => 'Shop', 'remaining_sites' => 0]]);
    $message = app(NotificationMessage::class)->build($event);

    expect(new RenderedAlert($message, $locale, 'UTC', 'Heptapod', 'https://heptapod.example')->details($markdown))->toContain($line)->not->toContain('|');
})->with([
    'EN one' => [1, 'en', false, '1 open item for: Shop'],
    'EN two' => [2, 'en', false, '2 open items for: Shop'],
    'EN one markdown' => [1, 'en', true, '1 open item for: Shop'],
    'DE one' => [1, 'de', false, 'Aktuell offen (1): Shop'],
]);
