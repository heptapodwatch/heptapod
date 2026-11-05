<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Events\LiveSignal;
use Heptapod\Models\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdateClient
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Client $client, array $attributes): Client
    {
        $incoming = $attributes['logo'] ?? null;
        if ($incoming instanceof UploadedFile && in_array($incoming->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw ValidationException::withMessages(['logo' => __('File too large (max 2 MB)')]);
        }
        /** @var array{name?: string, short_name?: string|null, contact_name?: string|null, locale?: string, timezone?: string, accent_color?: string, logo?: UploadedFile|null, remove_logo?: bool, avatar_site_id?: int|numeric-string|null} $data */
        $data = Validator::make($attributes, [
            'name' => [$client->exists ? 'sometimes' : 'required', 'string', 'max:255'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:8'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            // A client's contacts are its mail channels (/clients/{client}/notifications) since 2026-10-30.
            'contact_email' => ['prohibited'],
            'locale' => ['sometimes', Rule::in(['en', 'de'])],
            'timezone' => ['sometimes', 'timezone'],
            'accent_color' => ['sometimes', Rule::in(['#7C5E10', '#3B5068', '#2F5D4A', '#5B3A4A', '#4B4B57', '#3F4A3A', '#4A4A2F', '#3F3F46'])],
            'logo' => ['bail', 'sometimes', 'nullable', 'file', 'max:2048', 'mimetypes:image/png,image/jpeg,image/webp', 'extensions:png,jpg,jpeg,webp'],
            'remove_logo' => ['sometimes', 'boolean'],
            'avatar_site_id' => ['sometimes', 'nullable', 'integer'],
        ], [
            'logo.max' => __('File too large (max 2 MB)'),
            'logo.mimetypes' => __('Format not supported. Use PNG, JPEG or WebP.'),
            'logo.extensions' => __('Format not supported. Use PNG, JPEG or WebP.'),
        ])->validate();
        if (isset($data['avatar_site_id'])) {
            $data['avatar_site_id'] = (int) $data['avatar_site_id'];
        }
        $upload = $data['logo'] ?? null;
        if ($upload instanceof UploadedFile) {
            if (($data['remove_logo'] ?? false) || ($data['avatar_site_id'] ?? null) !== null) {
                throw ValidationException::withMessages(['logo' => __('Choose either an upload or a site icon.')]);
            }
            // Decode at the upload boundary before media replacement; malformed files keep the old avatar.
            $bytes = $upload->getContent();
            $info = @getimagesizefromstring($bytes);
            if ($info === false) {
                throw ValidationException::withMessages(['logo' => __('Image could not be read.')]);
            }
            if ($info[0] > 4096 || $info[1] > 4096) {
                throw ValidationException::withMessages(['logo' => __('Image dimensions too large (max 4096 × 4096).')]);
            }
            if (@imagecreatefromstring($bytes) === false) {
                throw ValidationException::withMessages(['logo' => __('Image could not be read.')]);
            }
        }

        return DB::transaction(function () use ($client, $data, $upload): Client {
            if ($client->exists) {
                // Serialize single-file replacement; concurrent uncommitted media rows are otherwise invisible.
                $locked = Client::query();
                $locked->getQuery()->lock('for no key update');
                $client = $locked->findOrFail($client->id);
            }
            // A site transfer locks this client too; validate membership only after acquiring it.
            Validator::make(Arr::only($data, ['avatar_site_id']), [
                'avatar_site_id' => ['nullable', Rule::exists('sites', 'id')->where('client_id', $client->id)->whereNotNull('icon_hash')],
            ], ['avatar_site_id.exists' => __('Choose a site belonging to this client with a stored icon.')])->validate();
            /** @var array<string, mixed> $values */
            $values = Arr::only($data, ['name', 'short_name', 'contact_name', 'locale', 'timezone', 'accent_color', 'avatar_site_id']);
            $choiceChanged = array_key_exists('avatar_site_id', $data) && $data['avatar_site_id'] !== $client->avatar_site_id;
            $avatarChanged = $upload instanceof UploadedFile || ($data['remove_logo'] ?? false) || $choiceChanged;
            if ($upload instanceof UploadedFile || ($data['remove_logo'] ?? false)) {
                $values['avatar_site_id'] = null;
            }
            $client->fill($values);
            $clientChanged = ! $client->exists || $client->isDirty();
            $client->save();
            if ($upload instanceof UploadedFile) {
                $extension = $upload->guessExtension() ?? throw new \LogicException('Validated client logo must have an image extension.');
                $hash = hash('sha256', $upload->getContent());
                $client->addMedia($upload)->usingFileName($hash.'.'.$extension)->toMediaCollection('logo');
            } elseif (($data['remove_logo'] ?? false) || ($choiceChanged && ($data['avatar_site_id'] ?? null) !== null)) {
                $client->clearMediaCollection('logo');
            }
            if ($avatarChanged) {
                $source = $upload instanceof UploadedFile ? 'uploaded' : (($data['avatar_site_id'] ?? null) !== null ? 'chosen-site' : 'automatic');
                activity('audit')->performedOn($client)->event('client.avatar.updated')->withProperties(['source' => $source])->log('client.avatar.updated');
                // Media-only changes do not trigger the client's updated observer.
                if (! $clientChanged) {
                    LiveSignal::forClient($client);
                }
            }

            return $client->refresh();
        });
    }
}
