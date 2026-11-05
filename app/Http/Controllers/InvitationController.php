<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\AcceptInvitation;
use Heptapod\Data\Ui\InvitationData;
use Heptapod\Data\Ui\InvitationPageData;
use Heptapod\Models\Client;
use Heptapod\Models\Invitation;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InvitationController extends Controller
{
    public function show(Request $request, string $token, InstanceSettings $instance): Response
    {
        try {
            $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
            abort_if($invitation->expires_at->isPast(), 410);
        } catch (ModelNotFoundException|HttpException $exception) {
            return $this->unavailable($request, $exception, $instance);
        }
        $clients = Client::query()->whereKey($invitation->client_ids)->withCount('sites')->get(['id', 'name']);
        $data = ['instance' => $instance->name, 'inviter' => $invitation->inviter?->name,
            'email' => $invitation->email, 'role' => $invitation->role, 'clients' => $clients,
            'expires_at' => $invitation->expires_at->toIso8601String()];

        if ($request->expectsJson()) {
            return response()->json($data)->header('Cache-Control', 'no-store');
        }

        $response = Inertia::render('invitations/show', new InvitationPageData(
            instance: $instance->name,
            state: 'valid',
            invitation: new InvitationData($instance->name, $data['inviter'], $invitation->email, $invitation->role,
                $invitation->client_scope, array_values($clients->map(static fn (Client $client): array => [
                    'id' => $client->id, 'name' => $client->name, 'sites_count' => $client->sites_count,
                ])->all()), $data['expires_at']),
            token: $token,
            passwordRules: Password::defaults()->toPasswordRulesString(),
        ))->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function store(Request $request, string $token, AcceptInvitation $accept, InstanceSettings $instance): Response
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $request->all();
        try {
            $user = $accept($token, $attributes);
        } catch (ModelNotFoundException|HttpException $exception) {
            return $this->unavailable($request, $exception, $instance);
        }
        Auth::login($user);
        $request->session()->regenerate();

        return to_route('sites.index');
    }

    /** @param ModelNotFoundException<Model>|HttpException $exception */
    private function unavailable(Request $request, ModelNotFoundException|HttpException $exception, InstanceSettings $instance): Response
    {
        $status = $exception instanceof ModelNotFoundException ? 404 : $exception->getStatusCode();
        if (! in_array($status, [404, 409, 410], true)) {
            throw $exception;
        }
        if ($request->expectsJson()) {
            $response = app(ExceptionHandler::class)->render($request, $exception);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        $response = Inertia::render('invitations/show', new InvitationPageData(
            instance: $instance->name,
            // Deleted tokens cannot distinguish acceptance, revocation or an unknown link.
            state: match ($status) {
                410 => 'expired', 409 => 'active-account', default => 'unavailable'
            },
            invitation: null,
        ))->toResponse($request)->setStatusCode($status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
