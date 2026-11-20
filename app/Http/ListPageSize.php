<?php

declare(strict_types=1);

namespace Heptapod\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The rows per page of a list: `per_page` from the URL (10, 20, 50 or 100; anything else fails validation; a list that shares
 * its page with others names its own parameter), else the size this browser last chose for that list, else 50. A chosen size is remembered per list in one plain
 * cookie, like the sidebar state: the server reads it on the first render, so a list never loads twice.
 */
final class ListPageSize
{
    /** @var list<int> */
    public const array Sizes = [10, 20, 50, 100];

    public const int Default = 50;

    public const string Cookie = 'list_page_sizes';

    public static function resolve(Request $request, string $list, string $parameter = 'per_page'): int
    {
        Validator::make($request->only($parameter), [$parameter => ['sometimes', 'integer', Rule::in(self::Sizes)]])->validate();
        $remembered = self::remembered($request);
        if ($request->has($parameter)) {
            $size = $request->integer($parameter);
            if (($remembered[$list] ?? null) !== $size) {
                $remembered[$list] = $size;
                $request->attributes->set(self::Cookie, $remembered);
                Cookie::queue(self::Cookie, json_encode($remembered, JSON_THROW_ON_ERROR), 60 * 24 * 365, httpOnly: false);
            }

            return $size;
        }

        return $remembered[$list] ?? self::Default;
    }

    /** @return array<string, int> Valid entries only; a tampered or stale cookie falls back to the default. */
    private static function remembered(Request $request): array
    {
        $updated = $request->attributes->get(self::Cookie);
        if (is_array($updated)) {
            /** @var array<string, int> $updated */
            return $updated;
        }
        $raw = $request->cookie(self::Cookie);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded)) {
            return [];
        }

        return array_filter($decoded, static fn (mixed $size, mixed $list): bool => is_string($list) && in_array($size, self::Sizes, true), ARRAY_FILTER_USE_BOTH);
    }
}
