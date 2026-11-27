<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class SnoozeServer
{
    public function __construct(private EvaluateServerIssues $issues) {}

    /**
     * The snooze end the server page and the API accept, the check snooze's choices: `minutes` (1–525600) from now, or an
     * absolute `snooze_until` with an offset, at most a year ahead.
     *
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function until(array $input): CarbonImmutable
    {
        $absolute = array_key_exists('snooze_until', $input);
        /** @var array{snooze_until?: string, minutes?: int|numeric-string} $valid */
        $valid = Validator::make($input, $absolute
            ? ['snooze_until' => ['required', 'string', 'date', 'regex:/(Z|[+-]\d{2}:\d{2})$/', 'after:now', 'before_or_equal:'.now()->addMinutes(525600)->toIso8601String()]]
            : ['minutes' => ['required', 'integer', 'between:1,525600']], ['snooze_until' => __('check_snooze.until_invalid')])->validate();

        return isset($valid['snooze_until']) ? CarbonImmutable::parse($valid['snooze_until'])->utc()
            : CarbonImmutable::now('UTC')->addMinutes((int) ($valid['minutes'] ?? 0));
    }

    /**
     * Snoozes the server's alerts until $until, or ends the snooze (null). The server keeps reporting and its issues keep
     * opening and resolving; an opening waits for the snooze's end and is sent then if its issue is still open. An absolute
     * end makes retries converge on the same snooze.
     */
    public function __invoke(Server $server, ?CarbonImmutable $until): void
    {
        DB::transaction(function () use ($server, $until): void {
            $this->issues->snooze($this->issues->lock($server->id) ?? abort(404), $until);
        });
    }
}
