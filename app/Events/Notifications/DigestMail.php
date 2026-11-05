<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\Mail\ListBlock;
use Heptapod\Contracts\Mail\MailModel;
use Heptapod\Contracts\Mail\NoteBlock;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Support\SiteLabels;

/** The aggregate digest mail: one row per retained source alert, never a single check or incident. */
final readonly class DigestMail
{
    private const int ShownItems = 20;

    public function __construct(private InstanceSettings $settings, private MailRouting $routing) {}

    public function build(Event $digest, NotificationDestination $destination): MailModel
    {
        $sourceIds = $digest->payload['delivery_ids'] ?? null;
        if (! is_array($sourceIds) || array_any($sourceIds, static fn (mixed $id): bool => ! is_int($id))) {
            throw new \LogicException('Invalid digest payload.');
        }
        $locale = $destination->effectiveLocale();
        // The digest's own source list bounds this primary-key lookup; ordering needs every source's category.
        $sources = Delivery::query()->with('event.check');
        $sources->getQuery()->whereIn('id', $sourceIds)->orderBy('id');
        $rows = [];
        $sites = [];
        foreach ($sources->get() as $source) {
            $event = $source->event ?? throw new \LogicException('Digest source event is missing.');
            // The source keeps the category it was routed with; an uninstalled add-on can no longer classify its event.
            // Only a legacy source from before categories were stored and whose add-on is gone has none: it is listed last as unclassified.
            $category = $source->category ?? NotificationCategory::of($event);
            $siteId = $event->site_id ?? throw new \LogicException('A digest source must keep its site.');
            $sites[$siteId] = ['name' => $event->site_name ?? throw new \LogicException('A digest source must retain its site label.'),
                'client' => $event->client_name ?? throw new \LogicException('A digest source must retain its client.'),
                'url' => $event->site_url ?? throw new \LogicException('A digest source must retain its URL.')];
            $rows[] = ['site' => $siteId, 'category' => $category,
                'check' => $event->check->type ?? $event->check_type ?? throw new \LogicException('A digest source needs its check type.')];
        }
        if ($rows === []) {
            throw new \LogicException('A digest mail needs its retained sources.');
        }
        $labels = SiteLabels::for($sites);
        // Most urgent category first (grid order: check error, warning, expiring, changes), then by site.
        $rank = array_flip(array_map(static fn (NotificationCategory $category): string => $category->value, NotificationCategory::grid()));
        /** @param array{site: int, category: ?NotificationCategory, check: CheckKey} $row */
        $order = static fn (array $row): int => $row['category'] instanceof NotificationCategory ? $rank[$row['category']->value] : count($rank);
        usort($rows, static fn (array $a, array $b): int => [$order($a), $labels[$a['site']]] <=> [$order($b), $labels[$b['site']]]);
        $shown = array_slice($rows, 0, self::ShownItems);
        // The rows share one byte budget; a shortened label keeps its distinguishing end, else falls back to its site ID.
        $items = MailRowBudget::fit(array_map(static fn (array $row): array => ['text' => $labels[$row['site']], 'key' => $row['site'],
            'meta' => __('checks.'.$row['check']->value.'.label', locale: $locale).' · '.__($row['category'] === null ? 'emails.digest_unclassified' : 'recipient_settings.category.'.$row['category']->value.'.label', locale: $locale),
        ], $shown), array_map(static fn (int $id): string => SiteLabels::withId($id, $sites[$id]), array_combine(array_keys($sites), array_keys($sites))));
        $at = $digest->occurred_at->setTimezone($destination->timezone());
        $at->locale($locale);
        $blocks = [new ListBlock(__('emails.digest_as_of', ['time' => $at->isoFormat('ll, HH:mm z')], $locale), $items)];
        $hidden = count($rows) - count($items);
        if ($hidden > 0) {
            $blocks[] = new NoteBlock(trans_choice('emails.digest_more', $hidden, ['count' => $hidden], $locale));
        }
        $lead = trans_choice('emails.digest_lead', count($rows), ['count' => count($rows)], $locale);
        $footer = $this->routing->footer($destination, __('emails.digest_category', locale: $locale));

        return new MailModel(locale: $locale, subject: __('notifications.digest_title', locale: $locale), preheader: $lead,
            headline: __('notifications.digest_title', locale: $locale), footerWhy: $footer['why'],
            instance: $this->settings->name.' · '.parse_url(config()->string('app.url'), PHP_URL_HOST), lead: $lead, blocks: $blocks,
            primary: ['label' => __('emails.open_sites', locale: $locale), 'url' => route('sites.index')],
            routingLabel: __($footer['labelKey'], locale: $locale), routingUrl: $footer['url'], design: $this->settings->mail_design);
    }
}
