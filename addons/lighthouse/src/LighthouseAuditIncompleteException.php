<?php

declare(strict_types=1);

namespace Heptapod\Addons\Lighthouse;

use Heptapod\Contracts\EngineFailure;

/** Chromium reported an error the renderer does not classify as a fetch failure. */
final class LighthouseAuditIncompleteException extends \UnexpectedValueException implements EngineFailure {}
