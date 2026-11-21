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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class RequestCheckRun
{
    public const int ApiUserRunsPerMinute = 10;

    public const int ApiTokenRunsPerMinute = 5;

    public function __construct(private ClaimService $claims, private CheckRegistry $registry) {}

    /** @param list<array{name: string, value: string}> $headers
     *
     * @throws ValidationException
     * @throws ManualRunRefused
     */
    public function __invoke(Check $check, RunOrigin $origin, array $headers = [], ?User $actor = null, ?int $actorTokenId = null): CheckRunAttempt
    {
        if (! in_array($check->type, $this->registry->keys(), true)) {
            throw ValidationException::withMessages(['type' => __('api.unsupported')]);
        }
        Validator::make(['headers' => $headers], ['headers' => ['array', 'max:5'], 'headers.*' => ['array:name,value'], 'headers.*.name' => ['required', 'string', 'regex:/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/'], ...new Field(FieldType::Secret, ['present', 'not_regex:/[\r\n]/'])->validationRules('headers.*.value')])->validate();
        $headers = array_values(array_filter($headers, static fn (array $header): bool => $header['value'] !== ''));
        if ($headers !== [] && $check->type !== CheckKey::Uptime) {
            throw ValidationException::withMessages(['httpClientHeaders' => __('api.unsupported')]);
        }
        $claim = fn (): CheckRunAttempt => $this->claims->runNow($check->id, $origin, $headers, $actor?->id, $actorTokenId)
            ?? throw ValidationException::withMessages(['check' => __('api.run_unavailable')]);

        if ($origin !== RunOrigin::Manual || ! $actor instanceof User || $actorTokenId === null) {
            return $claim();
        }

        return DB::transaction(function () use ($actor, $actorTokenId, $claim): CheckRunAttempt {
            // Budgets are per user and per token. One user lock serializes check through consumption, so parallel
            // distinct-key requests and token rotation cannot overshoot. Released at commit.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['manual-runs:user:'.$actor->id]);
            $budgets = ['manual-runs:user:'.$actor->id => self::ApiUserRunsPerMinute, 'manual-runs:token:'.$actorTokenId => self::ApiTokenRunsPerMinute];
            foreach ($budgets as $key => $limit) {
                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    throw new ManualRunRefused('manual_run_budget', max(1, RateLimiter::availableIn($key)));
                }
            }
            $attempt = $claim();
            foreach (array_keys($budgets) as $key) {
                RateLimiter::hit($key, 60);
                // The counter lives outside the database: an attempt that rolls back takes its hit back, so a request counts once, and only when it commits.
                DB::afterRollBack(static fn (): int => RateLimiter::decrement($key, 60));
            }

            return $attempt;
        });
    }
}
