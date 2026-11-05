<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Settings;

use Heptapod\Actions\SaveInstanceSettings;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Data\Ui\InstancePageData;
use Heptapod\Data\Ui\InstanceSettingsData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Heptapod\SystemHealth\ReadSystemHealth;
use Heptapod\SystemHealth\SystemIssueMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class InstanceController extends Controller
{
    public function edit(Request $request, InstanceSettings $settings, ReadSystemHealth $health): Response
    {
        return Inertia::render('settings/instance', new InstancePageData(
            instancePage: $this->data($request, $settings, $health),
        ));
    }

    public function show(Request $request, InstanceSettings $settings, ReadSystemHealth $health): JsonResponse
    {
        return response()->json($this->data($request, $settings, $health));
    }

    private function data(Request $request, InstanceSettings $settings, ReadSystemHealth $health): InstanceSettingsData
    {
        $this->owner($request);
        /** @var array{host?: string|null, port?: int|null, scheme?: string|null, username?: string|null, password?: string|null} $smtp */
        $smtp = config()->array('mail.mailers.smtp');
        /** @var array{keep_all_backups_for_days: int, keep_daily_backups_for_days: int, keep_weekly_backups_for_weeks: int, keep_monthly_backups_for_months: int, keep_yearly_backups_for_years: int, delete_oldest_backups_when_using_more_megabytes_than: int|null} $retention */
        $retention = config()->array('backup.cleanup.default_strategy');

        return new InstanceSettingsData($settings->name, $settings->mail_design, config()->string('app.url'),
            ['host' => $smtp['host'] ?? null, 'port' => $smtp['port'] ?? null,
                'scheme' => $smtp['scheme'] ?? null, 'username_configured' => filled($smtp['username'] ?? null),
                'password_configured' => filled($smtp['password'] ?? null)],
            config()->string('mail.default'), ! SystemIssueMailer::canDeliver(), config()->string('mail.from.name'), config()->string('mail.from.address'),
            filled(config('instance.heartbeat_url')), $health()->lastExternalPing, url('/up'),
            ['editable' => false, 'source' => 'check definitions'], false, $retention);
    }

    public function update(Request $request, SaveInstanceSettings $save): JsonResponse|RedirectResponse
    {
        $this->owner($request);
        /** @var array{name: string, mail_design?: string} $data */
        $data = Validator::make($request->all(), ['name' => ['required', 'string', 'max:120'], 'mail_design' => ['sometimes', 'required', Rule::enum(MailDesign::class)]],
            attributes: ['mail_design' => __('instance.mail_design.label')])->validate();
        $save($data['name'], isset($data['mail_design']) ? MailDesign::from($data['mail_design']) : null);

        if ($request->expectsJson()) {
            return response()->json(['name' => $data['name'], 'mail_design' => app(InstanceSettings::class)->mail_design->value]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Instance settings saved.')]);

        return to_route('settings.instance.edit');
    }

    private function owner(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasRole('Owner'), 403);
    }
}
