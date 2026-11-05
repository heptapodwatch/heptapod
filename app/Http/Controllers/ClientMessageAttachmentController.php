<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ClientMessageAttachmentController extends Controller
{
    /** GET …/client-messages/{message}/attachment: the CSV stored at approval, checked against its hash. */
    public function __invoke(Request $request, Client $client, int $message): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Gate::forUser($user)->authorize('view', $client);
        $query = Event::query()->whereKey($message)->where('client_id', $client->id);
        $query->getQuery()->whereIn('type', ['client_message.findings', 'client_message.incident']);
        $event = $query->firstOrFail();
        $row = DB::table('client_message_attachments')->where('event_id', $event->id)->first(['disk', 'path', 'sha256']);
        abort_if($row === null, 404);
        /** @var \stdClass&object{disk: string, path: string, sha256: string} $row */
        $contents = Storage::disk($row->disk)->get($row->path) ?? throw new \RuntimeException('The client message attachment is missing.');
        if (! hash_equals($row->sha256, hash('sha256', $contents))) {
            throw new \RuntimeException('The client message attachment changed after approval.');
        }
        $filename = $event->payload['attachment_filename'] ?? null;

        return response()->streamDownload(static function () use ($contents): void {
            echo $contents;
        }, is_string($filename) ? $filename : 'message.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
