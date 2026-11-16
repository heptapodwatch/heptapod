<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Dev;

use Heptapod\Contracts\AlertPreviewRenderer;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\Mail\MailRenderer;
use Heptapod\Events\Notifications\NotificationMessage;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Event;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\User;
use Heptapod\Notifications\AccountMailFactory;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;

/**
 * Exploration only (mail branding phase 1, local environment): renders a real alert or account mail through the real
 * templates with unsaved branding values shared into the view. Nothing is stored; phase 2 replaces this.
 */
final class MailBrandingPreviewController extends Controller
{
    public function __invoke(Request $request, NotificationMessage $messages, AccountMailFactory $accountMails, MailRenderer $renderer, InstanceSettings $settings): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasRole('Owner'), 403);
        /** @var array{kind: string, design: string, accent?: string|null, footer?: string|null, logo_width?: int|null, locale: string} $input */
        $input = Validator::make($request->all(), ['kind' => ['required', 'in:alert,reset'], 'design' => ['required', 'in:branded,plain'],
            'accent' => ['nullable', 'regex:/^#[0-9a-f]{6}$/i'], 'footer' => ['nullable', 'string', 'max:200'],
            'logo_width' => ['nullable', 'integer', 'min:1', 'max:200'], 'locale' => ['required', 'in:en,de']])->validate();
        View::share('mailBranding', ['accent' => $input['accent'] ?? null, 'footer' => $input['footer'] ?? null,
            'logo_width' => $input['logo_width'] ?? null]);
        $design = MailDesign::from($input['design']);

        if ($input['kind'] === 'reset') {
            $user->locale = $input['locale'];

            return response()->json($renderer->preview($accountMails->passwordReset($user, url('/reset-password/preview-token'))));
        }

        $previous = $settings->mail_design;
        $settings->mail_design = $design;
        $destination = NotificationDestination::query()->where('channel', 'mail')->oldest('id')->firstOrFail();
        $event = Event::incidentAlertPreviewTypes()->with(['site.client', 'check', 'incident'])->where('type', 'incident.opened')
            ->whereRelation('check', 'type', 'uptime')->where('payload->summary', 'uptime.http_reason')->latest('id')->firstOrFail();
        $config = $destination->destinationConfig();
        $preview = app(AlertPreviewRenderer::class)->render($messages->build($event, $destination), $input['locale'], $config->timezone, $settings->name, $config->instanceUrl);
        $settings->mail_design = $previous;

        return response()->json($preview);
    }
}
