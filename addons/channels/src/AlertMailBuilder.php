<?php

declare(strict_types=1);

namespace Heptapod\Addons\Channels;

use Heptapod\Contracts\CheckHeadline;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\Mail\AlertMailEvent;
use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\SuppressionComponent;
use Illuminate\Support\Facades\Lang;

final class AlertMailBuilder
{
    public function build(RenderedAlert $alert): MailModel
    {
        $message = $alert->message;
        $context = $message->mailContext ?? throw new \LogicException('Structured alert email requires retained mail context.');
        $reason = __($context->reason->key, $context->reason->localizedParams($alert->destinationLocale), $alert->destinationLocale);
        $subjectReason = __($context->reason->shortKey ?? $context->reason->key, $context->reason->localizedParams($alert->destinationLocale), $alert->destinationLocale);
        if ($context->tone !== MailTone::Down) {
            return $this->checkMail($alert);
        }
        $incidentId = $context->incidentId ?? throw new \LogicException('Uptime mail requires an incident.');
        // Before a cause change the check could not run, so the outage is only known from the change itself.
        $since = ($context->event === AlertMailEvent::CauseChanged ? $context->eventAt : $message->since)->setTimezone($context->timezone)->format('H:i T');
        $recovered = $context->event === AlertMailEvent::Recovered;
        $reminder = $context->event === AlertMailEvent::Reminder;
        $durationSeconds = $recovered
            ? ($message->downtimeSeconds ?? throw new \LogicException('Recovery email requires outage duration.'))
            : (int) $message->since->diffInSeconds($context->eventAt);
        $duration = $this->duration($durationSeconds, $alert->destinationLocale);
        $params = ['site' => $message->siteLabel, 'reason' => $reason, 'since' => $since, 'duration' => $duration];
        $subject = match ($context->event) {
            // A cause change to failed is the outage starting: the check could not run before.
            AlertMailEvent::Opened, AlertMailEvent::CauseChanged => $alert->translate('emails.down_subject', [...$params, 'reason' => $subjectReason]),
            AlertMailEvent::Reminder => $alert->translate('emails.reminder_subject', $params),
            AlertMailEvent::Recovered => $alert->translate('emails.recovered_subject', $params),
            default => throw new \LogicException('Unexpected uptime email variant.'),
        };
        $headline = $alert->translate(match ($context->event) {
            AlertMailEvent::Opened, AlertMailEvent::CauseChanged => 'emails.down_headline',
            AlertMailEvent::Reminder => 'emails.reminder_headline',
            AlertMailEvent::Recovered => 'emails.recovered_headline',
        }, [...$params, 'reason' => $alert->destinationLocale === 'en' && preg_match('/^\p{Lu}\p{Ll}/u', $reason) === 1 ? lcfirst($reason) : $reason]);
        $state = $recovered
            ? $alert->translate('emails.recovered_state', ['since' => $since, 'until' => $context->eventAt->setTimezone($context->timezone)->format('H:i T'), 'id' => $incidentId])
            : $alert->translate('emails.down_state', ['since' => $since, 'id' => $incidentId]);

        return new MailModel(
            locale: $alert->destinationLocale,
            subject: $subject,
            preheader: $reminder ? $alert->translate('emails.reminder_preheader') : ($recovered ? $alert->translate('emails.recovered_preheader') : $reason),
            headline: $headline,
            footerWhy: $context->routingWhy,
            instance: $alert->instanceName.' · '.parse_url($alert->instanceUrl, PHP_URL_HOST),
            tone: $recovered ? MailTone::Recovered : MailTone::Down,
            client: $message->clientName ?? throw new \LogicException('Uptime email requires client identity.'),
            site: $message->siteLabel,
            chip: $alert->translate($recovered ? 'emails.recovered' : 'emails.down'),
            stateMeta: $state,
            blocks: $context->blocks,
            primary: $message->link === null ? null : ['label' => $alert->translate('emails.open_incident', ['id' => $incidentId]), 'url' => $message->link],
            secondary: $reminder
                ? ($message->link === null ? null : ['label' => $alert->translate('emails.snooze_reminders'), 'url' => $message->link.'#snooze'])
                : ($recovered ? null : ['label' => $alert->translate('emails.open_check'), 'url' => $context->checkUrl]),
            routingLabel: $alert->translate($context->routingLabelKey),
            routingUrl: $context->routingUrl,
            avatarPng: $context->avatarPng,
            avatarCid: $context->avatarCid,
            avatarTile: $context->avatarTile,
            design: $context->design,
        );
    }

