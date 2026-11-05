<?php

namespace Heptapod\Testing;

use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\ChromiumRenderer;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\LighthouseReport;

final readonly class FakeChromiumRenderer implements ChromiumRenderer
{
    /** @var FakeResponses<LighthouseReport> */
    private FakeResponses $reports;

    public function __construct()
    {
        $this->reports = new FakeResponses(LighthouseReport::class);
    }

    /** @param array<string, mixed> $options */
    public function respondWithLighthouse(string $url, LighthouseReport|ExternalNetworkException $response, array $options = []): self
    {
        $this->reports->add('lighthouse', [$url, $options], $response);

        return $this;
    }

    public function lighthouse(string $url, CheckContext $context, array $options = []): LighthouseReport
    {
        return $this->reports->get('lighthouse', [$url, $options]);
    }
}
