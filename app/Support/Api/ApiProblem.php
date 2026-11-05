<?php

declare(strict_types=1);

namespace Heptapod\Support\Api;

use Illuminate\Http\JsonResponse;

/** Domain errors for new endpoints only; compatibility endpoints keep their existing envelopes. */
final class ApiProblem extends \RuntimeException
{
    /** @param array<array-key, mixed> $details */
    public function __construct(public readonly string $problemCode, public readonly int $status, public readonly array $details = [])
    {
        parent::__construct($problemCode);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->problemCode, 'code' => $this->problemCode, 'details' => (object) $this->details], $this->status)
            ->header('Cache-Control', 'private, no-store');
    }
}
