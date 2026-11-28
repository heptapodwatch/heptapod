<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\ImportOhDearAccount;
use Heptapod\Actions\PersistOhDearImportPlan;
use Heptapod\Data\Import\ImportPlanData;
use Heptapod\Enums\ImportSection;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class PlanOhDearImportController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, Request $request, ImportOhDearAccount $import, PersistOhDearImportPlan $persist): JsonResponse
    {
        /** @var array{ohdear_token: string, sections?: list<string>} $data */
        $data = Validator::make($request->only('ohdear_token', 'sections'), [
            'ohdear_token' => ['required', 'string', 'max:255'],
            'sections' => ['sometimes', 'required', 'array', 'list'],
            'sections.*' => ['required', 'string', 'distinct', Rule::enum(ImportSection::class)],
        ], ['sections.required' => __('import.sections_required'), 'sections.*.enum' => __('import.sections_invalid')], ['ohdear_token' => __('Oh Dear API token'), 'sections' => __('import.sections')])->validate();
        $sections = isset($data['sections']) ? array_map(ImportSection::from(...), $data['sections']) : ImportSection::cases();
        $plan = ImportOhDearAccount::stopOnLocalFault(static fn (): ImportPlanData => $import($data['ohdear_token'], dryRun: true, sections: $sections), static fn (): null => null);
        if ($plan === null) {
            return response()->json(['message' => __('import.plan_local_error')], 500)->header('Cache-Control', 'no-store');
        }
        $planId = $persist($user, $plan, $data['ohdear_token']);

        return response()->json([...$plan->toArray(), 'plan_id' => $planId])->header('Cache-Control', 'no-store');
    }
}
