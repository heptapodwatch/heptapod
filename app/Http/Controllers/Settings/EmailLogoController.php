<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Http\Controllers\Controller;
use Heptapod\Settings\InstanceMailBranding;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A saved mail logo for Settings → Email (Owner), which reads it into the page's draft. */
final class EmailLogoController extends Controller
{
    public function __invoke(InstanceSettings $settings, string $variant, string $file): StreamedResponse
    {
        $path = $variant === 'dark' ? $settings->mail_logo_dark_path : $settings->mail_logo_path;
        abort_unless($path !== null && basename($path) === $file, 404);

        return Storage::disk(InstanceMailBranding::Disk)->response($path, $file, [
            'Content-Type' => InstanceMailBranding::mime($path),
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
