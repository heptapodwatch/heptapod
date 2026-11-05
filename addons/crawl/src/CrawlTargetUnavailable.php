<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\EngineFailure;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;

/** A start-page transport failure is a check error, not an empty or successful crawl. */
final class CrawlTargetUnavailable extends \RuntimeException implements EngineFailure
{
    public readonly FetchFailure $failure;

    public function __construct(FetchException $previous)
    {
        $this->failure = $previous->failure;
        parent::__construct('Crawl start page unavailable. '.$this->failure->detail(), previous: $previous);
    }
}
