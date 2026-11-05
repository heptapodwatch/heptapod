<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\CreateSite;
use Heptapod\Checks\SiteProbe;
use Heptapod\Exceptions\CheckConfigurationValidationException;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Policies\SitePolicy;
use Heptapod\Queries\AddSiteReader;
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

        return Inertia::render('sites/create', $reader->read($user, $request->filled('client_id') ? $request->integer('client_id') : null)->toArray());
    }

    public function store(Request $request, CreateSite $create, SitePolicy $policy, SiteProbe $probe): RedirectResponse
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
            Validator::make($attributes, ['url' => ['required', 'string', 'max:2048', 'url:http,https']])->validate();
            $detection = $probe($request->string('url')->toString());
        }
        try {
            $site = $create($clientId, $attributes, $detection);
        } catch (CheckConfigurationValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $path => $messages) {
                $field = preg_replace('/^(?:values\.|'.preg_quote($exception->check->value, '/').'_check_settings\.)/', '', $path);
                $errors['checks.'.$exception->check->value.'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        return redirect()->route('sites.show', ['site' => $site->id]);
    }
}
