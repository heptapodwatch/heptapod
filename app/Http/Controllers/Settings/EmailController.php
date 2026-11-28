<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\SaveEmailSettings;
use Heptapod\Data\Ui\EmailPageData;
use Heptapod\Data\Ui\EmailSettingsData;
use Heptapod\Data\Ui\InstanceSettingsData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Email (Owner): the email appearance with its live preview, and the read-only delivery facts. */
final class EmailController extends Controller
{
    public function edit(InstanceSettings $settings): Response
    {
        $logoUrl = static fn (string $variant, ?string $path): ?string => $path === null ? null
            : route('settings.email.logo', ['variant' => $variant, 'file' => basename($path)]);

        return Inertia::render('settings/email', new EmailPageData(InstanceSettingsData::current($settings), new EmailSettingsData(
            $settings->mail_sender_name, $settings->mail_accent, $settings->mail_footer,
            $logoUrl('light', $settings->mail_logo_path), $logoUrl('dark', $settings->mail_logo_dark_path),
            SaveEmailSettings::SenderNameMax, SaveEmailSettings::FooterMax, SaveEmailSettings::LogoMaxKilobytes)));
    }

    public function update(Request $request, SaveEmailSettings $save): RedirectResponse
    {
        /** @var array<string, mixed> $input */
        $input = $request->all();
        $save($input);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email settings saved.')]);

        return to_route('settings.email.edit');
    }
}
