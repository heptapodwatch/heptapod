<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Heptapod\Contracts\RunResult;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Requests\Api\PaginationRequest;
use Heptapod\Http\Resources\OhDear\CheckRunResource;
use Heptapod\Http\Resources\OhDear\PaginatedCollection;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

#[Group('Check runs')]
final class CheckRunController extends Controller
{
    public function __invoke(PaginationRequest $request, Site $monitor, string $checkType): PaginatedCollection
    {
        Gate::authorize('view', $monitor);
        /** @var array{filter?: array{started_after?: string, started_before?: string, result?: string}} $data */
        $data = Validator::make($request->all(), ['filter' => ['sometimes', 'array:started_after,started_before,result'], 'filter.started_after' => ['sometimes', 'date_format:YmdHis'], 'filter.started_before' => ['sometimes', 'date_format:YmdHis'], 'filter.result' => ['sometimes', 'in:succeeded,warning,failed']])->validate();
        $query = $monitor->checks()->getQuery();
        $query->getQuery()->where('type', $checkType);
        $check = $query->firstOrFail();
        $runs = $check->runs()->getQuery();
        foreach (['started_after' => '>=', 'started_before' => '<='] as $field => $operator) {
            if (isset($data['filter'][$field])) {
                $runs->getQuery()->where('started_at', $operator, CarbonImmutable::createFromFormat('!YmdHis', $data['filter'][$field], 'UTC'));
            }
        }
        if (isset($data['filter']['result'])) {
            $runs->getQuery()->whereIn('result', $data['filter']['result'] === 'failed' ? [RunResult::Failed, RunResult::Errored] : [$data['filter']['result']]);
        }
        $runs->getQuery()->orderByDesc('ended_at')->orderByDesc('id');
        $paginator = $runs->paginate($request->pageSize(), ['*'], $request->pageParameter(), $request->pageNumber())->appends($request->except($request->pageParameter() === 'page' ? 'page' : 'page.number'));

        return new PaginatedCollection($paginator, CheckRunResource::class)->additional(['links' => ['documentation_url' => url('/docs/api')]]);
    }
}