    private function checkMail(RenderedAlert $alert): MailModel
    {
        $message = $alert->message;
        $context = $message->mailContext ?? throw new \LogicException('Check mail context missing.');
        $locale = $alert->destinationLocale;
        $presentation = $message->presentation ?? new CheckRunPresentationData([$context->reason, ...$context->additionalReasons]);
        $reason = CheckHeadline::format($presentation, $locale);
        $short = $this->partText($context->reason, $locale, true);
        if (! $presentation->names_check && $message->checkType !== null) {
            $short = CheckHeadline::prefix(CheckKey::from($message->checkType), $short, $locale);
        }
        $lead = $context->explanation instanceof CheckRunPresentationPartData ? $this->partText($context->explanation, $locale).'.' : null;
        $state = match ($context->tone) {
            MailTone::Failed => 'failed', MailTone::Warning => 'warning', MailTone::CheckError => 'check_error',
            MailTone::Changed => 'changed', MailTone::Recovered => 'check_recovered',
            MailTone::Down, MailTone::Neutral => throw new \LogicException('Invalid check mail state.'),
        };
        $component = $context->eventFacts->componentType instanceof SuppressionComponent;
        $knownStart = ! $component || is_string($context->eventFacts->payload['failure_started_at'] ?? null);
        $incidentResolved = $context->eventFacts->type === 'incident.resolved';
        $observedOnly = ! $knownStart || ($context->tone === MailTone::Recovered && ! $incidentResolved);
        $at = ($context->incidentId === null || $observedOnly ? $context->eventAt : $message->since)->setTimezone($context->timezone);
        $at->locale($locale);
        $meta = $alert->translate($context->incidentId === null ? 'emails.no_incident_state' : ($observedOnly ? 'emails.observed_state' : 'emails.down_state'), ['since' => $at->isoFormat('ll, HH:mm z'), 'id' => $context->incidentId ?? 0]);
        if ($incidentResolved && $context->incidentId !== null) {
            $until = $context->eventAt->setTimezone($context->timezone);
            $until->locale($locale);
            $meta = $alert->translate('emails.check_recovered_state', ['since' => $at->isoFormat('ll, HH:mm z'), 'until' => $until->isoFormat('ll, HH:mm z'), 'id' => $context->incidentId]);
        }
        if ([...$presentation->changes, ...$presentation->details] !== []) {
            $meta .= ' · '.implode(' · ', array_map(fn (CheckRunPresentationPartData $part): string => $this->partText($part, $locale), [...$presentation->changes, ...$presentation->details]));
        }
        $headline = $reason;
        if ($context->tone === MailTone::CheckError) {
            $check = $alert->translate($context->checkLabelKey);
            $subject = $alert->translate('emails.check_error_subject', ['site' => $message->siteLabel, 'check' => $check]);
            $headline = rtrim($alert->translate('emails.check_error_headline', ['site' => $message->siteLabel, 'check' => $check]), '.').'.';
            $lead = $alert->translate('emails.not_checked', ['reason' => rtrim($reason, '.')]).($lead === null ? '' : ' '.$lead);
        } else {
            $subject = $alert->translate('emails.'.$state.'_subject', ['site' => $message->siteLabel, 'reason' => $short]);
        }

        return new MailModel(
            locale: $locale,
            subject: $subject,
            preheader: $lead ?? $reason,
            headline: $headline,
            footerWhy: $context->routingWhy,
            instance: $alert->instanceName.' · '.parse_url($alert->instanceUrl, PHP_URL_HOST),
            tone: $context->tone,
            lead: $lead,
            client: $message->clientName ?? throw new \LogicException('Check email requires client identity.'),
            site: $message->siteLabel,
            // A recovery answers how long it was broken, in the uptime mail's "after :duration" wording.
            chip: $context->tone === MailTone::Recovered && $message->downtimeSeconds !== null
                ? $alert->translate('emails.check_recovered_after', ['duration' => $this->duration($message->downtimeSeconds, $locale)])
                : $alert->translate('emails.'.$state),
            stateMeta: $meta,
            blocks: $context->blocks,
            primary: $context->incidentId !== null && $message->link !== null
                ? ['label' => $alert->translate('emails.open_incident', ['id' => $context->incidentId]), 'url' => $message->link]
                : ['label' => $alert->translate('emails.open_check'), 'url' => $context->checkUrl],
            secondary: $context->incidentId === null ? null : ['label' => $alert->translate('emails.open_check'), 'url' => $context->checkUrl],
            routingLabel: $alert->translate($context->routingLabelKey),
            routingUrl: $context->routingUrl,
            avatarPng: $context->avatarPng,
            avatarCid: $context->avatarCid,
            avatarTile: $context->avatarTile,
            design: $context->design,
        );
    }

