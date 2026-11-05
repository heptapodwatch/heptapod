<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\ManualRunRefused;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class RequestCheckRun
{
    public function __construct(private ClaimService $claims, private CheckRegistry $registry) {}

    /** @param list<array{name: string, value: string}> $headers
     * @param  bool  $heavyAdmission  false only for the compatibility request-run route, which keeps just the shared cooldown
     *
     * @throws ValidationException
     * @throws ManualRunRefused
     */
    public function __invoke(Check $check, RunOrigin $origin, array $headers = [], ?User $actor = null, ?int $actorTokenId = null, bool $heavyAdmission = true): CheckRunAttempt
    {
        if (! in_array($check->type, $this->registry->keys(), true)) {
            throw ValidationException::withMessages(['type' => __('api.unsupported')]);
        }
        Validator::make(['headers' => $headers], ['headers' => ['array', 'max:5'], 'headers.*' => ['array:name,value'], 'headers.*.name' => ['required', 'string', 'regex:/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/'], ...new Field(FieldType::Secret, ['present', 'not_regex:/[\r\n]/'])->validationRules('headers.*.value')])->validate();
        $headers = array_values(array_filter($headers, static fn (array $header): bool => $header['value'] !== ''));
        if ($headers !== [] && $check->type !== CheckKey::Uptime) {
            throw ValidationException::withMessages(['httpClientHeaders' => __('api.unsupported')]);
        }

        return $this->claims->runNow($check->id, $origin, $headers, $actor?->id, $actorTokenId, $heavyAdmission)
            ?? throw ValidationException::withMessages(['check' => __('api.run_unavailable')]);
    }
}
