<?php

declare(strict_types=1);

namespace Heptapod\Http\Requests;

use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RequestCheckRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, mixed> */
    public function rules(SitePolicy $policy): array
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);
        $sites = $policy->visibleSites($user)->getQuery()->select('sites.id');

        return [
            'incident' => ['sometimes', 'integer', 'min:1', Rule::exists('incidents', 'id')->where(function (Builder $query) use ($sites): void {
                $query->where('check_id', $this->route('check'))->where('site_id', $this->route('site'))->whereIn('site_id', $sites);
            })],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['incident' => __('Incident')];
    }
}
