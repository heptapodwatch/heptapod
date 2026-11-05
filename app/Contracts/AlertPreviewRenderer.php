<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Contracts\Notifications\AlertMessage;

interface AlertPreviewRenderer
{
    /** @return array{subject: string, html: string} */
    public function render(AlertMessage $message, string $locale, string $timezone, string $instanceName, string $instanceUrl): array;
}
