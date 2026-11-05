<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Notifications;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Enums\NotificationCategory;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Heptapod\Queries\NotificationExplanationReader;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** GET /notifications/explain (design §2.3): who would get one category of this check's alerts at a moment, and why. */
final class NotificationExplanationController extends Controller
{
    public function __invoke(Request $request, NotificationExplanationReader $reader, InstanceSettings $settings): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        /** @var array{check_id: int, category: string, at: string, recovery_origin?: string} $data */
        $data = Validator::make($request->all(), [
            'check_id' => ['required', 'integer'],
            'category' => ['required', Rule::in(array_values(array_diff(array_map(static fn (NotificationCategory $category): string => $category->value,
                NotificationCategory::grid()), [NotificationCategory::System->value])))],
            'at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'recovery_origin' => ['sometimes', 'prohibited_unless:category,recovery',
                Rule::in([CheckResult::Failed->value, CheckResult::Errored->value, CheckResult::Warning->value])],
        ])->validate();
        $check = Check::query()->with('site.client')->find($data['check_id']);
        abort_unless($check instanceof Check && $check->site !== null, 404);
        Gate::forUser($user)->authorize('view', $check->site);

        return response()->json([...$reader->explain($user, $check, NotificationCategory::from($data['category']), CarbonImmutable::parse($data['at'])->utc(),
            isset($data['recovery_origin']) ? CheckResult::from($data['recovery_origin']) : null), 'timezone' => $settings->timezone]);
    }
}
