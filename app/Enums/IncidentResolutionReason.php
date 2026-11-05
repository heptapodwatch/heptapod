<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Stopping monitoring resolves an incident without claiming that its check recovered. */
#[TypeScript]
enum IncidentResolutionReason: string
{
    case CheckDisabled = 'check_disabled';
    case SitePaused = 'site_paused';
    case SiteDeleted = 'site_deleted';

    public function labelKey(): string
    {
        return 'incidents.resolution.'.$this->value;
    }

    public static function forCheck(Check $check, Site $site, bool $deletingSite = false): ?self
    {
        return match (true) {
            $deletingSite || $site->marked_for_deletion_at !== null => self::SiteDeleted,
            $site->paused_at !== null => self::SitePaused,
            ! $check->enabled => self::CheckDisabled,
            default => null,
        };
    }
}
