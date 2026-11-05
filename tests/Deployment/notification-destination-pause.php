<?php

// Shared by the disposable production OPS gate and the owned-stack manual proof.
declare(strict_types=1);

use Heptapod\Actions\CreateEmailDestination;
use Heptapod\Models\User;
use Psr\Http\Message\ResponseInterface;
use Spatie\Activitylog\Models\Activity;

/** @param Closure(string, string, array): ResponseInterface $request
 * @param  array<string, string>  $cookies
 * @return array<string, mixed>
 */
function verifyNotificationDestinationPause(Closure $request, array &$cookies, User $owner): array
{
    $destination = app(CreateEmailDestination::class)(['address' => 'test@example.test', 'label' => 'OPS destination pause', 'locale' => 'en']);
    $path = '/notification-channels/'.$destination->id.'/enabled';
    $xsrf = static function () use (&$cookies): string {
        return urldecode(substr($cookies['XSRF-TOKEN'], strlen('XSRF-TOKEN=')));
    };
    $pause = $request('PATCH', $path, ['headers' => ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $xsrf()], 'json' => ['enabled' => false]]);
    verify($pause->getStatusCode() === 200, 'Owner destination pause failed: '.$pause->getStatusCode());
    $paused = json_decode((string) $pause->getBody(), true, flags: JSON_THROW_ON_ERROR);
    verify($paused['enabled'] === false && $paused['suppressed_count'] === 0, 'Destination pause response is not canonical');
    $audit = Activity::query()->where('subject_type', $destination->getMorphClass())->where('subject_id', $destination->id)->where('event', 'updated')->latest('id')->firstOrFail();
    $changes = $audit->attribute_changes?->toArray() ?? [];
    verify($audit->causer_id === $owner->id && ($changes['old']['enabled'] ?? null) === true && ($changes['attributes']['enabled'] ?? null) === false, 'Destination pause audit lacks actor/old/new');
    verify(($audit->properties['suppressed_delivery_count'] ?? null) === 0, 'Destination pause audit lacks discarded count');
    $resume = $request('PATCH', $path, ['headers' => ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $xsrf()], 'json' => ['enabled' => true]]);
    verify($resume->getStatusCode() === 200 && $destination->refresh()->enabled, 'Owner destination resume failed');
    $ownerCookies = $cookies;
    $member = User::query()->create(['name' => 'OPS synthetic Member', 'email' => 'ops-member-'.bin2hex(random_bytes(6)).'@example.test', 'password' => 'password', 'email_verified_at' => now()]);
    $member->forceFill(['email_verified_at' => now()])->save();
    $member->assignRole('Member');
    try {
        $cookies = [];
        verify($request('GET', '/login')->getStatusCode() === 200, 'Member login page failed');
        $login = $request('POST', '/login', ['headers' => ['X-XSRF-TOKEN' => $xsrf()], 'form_params' => ['email' => $member->email, 'password' => 'password']]);
        verify($login->getStatusCode() === 302, 'Member login failed');
        $forbidden = $request('PATCH', $path, ['headers' => ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $xsrf()], 'json' => ['enabled' => false]]);
        $memberPage = $request('GET', '/settings/notifications/team/data', ['headers' => ['Accept' => 'application/json']]);
        verify($forbidden->getStatusCode() === 403, 'Member can change destination enabled flag');
        verify($memberPage->getStatusCode() === 403 && ! str_contains((string) $memberPage->getBody(), 'OPS destination pause'), 'Member team channels data is exposed: status '.$memberPage->getStatusCode().' location '.$memberPage->getHeaderLine('Location'));
        verify($destination->refresh()->enabled && $owner->fresh()->hasRole('Owner'), 'Authorization proof altered owner or destination');
    } finally {
        $member->delete();
        $cookies = $ownerCookies;
    }

    return ['pause_status' => $pause->getStatusCode(), 'resume_status' => $resume->getStatusCode(), 'member_patch_status' => $forbidden->getStatusCode(), 'member_page_status' => $memberPage->getStatusCode(), 'audit_actor' => $audit->causer_id, 'audit_changes' => $changes, 'owner_intact' => true, 'member_removed' => true];
}
