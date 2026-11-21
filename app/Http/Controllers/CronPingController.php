<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Closure;
use Heptapod\Actions\RecordCronPing;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CronTaskStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

final class CronPingController
{
    private const int MaxRuntimeSeconds = 31_536_000;

    private const int MaxMemoryBytes = 1_099_511_627_776;

    private const int MaxNumericLiteralLength = 32;

    public function __invoke(Request $request, string $uuid, string $phase = 'finished'): Response
    {
        abort_unless(app()->bound(CronTaskStore::class) && in_array(CheckKey::Cron, app(CheckRegistry::class)->keys(), true), 404);
        $boundedNumber = static function (string $attribute, mixed $value, Closure $fail): void {
            if ((is_string($value) && strlen($value) > self::MaxNumericLiteralLength) || (is_numeric($value) && ! is_finite((float) $value))) {
                $message = __('validation.numeric', ['attribute' => $attribute]);
                $fail(is_string($message) ? $message : 'The value must be a finite number.');
            }
        };
        /** @var array{runtime?: int|float|numeric-string, exit_code?: int|numeric-string, memory?: int|float|numeric-string, failure_message?: string|null} $validated */
        $validated = Validator::make($request->all(), [
            'runtime' => ['sometimes', 'numeric', $boundedNumber, 'min:0', 'max:'.self::MaxRuntimeSeconds],
            'exit_code' => ['sometimes', $boundedNumber, 'integer', 'min:0', 'max:255'],
            'memory' => ['sometimes', 'numeric', $boundedNumber, 'min:0', 'max:'.self::MaxMemoryBytes],
            'failure_message' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ])->validate();
        $values = [];
        if (array_key_exists('runtime', $validated)) {
            $values['runtime'] = (float) $validated['runtime'];
        }
        if (array_key_exists('exit_code', $validated)) {
            $values['exit_code'] = (int) $validated['exit_code'];
        }
        if (array_key_exists('memory', $validated)) {
            $memory = (float) $validated['memory'];
            $values['memory'] = fmod($memory, 1.0) === 0.0 ? (int) $memory : $memory;
        }
        if (array_key_exists('failure_message', $validated)) {
            $values['failure_message'] = $validated['failure_message'];
        }
        app(RecordCronPing::class)($uuid, $phase, $values);

        return response()->noContent(headers: ['Cache-Control' => 'no-store, private']);
    }
}
