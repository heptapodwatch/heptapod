<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final readonly class SiteUrlDuplicates
{
    public function __construct(private NormaliseSiteUrl $normalise, private SitePolicy $policy) {}

    public function find(string $url, ?int $exceptId = null): ?Site
    {
        $key = $this->normalise->key($url);
        $query = Site::query()->where('id', '!=', $exceptId ?? 0);
        $match = (clone $query)->where('url_key', $key)->first();
        if ($match !== null) {
            return $match;
        }
        // Rollback-era writes and retained collisions have no key; they still count as monitored.
        $query->getQuery()->whereNull('url_key');
        foreach ($query->lazyById(500) as $site) {
            try {
                if ($this->normalise->key($site->url) === $key) {
                    return $site;
                }
            } catch (ValidationException) {
                // Legacy invalid addresses are preserved, but cannot collide with a valid address.
                continue;
            }
        }

        return null;
    }

    public function visible(Site $site): bool
    {
        $user = Auth::user();

        return $user instanceof User && $this->policy->view($user, $site)->allowed();
    }

    public function message(Site $site): string
    {
        return $this->visible($site) ? __('sites.url_duplicate_named', ['name' => $site->displayName()]) : __('sites.url_duplicate');
    }

    public function warning(string $url, ?int $exceptId = null): ?Site
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        $apex = preg_replace('/^www\./', '', (string) $host);
        foreach ($this->policy->visibleSites($user, includeArchived: true)->where('id', '!=', $exceptId ?? 0)->lazyById(500) as $site) {
            try {
                $other = $this->normalise->__invoke($site->url);
            } catch (ValidationException) {
                continue;
            }
            $otherHost = parse_url($other, PHP_URL_HOST);
            if ($host === $otherHost || ($apex === preg_replace('/^www\./', '', (string) $otherHost)
                && parse_url($url, PHP_URL_PATH) === parse_url($other, PHP_URL_PATH)
                && parse_url($url, PHP_URL_QUERY) === parse_url($other, PHP_URL_QUERY)
                && parse_url($url, PHP_URL_PORT) === parse_url($other, PHP_URL_PORT))) {
                return $site;
            }
        }

        return null;
    }
}
