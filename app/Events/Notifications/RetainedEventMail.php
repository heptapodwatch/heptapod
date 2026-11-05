<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\FactsBlock;
use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\SecretRedactor;
use Heptapod\Contracts\SummaryKey;
use Heptapod\Contracts\SummaryParameters;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\IncidentResolutionReason;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Enums\RetainedAlert;
use Heptapod\Events\EventFacts;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\Site;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Support\RedactionContext;
use Illuminate\Support\Facades\Lang;

/**
 * The block mail for an alert whose live mail evidence cannot be shown (see RetainedAlert). It states only retained event
 * facts: the retained summary, the check, the time, and an explicit note on what is no longer available.
 */
final readonly class RetainedEventMail
{
    public function __construct(private InstanceSettings $settings, private MailRouting $routing, private MailAvatar $avatars, private CheckRegistry $registry) {}

    /** @param ?NotificationCategory $category the delivery's retained category; null only when the add-on that defines it is gone */
    public function build(Event $event, AlertSeverity $severity, RetainedAlert $kind, NotificationDestination $destination, ?NotificationCategory $category): MailModel
    {
        $locale = $destination->effectiveLocale();
        $timezone = $destination->timezone();
        $site = $kind === RetainedAlert::SiteDeleted ? null : ($event->site ?? throw new \LogicException('A retained alert keeps its live site unless the site was deleted.'));
        $name = $site?->displayName() ?? $event->site_name ?? throw new \LogicException('A retained alert must keep its site label.');
        $label = $site === null ? __('events.deleted_site', ['label' => $name], $locale) : $name;
        $client = $site?->client;
        $clientName = $client->name ?? $event->client_name ?? throw new \LogicException('A retained alert must keep its client.');
        $checkType = $event->check->type ?? $event->check_type ?? throw new \LogicException('A retained alert must keep its check type.');
        $facts = EventFacts::fromEvent($event);
        // Whether the check's add-on is installed is independent of whether its site or check still exists.
        $addonInstalled = in_array($checkType, $this->registry->keys(), true);
        // How the incident was closed is a fact of the event; a deleted site or removed check only limits what is still shown.
        // Only ResolveInactiveCheckIncidents records a resolution_reason: monitoring stopped. Any other closure with a
        // resolution_key was reported by the check itself because nothing is left to check (cron without tasks).
        $closure = null;
        if ($event->type === 'incident.resolved' && isset($facts->summaryParams['resolution_key'])) {
            $stoppedBy = $event->payload['resolution_reason'] ?? null;
            $closure = is_string($stoppedBy) && IncidentResolutionReason::tryFrom($stoppedBy) instanceof IncidentResolutionReason ? 'administrative' : 'closed_by_check';
        }
        $state = $closure ?? $this->state($event, $severity, $checkType);
        $subject = __('checks.'.$checkType->value.'.label', locale: $locale).($facts->componentName === null ? '' : ' · '.$facts->componentName);
        $time = static function (CarbonImmutable $at) use ($timezone, $locale): string {
            $local = $at->setTimezone($timezone);
            $local->locale($locale);

            return $local->isoFormat('ll, HH:mm z');
        };

        $rows = [['label' => __('emails.retained_check', locale: $locale), 'value' => new Cell($subject)]];
        $reason = $this->reason($event, $facts, $closure === 'administrative', $addonInstalled, $site, $locale);
        if ($reason !== null) {
            $rows[] = ['label' => __($closure !== null ? 'emails.retained_closed_because' : 'emails.retained_reason', locale: $locale), 'value' => new Cell($reason)];
        }
        if ($event->incident !== null) {
            $rows[] = ['label' => __('emails.retained_opened', locale: $locale), 'value' => new Cell($time($event->incident->opened_at))];
        }
        $rows[] = ['label' => __($closure !== null ? 'emails.retained_closed_at' : 'emails.retained_at', locale: $locale), 'value' => new Cell($time($event->occurred_at))];
        $notes = $closure === null ? [] : ['emails.'.$closure.'_note'];
        $availability = match ($kind) {
            RetainedAlert::SiteDeleted => 'emails.site_deleted_note',
            RetainedAlert::CheckRemoved => 'emails.check_removed_note',
            RetainedAlert::EvidenceUnavailable => 'emails.evidence_unavailable_note',
            RetainedAlert::Reminder => 'emails.retained_reminder_note',
            RetainedAlert::ComponentDetailsPruned => 'emails.details_not_retained',
            RetainedAlert::ClosedWithoutRecovery => null,
        };
        if ($availability !== null) {
            $notes[] = $availability;
        }
        if (! $addonInstalled && $kind !== RetainedAlert::EvidenceUnavailable) {
            $notes[] = 'emails.evidence_unavailable_note';
        }
        $blocks = [new FactsBlock('', $rows), ...array_map(static fn (string $note): NoteBlock => new NoteBlock(__($note, locale: $locale)), $notes)];

        // A retained delivery keeps its category; without one (its add-on is gone) the footer names the channel's alerts.
        $footer = $this->routing->footer($destination, __($category instanceof NotificationCategory ? 'recipient_settings.category.'.$category->value.'.plural' : 'emails.retained_category_unknown', locale: $locale));
        $avatar = $site !== null && $client !== null ? $this->avatars->for($client, $site) : ['png' => null, 'cid' => null, 'tile' => null];
        $params = ['site' => $label, 'check' => $subject];
        $headline = __('emails.retained_headline.'.$state, $params, $locale);
        $incidentUrl = $site !== null && $event->incident_id !== null ? route('incidents.show', ['incident' => $event->incident_id]) : null;
        $checkUrl = $site !== null && $event->check_id !== null ? route('sites.checks.show', ['site' => $site->id, 'check' => $event->check_id]) : null;
        $siteUrl = $site === null ? null : route('sites.show', ['site' => $site->id]);

        return new MailModel(locale: $locale, subject: __('emails.retained_subject.'.$state, $params, $locale), preheader: $headline,
            headline: $headline, footerWhy: $footer['why'], instance: $this->settings->name.' · '.parse_url(config()->string('app.url'), PHP_URL_HOST),
            tone: match ($state) {
                'down', 'still_down' => MailTone::Down,
                'failed', 'still_failing' => MailTone::Failed,
                'check_error', 'still_check_error' => MailTone::CheckError,
                'warning' => MailTone::Warning,
                'recovered' => MailTone::Recovered,
                'changed' => MailTone::Changed,
                default => MailTone::Neutral,
            },
            client: $clientName, site: $label, chip: __('emails.retained_chip.'.$state, locale: $locale),
            stateMeta: $event->incident_id === null ? __('emails.retained_no_incident', locale: $locale) : '#'.$event->incident_id,
            blocks: $blocks,
            primary: $incidentUrl !== null ? ['label' => __('emails.open_incident', ['id' => $event->incident_id], $locale), 'url' => $incidentUrl]
                : ($checkUrl !== null ? ['label' => __('emails.open_check', locale: $locale), 'url' => $checkUrl]
                    : ($siteUrl !== null ? ['label' => __('emails.open_site', locale: $locale), 'url' => $siteUrl] : null)),
            secondary: $incidentUrl !== null && $checkUrl !== null ? ['label' => __('emails.open_check', locale: $locale), 'url' => $checkUrl] : null,
            routingLabel: __($footer['labelKey'], locale: $locale), routingUrl: $footer['url'],
            avatarPng: $avatar['png'], avatarCid: $avatar['cid'], avatarTile: $avatar['tile'], design: $this->settings->mail_design);
    }

    /** The glossary state of a retained observation; closures without a measured recovery are decided by the caller. */
    private function state(Event $event, AlertSeverity $severity, CheckKey $check): string
    {
        $errored = $event->cause === IncidentCause::Errored || $severity === AlertSeverity::Error;
        $outage = $check === CheckKey::Uptime && $event->component_type === null;
        if ($event->type === 'incident.still_down') {
            return $errored ? 'still_check_error' : ($outage ? 'still_down' : 'still_failing');
        }

        return match ($severity) {
            AlertSeverity::Recovered => 'recovered',
            AlertSeverity::Warning => 'warning',
            AlertSeverity::Info => 'changed',
            AlertSeverity::Error => 'check_error',
            AlertSeverity::Down => $errored ? 'check_error' : ($outage ? 'down' : 'failed'),
        };
    }

    private function reason(Event $event, EventFacts $facts, bool $monitoringStopped, bool $addonInstalled, ?Site $site, string $locale): ?string
    {
        if ($monitoringStopped) {
            $key = $facts->summaryParams['resolution_key'] ?? null;

            return is_string($key) && Lang::has($key, $locale) ? __($key, locale: $locale) : throw new \LogicException('An administrative resolution must retain a known reason.');
        }
        if ($facts->summaryKey === null) {
            return null;
        }
        if (SummaryKey::isFreeText($facts->summaryKey)) {
            return rtrim($facts->summaryKey, '.');
        }
        if (! Lang::has($facts->summaryKey, $locale)) {
            // Only a missing add-on takes its translations with it; for installed add-ons a missing key is a bug.
            return $addonInstalled ? throw new \LogicException('Retained summary translation missing: '.$facts->summaryKey) : null;
        }
        // Recorder payloads are redacted; the site and check secrets are applied again as the alert builder does.
        $contextSite = $site ?? new Site(['friendly_name' => $event->site_name, 'url' => $event->site_url ?? throw new \LogicException('A deleted-site alert must retain its URL.')]);
        $params = SecretRedactor::redactStringValues($facts->summaryParams, RedactionContext::forSite($contextSite, $event->check));
        $text = __($facts->summaryKey, SummaryParameters::localizedMeasurements($params, $locale), $locale);

        return is_string($text) ? rtrim($text, '.') : throw new \LogicException('A retained summary must translate to text.');
    }
}
