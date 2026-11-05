<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\ImportOhDearAccount;
use Heptapod\Actions\PersistOhDearImportPlan;
use Heptapod\Concerns\AuthorizesSettingsOperator;
use Heptapod\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class PlanOhDearImportController extends Controller
{
    use AuthorizesSettingsOperator;

    public function __invoke(Request $request, ImportOhDearAccount $import, PersistOhDearImportPlan $persist): JsonResponse
    {
        $user = $this->operator($request);
        /** @var array{ohdear_token: string} $data */
        $data = Validator::make($request->only('ohdear_token'), ['ohdear_token' => ['required', 'string', 'max:255']], [], ['ohdear_token' => __('Oh Dear API token')])->validate();
        $plan = $import($data['ohdear_token'], dryRun: true);
        $planId = $persist($user, $plan, $data['ohdear_token']);

        return response()->json([...$plan->toArray(), 'plan_id' => $planId])->header('Cache-Control', 'no-store');
    }
}
