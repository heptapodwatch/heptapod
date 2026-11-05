<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum CheckKey: string
{
    case Uptime = 'uptime';
    case Performance = 'performance';
    case CertificateHealth = 'certificate_health';
    case BrokenLinks = 'broken_links';
    case MixedContent = 'mixed_content';
    case Dns = 'dns';
    case Domain = 'domain';
    case Lighthouse = 'lighthouse';
    case Cron = 'cron';
    case ApplicationHealth = 'application_health';
    case Sitemap = 'sitemap';

    public function canCancelRun(): bool
    {
        return in_array($this, [self::BrokenLinks, self::MixedContent, self::Lighthouse], true);
    }
}
