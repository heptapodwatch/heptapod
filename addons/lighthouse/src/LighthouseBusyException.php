<?php

declare(strict_types=1);

namespace Heptapod\Addons\Lighthouse;

use Heptapod\Contracts\EngineFailure;

final class LighthouseBusyException extends \RuntimeException implements EngineFailure
{
    public function __construct()
    {
        // Fixed English diagnostic (it was the localized text, which EN runs retained verbatim); EngineMessage maps it.
        parent::__construct('Lighthouse was busy; the audit did not run');
    }
}
