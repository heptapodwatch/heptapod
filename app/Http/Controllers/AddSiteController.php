<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\CreateSite;
use Heptapod\Actions\NormaliseSiteUrl;
use Heptapod\Checks\SiteProbe;
use Heptapod\Exceptions\CheckConfigurationValidationException;
use Heptapod\Exceptions\ExpectedRefusal;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Queries\AddSiteReader;
use Heptapod\Settings\InstanceSettings;
use Heptapod\Support\AtomicRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AddSiteController extends Controller
{
    public function create(Request $request, AddSiteReader $reader): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        Validator::make($request->all(), ['client_id' => ['sometimes', 'integer', 'min:1']])->validate();

        return Inertia::render('sites/create', $reader->read($user, $request->filled('client_id') ? $request->integer('client_id') : null)->toArray())
            ->with('instanceTimezone', app(InstanceSettings::class)->timezone);
    }

    public function store(Request $request, CreateSite $create, SitePolicy $policy, SiteProbe $probe, NormaliseSiteUrl $normalise, AtomicRateLimiter $limiter): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $clients = $policy->visibleClients($user);
        Validator::make($request->all(), ['client_id' => ['required', 'integer', 'min:1']])->validate();
        $clients->getQuery()->where('id', $request->integer('client_id'));
        $clientId = $clients->firstOrFail()->id;
        Gate::authorize('create', [Site::class, $clientId]);
        if ($request->exists('cron_task_definitions')) {
            throw ValidationException::withMessages(['cron_task_definitions' => __('sites.cron_setup_after_create')]);
        }
        $checks = $request->input('checks');
        if (is_array($checks) && in_array('cron', $checks, true)) {
            throw ValidationException::withMessages(['checks' => __('sites.cron_setup_after_create')]);
        }
        /** @var array<string, mixed> $attributes */
        $attributes = $request->except('client_id', '_token');
        $detection = null;
        if (! array_key_exists('checks', $attributes)) {
            /** @var array{url: string} $address */
            $address = Validator::make($attributes, ['url' => ['required', 'string', 'max:2048']])->validate();
            $this->consumeSiteProbeLimit($request, $limiter, $user);
            $attributes['url'] = $normalise($address['url']);
            $detection = $probe($attributes['url']);
        }
        try {
            $site = $create($clientId, $attributes, $detection);
        } catch (CheckConfigurationValidationException $exception) {
            throw ValidationException::withMessages($exception->formErrors());
        }

        return redirect()->route('sites.show', ['site' => $site->id]);
    }

    private function consumeSiteProbeLimit(Request $request, AtomicRateLimiter $limiter, User $user): void
    {
        $resolver = $limiter->limiter('site-probe');
        if ($resolver === null) {
            return;
        }
        /** @var Limit|list<Limit> $resolved */
        $resolved = $resolver($request);
        $limits = is_array($resolved) ? $resolved : [$resolved];
        $entries = array_map(static fn (Limit $limit): array => [
            'key' => md5('site-probe'.(is_scalar($limit->key) ? (string) $limit->key : '')),
            'maxAttempts' => $limit->maxAttempts,
            'decaySeconds' => $limit->decaySeconds,
        ], $limits);
        $exhausted = $limiter->consume($entries);
        if ($exhausted !== null) {
            $retryAfter = max(1, $limiter->availableIn($entries[$exhausted]['key']));
            ['wait' => $wait] = ExpectedRefusal::cooldownParts($retryAfter, $user);

            throw new ExpectedRefusal(
                status: 429,
                messageKey: 'errors.429.retry_in',
                params: ['wait' => $wait],
                errorCode: 'rate_limited',
                retryAfterSeconds: $retryAfter,
            );
        }
    }
}
