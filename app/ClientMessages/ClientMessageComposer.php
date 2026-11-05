<?php

declare(strict_types=1);

namespace Heptapod\ClientMessages;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CheckRunPresentations;
use Heptapod\Contracts\CheckHeadline;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckRunPresentationData;
use Heptapod\Contracts\Findings\FindingStatus;
use Heptapod\Contracts\FindingsExportProvider;
use Heptapod\Contracts\Mail\Cell;
use Heptapod\Contracts\Mail\FactsBlock;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Contracts\Mail\TableBlock;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\SecretRedactor;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Support\RedactionContext;
use Illuminate\Support\Traits\Localizable;
use Illuminate\Validation\ValidationException;

/**
 * Builds the facts of a client message once, at preview or approval, in the client's language and timezone, and renders
 * the mail only from those frozen facts. Client copy: formal, no app links, no internal terms (design §3.1, §4).
 *
 * @phpstan-type FindingRow array{url: string, result: string, classification: string, found_on: string, link_text: string}
 * @phpstan-type Facts array{kind: string, locale: string, timezone: string, instance: string, site_label: string, site_host: string,
 *     check_type: string, observed_at: string, total: int, confirmed: int, unverified: int, limited?: bool, shown: list<FindingRow>, incident: array{id: int, open: bool, started_at: string, resolved_at: string|null, headline: string}|null, note: string,
 *     attach: bool, attachment_filename: string|null, sender: array{name: string, address: string}, subject: string, design?: string}
 *
 * A payload without a design was sent before the Plain design existed, so it was Branded.
 */
final class ClientMessageComposer
{
    use Localizable;

    public const array FindingTypes = [CheckKey::BrokenLinks, CheckKey::MixedContent, CheckKey::Sitemap];

    /** Rows in the mail body; the CSV holds every row. */
    public const int ShownRows = 20;

    /** CSV bounds (design §4). */
    public const int MaxRows = 10000;

    public const int MaxBytes = 5 * 1024 * 1024;

    public function __construct(private CheckRegistry $registry, private CheckRunPresentations $presentations, private InstanceSettings $settings) {}

    /**
     * The current findings of a check as the client will read them; every row passes through $each (the CSV writer).
     *
     * @param  (\Closure(FindingRow): void)|null  $each
     * @return Facts
     */
    public function findings(Client $client, Site $site, Check $check, User $sender, string $note, bool $attach, MailDesign $design, ?\Closure $each = null): array
    {
        if (! in_array($check->type, self::FindingTypes, true)) {
            throw ValidationException::withMessages(['check_id' => __('client_messages.no_findings_check')]);
        }
        $provider = $this->registry->get($check->type)->persistence();
        if (! $provider instanceof FindingsExportProvider || $check->latest_run_id === null || $check->latest_run_ended_at === null) {
            throw ValidationException::withMessages(['check_id' => __('client_messages.no_findings')]);
        }
        $run = new RunReference($check->latest_run_id, $check->latest_run_ended_at);
        $redaction = RedactionContext::forSite($site, $check);
        $locale = $client->locale;

        return $this->withLocale($locale, function () use ($provider, $check, $run, $redaction, $client, $site, $sender, $note, $attach, $design, $each, $locale): array {
            $rows = $provider->exportFindings($check->type, $check->id, $run);
            if ($rows === null) {
                throw ValidationException::withMessages(['check_id' => __('client_messages.no_findings')]);
            }
            $shown = [];
            $later = [];
            $total = 0;
            $confirmed = 0;
            $limits = 0;
            foreach ($rows as $row) {
                $total++;
                if ($total > self::MaxRows) {
                    throw ValidationException::withMessages(['attach' => __('client_messages.too_many_rows', ['max' => self::MaxRows])]);
                }
                $clean = static fn (?string $value): string => $value === null ? '' : SecretRedactor::redact($value, $redaction->secrets, $redaction->urls);
                // Only a confirmed problem is a finding; a page that could not be checked is said separately (P1, N5 review).
                // Addon output: a provider that leaves out the classification fails loudly instead of defaulting.
                if (! array_key_exists('status', $row)) { // @phpstan-ignore function.alreadyNarrowedType (addons are checked at runtime too)
                    throw new \LogicException('An exported finding must name its classification.');
                }
                $status = $row['status'];
                $isFinding = $status === FindingStatus::Broken;
                $confirmed += $isFinding ? 1 : 0;
                // A collection limit says how far the check got, not that some address failed or could not be checked.
                $limits += $status === FindingStatus::CoverageLimit ? 1 : 0;
                $finding = ['url' => $clean($row['url']), 'result' => $clean($row['result']),
                    'classification' => __(match ($status) {
                        FindingStatus::Broken => 'client_messages.classification.finding',
                        FindingStatus::CoverageLimit => 'client_messages.classification.limit',
                        FindingStatus::NotVerifiable, FindingStatus::Unchecked => 'client_messages.classification.unverified',
                    }),
                    'found_on' => $clean($row['found_on']), 'link_text' => $clean($row['link_text'])];
                if ($isFinding && count($shown) < self::ShownRows) {
                    $shown[] = $finding;
                } elseif (! $isFinding && count($later) < self::ShownRows) {
                    $later[] = $finding;
                }
                if ($each instanceof \Closure) {
                    $each($finding);
                }
            }
            if ($total === 0) {
                throw ValidationException::withMessages(['check_id' => __('client_messages.no_findings')]);
            }
            $facts = $this->base('findings', $client, $site, $check, $sender, $note, $design, $locale);
            $facts['observed_at'] = $run->endedAt->utc()->toIso8601String();
            $facts['total'] = $total;
            $facts['confirmed'] = $confirmed;
            $facts['unverified'] = $total - $confirmed - $limits;
            $facts['limited'] = $limits > 0;
            // Confirmed findings first; unverifiable pages only fill the table when there is room.
            $facts['shown'] = array_slice([...$shown, ...$later], 0, self::ShownRows);
            $facts['attach'] = $attach;
            $facts['attachment_filename'] = $attach ? $this->filename($check->type, $facts['site_host'], $run->endedAt->setTimezone($client->timezone)) : null;
            $facts['subject'] = $confirmed === 0
                ? __(($facts['unverified'] === 0 ? 'client_messages.subject.limited_only.' : 'client_messages.subject.unverified_only.').$check->type->value,
                    ['site' => $facts['site_host']])
                : trans_choice('client_messages.subject.'.$check->type->value, $confirmed, ['site' => $facts['site_host'], 'count' => $confirmed]);

            return $facts;
        });
    }

