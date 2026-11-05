<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\DeleteRecurringMaintenancePeriod;
use Heptapod\Actions\UpdateRecurringMaintenancePeriod;
use Heptapod\Http\MaintenanceReturnContext;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MaintenanceSeriesController extends Controller
{
    public function update(Request $request, RecurringMaintenancePeriod $series, UpdateRecurringMaintenancePeriod $update): RedirectResponse
    {
        $this->writable($request);
        $returnContext = MaintenanceReturnContext::parameters($request);
        /** @var array<string, mixed> $data */
        $data = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255', 'reason' => 'nullable|string|max:255',
            'mute_alerts' => 'sometimes|boolean', 'catch_up_at_end' => 'sometimes|boolean',
            'target_type' => ['sometimes', Rule::in(['site', 'client', 'tag'])], 'target_id' => 'sometimes|integer|min:1',
            'recurrence_type' => ['sometimes', Rule::in(['daily', 'weekly', 'monthly'])],
            'interval' => 'sometimes|integer|between:1,52', 'nth_weekday' => 'nullable|integer|between:1,5',
            'day_of_week' => 'nullable|integer|between:0,6', 'days_of_week' => 'nullable|array|min:1',
            'days_of_week.*' => 'integer|between:0,6|distinct', 'day_of_month' => 'nullable|integer|between:1,31',
            'start_time' => 'sometimes|date_format:H:i', 'end_time' => 'sometimes|date_format:H:i',
            'timezone' => 'sometimes|timezone:all',
            'anchor_date' => 'sometimes|date_format:Y-m-d',
        ])->validate();
        if (isset($data['target_type']) || isset($data['target_id'])) {
            $targetType = $data['target_type'] ?? $series->target_type;
            $targetId = $data['target_id'] ?? $series->target_id ?? $series->site_id;
            if (! is_string($targetType) || ! is_int($targetId)) {
                throw new \LogicException('Maintenance target must have a type and ID.');
            }
            $table = match ($targetType) {
                'site' => 'sites', 'client' => 'clients', 'tag' => 'tags',
                default => throw new \InvalidArgumentException('Unknown maintenance target type.')
            };
            if (! DB::table($table)->where('id', $targetId)->exists()) {
                throw ValidationException::withMessages(['target_id' => __('validation.exists', ['attribute' => __('Target')])]);
            }
        }
        $update($series, $data);

        return to_route('maintenance.index', $returnContext);
    }

    public function destroy(Request $request, RecurringMaintenancePeriod $series, DeleteRecurringMaintenancePeriod $delete): RedirectResponse
    {
        $this->writable($request);
        $returnContext = MaintenanceReturnContext::parameters($request);
        $delete($series);

        return to_route('maintenance.index', $returnContext);
    }

    private function writable(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasAnyRole(['Owner', 'Admin']), 403);
    }
}
