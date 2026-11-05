<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Heptapod\Contracts\CheckDefinition;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Schema\SecretMinimum;
use Heptapod\Models\Check;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class CheckEnablement
{
    public function __construct(private CheckRegistry $registry) {}

    public function enable(Check $check, CheckDefinition $definition): void
    {
        if ($check->type !== $definition->key) {
            throw new \InvalidArgumentException('Cannot enable a check using another addon definition.');
        }
        $reason = $this->registry->availability($check->type)->reason;
        if ($reason !== null) {
            throw ValidationException::withMessages(['type' => __($reason)]);
        }
        $site = $check->site()->getQuery()->firstOrFail();
        if ($definition->requiresHttps && strtolower((string) parse_url($site->url, PHP_URL_SCHEME)) !== 'https') {
            throw ValidationException::withMessages(['type' => __('checks.requires_https')]);
        }
        $definition->configSchema->validateConfig($check->executionConfig());
        Validator::make(['crawler_headers' => $site->crawler_headers ?? []], ['crawler_headers.*.value' => ['string', new SecretMinimum]])->validate();
        $check->update(['enabled' => true, ...($check->enabled ? [] : ['awaiting_result_since' => null, 'lost_attempts' => 0, 'last_loss_started_at' => null])]);
    }
}
