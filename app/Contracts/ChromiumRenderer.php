<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface ChromiumRenderer
{
    /**
     * @param  array<string, mixed>  $options
     *
     * @throws ExternalNetworkException
     */
    public function lighthouse(string $url, CheckContext $context, array $options = []): LighthouseReport;
}
