<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Checks\Execution\ManualRunRefused;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class RequestSiteChecks
{
    public function __construct(private RequestCheckRun $request, private CheckRegistry $registry) {}

    /** @return array{queued: int, unavailable: int, results: list<array{check_id: int, status: string, reason: ?string}>} */
    public function __invoke(Site $site, User $actor, bool $fastOnly = false): array
    {
        $queued = 0;
        $unavailable = 0;
        $results = [];
        $checks = Check::query()->where('site_id', $site->id)->where('enabled', true);
        $checks->getQuery()->orderBy('id');
        foreach ($checks->get() as $check) {
            $reason = null;
            if (! in_array($check->type, $this->registry->keys(), true) || $this->registry->availability($check->type)->reason !== null) {
                $reason = 'unavailable';
            } elseif ($fastOnly && $this->registry->get($check->type)->definition()->queue !== 'checks-fast') {
                $reason = 'fast_only';
            } elseif ($check->claim_token !== null) {
                $reason = 'busy';
            } else {
                try {
                    ($this->request)($check, RunOrigin::Manual, actor: $actor);
                    $queued++;
                    $results[] = ['check_id' => $check->id, 'status' => 'queued', 'reason' => null];

                    continue;
                } catch (ValidationException $exception) {
                    if (! isset($exception->errors()['check'])) {
                        throw $exception;
                    }
                    $reason = 'busy';
                } catch (ManualRunRefused) {
                    $reason = 'rate_limited';
                }
            }
            if ($reason !== 'fast_only') {
                $unavailable++;
            }
            $results[] = ['check_id' => $check->id, 'status' => 'skipped', 'reason' => $reason];
        }

        return ['queued' => $queued, 'unavailable' => $unavailable, 'results' => $results];
    }
}
