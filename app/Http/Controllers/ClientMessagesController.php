<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Actions\SendClientMessage;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\User;
use Heptapod\Policies\ClientPolicy;
use Heptapod\Queries\ClientMessagesReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * "Send to client" (design §4). Owners and Admins with access to the client send; Members only read the history.
 */
final class ClientMessagesController extends Controller
{
    /** GET …/client-messages?before=: one page (newest first, by delivery id) of everything this client was sent; Members read it with addresses hidden. */
    public function index(Request $request, Client $client, ClientMessagesReader $reader, ClientPolicy $policy): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $policy->view($user, $client)->authorize();
        /** @var array{before?: int|string|null} $query */
        $query = Validator::make($request->query(), ['before' => ['nullable', 'integer', 'min:1']])->validate();
        $before = isset($query['before']) ? (int) $query['before'] : null;

        return response()->json([...$reader->page($user, $client, $before), 'can_send' => Gate::forUser($user)->allows('update', $client)])
            ->header('Cache-Control', 'private, no-store');
    }

    /** GET …/client-messages/{message}: the message exactly as approved, rendered from its frozen payload. */
    public function show(Request $request, Client $client, int $message, SendClientMessage $send, ClientPolicy $policy): JsonResponse
    {
        $event = $this->message($request, $client, $message, $policy);
        $rendered = $send->render($event);
        $attachment = DB::table('client_message_attachments')->where('event_id', $event->id)->first(['rows']);

        return response()->json(['subject' => $rendered['subject'], 'html' => $rendered['html'],
            'attachment' => $attachment === null ? null : ['filename' => $event->payload['attachment_filename'] ?? null, 'rows' => $attachment->rows]])
            ->header('Cache-Control', 'private, no-store');
    }

    /** One message of this client, for anyone who may see the client; another client's message is not found. */
    private function message(Request $request, Client $client, int $message, ClientPolicy $policy): Event
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $policy->view($user, $client)->authorize();
        $query = Event::query()->whereKey($message)->where('client_id', $client->id);
        $query->getQuery()->whereIn('type', ['client_message.findings', 'client_message.incident']);

        return $query->firstOrFail();
    }

    public function store(Request $request, Client $client, SendClientMessage $send, ClientPolicy $policy): JsonResponse
    {
        $sender = $this->sender($request, $client, $policy);
        $key = $request->header('Idempotency-Key');
        abort_if($key === null, 428, __('client_messages.key_required'));
        $result = $send->send($sender, $client, $this->input($request), $key);

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        /** @var array<string, mixed> */
        return $request->all();
    }

    private function sender(Request $request, Client $client, ClientPolicy $policy): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $policy->view($user, $client)->authorize();
        abort_unless(Gate::forUser($user)->allows('update', $client), 403);

        return $user;
    }
}
