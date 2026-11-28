<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Enums\Capability;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceMailBranding;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A saved mail logo for Settings → Email (Owner), which reads it into the page's draft. */
final class EmailLogoController extends Controller
{
    public function __invoke(Request $request, InstanceSettings $settings, string $variant, string $file): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && Capability::ManageInstance->allows($user), 403);
        $path = $variant === 'dark' ? $settings->mail_logo_dark_path : $settings->mail_logo_path;
        abort_unless($path !== null && basename($path) === $file, 404);

        return Storage::disk(InstanceMailBranding::Disk)->response($path, $file, [
            'Content-Type' => InstanceMailBranding::mime($path),
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
