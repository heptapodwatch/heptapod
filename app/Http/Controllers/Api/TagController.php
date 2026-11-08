<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\CreateTag;
use Heptapod\Concerns\ResolvesApiPrincipal;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\TagResource;
use Heptapod\Models\Site;
use Heptapod\Policies\SitePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Tags\Tag;

#[Group('Monitors')]
final class TagController extends Controller
{
    use ResolvesApiPrincipal;

    public function index(Request $request, SitePolicy $policy): JsonResponse
    {
        $user = $this->principal($request);
        $visible = $policy->visibleSites($user)->getQuery()->select('sites.id');
        /** @var Collection<int, object{tag_id: int, taggable_id: int}&\stdClass> $associations */
        $associations = DB::table('taggables')->where('taggable_type', new Site()->getMorphClass())->whereIn('taggable_id', $visible)->orderBy('taggable_id')->get(['tag_id', 'taggable_id']);
        $tagQuery = Tag::query()->where('type', 'sites');
        if ($user->isRestrictedMember() || $user->tokenClientConstraint() !== null) {
            $tagQuery->getQuery()->whereIn('id', $associations->pluck('tag_id'));
        }
        $sitesByTag = [];
        foreach ($associations as $association) {
            $sitesByTag[$association->tag_id][] = $association->taggable_id;
        }
        $tags = $tagQuery->get();

        return response()->json(['data' => $tags->map(fn (Tag $tag): array => new TagResource($tag, $sitesByTag[$tag->id] ?? [])->resolve($request))->all()]);
    }

    public function store(Request $request, CreateTag $create): JsonResponse
    {
        $user = $this->principal($request, 403);
        abort_unless($user->hasAnyRole(['Owner', 'Admin']), 403);
        /** @var array{name: string, monitors?: list<int>, sites?: list<int>} $data */
        $data = Validator::make($request->all(), ['team_id' => ['required', 'integer', 'in:1'], 'name' => ['required', 'string', 'max:255'], 'monitors' => ['sometimes', 'array'], 'monitors.*' => ['integer', 'exists:sites,id'], 'sites' => ['sometimes', 'array'], 'sites.*' => ['integer', 'exists:sites,id']])->validate();
        if (array_key_exists('monitors', $data) && array_key_exists('sites', $data) && $data['monitors'] !== $data['sites']) {
            throw ValidationException::withMessages(['monitors' => __('api.conflicting_aliases')]);
        }
        $ids = $data['monitors'] ?? $data['sites'] ?? [];
        foreach (Site::query()->whereKey($ids)->get() as $site) {
            Gate::authorize('update', $site);
        }
        $tag = $create($data['name'], $ids);

        return new TagResource($tag)->response()->setStatusCode(201);
    }
}
