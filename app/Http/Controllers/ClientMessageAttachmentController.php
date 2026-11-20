<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Contracts\Export\Column;
use Heptapod\Contracts\Export\Table;
use Heptapod\Enums\ExportFormat;
use Heptapod\Export\Csv;
use Heptapod\Export\ExportResponse;
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
    /**
     * GET …/client-messages/{message}/attachment.{format}: the CSV stored at approval, checked against its hash. CSV
     * serves the sent file byte for byte; Markdown and plain text show the same rows.
     */
    public function __invoke(Request $request, Client $client, int $message, ExportFormat $format): StreamedResponse
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
        $stem = is_string($filename) ? pathinfo($filename, PATHINFO_FILENAME) : 'message';

        if ($format === ExportFormat::Csv) {
            return response()->streamDownload(static function () use ($contents): void {
                echo $contents;
            }, $stem.'.csv', ['Content-Type' => $format->contentType(), 'Cache-Control' => 'private, no-store']);
        }
        $file = fopen('php://temp', 'w+b');
        if ($file === false || ! str_starts_with($contents, Csv::ByteOrderMark) || fwrite($file, substr($contents, strlen(Csv::ByteOrderMark))) === false || ! rewind($file)) {
            throw new \RuntimeException('Cannot read the client message attachment.');
        }
        $header = fgetcsv($file, escape: '');
        if ($header === false) {
            throw new \RuntimeException('The client message attachment has no header row.');
        }
        $columns = array_map(static fn (?string $label): Column => new Column((string) $label), $header);
        $lines = (static function () use ($file): \Generator {
            while (($cells = fgetcsv($file, escape: '')) !== false) {
                /** @var list<string> $cells */
                yield array_map(Csv::readCell(...), $cells);
            }
            fclose($file);
        })();
        $subject = $event->payload['subject'] ?? null;

        return ExportResponse::make(new Table(is_string($subject) ? $subject : $stem, [], $columns, $lines, $stem), $format);
    }
}
