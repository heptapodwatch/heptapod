<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Settings\InstanceMailBranding;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Settings → Email: validates every field with the rules the page shows and saves nothing while any field is invalid.
 * Logos are stored content-addressed on the local disk; a replaced or removed file is deleted after the settings save.
 */
final readonly class SaveEmailSettings
{
    public const int SenderNameMax = 100;

    public const int FooterMax = 160;

    public const int LogoMaxKilobytes = 512;

    public const float MinContrast = 4.5;

    /** Decoding bound for an uploaded logo; the page names it with the other upload limits. */
    public const int LogoMaxPixels = 4096;

    /** Header height and width cap of a logo in Branded mail; the page computes the same size for its preview. */
    public const int LogoHeight = 24;

    public const int LogoMaxWidth = 200;

    public function __construct(private InstanceSettings $settings) {}

    /** @param array<string, mixed> $input */
    public function __invoke(array $input): void
    {
        $tooLarge = [];
        foreach (['logo', 'logo_dark'] as $field) {
            $upload = $input[$field] ?? null;
            // An upload PHP refused for its size arrives as an invalid file; name the size, not "failed to upload".
            if ($upload instanceof UploadedFile && in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                unset($input[$field]);
                $tooLarge[$field] = __('mail_branding.logo.too_large');
            }
        }
        $logoRule = ['bail', 'nullable', 'file', 'max:'.self::LogoMaxKilobytes, 'mimetypes:image/png,image/jpeg'];
        $validator = Validator::make($input, [
            'mail_design' => ['required', Rule::enum(MailDesign::class)],
            ...self::textRules(),
            'logo' => $logoRule,
            'logo_dark' => $logoRule,
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_logo_dark' => ['sometimes', 'boolean'],
        ], [
            ...self::textMessages(),
            'logo.max' => __('mail_branding.logo.too_large'),
            'logo_dark.max' => __('mail_branding.logo.too_large'),
            'logo.mimetypes' => __('mail_branding.logo.unsupported'),
            'logo_dark.mimetypes' => __('mail_branding.logo.unsupported'),
            'logo.file' => __('mail_branding.logo.unreadable'),
            'logo_dark.file' => __('mail_branding.logo.unreadable'),
        ], ['mail_design' => __('instance.mail_design.label')]);
        $validator->after(function (ValidatorInstance $validator) use ($input, $tooLarge): void {
            foreach ($tooLarge as $field => $message) {
                $validator->errors()->add($field, $message);
            }
            foreach (['logo', 'logo_dark'] as $field) {
                $upload = $input[$field] ?? null;
                if ($upload instanceof UploadedFile && ! $validator->errors()->has($field)) {
                    // The content decides the type, whatever the file name or the browser's claimed type says.
                    $info = @getimagesizefromstring($upload->getContent());
                    if ($info !== false && ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                        $validator->errors()->add($field, __('mail_branding.logo.unsupported'));
                    } elseif ($info !== false && max($info[0], $info[1]) > self::LogoMaxPixels) {
                        $validator->errors()->add($field, __('mail_branding.logo.too_many_pixels', ['max' => self::LogoMaxPixels]));
                    } elseif ($this->decoded($upload) === null) {
                        $validator->errors()->add($field, __('mail_branding.logo.unreadable'));
                    }
                }
            }
            if (($input['logo_dark'] ?? null) instanceof UploadedFile && ! $this->keepsLogo($input)) {
                $validator->errors()->add('logo_dark', __('mail_branding.logo_dark.needs_logo'));
            }
        });
        /** @var array{mail_design: string, sender_name?: string|null, accent?: string|null, footer?: string|null, logo?: UploadedFile|null, logo_dark?: UploadedFile|null, remove_logo?: bool|string, remove_logo_dark?: bool|string} $data */
        $data = $validator->validate();

        $settings = $this->settings;
        $previous = array_filter([$settings->mail_logo_path, $settings->mail_logo_dark_path], is_string(...));
        $settings->mail_design = MailDesign::from($data['mail_design']);
        $settings->mail_sender_name = self::trimmed($data['sender_name'] ?? null);
        $settings->mail_accent = isset($data['accent']) ? strtolower($data['accent']) : null;
        $settings->mail_footer = self::trimmed($data['footer'] ?? null);
        $logo = $data['logo'] ?? null;
        if ($logo instanceof UploadedFile) {
            [$settings->mail_logo_path, $settings->mail_logo_width, $settings->mail_logo_height] = $this->store($logo);
        } elseif (filter_var($data['remove_logo'] ?? false, FILTER_VALIDATE_BOOL)) {
            [$settings->mail_logo_path, $settings->mail_logo_width, $settings->mail_logo_height] = [null, null, null];
        }
        $logoDark = $data['logo_dark'] ?? null;
        if ($logoDark instanceof UploadedFile) {
            [$settings->mail_logo_dark_path, $settings->mail_logo_dark_width, $settings->mail_logo_dark_height] = $this->store($logoDark);
        } elseif ($settings->mail_logo_path === null || filter_var($data['remove_logo_dark'] ?? false, FILTER_VALIDATE_BOOL)) {
            [$settings->mail_logo_dark_path, $settings->mail_logo_dark_width, $settings->mail_logo_dark_height] = [null, null, null];
        }
        $settings->save();
        $kept = array_filter([$settings->mail_logo_path, $settings->mail_logo_dark_path], is_string(...));
        foreach (array_diff($previous, $kept) as $path) {
            Storage::disk(InstanceMailBranding::Disk)->delete($path);
        }
    }

    /**
     * The text fields, shared with the page preview so a draft the save would refuse never renders.
     *
     * @return array<string, list<mixed>>
     */
    public static function textRules(): array
    {
        // Control characters include CR and LF: a sender name with a line break could inject mail headers.
        $oneLine = 'not_regex:/[\p{Cc}\x{2028}\x{2029}]/u';

        return [
            'sender_name' => ['nullable', 'string', 'max:'.self::SenderNameMax, $oneLine],
            'accent' => ['nullable', 'string', 'bail', 'regex:/^#[0-9a-fA-F]{6}$/', static function (string $attribute, string $value, \Closure $fail): void {
                $ratio = self::contrastWithWhite($value);
                if ($ratio < self::MinContrast) {
                    $fail(__('mail_branding.accent.unreadable', ['ratio' => number_format(floor($ratio * 10) / 10, 1)]));
                }
            }],
            'footer' => ['nullable', 'string', 'max:'.self::FooterMax, $oneLine, 'not_regex:/[<>]/'],
        ];
    }

    /** @return array<string, string> */
    public static function textMessages(): array
    {
        return [
            'sender_name.max' => __('mail_branding.sender.too_long', ['max' => self::SenderNameMax]),
            'sender_name.not_regex' => __('mail_branding.sender.one_line'),
            'accent.regex' => __('mail_branding.accent.format'),
            'footer.max' => __('mail_branding.footer.too_long', ['max' => self::FooterMax]),
            'footer.not_regex' => __('mail_branding.footer.plain'),
        ];
    }

    /**
     * Sender name and footer skip the global trim (bootstrap/app.php) so their rules see an edge line break or null byte and
     * refuse it; surrounding spaces are trimmed here once the value is valid.
     */
    public static function trimmed(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value, ' ');

        return $value === '' ? null : $value;
    }

    /** White button text on the accent, and accent links on the white card: one contrast ratio covers both. */
    public static function contrastWithWhite(string $hex): float
    {
        $channels = array_map(static function (int $offset) use ($hex): float {
            $value = hexdec(substr($hex, $offset, 2)) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, [1, 3, 5]);

        return 1.05 / (0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2] + 0.05);
    }

    /** @return array{0: int, 1: int, 2: string}|null the rendered size and file extension, or null when the file is not a readable PNG or JPEG */
    private function decoded(UploadedFile $upload): ?array
    {
        $bytes = $upload->getContent();
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || $info[0] < 1 || $info[1] < 1
            || max($info[0], $info[1]) > self::LogoMaxPixels || @imagecreatefromstring($bytes) === false) {
            return null;
        }
        $ratio = $info[0] / $info[1];
        $width = max(1, min(self::LogoMaxWidth, (int) round(self::LogoHeight * $ratio)));

        return [$width, max(1, min(self::LogoHeight, (int) round($width / $ratio))), $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg'];
    }

    /** @param array<string, mixed> $input */
    private function keepsLogo(array $input): bool
    {
        if (($input['logo'] ?? null) instanceof UploadedFile) {
            return true;
        }

        return $this->settings->mail_logo_path !== null && ! filter_var($input['remove_logo'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /** @return array{0: string, 1: int, 2: int} */
    private function store(UploadedFile $upload): array
    {
        [$width, $height, $extension] = $this->decoded($upload) ?? throw new \LogicException('A validated mail logo must decode.');
        $bytes = $upload->getContent();
        $path = 'mail-branding/'.hash('sha256', $bytes).'.'.$extension;
        Storage::disk(InstanceMailBranding::Disk)->put($path, $bytes);

        return [$path, $width, $height];
    }
}
