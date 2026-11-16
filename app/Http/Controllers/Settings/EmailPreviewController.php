<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\SaveEmailSettings;
use Heptapod\Contracts\Mail\MailBranding;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\Mail\MailLogo;
use Heptapod\Contracts\Mail\MailRenderer;
use Heptapod\Events\Notifications\EmailPreviewSample;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Notifications\AccountMailFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Settings → Email preview (Owner): the unsaved draft rendered by the real mail templates. */
final class EmailPreviewController extends Controller
{
    /** Draft logos carry only their size; the page fills in the image. */
    public function __invoke(Request $request, EmailPreviewSample $sample, AccountMailFactory $accountMails, MailRenderer $renderer): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasRole('Owner'), 403);
        /** @var array{kind: string, design: string, sender_name?: string|null, accent?: string|null, footer?: string|null, logo_width?: int|string|null, logo_height?: int|string|null, logo_dark_width?: int|string|null, logo_dark_height?: int|string|null} $draft */
        $draft = Validator::make($request->query(), [
            'kind' => ['required', Rule::in(['alert', 'reset'])],
            'design' => ['required', Rule::enum(MailDesign::class)],
            ...SaveEmailSettings::textRules(),
            'logo_width' => ['nullable', 'integer', 'min:1', 'max:'.SaveEmailSettings::LogoMaxWidth, 'required_with:logo_height'],
            'logo_height' => ['nullable', 'integer', 'min:1', 'max:'.SaveEmailSettings::LogoHeight, 'required_with:logo_width'],
            'logo_dark_width' => ['nullable', 'integer', 'min:1', 'max:'.SaveEmailSettings::LogoMaxWidth, 'required_with:logo_dark_height',
                Rule::prohibitedIf($request->query('logo_width') === null)],
            'logo_dark_height' => ['nullable', 'integer', 'min:1', 'max:'.SaveEmailSettings::LogoHeight, 'required_with:logo_dark_width'],
        ], SaveEmailSettings::textMessages())->validate();
        $logo = static fn (int|string|null $width, int|string|null $height): ?MailLogo => $width === null || $height === null ? null
            // The draft's file type does not matter here: its image never leaves the browser.
            : new MailLogo(null, 'image/png', (int) $width, (int) $height);
        $branding = new MailBranding($draft['sender_name'] ?? null, isset($draft['accent']) ? strtolower($draft['accent']) : null, $draft['footer'] ?? null,
            $logo($draft['logo_width'] ?? null, $draft['logo_height'] ?? null), $logo($draft['logo_dark_width'] ?? null, $draft['logo_dark_height'] ?? null));
        $locale = $user->locale ?? app()->getLocale();
        $mail = $draft['kind'] === 'alert'
            ? $sample->alert($locale, MailDesign::from($draft['design']))
            : $accountMails->passwordReset($user, route('password.reset', ['token' => 'preview']));

        return response()->json($renderer->preview($mail, $branding))->header('Cache-Control', 'private, no-store');
    }
}
