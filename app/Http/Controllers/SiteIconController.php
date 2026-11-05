<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Models\Site;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SiteIconController extends Controller
{
    public function show(Site $site, string $hash): StreamedResponse
    {
        Gate::authorize('view', $site);
        abort_unless($site->icon_hash === $hash, 404);

        return Storage::disk()->response('site-icons/'.$site->id.'/'.$hash.'.png', 'icon.png', [
            'Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
