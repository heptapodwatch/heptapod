<?php

declare(strict_types=1);

namespace Heptapod\Enums;

use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Stopping monitoring or moving the site to another client resolves an incident without claiming that its check recovered. */
#[TypeScript]
enum IncidentResolutionReason: string
{
    case CheckDisabled = 'check_disabled';
    case SitePaused = 'site_paused';
    case SiteDeleted = 'site_deleted';
    case MovedToClient = 'moved_to_client';

    public function labelKey(): string
    {
        return 'incidents.resolution.'.$this->value;
    }

    /** Why monitoring of this check has stopped, if it has. A move is no stopped state: MoveSiteToClient passes its reason. */
    public static function forCheck(Check $check, Site $site): ?self
    {
        return match (true) {
            $site->marked_for_deletion_at !== null => self::SiteDeleted,
            $site->paused_at !== null => self::SitePaused,
            ! $check->enabled => self::CheckDisabled,
            default => null,
        };
    }
}
