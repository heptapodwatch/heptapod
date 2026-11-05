<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\Notifications\AlertSeverity;
use Heptapod\Events\Notifications\NotificationEventTypes;
use Heptapod\Models\Event;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The single routing registry. Planning, the delivery backfill and the explanation map events through of().
 * Changes is a separate category, off by default for everyone. Report is reserved and hidden.
 */
#[TypeScript]
enum NotificationCategory: string
{
    case Outage = 'outage';
    case Failure = 'failure';
    case CheckError = 'check_error';
    case Warning = 'warning';
    case Expiring = 'expiring';
    case Recovery = 'recovery';
    case Changes = 'changes';
    case System = 'system';
    case Report = 'report';

    /** Routing keys whose events become Changes. */
    public const array ChangeKeys = [
        'application_health.component_changed', 'certificate_health.certificate_changed', 'broken_links.new_broken_found',
        'mixed_content.new_finding', 'dns.records_changed', 'domain.expiry_changed', 'domain.registration_changed',
        'lighthouse.score_threshold_crossed', 'sitemap.issues_changed', 'scheduled_task.missed',
    ];

    /** @return list<self> */
    public static function grid(): array
    {
        return [self::Outage, self::Failure, self::CheckError, self::Warning, self::Expiring, self::Recovery, self::Changes, self::System];
    }

    public function allowsDigest(): bool
    {
        return in_array($this, [self::CheckError, self::Warning, self::Expiring, self::Changes], true);
    }

    /** Null when the event is not routed (no routing key, digest containers, reclassified findings). */
    public static function of(Event $event): ?self
    {
        if (in_array($event->type, ['system.issue_opened', 'system.issue_resolved'], true)) {
            return self::System;
        }
        $types = app(NotificationEventTypes::class);
        $routing = $event;
        if ($event->type === 'maintenance.catch_up') {
            // A catch-up stands in for its opening alert and takes its category.
            $routing = clone $event;
            $routing->type = 'incident.opened';
        }
        $key = $types->key($routing);
        if ($key === null || $key === 'notification.digest') {
            return null;
        }

        return self::forKey($key, $types->severity($routing), $routing->check->type ?? $routing->check_type);
    }

    public static function forKey(string $key, AlertSeverity $severity, ?CheckKey $checkType): self
    {
        return match (true) {
            $key === 'site.down' => self::Outage,
            $key === 'check.failing' => $severity === AlertSeverity::Down ? self::Failure : self::CheckError,
            $key === 'check.warning' => $checkType === CheckKey::Domain ? self::Expiring : self::Warning,
            $key === 'certificate.expiring' => self::Expiring,
            in_array($key, ['site.recovered', 'check.recovered'], true) => self::Recovery,
            in_array($key, self::ChangeKeys, true) => self::Changes,
            default => throw new \LogicException('Unmapped notification routing key: '.$key),
        };
    }
}
