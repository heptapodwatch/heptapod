<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Schema\Field;
use Heptapod\Contracts\Schema\FieldType;
use Heptapod\Contracts\Schema\SecretMinimum;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Exceptions\CheckConfigurationValidationException;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Heptapod\Support\SecretMerge;
use Heptapod\Support\SecretReentry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class UpdateSite
{
    public function __construct(private CheckRegistry $registry, private EnableCheck $enable, private DisableCheck $disable, private UpdateCheckConfig $configure, private SyncSiteTags $tags, private ClaimService $claims, private NormaliseSiteUrl $normalise, private SiteUrlDuplicates $duplicates) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(Site $site, array $attributes): Site
    {
        try {
            return DB::transaction(function () use ($site, $attributes): Site {
                if ($site->exists) {
                    // Every monitor edit takes check locks in ID order before the site lock.
                    $checks = Check::query()->where('site_id', $site->id);
                    NotificationLocks::forUpdate($checks->getQuery()->orderBy('id'));
                    $checks->get();
                    $query = Site::query()->whereKey($site->id);
                    NotificationLocks::forUpdate($query->getQuery());
                    $site = $query->firstOrFail();
                }
                $supplied = $attributes;
                $urlChanged = is_string($attributes['url'] ?? null) && (! $site->exists || $attributes['url'] !== $site->url);
                if ($urlChanged) {
                    $attributes['url'] = ($this->normalise)($attributes['url']);
                }
                $headerField = new Field(FieldType::List, items: new Field(FieldType::Object, properties: [
                    'name' => new Field(FieldType::String), 'value' => new Field(FieldType::Secret),
                ]));
                foreach (['crawler_headers', 'crawler_settings.headers'] as $path) {
                    $headers = Arr::get($attributes, $path);
                    if (Arr::has($attributes, $path) && $headers === null) {
                        Arr::forget($attributes, $path);
                    }
                    if (! is_array($headers)) {
                        continue;
                    }
                    /** @var array<mixed> $headers */
                    $headers = SecretMerge::preserve($headerField, $headers, $site->crawler_headers ?? [], $path);
                    $headers = $headerField->withoutEmptySecrets($headers);
                    Arr::set($attributes, $path, $headers);
                }
                $rules = [
                    'url' => [$site->exists ? 'sometimes' : 'required', 'string', 'max:2048', 'url:http,https',
                        static function (string $attribute, mixed $value, \Closure $fail): void {
                            $parts = is_string($value) ? parse_url($value) : false;
                            if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
                                $fail(__('api.url_credentials_unsupported'));
                            }
                        },
                    ],
                    'team_id' => ['sometimes', 'integer', 'in:1'], 'type' => ['sometimes', 'in:http'],
                    'friendly_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'group_name' => ['sometimes', 'string', 'max:255'],
                    'description' => ['sometimes', 'nullable', 'string'], 'notes' => ['sometimes', 'nullable', 'string'],
                    'tags' => ['sometimes', 'array'], 'tags.*' => ['string', 'max:255'],
                    'checks' => ['sometimes', 'array'], 'checks.*' => [Rule::enum(CheckKey::class), Rule::in($this->registry->keys()), 'distinct',
                        function (string $attribute, mixed $value, \Closure $fail): void {
                            $key = is_string($value) ? CheckKey::tryFrom($value) : null;
                            $reason = $key === null ? null : $this->registry->availability($key)->reason;
                            if ($reason !== null) {
                                $fail(__($reason));
                            }
                        },
                    ],
                    'crawler_settings' => ['sometimes', 'array:headers'], 'crawler_settings.headers' => ['sometimes', 'array'],
                    'crawler_headers' => ['sometimes', 'nullable', 'array'],
                    'send_report_to_emails' => ['sometimes', 'nullable', 'array'], 'send_report_to_emails.*' => ['email'],
                    'include_check_types_in_report' => ['sometimes', 'nullable', 'array'], 'include_check_types_in_report.*' => [Rule::in($this->registry->keys())],
                ];
                foreach (['crawler_headers', 'crawler_settings.headers'] as $path) {
                    $rules[$path.'.*'] = ['array:name,value'];
                    $rules[$path.'.*.name'] = ['required', 'string', 'regex:/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/'];
                    $rules[$path.'.*.value'] = ['present', 'string', new SecretMinimum, 'not_regex:/[\r\n]/'];
                }
                foreach ($this->registry->keys() as $key) {
                    $rules[$key->value.'_check_settings'] = ['sometimes', 'array'];
                }
                foreach (array_diff(array_keys($attributes), array_keys($rules)) as $key) {
                    throw ValidationException::withMessages([$key => __('api.unsupported')]);
                }
                /** @var array{crawler_settings?: array{headers?: list<array{name: string, value: string}>}, crawler_headers?: list<array{name: string, value: string}>|null} $aliases */
                $aliases = $attributes;
                if (isset($aliases['crawler_settings']['headers']) && array_key_exists('crawler_headers', $aliases) && $aliases['crawler_settings']['headers'] !== $aliases['crawler_headers']) {
                    throw ValidationException::withMessages(['crawler_headers' => __('api.conflicting_aliases')]);
                }
                /** @var array<string, mixed> $data */
                $data = Validator::make($attributes, $rules)->validate();
                if ($urlChanged) {
                    /** @var string $url */
                    $url = $data['url'];
                    $duplicate = $this->duplicates->find($url, $site->id);
                    if ($duplicate instanceof Site) {
                        throw ValidationException::withMessages(['url' => $this->duplicates->message($duplicate)]);
                    }
                    $site->setAttribute('url_key', $this->normalise->key($url));
                }
                if ($site->exists && is_string($data['url'] ?? null) && SecretReentry::origin($site->url) !== SecretReentry::origin($data['url'])) {
                    $this->requireSecretReentry($site, $supplied);
                }
                /** @var array<string, mixed> $siteAttributes */
                $siteAttributes = Arr::only($data, ['url', 'friendly_name', 'group_name', 'description', 'notes', 'crawler_headers', 'send_report_to_emails', 'include_check_types_in_report']);
                $previousUrl = $site->url;
                $previousHeaders = $site->crawler_headers;
                $site->fill($siteAttributes);
                /** @var array{crawler_settings?: array{headers?: list<array{name: string, value: string}>}} $crawler */
                $crawler = $data;
                if (isset($crawler['crawler_settings']['headers'])) {
                    $site->crawler_headers = $crawler['crawler_settings']['headers'];
                }
                if ($site->exists && ($site->url !== $previousUrl || $site->crawler_headers !== $previousHeaders)) {
                    $checks = $site->checks();
                    $checks->getQuery()->getQuery()->orderBy('id');
                    foreach ($checks->get() as $check) {
                        $this->claims->revoke($check);
                    }
                }
                $site->save();
                /** @var list<string> $checks */
                $checks = $data['checks'] ?? [];
                foreach ($this->registry->keys() as $key) {
                    $hasSettings = array_key_exists($key->value.'_check_settings', $data);
                    if (! $hasSettings && ! array_key_exists('checks', $data)) {
                        continue;
                    }
                    $check = $site->checks()->getQuery()->where('type', $key)->first();
                    if (! $hasSettings && ! in_array($key->value, $checks, true) && ! $check instanceof Check) {
                        continue;
                    }
                    $definition = $this->registry->get($key)->definition();
                    $check ??= $site->checks()->make(['type' => $key, 'enabled' => false, 'interval_seconds' => $definition->defaultInterval, 'failure_threshold' => $definition->defaultFailureThreshold]);
                    if (! $check->exists || $hasSettings) {
                        /** @var array<string, mixed> $settings */
                        $settings = $data[$key->value.'_check_settings'] ?? [];
                        try {
                            ($this->configure)($check, $settings);
                        } catch (ValidationException $exception) {
                            throw new CheckConfigurationValidationException($key, $exception);
                        }
                    }
                    if (array_key_exists('checks', $data)) {
                        if (in_array($key->value, $checks, true)) {
                            ($this->enable)($check);
                        } else {
                            ($this->disable)($check);
                        }
                    } elseif ($check->enabled) {
                        ($this->enable)($check);
                    }
                }
                if (array_key_exists('tags', $data)) {
                    /** @var list<string> $tags */
                    $tags = $data['tags'];
                    ($this->tags)($site, $tags);
                }

                return $site->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Concurrent requests can pass unique validation before either transaction commits.
            if (! str_contains($exception->getMessage(), 'sites_url_unique') && ! str_contains($exception->getMessage(), 'sites_url_key_unique')) {
                throw $exception;
            }
            /** @var string $url */
            $url = $attributes['url'];
            $duplicate = $this->duplicates->find($url, $site->id);
            throw ValidationException::withMessages(['url' => $duplicate instanceof Site ? $this->duplicates->message($duplicate) : __('sites.url_duplicate')]);
        }
    }

    /** @param array<string, mixed> $supplied */
    private function requireSecretReentry(Site $site, array $supplied): void
    {
        $headersPath = is_array($supplied['crawler_headers'] ?? null) ? 'crawler_headers' : 'crawler_settings.headers';
        $headers = Arr::get($supplied, $headersPath);
        $crawlerSecrets = array_map(static fn (array $header): array => ['value' => $header['value']], $site->crawler_headers ?? []);
        $errors = SecretReentry::errors($crawlerSecrets, $headers, Arr::has($supplied, $headersPath) ? $headersPath : 'crawler_headers', allowClearedListSecrets: true);
        foreach ($site->checks()->get() as $check) {
            if (! in_array($check->type, $this->registry->keys(), true)) {
                continue;
            }
            $settingsPath = $check->type->value.'_check_settings';
            $settings = $supplied[$settingsPath] ?? null;
            $errors += SecretReentry::errors($check->config_secrets ?? [], $settings, $settingsPath, allowClearedListSecrets: true);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