    private function partText(CheckRunPresentationPartData $part, string $locale, bool $short = false, bool $continuation = false): string
    {
        $params = $part->localizedParams($locale);
        $count = $part->params['count'] ?? null;
        $plural = $count === 1 ? '_one' : '_other';
        foreach ($part->labels as $name => $key) {
            $labelKey = is_numeric($count) && Lang::has($key.$plural, $locale) ? $key.$plural : $key;
            $label = __($labelKey, locale: $locale);
            if (! is_string($label) || ! Lang::hasForLocale($labelKey, $locale)) {
                throw new \LogicException('Check presentation label missing: '.$labelKey);
            }
            $params[$name] = $continuation && $name === 'subject' ? '' : $label;
        }
        if (is_numeric($count)) {
            $params['formattedCount'] = $params['count'];
        }
        $key = $short ? ($part->shortKey ?? $part->key) : $part->key;
        if (is_numeric($count) && Lang::has($key.$plural, $locale)) {
            $key .= $plural;
        }
        $template = __($key, locale: $locale);
        if (! is_string($template) || ! Lang::hasForLocale($key, $locale)) {
            throw new \LogicException('Check mail presentation translation missing: '.$key);
        }
        // Resolve template tokens once. Braces and colons in retained target text remain data, not interpolation.
        preg_match_all('/\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}|:([A-Za-z_][A-Za-z0-9_]*)/', $template, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $tokens = [];
        foreach ($matches as $match) {
            $name = $match[1] ?? $match[2] ?? throw new \LogicException('Presentation token has no name.');
            $value = $params[$name] ?? null;
            if (! is_scalar($value)) {
                throw new \LogicException('Check mail presentation parameter missing: '.$key.' '.$name);
            }
            $tokens[$match[0]] = (string) $value;
        }

        return rtrim(strtr($template, $tokens), '.');
    }

    private function duration(int $seconds, string $locale): string
    {
        if ($seconds < 60) {
            return __('emails.duration_seconds', ['count' => $seconds], $locale);
        }
        // Mail shows minute precision below a day, hour precision above it; rounding may carry into a larger unit.
        $precision = $seconds >= 86400 ? 3600 : 60;
        $seconds = (int) (round($seconds / $precision) * $precision);
        $parts = [];
        $started = false;
        foreach ([86400 => 'days', 3600 => 'hours', 60 => 'minutes'] as $size => $unit) {
            $value = intdiv($seconds, $size);
            $seconds %= $size;
            if ($value > 0) {
                $parts[] = __('emails.duration_'.$unit, ['count' => $value], $locale);
            }
            if ($started) {
                break;
            }
            $started = $value > 0;
        }

        return implode(' ', $parts);
    }
}
