<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\EngineFailure;

/** A collected crawl artifact whose encoded payload exceeds CrawlPersistence::MAX_ARTIFACT_BYTES. */
final class CrawlArtifactTooLarge extends \RuntimeException implements EngineFailure
{
    public function __construct(public readonly int $bytes, public readonly int $pageCap)
    {
        parent::__construct('Crawl artifact exceeds the maximum byte limit ('.$bytes.' > '.CrawlPersistence::MAX_ARTIFACT_BYTES.').');
    }
}
