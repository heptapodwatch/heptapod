<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\PlanNotificationEvent;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Events\Notifications\ExplanationExemplars;
use Heptapod\Events\Notifications\NotificationEventTypes;
use Heptapod\Models\Check;
use Heptapod\Models\Event;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-30T12:00:00Z'));
});

/**
 * Every event shape the engine can emit, enumerated the way addonAlertKeys does: each check type, the incident
 * events, result changes and each declared addon event with every non-pending result and previous result.
 *
 * @return list<Event>
 */
function everyRoutableShape(): array
{
    $results = array_values(array_filter(CheckResult::cases(), fn (CheckResult $result): bool => $result !== CheckResult::Pending));
    $shapes = [];
    foreach (CheckKey::cases() as $type) {
        $check = new Check(['type' => $type]);
        $events = [];
        foreach ([CheckResult::Failed, CheckResult::Errored] as $result) {
            $cause = $result === CheckResult::Errored ? IncidentCause::Errored : IncidentCause::Failed;
            foreach (['incident.opened', 'incident.cause_changed', 'incident.still_down'] as $opening) {
                $events[] = new Event(['type' => $opening, 'to_result' => $result, 'cause' => $cause]);
            }
            $events[] = new Event(['type' => 'incident.resolved', 'from_result' => $result, 'to_result' => CheckResult::Succeeded, 'cause' => $cause]);
        }
        foreach ([...$results, null] as $from) {
            foreach ($results as $to) {
                $events[] = new Event(['type' => 'check.result_changed', 'from_result' => $from, 'to_result' => $to]);
                foreach (app(CheckRegistry::class)->get($type)->definition()->emittedEvents as $name) {
                    $events[] = new Event(['type' => $name, 'to_result' => $to, 'payload' => ['result' => $to->value, 'from_result' => $from?->value]]);
                }
            }
        }
        foreach ($events as $event) {
            $shapes[] = $event->setRelation('check', $check);
        }
    }

    return $shapes;
}

it('maps every reachable routing key to one category and reaches exactly the declared keys', function (): void {
    $types = app(NotificationEventTypes::class);
    $reached = [];
    foreach (everyRoutableShape() as $event) {
        $key = $types->key($event);
        if ($key === null) {
            expect(NotificationCategory::of($event))->toBeNull();

            continue;
        }
        expect(NotificationCategory::of($event))->toBeInstanceOf(NotificationCategory::class);
        $reached[$key] = true;
    }

    expect(array_keys($reached))->toEqualCanonicalizing($types->keys());
    expect($reached)->not->toHaveKey('cron.task_result_changed');
    expect($types->keys())->not->toContain('cron.task_result_changed');
});

it('routes cron results by result: failed is a change, warning a warning, a success after a problem a recovery', function (?string $from, string $result, ?NotificationCategory $category): void {
    $check = new Check(['type' => CheckKey::Cron]);
    $event = new Event(['type' => 'cron.task_result_changed', 'to_result' => CheckResult::from($result),
        'payload' => ['result' => $result, 'from_result' => $from]]);
    $event->setRelation('check', $check);

    expect(NotificationCategory::of($event))->toBe($category);
    expect(app(NotificationEventTypes::class)->key($event))->not->toBe('cron.task_result_changed');
})->with([
    'missed task' => ['succeeded', 'failed', NotificationCategory::Changes],
    'warning' => ['succeeded', 'warning', NotificationCategory::Warning],
    'recovered from failed' => ['failed', 'succeeded', NotificationCategory::Recovery],
    'recovered from warning' => ['warning', 'succeeded', NotificationCategory::Recovery],
    'first success' => [null, 'succeeded', null],
    'success after pending' => ['pending', 'succeeded', null],
]);

it('generates explanation exemplars per category from the mapping', function (CheckKey $type, NotificationCategory $category, bool $applicable): void {
    $check = Check::factory()->create(['type' => $type]);

    $exemplars = app(ExplanationExemplars::class)->for($check, $category);

    expect($exemplars !== [])->toBe($applicable);
    foreach ($exemplars as $event) {
        expect(NotificationCategory::of($event))->toBe($category);
    }
})->with([
    'expiring certificate' => [CheckKey::CertificateHealth, NotificationCategory::Expiring, true],
    'expiring domain' => [CheckKey::Domain, NotificationCategory::Expiring, true],
    'no expiry on DNS' => [CheckKey::Dns, NotificationCategory::Expiring, false],
    'broken links change' => [CheckKey::BrokenLinks, NotificationCategory::Changes, true],
    'no change on uptime' => [CheckKey::Uptime, NotificationCategory::Changes, false],
    'uptime outage' => [CheckKey::Uptime, NotificationCategory::Outage, true],
    'no outage on DNS' => [CheckKey::Dns, NotificationCategory::Outage, false],
    'no system on any check' => [CheckKey::Uptime, NotificationCategory::System, false],
]);

it('refuses to plan an event whose routing key has no category', function (): void {
    config(['notifications.event_types.certificate_health' => ['check.warning' => 'certificate.unmapped']]);
    $check = Check::factory()->create(['type' => CheckKey::CertificateHealth]);
    $event = Event::factory()->create(['check_id' => $check->id, 'from_result' => CheckResult::Succeeded, 'to_result' => CheckResult::Warning]);

    expect(fn () => app(PlanNotificationEvent::class)($event->id))->toThrow(LogicException::class, 'Unmapped notification routing key: certificate.unmapped');
    expect($event->fresh()->notification_routed_at)->toBeNull();
});
