<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\CreateSite;
use Heptapod\Actions\NormaliseSiteUrl;
use Heptapod\Checks\SiteProbe;
use Heptapod\Exceptions\CheckConfigurationValidationException;
use Heptapod\Http\Middleware\ThrottleRequestsExactly;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Queries\AddSiteReader;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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

    public function store(Request $request, CreateSite $create, SitePolicy $policy, SiteProbe $probe, NormaliseSiteUrl $normalise, ThrottleRequestsExactly $throttle): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        // A client gone or out of reach is refused at the Client field alike (a restored draft, a pick made elsewhere).
        Validator::make($request->all(), ['client_id' => ['required', 'integer', 'min:1', $policy->visibleClientRule($user)]],
            ['client_id.exists' => __('sites.client_unavailable')])->validate();
        $clientId = $request->integer('client_id');
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
            $throttle->handle($request, static fn (): HttpResponse => response()->noContent(), 'site-probe');
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
}
