<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\RecordCronPing;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CronTaskStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

final class CronPingController
{
    public function __invoke(Request $request, string $uuid, string $phase = 'finished'): Response
    {
        abort_unless(app()->bound(CronTaskStore::class) && in_array(CheckKey::Cron, app(CheckRegistry::class)->keys(), true), 404);
        /** @var array{runtime?: int|float, exit_code?: int|string, memory?: int|float, failure_message?: string|null} $values */
        $values = Validator::make($request->all(), [
            'runtime' => ['sometimes', 'numeric', 'min:0', 'max:31536000'],
            'exit_code' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'memory' => ['sometimes', 'numeric', 'min:0'],
            'failure_message' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ])->validate();
        app(RecordCronPing::class)($uuid, $phase, $values);

        return response()->noContent();
    }
}
