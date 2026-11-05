<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Tags\Tag;

final class CreateTag
{
    /** @param list<int> $siteIds */
    public function __invoke(string $name, array $siteIds): Tag
    {
        Validator::make(['name' => $name, 'sites' => $siteIds], ['name' => ['required', 'string', 'max:255'], 'sites' => ['array'], 'sites.*' => ['integer', 'exists:sites,id', 'distinct']])->validate();

        return DB::transaction(function () use ($name, $siteIds): Tag {
            $tag = Tag::findOrCreate($name, 'sites', 'en');
            if (! $tag instanceof Tag) {
                throw new \LogicException('Tag model must use the configured core Tag class');
            }
            foreach (Site::query()->whereKey($siteIds)->get() as $site) {
                $site->attachTag($tag);
            }

            return $tag;
        });
    }
}
