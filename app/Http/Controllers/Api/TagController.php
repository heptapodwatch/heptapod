<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Actions\AuthorizeSiteWrite;
use Heptapod\Actions\CreateTag;
use Heptapod\Enums\Capability;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Resources\OhDear\TagResource;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Tags\Tag;

#[Group('Monitors')]
final class TagController extends Controller
{
    public function index(#[CurrentUser] User $user, Request $request, SitePolicy $policy): JsonResponse
    {
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

    public function store(#[CurrentUser] User $user, Request $request, CreateTag $create, SitePolicy $policy, AuthorizeSiteWrite $authorize): JsonResponse
    {
        abort_unless(Capability::Manage->allows($user), 403);
        $visibleSite = $policy->visibleSiteRule($user);
        /** @var array{name: string, monitors?: list<int>, sites?: list<int>} $data */
        $data = Validator::make($request->all(), ['team_id' => ['required', 'integer', 'in:1'], 'name' => ['required', 'string', 'max:255'], 'monitors' => ['sometimes', 'array'], 'monitors.*' => ['integer', $visibleSite], 'sites' => ['sometimes', 'array'], 'sites.*' => ['integer', $visibleSite]])->validate();
        if (array_key_exists('monitors', $data) && array_key_exists('sites', $data) && $data['monitors'] !== $data['sites']) {
            throw ValidationException::withMessages(['monitors' => __('api.conflicting_aliases')]);
        }
        $ids = $data['monitors'] ?? $data['sites'] ?? [];
        $tag = $authorize($user, 'update', static fn (): Tag => $create($data['name'], $ids), ...Site::query()->whereKey($ids)->get()->all());

        return new TagResource($tag)->response()->setStatusCode(201);
    }
}
