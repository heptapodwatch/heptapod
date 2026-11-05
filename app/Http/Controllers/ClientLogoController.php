<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Models\Client;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ClientLogoController extends Controller
{
    public function __invoke(Client $client, string $hash): StreamedResponse
    {
        Gate::authorize('view', $client);
        $logo = $client->getFirstMedia('logo');
        abort_unless($logo instanceof Media && $logo->hasGeneratedConversion('avatar') && $logo->getCustomProperty('avatar_hash') === $hash, 404);

        return Storage::disk($logo->conversions_disk)->response($logo->getPathRelativeToRoot('avatar'), 'avatar.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
