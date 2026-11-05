<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Contracts\CheckHeadline;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckRunEvidence;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationInput;
use Heptapod\Contracts\Mail\AlertMailContext;
use Heptapod\Contracts\Mail\AlertMailEvent;
use Heptapod\Contracts\Mail\EvidenceBlock;
use Heptapod\Contracts\Mail\ListBlock;
use Heptapod\Contracts\Mail\MailEventFacts;
use Heptapod\Contracts\Mail\MailEvidenceProvider;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Events\EventFacts;
use Heptapod\Events\Notifications\MailAvatar;
use Heptapod\Events\Notifications\MailRouting;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\Log;

final readonly class BuildAlertMailContext
{
    public function __construct(private CheckRegistry $registry, private MailAvatar $avatars, private InstanceSettings $settings, private CheckRunPresentations $presentations, private MailRouting $routing) {}

    /**
     * The addon evidence mail for an event on a live check whose addon provides mail evidence. Uptime outages, their
     * reminders and measured recoveries use the outage mail; every other uptime event (check errors, cause changes,
     * warnings) uses the declared event tones of its add-on like any other check. Null means a component observation was
     * pruned and its addon cannot present it.
     *
     * @param  list<string>  $affectedChecks
     * @param  list<string>  $recheckingChecks
     */
    public function __invoke(Event $event, AlertSeverity $severity, array $affectedChecks, array $recheckingChecks, NotificationDestination $destination): ?AlertMailContext
    {
        $outage = $event->check?->type === CheckKey::Uptime && $event->cause !== IncidentCause::Errored
            && in_array($event->type, ['incident.opened', 'incident.cause_changed', 'incident.still_down', 'incident.resolved'], true)
            && in_array($severity, [AlertSeverity::Down, AlertSeverity::Recovered], true);
        if (! $outage) {
            return $this->eventContext($event, $destination);
        }
        $site = $event->site ?? throw new \LogicException('Mail context requires a site.');
        $client = $site->client ?? throw new \LogicException('Mail context requires a client.');
        $check = $event->check ?? throw new \LogicException('Mail context requires a check.');
        $incident = $event->incident ?? throw new \LogicException('Mail context requires an incident.');
        $variant = AlertMailEvent::from($event->type);
        $openingIncident = $incident;
        if ($variant === AlertMailEvent::Recovered && UptimeAlertEpisode::appliesTo($incident)) {
            $sent = UptimeAlertEpisode::sentOpening($incident, $destination->id)
                ?? throw new \LogicException('An episode recovery requires this recipient\'s sent opener.');
            $openingIncident = $sent->event->incident ?? throw new \LogicException('A sent episode opener requires its retained incident.');
        }
        $snapshot = $openingIncident->evidence;
        $summary = $snapshot['summary'] ?? null;
        $params = $snapshot['summary_params'] ?? [];
        $evidence = $snapshot['evidence'] ?? [];
        $metrics = $snapshot['metrics'] ?? null;
        $observedAt = $snapshot['run_ended_at'] ?? null;
        if (! is_string($summary) || ! is_array($params) || ! is_array($evidence) || ($metrics !== null && ! is_array($metrics)) || ! is_string($observedAt)) {
            throw new \LogicException('Invalid retained opening evidence.');
        }
        foreach ($params as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                throw new \LogicException('Opening summary parameters must be scalar.');
            }
        }
        /** @var array<string, mixed> $evidence */
        /** @var array<string, mixed>|null $metrics */
        $input = new CheckRunPresentationInput(RunResult::Failed, $evidence, $metrics, $summary, $params);
        $provider = $this->registry->get($check->type);
        if (! $provider instanceof MailEvidenceProvider) {
            throw new \LogicException('Structured alert email requires an addon mail evidence provider.');
        }
        $locale = $destination->effectiveLocale();
        $timezone = $client->timezone ?? $this->settings->timezone;
        // A cause change to failed names the failure it now sees, not the check error the incident opened with.
        $presentation = ($variant === AlertMailEvent::CauseChanged ? $this->causeChangePresentation($event, $check) : $this->presentations->atOpening($openingIncident))
            ?? throw new \LogicException('Structured alert email requires its shared opening presentation.');
        $openingId = $snapshot['run_id'] ?? null;
        $facts = new MailEventFacts($event->type, $event->payload, $check->id, $event->component_type, $event->component_id, is_int($openingId) ? new RunReference($openingId, CarbonImmutable::parse($observedAt)->utc()) : null);
        $blocks = match ($variant) {
            AlertMailEvent::Opened => $provider->mailEvidence($input, CarbonImmutable::parse($observedAt)->utc(), $locale, $timezone, $facts),
            // The opening snapshot is the check error; the cause change itself retains the failed observation.
            AlertMailEvent::CauseChanged => $this->causeChangeEvidence($event, $provider, $locale, $timezone, $facts),
            AlertMailEvent::Reminder => [new NoteBlock(__('emails.reminder_note', ['id' => $incident->id], $locale))],
            AlertMailEvent::Recovered => $provider->mailRecoveryEvidence($input, CarbonImmutable::parse($observedAt)->utc(), $this->recoveryRun($event), $event->occurred_at, $locale, $timezone, $facts),
        };
        if (($event->payload['uptime_episode'] ?? false) === true || ($event->payload['maintenance_first_alert'] ?? false) === true) {
            $blocks[] = new NoteBlock(__('notifications.maintenance_ended_context', locale: $locale));
        }
        if ($variant === AlertMailEvent::Opened && $affectedChecks !== []) {
            $blocks[] = new ListBlock(__('emails.also_affected', locale: $locale), array_map(static fn (string $key): array => [
                'text' => __('checks.'.$key.'.label', locale: $locale), 'meta' => __('emails.held_check', locale: $locale),
            ], $affectedChecks));
        }
        if ($variant === AlertMailEvent::Recovered && $recheckingChecks !== []) {
            $blocks[] = new ListBlock(__('emails.rechecking', locale: $locale), array_map(static fn (string $key): array => [
                'text' => __('checks.'.$key.'.label', locale: $locale), 'meta' => '',
            ], $recheckingChecks));
            $blocks[] = new NoteBlock(__('emails.rechecking_note', locale: $locale));
        }

        return $this->context($event, $destination, $presentation, $blocks, $variant, MailTone::Down, $facts, $openingIncident);
    }

    /** @param list<EvidenceBlock> $blocks */
    private function context(Event $event, NotificationDestination $destination, CheckRunPresentationData $presentation, array $blocks, AlertMailEvent $variant, MailTone $tone, MailEventFacts $facts, ?Incident $openingIncident = null): AlertMailContext
    {
        $site = $event->site ?? throw new \LogicException('Mail context requires a site.');
        $client = $site->client ?? throw new \LogicException('Mail context requires a client.');
        $check = $event->check ?? throw new \LogicException('Mail context requires a check.');
        $timezone = $client->timezone ?? $this->settings->timezone;
        if ($variant === AlertMailEvent::Recovered && $event->incident !== null) {
            // Uptime episodes use this recipient's sent opener, not a later incident in the same episode.
            $openingIncident ??= $event->incident;
            $opening = $this->presentations->atOpening($openingIncident);
            // Legacy incidents can lack an opening reason; omit the line rather than inventing one.
            if ($opening instanceof CheckRunPresentationData) {
                $locale = $destination->effectiveLocale();
                $startedAt = (UptimeAlertEpisode::appliesTo($openingIncident)
                    ? UptimeAlertEpisode::start($openingIncident)
                    : $openingIncident->first_failure_at)->setTimezone($timezone);
                $startedAt->locale($locale);
                array_unshift($blocks, new NoteBlock(__('emails.resolved_opening', [
                    'reason' => rtrim(CheckHeadline::format($opening, $locale), '.'),
                    'opened' => $tone === MailTone::Down ? $startedAt->format('H:i T') : $startedAt->isoFormat('ll, HH:mm z'),
                ], $locale)));
            }
        }
        $reason = $presentation->parts[0] ?? throw new \LogicException('Mail presentation requires a reason.');
        $avatar = $this->avatars->for($client, $site);
        $routing = $this->routingWhy($event, $destination);

        return new AlertMailContext($event->incident_id, $variant, $event->occurred_at, $facts, $timezone, $this->registry->get($check->type)->definition()->labelKey, $reason, $blocks, $avatar['png'], $avatar['cid'],
            route('sites.checks.show', ['site' => $site->id, 'check' => $check->id]),
            $routing['why'], $routing['url'], $avatar['tile'], $routing['labelKey'], $presentation->explanation, $tone, array_slice($presentation->parts, 1), $this->settings->mail_design);
    }

    private function eventContext(Event $event, NotificationDestination $destination): ?AlertMailContext
    {
        $check = $event->check ?? throw new \LogicException('Mail event requires a check.');
        $provider = $this->registry->get($check->type);
        if (! $provider instanceof MailEvidenceProvider) {
            throw new \LogicException('Mail event requires addon evidence.');
        }
        $locale = $destination->effectiveLocale();
        $site = $event->site ?? throw new \LogicException('Mail event requires a site.');
        $client = $site->client ?? throw new \LogicException('Mail event requires a client.');
        $timezone = $client->timezone ?? $this->settings->timezone;
        $resultValue = $event->payload['result'] ?? $event->to_result->value ?? null;
        if (! is_string($resultValue)) {
            throw new \LogicException('Alert mail requires a retained final event result.');
        }
        $result = RunResult::from($resultValue);
        $tone = $provider->mailEventTones()[$event->type][$result->value]
            ?? throw new \LogicException('Addon did not declare the event/result mail tone: '.$event->type.' '.$result->value);
        // Only an uptime cause change to failed is an outage here: the check could not run before, now the site is down.
        if ($tone === MailTone::Down && ($check->type !== CheckKey::Uptime || $event->type !== 'incident.cause_changed')) {
            throw new \LogicException('Addon declared the outage tone outside an uptime cause change.');
        }
        $variant = $event->type === 'incident.cause_changed' ? AlertMailEvent::CauseChanged : match ($tone) {
            MailTone::Failed => AlertMailEvent::Opened,
            MailTone::CheckError => AlertMailEvent::CheckError,
            MailTone::Recovered => AlertMailEvent::Recovered,
            MailTone::Warning => AlertMailEvent::Warning,
            MailTone::Changed => AlertMailEvent::Changed,
            MailTone::Down, MailTone::Neutral => throw new \LogicException('Addon declared an invalid non-uptime mail tone.'),
        };
        $run = $this->eventRun($event);
        $reference = $run instanceof CheckRun ? new RunReference($run->id, $run->ended_at) : null;
        // Opening snapshots survive raw-run retention; never substitute the check's current latest observation.
        if ($event->type === 'incident.opened' && $event->incident !== null) {
            $snapshot = $event->incident->evidence;
            $summary = $snapshot['summary'] ?? null;
            if (! is_string($summary) || $summary === '') {
                throw new \LogicException('Opening mail snapshot requires a retained summary.');
            }
            $run = new CheckRun(['check_id' => $check->id, 'result' => ($snapshot['error'] ?? null) === null ? RunResult::Failed : RunResult::Errored,
                'summary' => $summary, 'summary_params' => $snapshot['summary_params'] ?? [], 'evidence' => $snapshot['evidence'] ?? [], 'metrics' => $snapshot['metrics'] ?? null, 'error' => $snapshot['error'] ?? null]);
            $id = $snapshot['run_id'] ?? null;
            $at = $snapshot['run_ended_at'] ?? null;
            $reference = is_int($id) && is_string($at) ? new RunReference($id, CarbonImmutable::parse($at)->utc()) : null;
        }
        $facts = new MailEventFacts($event->type, $event->payload, $check->id, $event->component_type, $event->component_id, $reference);
        $retained = EventFacts::fromEvent($event, $run);
        if (! $run instanceof CheckRun) {
            // Raw retention may remove the observation and its summary. That absence is shown explicitly, not as success.
            $input = new CheckRunPresentationInput($result, [], null, $retained->summaryKey ?? 'emails.details_not_retained', $retained->summaryParams, $facts);
            $presentation = $this->presentations->forInput($check, $input);
            $blocks = [new NoteBlock(__('emails.details_not_retained', locale: $locale))];
            // Event-owned diffs and component messages remain usable even when the raw observation was pruned.
            if ($event->payload !== []) {
                $blocks = [...$provider->mailEvidence($input, $event->occurred_at, $locale, $timezone, $facts), ...$blocks];
            }
        } else {
            $input = new CheckRunPresentationInput($run->result, $run->evidence, $run->metrics, $retained->summaryKey ?? $run->summary, $retained->summaryParams, $facts);
            $presentedRun = clone $run;
            $presentedRun->summary = $input->summary;
            $presentedRun->summary_params = $input->summaryParams;
            $presentation = $this->presentations->forRun($check, $presentedRun, $facts);
            $blocks = $provider->mailEvidence($input, $reference->endedAt ?? $event->occurred_at, $locale, $timezone, $facts);
        }
        if ($blocks === [] && $tone === MailTone::CheckError) {
            $blocks = [new NoteBlock(__('emails.check_error_no_evidence', locale: $locale), MailTone::CheckError)];
        }
        $componentPresentationUnavailable = $event->component_type instanceof SuppressionComponent
            && in_array($event->type, $provider->definition()->emittedEvents, true)
            && (! $run instanceof CheckRun || CheckRunEvidence::observation($run->evidence) === [])
            && ! $presentation instanceof CheckRunPresentationData;
        if ($componentPresentationUnavailable) {
            return null;
        }
        if (! $presentation instanceof CheckRunPresentationData || $presentation->parts === []) {
            throw new \LogicException('Installed addon must provide a retained mail presentation.');
        }

        return $this->context($event, $destination, $presentation, $blocks, $variant, $tone, $facts);
    }

    private function eventRun(Event $event): ?CheckRun
    {
        $id = $event->payload['run_id'] ?? null;
        $endedAt = $event->payload['run_ended_at'] ?? null;
        if (is_int($id) && is_string($endedAt)) {
            $run = CheckRun::forRun(new RunReference($id, CarbonImmutable::parse($endedAt)->utc()))->first();
            if ($run !== null && $run->check_id !== $event->check_id) {
                throw new \LogicException('Mail observation belongs to a different check.');
            }

            return $run;
        }
        // Addon outbox events use the accepted run's ended_at as occurred_at, but the pair is not unique by constraint.
        $query = CheckRun::query()->where('check_id', $event->check_id)->where('ended_at', $event->occurred_at->format('Y-m-d H:i:s.uP'));
        $query->getQuery()->limit(2);
        $runs = $query->get();
        if ($runs->count() > 1) {
            Log::error('Ambiguous alert mail observation; retaining the alert without run details.', ['event_id' => $event->id, 'check_id' => $event->check_id, 'ended_at' => $event->occurred_at->format('Y-m-d H:i:s.uP')]);

            return null;
        }

        return $runs->first();
    }

    private function causeChangePresentation(Event $event, Check $check): ?CheckRunPresentationData
    {
        $summary = $event->payload['summary'] ?? null;
        $params = $event->payload['summary_params'] ?? [];
        if (! is_string($summary) || ! is_array($params)) {
            throw new \LogicException('An uptime cause change must retain its summary.');
        }

        /** @var array<string, string|int|float|bool|null> $params */
        return $this->presentations->forInput($check, new CheckRunPresentationInput(RunResult::Failed, [], null, $summary, $params));
    }

    /** @return list<EvidenceBlock> */
    private function causeChangeEvidence(Event $event, MailEvidenceProvider $provider, string $locale, string $timezone, MailEventFacts $facts): array
    {
        $summary = $event->payload['summary'] ?? null;
        $params = $event->payload['summary_params'] ?? [];
        if (! is_string($summary) || ! is_array($params)) {
            throw new \LogicException('An uptime cause change must retain its summary.');
        }
        $run = $this->eventRun($event);
        /** @var array<string, string|int|float|bool|null> $params */
        $input = new CheckRunPresentationInput(RunResult::Failed, $run->evidence ?? [], $run?->metrics, $summary, $params);

        return $provider->mailEvidence($input, $run->ended_at ?? $event->occurred_at, $locale, $timezone, $facts);
    }

    private function recoveryRun(Event $event): ?CheckRunPresentationInput
    {
        $id = $event->payload['run_id'] ?? null;
        $endedAt = $event->payload['run_ended_at'] ?? null;
        if (! is_int($id) || ! is_string($endedAt)) {
            throw new \LogicException('Recovery event requires its accepted run reference.');
        }
        // Exact composite-PK lookup. Raw retention may legitimately have removed this recorded run.
        $run = CheckRun::forRun(new RunReference($id, CarbonImmutable::parse($endedAt)->utc()))->first();
        if ($run === null) {
            return null;
        }
        if ($run->check_id !== $event->check_id) {
            throw new \LogicException('Recovery run belongs to a different check.');
        }

        return new CheckRunPresentationInput($run->result, $run->evidence, $run->metrics, $run->summary, $run->summary_params ?? []);
    }

    /** @return array{why: string, url: string, labelKey: string} */
    private function routingWhy(Event $event, NotificationDestination $destination): array
    {
        $category = NotificationCategory::of($event) ?? throw new \LogicException('A routed alert mail needs a category.');

        return $this->routing->footer($destination, __('recipient_settings.category.'.$category->value.'.plural', locale: $destination->effectiveLocale()));
    }
}
