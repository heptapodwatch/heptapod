<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/**
 * CheckError class for a Check error that releases before instance.check_error_reclassified_at recorded as Failed.
 * An incident opened before that release changes cause silently, so the upgrade alone does not re-alert.
 */
final class ReclassifiedCheckError extends \RuntimeException {}
