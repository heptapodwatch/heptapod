<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A client message's CSV is written before its transaction; if that transaction never committed, the file has no
 * attachment row. Files with a row live as long as their event (design §4). Only orphans older than 24 hours go, so a
 * send that is still committing keeps its file.
 */
final readonly class SweepClientMessageAttachments
{
    /** Deletes the orphan attachment files and returns how many went. */
    public function __invoke(): int
    {
        $disk = Storage::disk(SendClientMessage::Disk);
        $cutoff = CarbonImmutable::now()->subDay()->getTimestamp();
        $deleted = 0;
        foreach (array_chunk($disk->files(SendClientMessage::Directory), 500) as $paths) {
            /** @var list<string> $kept */
            $kept = DB::table('client_message_attachments')->whereIn('path', $paths)->pluck('path')->all();
            foreach (array_diff($paths, $kept) as $path) {
                if ($disk->lastModified($path) < $cutoff) {
                    $disk->delete($path);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
