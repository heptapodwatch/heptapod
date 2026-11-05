<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckSummaryPresenter;
use Heptapod\Contracts\EngineMessage;
use Heptapod\Contracts\SecretRedactor;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Support\RedactionContext;
use Illuminate\Queue\TimeoutExceededException;

/** Projects retained addon summaries through their shared presenter after output-boundary redaction. */
final readonly class CauseSummary
{
    public function __construct(private CheckRegistry $registry) {}

    /** @return array{key: string|null, params: array<string, int|float|string|bool|null>} */
    public function project(Check $check, ?CheckRun $run = null): array
    {
        $key = $run instanceof CheckRun ? $run->summary : $check->summary;
        $params = $run instanceof CheckRun ? $run->summary_params : $check->summary_params;
        $params ??= [];
        if ($run instanceof CheckRun && in_array($key, ['checks.engine.errored', 'causes.error'], true) && $run->error !== null) {
            $error = json_decode($run->error, true, flags: JSON_THROW_ON_ERROR);
            $class = is_array($error) && is_string($error['class'] ?? null) ? $error['class'] : null;
            // Old timeout rows did not retain the job budget. Only newly recorded timeout summaries prove it.
            $retainedTimeout = $class === TimeoutExceededException::class;
            if (! $retainedTimeout && $class !== null && isset(EngineMessage::Classes[$class])) {
                // Also covers retained runs that stored the framework text as their `causes.error` message.
                $key = EngineMessage::Classes[$class];
                $params = [];
            } elseif ($key === 'checks.engine.errored' && is_array($error) && is_string($error['message'] ?? null)) {
                $key = 'causes.error';
                $params = ['message' => $error['message']];
            }
        }
        $engine = $key === null ? null : EngineMessage::fromSummary($key, $params);
        if ($engine !== null) {
            $key = $engine['key'];
            $params = $engine['params'];
        }
        $site = $check->site ?? throw new \LogicException('A check summary must have a site.');
        foreach ($params as $value) {
            /** Stored JSON may violate the model PHPDoc type.
             * @phpstan-ignore notIdentical.alwaysFalse, booleanAnd.alwaysFalse
             */
            if (! is_scalar($value) && $value !== null) {
                throw new \LogicException('Run summary parameters must be scalar.');
            }
        }
        // Most summaries have no text parameters; only text needs the secret context.
        if (array_any($params, static fn (mixed $value): bool => is_string($value))) {
            $params = SecretRedactor::redactStringValues($params, RedactionContext::forSite($site, $check, $run instanceof CheckRun ? $run->evidence : []));
        }

        if ($key !== null && in_array($check->type, $this->registry->keys(), true)) {
            $presenter = $this->registry->get($check->type);
            if ($presenter instanceof CheckSummaryPresenter) {
                $presented = $presenter->presentSummary($key, $params);
                $key = $presented->key;
                $params = $presented->params;
            }
        }

        return ['key' => $key, 'params' => $params];
    }
}
