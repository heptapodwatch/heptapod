<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Heptapod\Actions\ResolveClientAvatar;
use Heptapod\Contracts\Mail\LetterTile;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;

/** The client avatar in a mail header: the inline PNG when one exists, otherwise the letter tile. */
final readonly class MailAvatar
{
    public function __construct(private ResolveClientAvatar $avatars) {}

    /** @return array{png: ?string, cid: ?string, tile: ?LetterTile} */
    public function for(Client $client, Site $site): array
    {
        $client->loadMissing(Client::AvatarRelations);
        $avatar = ($this->avatars)($client);
        $png = null;
        if ($avatar->url !== null) {
            if ($avatar->site_id !== null) {
                $source = $client->sites->firstWhere('id', $avatar->site_id) ?? throw new \LogicException('Avatar source site missing.');
                $png = $this->png(Storage::disk(), 'site-icons/'.$source->id.'/'.$source->icon_hash.'.png', $site, $client);
            } else {
                $logo = $client->getFirstMedia('logo') ?? throw new \LogicException('Avatar conversion missing.');
                $png = $this->png(Storage::disk($logo->conversions_disk), $logo->getPathRelativeToRoot('avatar'), $site, $client);
            }
        }

        return ['png' => $png, 'cid' => $png === null ? null : 'avatar-'.$client->id, 'tile' => $png === null ? $this->letterTile($client) : null];
    }

    private function png(FilesystemAdapter $disk, string $path, Site $site, Client $client): ?string
    {
        try {
            $png = $disk->get($path);
        } catch (UnableToReadFile $exception) {
            // A replaced/deleted avatar file is cosmetic. Confirm absence; permission, storage and all other errors propagate.
            if ($disk->exists($path)) {
                throw $exception;
            }
            Log::warning('Email avatar file missing; using letter tile.', ['site_id' => $site->id, 'client_id' => $client->id, 'path' => $path]);

            return null;
        }

        return $png ?? throw new \LogicException('Avatar read returned no bytes without a missing-file error.');
    }

    private function letterTile(Client $client): LetterTile
    {
        if ($client->is_internal) {
            // Reuse the mail palette's soft surface and muted ink, not the client's branding.
            return new LetterTile('—', '#e1e5e8', '#5c6771');
        }
        $words = preg_split('/\s+/u', trim($client->name));
        if ($words === false) {
            throw new \LogicException('Could not split client name.');
        }
        $initials = mb_strtoupper(implode('', array_map(static fn (string $word): string => mb_substr($word, 0, 1), array_slice($words, 0, 2))));
        $background = $client->accent_color ?? '#4B4B57';
        $luminance = 0.0;
        foreach ([0.2126, 0.7152, 0.0722] as $index => $weight) {
            $channel = hexdec(substr($background, 1 + $index * 2, 2)) / 255;
            $luminance += $weight * ($channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4);
        }
        // Choose the higher-contrast black/white ink; this guarantees at least 4.5:1 on a solid accent colour.
        $foreground = $luminance > 0.179 ? '#000000' : '#ffffff';

        return new LetterTile($client->short_name !== null && $client->short_name !== '' ? $client->short_name : $initials, $background, $foreground);
    }
}
