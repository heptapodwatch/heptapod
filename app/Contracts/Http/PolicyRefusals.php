<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

/** Execution-only signal: a guard refusal must not become target downtime or a failed finding. */
final class PolicyRefusals
{
    private ?FetchFailure $failure = null;

    public function record(FetchFailure $failure): void
    {
        if ($failure->isPolicyRefusal()) {
            $this->failure ??= $failure;
        }
    }

    public function first(): ?FetchFailure
    {
        return $this->failure;
    }
}