    /** @return Facts */
    public function incident(Client $client, Site $site, Check $check, Incident $incident, User $sender, string $note, MailDesign $design): array
    {
        $locale = $client->locale;

        return $this->withLocale($locale, function () use ($client, $site, $check, $incident, $sender, $note, $design, $locale): array {
            $presentation = $this->presentations->atOpening($incident);
            $headline = $presentation instanceof CheckRunPresentationData ? CheckHeadline::format($presentation, $locale, $check->type) : __('client_messages.incident.generic_problem');
            $redaction = RedactionContext::forSite($site, $check);
            $facts = $this->base('incident', $client, $site, $check, $sender, $note, $design, $locale);
            $facts['observed_at'] = ($incident->resolved_at ?? $incident->first_failure_at)->utc()->toIso8601String();
            $facts['incident'] = ['id' => $incident->id, 'open' => $incident->resolved_at === null,
                'started_at' => $incident->first_failure_at->utc()->toIso8601String(), 'resolved_at' => $incident->resolved_at?->utc()->toIso8601String(),
                'headline' => SecretRedactor::redact($headline, $redaction->secrets, $redaction->urls)];
            $facts['subject'] = __($incident->resolved_at === null ? 'client_messages.subject.incident_open' : 'client_messages.subject.incident_resolved',
                ['site' => $facts['site_host'], 'check' => __('checks.'.$check->type->value.'.label')]);

            return $facts;
        });
    }

