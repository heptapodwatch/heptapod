<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Models\Event;
use Heptapod\Models\Site;

/** What routing needs to know about one event: built from a real event, or from an explanation exemplar. */
final readonly class RoutingContext
{
    public function __construct(
        public Site $site,
        public NotificationCategory $category,
        public AlertSeverity $severity,
        public bool $critical,
        public ?CarbonImmutable $notBefore,
    ) {}

    /** Null when the event is not routed. */
    public static function fromEvent(Event $event): ?self
    {
        $site = $event->site;
        $category = NotificationCategory::of($event);
        if ($site === null || ! $category instanceof NotificationCategory) {
            return null;
        }
        $types = app(NotificationEventTypes::class);

        return new self($site, $category, $types->severity($event), $types->critical($event), UptimeAlertEpisode::notBefore($event));
    }
}
