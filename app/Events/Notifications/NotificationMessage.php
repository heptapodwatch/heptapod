<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Actions\BuildAlertMailContext;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Mail\AlertMailContext;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Notifications\AlertMessage;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\SecretRedactor;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\RetainedAlert;
use Heptapod\Events\EventFacts;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Support\RedactionContext;

final readonly class NotificationMessage
{
    public function __construct(private NotificationEventTypes $types, private UptimeDependencies $dependencies, private BuildAlertMailContext $mailContexts, private CheckRunPresentations $presentations, private DigestMail $digests, private CatchUpMail $catchUps, private RetainedEventMail $retainedMails, private CheckRegistry $registry) {}

    /** Alerts whose live mail evidence cannot be shown honestly get the retained-facts mail; null means the add-on evidence mail. */
    private function retainedAlert(Event $event, AlertSeverity $severity, bool $deletedSite): ?RetainedAlert
    {
        $type = $event->check->type ?? $event->check_type;

        return match (true) {
            $deletedSite => RetainedAlert::SiteDeleted,
            // Stopped monitoring and a check with nothing left to check close the incident without a measured recovery.
            $event->type === 'incident.resolved' && isset(EventFacts::fromEvent($event)->summaryParams['resolution_key']) => RetainedAlert::ClosedWithoutRecovery,
            $event->check === null => RetainedAlert::CheckRemoved,
            ! in_array($type, $this->registry->keys(), true) || ! $this->registry->get($event->check->type) instanceof MailEvidenceProvider => RetainedAlert::EvidenceUnavailable,
            // New reminders are uptime outages only; a retained non-uptime or check-error reminder keeps a reminder tone.
            $event->type === 'incident.still_down' && ($type !== CheckKey::Uptime || $severity !== AlertSeverity::Down) => RetainedAlert::Reminder,
            default => null,
        };
    }

    /** $category is the delivery's retained category; it outlives an uninstalled add-on, whose routing keys are gone. */
    public function build(Event $event, ?NotificationDestination $destination = null, ?NotificationCategory $category = null): AlertMessage
    {
        if ($event->type === 'notification.digest') {
            $count = $event->payload['count'] ?? null;
            $sites = $event->payload['sites'] ?? null;
            if (! is_int($count) || ! is_string($sites)) {
                throw new \LogicException('Invalid digest payload.');
            }

            $remaining = $event->payload['remaining_sites'] ?? 0;
            if (! is_int($remaining) || $remaining < 0) {
                throw new \LogicException('Invalid digest remaining-site count.');
            }

            return new AlertMessage($event->id, AlertSeverity::Info, 'notifications.digest_title', [],
                $remaining > 0 ? 'notifications.digest_summary_more' : 'notifications.digest_summary', ['count' => $count, 'sites' => $sites, 'remaining' => $remaining],
                0, 'Heptapod', config()->string('app.url'), null, $event->occurred_at->utc(), null, null, route('sites.index'),
                mail: $destination?->channel === 'mail' ? $this->digests->build($event, $destination) : null);
        }
        $deletedSite = $event->site === null;
        $site = $event->site;
        if ($deletedSite) {
            $site = new Site(['friendly_name' => $event->site_name ?? throw new \LogicException('A deleted-site alert must retain its label.'),
                'url' => $event->site_url ?? throw new \LogicException('A deleted-site alert must retain its URL.')]);
            $site->setRelation('client', new Client(['name' => $event->client_name ?? throw new \LogicException('A deleted-site alert must retain its client.')]));
        }
        if ($site === null) {
            throw new \LogicException('An alert must have site identity.');
        }
        if (($event->type === 'maintenance.catch_up' && ($event->payload['uptime_episode'] ?? false) === true)
            || ($event->type === 'incident.still_down' && ($event->payload['maintenance_first_alert'] ?? false) === true)) {
            // Keep the audit event type; render the first post-maintenance episode alert through the normal opener template.
            $opening = clone $event;
            $incident = $event->incident ?? throw new \LogicException('An uptime catch-up needs its incident.');
            $opening->type = 'incident.opened';
            $opening->payload = [...$event->payload, 'summary' => $incident->evidence['summary'] ?? null,
                'summary_params' => $incident->evidence['summary_params'] ?? []];

            return $this->build($opening, $destination, $category);
        }
        if ($event->type === 'maintenance.catch_up') {
            $count = $event->payload['count'] ?? null;
            $checks = $event->payload['check_keys'] ?? null;
            $since = $event->payload['since'] ?? null;
            if (! is_int($count) || ! is_array($checks) || array_any($checks, static fn (mixed $check): bool => ! is_string($check))
                || ! is_string($since)) {
                throw new \LogicException('Invalid maintenance catch-up payload.');
            }
            /** @var list<string> $checks */
            $client = $site->client ?? throw new \LogicException('Alert site must have a client.');
            $context = RedactionContext::forSite($site, null);

            return new AlertMessage($event->id, AlertSeverity::Down, 'notifications.maintenance_catch_up_title',
                ['site' => $site->displayName()], 'notifications.maintenance_catch_up_summary',
                ['count' => $count, 'checks' => ''], $site->id, $site->displayName(),
                SecretRedactor::redact($site->url, $context->secrets, $context->urls), null,
                CarbonImmutable::parse($since)->utc(), null, 'failed', $deletedSite ? null : route('sites.show', ['site' => $site->id]), clientName: $client->name, checkTypes: $checks,
                mail: $destination?->channel === 'mail' ? $this->catchUps->build($event, $destination) : null);
        }
        $severity = $this->types->severity($event);
        $title = match ($severity) {
            AlertSeverity::Down => $this->types->key($event) === 'site.down' ? 'notifications.down_title' : 'notifications.failing_title',
            AlertSeverity::Recovered => 'notifications.recovered_title',
            AlertSeverity::Warning => 'notifications.warning_title',
            AlertSeverity::Error => 'notifications.errored_title',
            AlertSeverity::Info => 'notifications.info_title',
        };
        $facts = EventFacts::fromEvent($event);
        $component = $event->component_type !== null;
        $since = $facts->failureStartedAt ?? $event->occurred_at;
        $recovered = $facts->recoveredAt;
        $downtime = $severity === AlertSeverity::Recovered ? $facts->durationSeconds : null;
        if (UptimeAlertEpisode::appliesTo($event->incident)) {
            $since = UptimeAlertEpisode::start($event->incident ?? throw new \LogicException('An episode requires its incident.'));
            if ($severity === AlertSeverity::Recovered && $recovered instanceof CarbonImmutable) {
                $downtime = (int) $since->diffInSeconds($recovered);
            }
        }
        $label = $site->displayName();
        $client = $site->client ?? throw new \LogicException('Alert site must have a client.');
        $check = ($event->check->type ?? $event->check_type)?->value;

        $params = ['site' => $label, 'check' => $check ?? ''];
        if ($event->type === 'incident.still_down' && ! $deletedSite) {
            $incident = $event->incident ?? throw new \LogicException('A reminder must have an incident.');
            $title = 'notifications.still_down_title';
            $params['hours'] = max(1, (int) floor($incident->opened_at->diffInSeconds($event->occurred_at) / 3600));
        }
        $summary = 'notifications.'.$severity->value.'_summary';
        $summaryParams = [];
        if ($component) {
            $name = $facts->componentName ?? throw new \LogicException('A component notification must have an identity.');
            $params['component'] = $name;
            $title = 'notifications.component_'.$severity->value.'_title';
            $summary = $recovered instanceof CarbonImmutable ? 'notifications.component_recovered_summary' : 'notifications.component_summary';
            $summaryParams = ['component' => $name, 'at' => ($recovered ?? $event->occurred_at)->toIso8601String()];
        }

        if (! $component && ($event->check !== null || $deletedSite)) {
            $event->check?->setRelation('site', $site);
            if ($facts->summaryKey !== null) {
                $summary = $facts->summaryKey;
                $summaryParams = $facts->summaryParams;
            }
            foreach ($summaryParams as $value) {
                if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                    throw new \LogicException('Notification summary parameters must be scalar text or numbers.');
                }
            }
        }

        $context = RedactionContext::forSite($site, $event->check);
        $summaryParams = SecretRedactor::redactStringValues($summaryParams, $context);

        $affectedChecks = $event->check?->type === CheckKey::Uptime && $event->incident !== null
            && in_array($event->type, ['incident.opened', 'incident.cause_changed', 'incident.still_down'], true)
            ? $this->dependencies->affected($event->incident) : [];

        $recheckingChecks = $event->check?->type === CheckKey::Uptime && $event->incident !== null && $event->type === 'incident.resolved'
            ? $this->dependencies->rechecking($event->incident) : [];

        $mailContext = null;
        $mail = null;
        if ($destination?->channel === 'mail') {
            $retained = $this->retainedAlert($event, $severity, $deletedSite);
            if (! $retained instanceof RetainedAlert) {
                $mailContext = ($this->mailContexts)($event, $severity, $affectedChecks, $recheckingChecks, $destination);
                $retained = $mailContext instanceof AlertMailContext ? null : RetainedAlert::ComponentDetailsPruned;
            }
            $mail = $retained instanceof RetainedAlert ? $this->retainedMails->build($event, $severity, $retained, $destination, $category ?? NotificationCategory::of($event)) : null;
        }

        return new AlertMessage($event->id, $severity, $title, $params,
            $summary, $summaryParams,
            $site->id, $label, SecretRedactor::redact($site->url, $context->secrets, $context->urls), $check, $since->utc(), $downtime,
            $severity === AlertSeverity::Error ? 'errored' : ($severity === AlertSeverity::Down ? 'failed' : null),
            $deletedSite ? null : ($event->incident_id !== null ? route('incidents.show', ['incident' => $event->incident_id]) : route('sites.show', ['site' => $site->id])),
            clientName: $client->name,
            affectedChecks: $affectedChecks,
            recheckingChecks: $recheckingChecks,
            mailContext: $mailContext,
            mail: $mail,
            presentation: $this->presentations->forEvent($event),
            maintenanceEnded: ($event->payload['uptime_episode'] ?? false) === true || ($event->payload['maintenance_first_alert'] ?? false) === true);
    }
}