    /** @param Facts $facts */
    public function mail(array $facts): MailModel
    {
        $design = MailDesign::from($facts['design'] ?? MailDesign::Branded->value);

        return $this->withLocale($facts['locale'], function () use ($facts, $design): MailModel {
            $at = fn (string $value): string => $this->time(CarbonImmutable::parse($value)->setTimezone($facts['timezone']), $facts['locale']);
            $blocks = [];
            if ($facts['note'] !== '') {
                $blocks[] = new NoteBlock($facts['note']);
            }
            if ($facts['kind'] === 'findings') {
                $when = ['site' => $facts['site_host'], 'when' => $at($facts['observed_at'])];
                $lead = $facts['confirmed'] === 0
                    ? __('client_messages.findings.lead_none.'.$facts['check_type'], $when)
                    : trans_choice('client_messages.findings.lead.'.$facts['check_type'], $facts['confirmed'], [...$when, 'count' => $facts['confirmed']]);
                if ($facts['unverified'] > 0) {
                    $lead .= ' '.trans_choice('client_messages.findings.unverified.'.$facts['check_type'], $facts['unverified'], ['count' => $facts['unverified']]);
                }
                if ($facts['limited'] ?? false) {
                    $lead .= ' '.__('client_messages.findings.limited.'.$facts['check_type']);
                }
                $rest = $facts['total'] - count($facts['shown']);
                $more = $rest <= 0 ? null : trans_choice($facts['attach'] ? 'client_messages.findings.more_attached' : 'client_messages.findings.more', $rest, ['count' => $rest]);
                $blocks[] = new TableBlock('', [__('client_messages.column.url'), __('client_messages.column.result'), __('client_messages.column.found_on')],
                    array_map(fn (array $row): array => [new Cell($row['url'], mono: true, plainHtmlText: $this->path($row['url'], $facts['site_host'])), new Cell($row['result']),
                        new Cell($row['found_on'], mono: true, plainHtmlText: $this->path($row['found_on'], $facts['site_host']))], $facts['shown']), $more);
                // A report the sender chose to send: no alert colours.
                $tone = MailTone::Neutral;
                if ($facts['attach'] && $rest <= 0) {
                    $blocks[] = new NoteBlock(__('client_messages.findings.attached'));
                }
            } else {
                $incident = $facts['incident'] ?? throw new \LogicException('An incident message needs its incident facts.');
                $lead = __($incident['open'] ? 'client_messages.incident.lead_open' : 'client_messages.incident.lead_resolved', ['site' => $facts['site_host']]);
                $rows = [['label' => __('client_messages.incident.what'), 'value' => new Cell($incident['headline'])],
                    ['label' => __('client_messages.incident.since'), 'value' => new Cell($at($incident['started_at']), mono: true)]];
                if ($incident['resolved_at'] !== null) {
                    $rows[] = ['label' => __('client_messages.incident.resolved_at'), 'value' => new Cell($at($incident['resolved_at']), mono: true)];
                }
                $blocks[] = new FactsBlock('', $rows);
                $tone = $incident['open'] ? MailTone::Failed : MailTone::Recovered;
            }
            $headline = __('client_messages.greeting');

            return new MailModel(locale: $facts['locale'], subject: $facts['subject'], preheader: $lead, headline: $headline,
                footerWhy: __('client_messages.footer', ['sender' => $facts['sender']['name'], 'instance' => $facts['instance']]),
                instance: $facts['instance'], tone: $tone, lead: $lead,
                blocks: [...$blocks, new NoteBlock(__('client_messages.sign_off', ['sender' => $facts['sender']['name'], 'instance' => $facts['instance']]))],
                design: $design);
        });
    }

    /** @return Facts */
    private function base(string $kind, Client $client, Site $site, Check $check, User $sender, string $note, MailDesign $design, string $locale): array
    {
        $host = parse_url($site->url, PHP_URL_HOST);

        return ['kind' => $kind, 'locale' => $locale, 'timezone' => $client->timezone, 'instance' => $this->settings->name,
            'site_label' => $site->displayName(), 'site_host' => is_string($host) ? $host : $site->displayName(), 'check_type' => $check->type->value,
            'observed_at' => '', 'total' => 0, 'confirmed' => 0, 'unverified' => 0, 'shown' => [], 'incident' => null, 'note' => $note, 'attach' => false, 'attachment_filename' => null,
            'sender' => ['name' => $sender->name, 'address' => $sender->email], 'subject' => '', 'design' => $design->value];
    }

    /**
     * The Plain HTML table names an address on the message's own site by its path, since the lead already names the host;
     * other hosts keep the full URL (null). The text part, Branded and the CSV always show the full URL.
     */
    private function path(string $url, string $siteHost): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || strcasecmp($parts['host'] ?? '', $siteHost) !== 0 || isset($parts['port']) || isset($parts['user'])) {
            return null;
        }

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    private function filename(CheckKey $type, string $host, CarbonImmutable $at): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(__('client_messages.file.'.$type->value).'-'.$host)), '-');

        return $slug.'-'.$at->format('Y-m-d').'.csv';
    }

    private function time(CarbonImmutable $at, string $locale): string
    {
        return __('client_messages.when', ['date' => $at->format($locale === 'de' ? 'd.m.Y' : 'j M Y'), 'time' => $at->format('H:i')]);
    }
}
