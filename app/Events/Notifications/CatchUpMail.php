<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\Mail\ListBlock;
use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\MailTone;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** The site-level catch-up after maintenance: one row per incident that is still open, never a single check or incident. */
final readonly class CatchUpMail
{
    private const int ShownItems = 20;

    public function __construct(private InstanceSettings $settings, private MailRouting $routing, private MailAvatar $avatars) {}

    public function build(Event $event, NotificationDestination $destination): MailModel
    {
        $locale = $destination->effectiveLocale();
        $site = $event->site;
        $name = $site?->displayName() ?? $event->site_name ?? throw new \LogicException('A catch-up must retain its site label.');
        $label = $site === null ? __('events.deleted_site', ['label' => $name], $locale) : $name;
        $rows = $site === null ? $this->retainedRows($event, $locale) : $this->openRows($event, $locale, $destination->timezone());
        // Failures before check errors, then by check name.
        usort($rows, static fn (array $a, array $b): int => [$a['state'] === 'check_error', $a['check']] <=> [$b['state'] === 'check_error', $b['check']]);
        // The rows share one byte budget, as in the digest; check names are unique per site, so each is its own fallback.
        $shown = array_slice($rows, 0, self::ShownItems);
        $items = MailRowBudget::fit(array_map(static fn (array $row, int $index): array => ['text' => $row['check'], 'meta' => $row['meta'], 'key' => $index], $shown, array_keys($shown)),
            array_column($shown, 'check'));
        $blocks = [new ListBlock(__($site === null ? 'emails.catch_up_open_retained' : 'emails.catch_up_open', locale: $locale), $items)];
        $hidden = count($rows) - count($items);
        if ($hidden > 0) {
            $blocks[] = new NoteBlock(trans_choice('emails.catch_up_more', $hidden, ['count' => $hidden], $locale));
        }
        if ($site === null) {
            $blocks[] = new NoteBlock(__('emails.site_deleted_note', locale: $locale));
        }
        $category = NotificationCategory::of($event) ?? throw new \LogicException('A routed catch-up mail needs a category.');
        $footer = $this->routing->footer($destination, __('recipient_settings.category.'.$category->value.'.plural', locale: $locale));
        $client = $site?->client;
        $avatar = $site !== null && $client !== null ? $this->avatars->for($client, $site) : ['png' => null, 'cid' => null, 'tile' => null];
        $lead = trans_choice($site === null ? 'emails.catch_up_lead_retained' : 'emails.catch_up_lead', count($rows), ['count' => count($rows)], $locale);

        return new MailModel(locale: $locale, subject: __($site === null ? 'emails.catch_up_subject_retained' : 'notifications.maintenance_catch_up_title', ['site' => $label], $locale), preheader: $lead,
            headline: __($site === null ? 'emails.catch_up_headline_retained' : 'emails.catch_up_headline', ['site' => $name], $locale), footerWhy: $footer['why'],
            instance: $this->settings->name.' · '.parse_url(config()->string('app.url'), PHP_URL_HOST),
            tone: array_any($rows, static fn (array $row): bool => $row['state'] !== 'check_error') ? MailTone::Failed : MailTone::CheckError,
            lead: $lead, client: ($client ?? ($event->client?->is_internal === true ? $event->client : null))?->displayName($locale) ?? $event->client_name ?? throw new \LogicException('A catch-up must retain its client.'), site: $label, blocks: $blocks,
            primary: $site === null ? null : ['label' => __('emails.open_site', locale: $locale), 'url' => route('sites.show', ['site' => $site->id])],
            routingLabel: __($footer['labelKey'], locale: $locale), routingUrl: $footer['url'],
            avatarPng: $avatar['png'], avatarCid: $avatar['cid'], avatarTile: $avatar['tile'], design: $this->settings->mail_design);
    }

    /** @return list<array{check: string, state: string, meta: string}> */
    private function openRows(Event $event, string $locale, string $timezone): array
    {
        // A site's catch-up links at most one open incident per check.
        $incidents = Incident::query()->with('check');
        $incidents->getQuery()->whereNull('resolved_at')
            ->whereIn('id', static fn (QueryBuilder $links): QueryBuilder => $links->from('maintenance_catch_up_incidents')->select('incident_id')->where('event_id', $event->id));
        $rows = [];
        foreach ($incidents->get() as $incident) {
            $check = ($incident->check ?? throw new \LogicException('A catch-up incident needs its check.'))->type;
            $state = match (true) {
                $incident->cause === IncidentCause::Errored => 'check_error',
                $check === CheckKey::Uptime => 'down',
                default => 'failed',
            };
            $since = $incident->opened_at->setTimezone($timezone);
            $since->locale($locale);
            $rows[] = ['check' => __('checks.'.$check->value.'.label', locale: $locale), 'state' => $state,
                'meta' => __('emails.'.$state, locale: $locale).' · '.__('emails.catch_up_since', ['since' => $since->isoFormat('ll, HH:mm z')], $locale)];
        }

        return $rows === [] ? throw new \LogicException('A catch-up mail needs an open incident.') : $rows;
    }

    /**
     * A site deleted after the claim took its incidents with it; only the retained check keys remain.
     *
     * @return list<array{check: string, state: string, meta: string}>
     */
    private function retainedRows(Event $event, string $locale): array
    {
        $checks = $event->payload['check_keys'] ?? null;
        if (! is_array($checks) || $checks === [] || array_any($checks, static fn (mixed $check): bool => ! is_string($check))) {
            throw new \LogicException('A catch-up must retain its check keys.');
        }

        /** @var list<string> $checks */
        return array_map(static fn (string $check): array => ['check' => __('checks.'.CheckKey::from($check)->value.'.label', locale: $locale),
            'state' => 'retained', 'meta' => ''], $checks);
    }
}
