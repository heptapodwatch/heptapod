<?php

// Runs inside the disposable production app. No test-only production routes.
declare(strict_types=1);

use GuzzleHttp\Client as HttpClient;
use Heptapod\Actions\CreateSite;
use Heptapod\Actions\DisableCheck;
use Heptapod\Actions\FetchSiteIcon;
use Heptapod\Checks\Execution\CheckExecutor;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Client;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
verify(getenv('DB_HOST') === false && getenv('REDIS_HOST') === false, 'Host-style env leaked into URL-only proof');
verify(config('heptapod.browser.enabled') === false, 'Browser configuration not disabled');
$user = User::query()->firstOrCreate(['email' => 'test@example.test'], ['name' => 'Ops acceptance', 'email' => 'test@example.test', 'password' => 'password', 'email_verified_at' => now()]);
$user->forceFill(['email_verified_at' => now()])->save();
$user->assignRole('Owner');
$http = new HttpClient(['base_uri' => 'http://nginx', 'http_errors' => false, 'allow_redirects' => false, 'headers' => ['Host' => 'monitor.example.com:8443']]);
$cookies = [];
$request = function (string $method, string $path, array $options = []) use ($http, &$cookies): ResponseInterface {
    $options['headers']['Host'] = 'monitor.example.com:8443';
    $options['headers']['Cookie'] = implode('; ', $cookies);
    $response = $http->request($method, $path, $options);
    foreach ($response->getHeader('Set-Cookie') as $header) {
        $cookie = explode(';', $header)[0];
        $cookies[explode('=', $cookie)[0]] = $cookie;
    }

    return $response;
};
$response = $request('GET', '/login');
verify($response->getStatusCode() === 200, 'Login page unavailable: '.$response->getStatusCode().' '.$response->getHeaderLine('Location'));
verify(str_contains((string) $response->getBody(), 'https://monitor.example.com:8443'), 'Login page missing public origin');
verify(str_contains((string) $response->getBody(), '<html lang="'.config()->string('app.locale').'"'), 'Guest login without Accept-Language must use the configured locale');
verify(! str_contains((string) $response->getBody(), 'devLogin') && ! str_contains((string) $response->getBody(), 'Sign in as demo'), 'Production login page must not offer the local demo sign-in');
// A separate guest session: the guest redirect stores the intended URL, which would change the login redirect below.
$loginCookies = $cookies;
$cookies = [];
$missingPage = $request('GET', '/missing-ops-page', ['headers' => ['Accept-Language' => 'de-DE,de;q=0.9']]);
$missingBody = (string) $missingPage->getBody();
verify($missingPage->getStatusCode() === 404 && $missingPage->getHeader('Location') === [], 'Missing page must retain raw 404 without redirect');
verify($missingPage->getHeader('Content-Type') === ['text/html; charset=UTF-8'], 'Missing page must be HTML');
verify(str_contains($missingBody, '<html lang="de"') && str_contains($missingBody, 'Heptapod') && str_contains($missingBody, 'Seite nicht gefunden'), 'Guest error page must honor Accept-Language and show localized Heptapod copy');
verify(str_contains($missingBody, 'href="https://monitor.example.com:8443/login"'), 'Guest error recovery must use the canonical HTTPS login origin');
$cookies = ['locale' => 'locale=de'];
$cookieMissing = $request('GET', '/missing-ops-page', ['headers' => ['Accept-Language' => 'en']]);
verify(str_contains((string) $cookieMissing->getBody(), '<html lang="de"'), 'Guest locale cookie must take precedence over Accept-Language');
$cookies = [];
$unmatchedLanguage = $request('GET', '/missing-ops-page', ['headers' => ['Accept-Language' => 'fr-FR,fr;q=0.9']]);
verify(str_contains((string) $unmatchedLanguage->getBody(), '<html lang="'.config()->string('app.locale').'"'), 'Unsupported guest Accept-Language must use the configured locale');
$englishMissing = $request('GET', '/missing-ops-page', ['headers' => ['Accept-Language' => 'en']]);
verify(str_contains((string) $englishMissing->getBody(), '<html lang="en"'), 'An explicit supported guest language must override the configured locale');
$alertsGuest = $request('GET', '/settings/alerts');
verify($alertsGuest->getStatusCode() === 302 && $alertsGuest->getHeader('Location') === ['https://monitor.example.com:8443/login'], 'Alert sensitivity guest redirect must use the canonical HTTPS login origin');
$devLoginPage = $request('GET', '/dev-login');
verify($devLoginPage->getStatusCode() === 404 && $devLoginPage->getHeader('Location') === [], 'Production must not serve /dev-login: '.$devLoginPage->getStatusCode());
$loginLink = $request('POST', '/laravel-login-link-login', ['form_params' => ['email' => $user->email]]);
verify(in_array($loginLink->getStatusCode(), [404, 405], true) && $loginLink->getHeader('Location') === [], 'Production must not serve the login-link route: '.$loginLink->getStatusCode().' '.$loginLink->getHeaderLine('Location'));
$findingsGuest = $request('GET', '/sites/1/checks/1/findings.csv?run=1&observed_at=2026-10-14T00%3A00%3A00Z');
$request('GET', '/login');
$guestXsrf = urldecode(substr($cookies['XSRF-TOKEN'], strlen('XSRF-TOKEN=')));
$restoreGuest = $request('POST', '/clients/1/restore', ['headers' => ['X-XSRF-TOKEN' => $guestXsrf]]);
verify($restoreGuest->getStatusCode() === 302 && $restoreGuest->getHeader('Location') === ['https://monitor.example.com:8443/login'], 'Client restore guest redirect must use the canonical HTTPS login origin');
verify(route('clients.restore', ['client' => 1]) === 'https://monitor.example.com:8443/clients/1/restore', 'Client restore action must use the canonical HTTPS origin');
$cookies = $loginCookies;
verify($findingsGuest->getStatusCode() === 302 && $findingsGuest->getHeaderLine('Location') === 'https://monitor.example.com:8443/login', 'Findings export guest redirect must use the canonical HTTPS login origin');
verify(! str_starts_with($findingsGuest->getHeaderLine('Content-Type'), 'text/csv'), 'Unauthenticated findings export must not disclose CSV');
$findingsApiGuest = $request('GET', '/api/findings?incident_id=1', ['headers' => ['Accept' => 'application/json']]);
verify($findingsApiGuest->getStatusCode() === 401, 'Findings API must require a persistent bearer token');
verify($findingsApiGuest->getHeader('Location') === [] && $findingsApiGuest->getHeader('Content-Type') === ['application/json'], 'Findings API guest response must be raw JSON, not a redirect to an internal HTTP origin');
verify(json_decode((string) $findingsApiGuest->getBody(), true, flags: JSON_THROW_ON_ERROR) === ['error' => 'Unauthenticated.'], 'Findings API guest must not disclose monitoring evidence');
$options = json_decode((string) $request('GET', '/passkeys/login/options', ['headers' => ['Accept' => 'application/json']])->getBody(), true, flags: JSON_THROW_ON_ERROR);
verify(($options['options']['rpId'] ?? null) === 'monitor.example.com', 'WebAuthn relying-party host wrong');
verify(config('fortify.passkeys.allowed_origins') === ['https://monitor.example.com:8443'], 'WebAuthn allowed origin wrong');
$xsrf = urldecode(substr($cookies['XSRF-TOKEN'], strlen('XSRF-TOKEN=')));
$response = $request('POST', '/login', ['headers' => ['X-XSRF-TOKEN' => $xsrf], 'form_params' => ['email' => $user->email, 'password' => 'password']]);
verify($response->getStatusCode() === 302 && $response->getHeaderLine('Location') === 'https://monitor.example.com:8443/sites', 'Login origin wrong: '.$response->getStatusCode().' '.$response->getHeaderLine('Location'));
verify($request('GET', '/sites')->getStatusCode() === 200, 'Authenticated session failed');
$alertsPage = $request('GET', '/settings/alerts');
verify($alertsPage->getStatusCode() === 200 && $alertsPage->getHeader('Location') === [], 'Owner alert sensitivity page must be served without redirect');
verify(str_contains((string) $alertsPage->getBody(), 'https://monitor.example.com:8443'), 'Alert sensitivity page must use the canonical HTTPS origin');
$signedInMissing = $request('GET', '/missing-ops-page');
verify($signedInMissing->getStatusCode() === 404 && $signedInMissing->getHeader('Location') === [], 'Signed-in missing page must retain raw 404 without redirect');
verify(str_contains((string) $signedInMissing->getBody(), 'href="https://monitor.example.com:8443/sites"'), 'Signed-in error recovery must use the canonical HTTPS sites origin');
$legacyAppearance = $request('GET', '/settings/appearance');
verify($legacyAppearance->getStatusCode() === 301 && $legacyAppearance->getHeaderLine('Location') === 'https://monitor.example.com:8443/settings/profile', 'Appearance alias origin wrong: '.$legacyAppearance->getStatusCode().' '.$legacyAppearance->getHeaderLine('Location'));
$legacyDashboard = $request('GET', '/dashboard');
verify($legacyDashboard->getStatusCode() === 301 && $legacyDashboard->getHeaderLine('Location') === '/sites', 'Dashboard alias wrong: '.$legacyDashboard->getStatusCode().' '.$legacyDashboard->getHeaderLine('Location'));
$xsrf = urldecode(substr($cookies['XSRF-TOKEN'], strlen('XSRF-TOKEN=')));
if (in_array('--public-probe-only', $argv, true)) {
    $before = [Site::query()->count(), Check::query()->count(), CheckRun::query()->count(), Incident::query()->count()];
    $response = $request('POST', '/sites/probe', ['headers' => ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $xsrf], 'json' => ['url' => 'https://example.com/']]);
    $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    echo json_encode(['http_status' => $response->getStatusCode(), 'payload' => $payload], JSON_THROW_ON_ERROR)."\n";
    verify($response->getStatusCode() === 200 && $payload['status'] === 200 && $payload['failure'] === null && str_starts_with($payload['final_url'], 'https://example.com/'), 'Public web probe failed');
    verify($before === [Site::query()->count(), Check::query()->count(), CheckRun::query()->count(), Incident::query()->count()], 'Public read-only probe created monitoring data');
    exit;
}
require __DIR__.'/notification-destination-pause.php';
$pauseProof = verifyNotificationDestinationPause($request, $cookies, $user);
echo json_encode(['notification_destination_pause' => $pauseProof], JSON_THROW_ON_ERROR)."\n";
verify($request('GET', '/settings/notifications/team/data', ['headers' => ['Accept' => 'application/json']])->getStatusCode() === 200, 'Owner team channels data unavailable after member proof');
if (in_array('--notification-pause-only', $argv, true)) {
    exit;
}
verify(gethostbyname('outbound.fixture.test') === '11.123.197.2', 'Outbound fixture must resolve through NSS to its isolated local address');
$clientRecord = Client::query()->firstOrCreate(['slug' => 'web-probe-acceptance'], ['name' => 'Web probe acceptance']);
$fixtureUrl = 'http://outbound.fixture.test';
$site = Site::query()->firstOrCreate(['client_id' => $clientRecord->id, 'url' => $fixtureUrl], ['paused_at' => now(), 'badge_id' => (string) Str::uuid()]);
$check = Check::query()->firstOrCreate(['site_id' => $site->id, 'type' => CheckKey::ApplicationHealth], [
    'enabled' => false, 'interval_seconds' => 60, 'next_run_at' => now(),
    'config' => app(CheckRegistry::class)->get(CheckKey::ApplicationHealth)->definition()->configSchema->validateConfig([
        'result_url' => $fixtureUrl.'/application-health/ok', 'secret' => 'fixture-secret',
    ]),
]);
$before = [Site::query()->count(), Check::query()->count(), CheckRun::query()->count()];
$failures = [];
foreach ([
    ['/sites/probe', ['url' => $fixtureUrl.'/alert-quality/public'], ['final_url' => $fixtureUrl.'/alert-quality/public', 'status' => 200, 'server' => 'openresty', 'sitemap_url' => $fixtureUrl.'/sitemap.xml', 'failure' => null]],
    ['/sites/'.$site->id.'/checks/'.$check->id.'/health/probe', ['result_url' => $fixtureUrl.'/application-health/ok', 'secret' => 'fixture-secret'], ['result' => 'ok', 'component_count' => 1]],
] as [$path, $input, $expected]) {
    $response = $request('POST', $path, ['headers' => ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $xsrf], 'json' => $input]);
    $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    echo json_encode(['path' => $path, 'http_status' => $response->getStatusCode(), 'payload' => $payload], JSON_THROW_ON_ERROR)."\n";
    if ($response->getStatusCode() !== 200 || array_intersect_key($payload, $expected) !== $expected) {
        $failures[] = $path;
    }
}
verify($before === [Site::query()->count(), Check::query()->count(), CheckRun::query()->count()], 'Read-only web probes created sites, checks or runs');
verify($failures === [], 'Web outbound probe regression: '.implode(', ', $failures));
echo "PASS: production WEB site and health probes return local fixture facts through native NSS/DNS and SSRF guard\n";
if (in_array('--web-probes-only', $argv, true)) {
    exit;
}
$enablePath = '/sites/'.$site->id.'/checks/'.$check->id.'/enable';
$enableResponse = $request('POST', $enablePath, ['headers' => ['X-XSRF-TOKEN' => $xsrf]]);
verify($enableResponse->getStatusCode() === 302 && $enableResponse->getHeader('Location') === ['https://monitor.example.com:8443/sites/'.$site->id.'/checks/'.$check->id], 'Check enable redirect origin wrong');
verify($check->refresh()->enabled, 'Owner check enable did not persist');
app(DisableCheck::class)($check);
$mismatchResponse = $request('POST', '/sites/'.($site->id + 1).'/checks/'.$check->id.'/enable', ['headers' => ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $xsrf]]);
verify($mismatchResponse->getStatusCode() === 404 && ! $check->refresh()->enabled, 'Mismatched site/check enable was not rejected');
$ownerCookies = $cookies;
$member = User::query()->create(['name' => 'OPS synthetic Member', 'email' => 'ops-check-member-'.bin2hex(random_bytes(6)).'@example.test', 'password' => 'password']);
$member->forceFill(['email_verified_at' => now()])->save();
$member->assignRole('Member');
try {
    $cookies = [];
    verify($request('GET', '/login')->getStatusCode() === 200, 'Check Member login page failed');
    $memberXsrf = urldecode(substr($cookies['XSRF-TOKEN'], strlen('XSRF-TOKEN=')));
    $memberLogin = $request('POST', '/login', ['headers' => ['X-XSRF-TOKEN' => $memberXsrf], 'form_params' => ['email' => $member->email, 'password' => 'password']]);
    verify($memberLogin->getStatusCode() === 302, 'Check Member login failed');
    $memberXsrf = urldecode(substr($cookies['XSRF-TOKEN'], strlen('XSRF-TOKEN=')));
    $forbiddenEnable = $request('POST', $enablePath, ['headers' => ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $memberXsrf]]);
    verify($forbiddenEnable->getStatusCode() === 403 && ! $check->refresh()->enabled, 'Member enabled a check or received a non-403 response');
} finally {
    $member->delete();
    $cookies = $ownerCookies;
}
echo "PASS: Owner check enable persists and redirects to the canonical HTTPS origin; Member 403 and mismatched pair 404 leave it disabled; synthetic Member removed\n";
$originalCa = config('certificate_health.ca_file');
$tlsProofStarted = now()->subSecond();
$tlsProofSite = null;
try {
    config(['certificate_health.ca_file' => '/app/docker/fixtures/tls/ca.crt']);
    $tlsProofSite = DB::transaction(function () use ($clientRecord): Site {
        $created = app(CreateSite::class)($clientRecord->id, ['url' => 'https://outbound.fixture.test/clarity-tls', 'friendly_name' => 'OPS TLS clarity', 'checks' => ['certificate_health', 'uptime']]);
        foreach ($created->checks as $tlsCheck) {
            verify(app(ClaimService::class)->runNow($tlsCheck->id, RunOrigin::System) !== null, 'TLS clarity claim refused');
            $tlsCheck->refresh();
            $definition = app(CheckRegistry::class)->get($tlsCheck->type)->definition();
            app(CheckExecutor::class)->execute($tlsCheck->id, $tlsCheck->claim_token, $definition->timeout);
            $tlsCheck->refresh()->update(['next_run_at' => now()->addDay()]);
        }

        return $created;
    });
    foreach ($tlsProofSite->checks()->get() as $tlsCheck) {
        $tlsPath = '/sites/'.$tlsProofSite->id.'/checks/'.$tlsCheck->id;
        $tlsVersionResponse = $request('GET', $tlsPath, ['headers' => ['X-Inertia' => 'true', 'Accept' => 'application/json']]);
        verify($tlsVersionResponse->getStatusCode() === 409 && $tlsVersionResponse->getHeader('X-Inertia-Location') === ['https://monitor.example.com:8443'.$tlsPath], 'TLS clarity version reload origin wrong');
        $tlsVersion = $tlsVersionResponse->getHeaderLine('X-Inertia-Version');
        verify($tlsVersion !== '', 'TLS clarity version header missing');
        $tlsResponse = $request('GET', $tlsPath, ['headers' => ['X-Inertia' => 'true', 'X-Inertia-Version' => $tlsVersion, 'Accept' => 'application/json']]);
        verify($tlsResponse->getStatusCode() === 200, 'TLS clarity detail unavailable');
        $tlsPage = json_decode((string) $tlsResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
        verify($tlsPage['props']['check']['summary'] === 'causes.tls.name_mismatch_subject', 'TLS clarity reason missing from '.$tlsCheck->type->value);
        verify($tlsPage['props']['check']['summary_params'] === ['host' => 'outbound.fixture.test', 'subject' => 'fixtures.test'], 'TLS clarity certificate facts differ');
        echo json_encode(['tls_clarity' => $tlsCheck->type->value, 'origin' => 'https://monitor.example.com:8443'.$tlsPath, 'summary' => $tlsPage['props']['check']['summary'], 'params' => $tlsPage['props']['check']['summary_params']], JSON_THROW_ON_ERROR)."\n";
    }
} finally {
    config(['certificate_health.ca_file' => $originalCa]);
    if ($tlsProofSite instanceof Site) {
        $tlsCheckIds = $tlsProofSite->checks()->pluck('id');
        CheckRun::query()->whereIn('check_id', $tlsCheckIds)->where('ended_at', '>=', $tlsProofStarted)->delete();
        $tlsProofSite->delete();
    }
}
echo "PASS: native certificate and uptime TLS name mismatch reaches the authenticated public-Host detail with identical subject/host evidence; TLS fixtures removed\n";
$signed = URL::temporarySignedRoute('verification.verify', now()->addMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);
verify(str_starts_with($signed, 'https://monitor.example.com:8443/'), 'Signed URL origin wrong');
$signedResponse = $request('GET', substr($signed, strlen('https://monitor.example.com:8443')));
verify($signedResponse->getStatusCode() === 302, 'Signature invalid behind nginx: '.$signedResponse->getStatusCode());
$mail = new ResetPassword('ops-token')->toMail($user);
verify(str_starts_with($mail->actionUrl, 'https://monitor.example.com:8443/reset-password/ops-token'), 'Mail link origin wrong');
$s3 = Storage::disk();
// Create the disposable bucket using the installed AWS SDK, then write through Laravel.
$client = $s3->getClient();
if (! $client->doesBucketExistV2('heptapod')) {
    $client->createBucket(['Bucket' => 'heptapod']);
}
$s3->put('acceptance/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><text>Heptapod</text></svg>');
verify(str_contains($s3->get('acceptance/logo.svg'), 'Heptapod'), 'S3 persistent write failed');
$icon = app(FetchSiteIcon::class)->normalize(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWMICLgDAAJwAX3gXraMAAAAAElFTkSuQmCC', true));
$iconHash = hash('sha256', $icon);
$s3->put('site-icons/'.$site->id.'/'.$iconHash.'.png', $icon);
$site->forceFill(['icon_hash' => $iconHash])->save();
$iconUrl = route('sites.icon', ['site' => $site->id, 'hash' => $iconHash]);
verify($iconUrl === 'https://monitor.example.com:8443/sites/'.$site->id.'/icon/'.$iconHash, 'Icon origin wrong: '.$iconUrl);
$iconPath = '/sites/'.$site->id.'/icon/'.$iconHash;
$iconResponse = $request('GET', $iconPath);
verify($iconResponse->getStatusCode() === 200 && (string) $iconResponse->getBody() === $icon, 'Icon disk/origin response wrong');
verify($iconResponse->getHeader('Content-Type') === ['image/png'], 'Icon content type wrong');
verify($iconResponse->getHeader('X-Content-Type-Options') === ['nosniff', 'nosniff'], 'App and Caddy icon sniffing headers wrong');
verify($iconResponse->getHeader('Cache-Control') === ['immutable, max-age=31536000, private'], 'Icon cache headers wrong: '.$iconResponse->getHeaderLine('Cache-Control'));
// Guzzle derives Host from base_uri, so the public host must be sent per request, as $request does.
$guestIcon = $http->get($iconPath, ['headers' => ['Host' => 'monitor.example.com:8443']]);
verify($guestIcon->getStatusCode() === 302 && $guestIcon->getHeader('Location') === ['https://monitor.example.com:8443/login'], 'Guest icon access/origin wrong: '.$guestIcon->getStatusCode().' '.$guestIcon->getHeaderLine('Location'));
$pausedRefresh = $request('POST', '/sites/'.$site->id.'/icon', ['headers' => ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $xsrf]]);
verify($pausedRefresh->getStatusCode() === 422, 'Paused site icon refresh not refused');
echo "PASS: same-origin PNG icon served from configured S3 disk with raw private immutable headers; guest and paused refresh refused\n";
$clientRecord = Client::query()->firstOrCreate(['slug' => 'ops-acceptance'], ['name' => 'Ops acceptance']);
$logo = $clientRecord->addMediaFromString($icon)->usingFileName($iconHash.'.png')->toMediaCollection('logo');
$avatar = Storage::disk($logo->conversions_disk)->get($logo->getPathRelativeToRoot('avatar'));
$avatarHash = $logo->fresh()->getCustomProperty('avatar_hash');
$avatarPath = '/clients/'.$clientRecord->id.'/logo/'.$avatarHash;
verify(route('clients.logo', ['client' => $clientRecord->id, 'hash' => $avatarHash]) === 'https://monitor.example.com:8443'.$avatarPath, 'Client avatar canonical origin wrong');
$avatarResponse = $request('GET', $avatarPath);
verify($avatarResponse->getStatusCode() === 200 && (string) $avatarResponse->getBody() === $avatar, 'Client avatar conversion response wrong');
verify(getimagesizefromstring((string) $avatar)[0] === 128 && getimagesizefromstring((string) $avatar)[1] === 128, 'Client avatar dimensions wrong');
verify($avatarResponse->getHeader('Content-Type') === ['image/png'], 'Client avatar content type wrong');
verify($avatarResponse->getHeader('Cache-Control') === ['immutable, max-age=31536000, private'], 'Client avatar raw cache headers wrong');
verify($avatarResponse->getHeader('X-Content-Type-Options') === ['nosniff', 'nosniff'], 'Client avatar raw sniffing headers wrong');
$guestAvatar = $http->get($avatarPath, ['headers' => ['Host' => 'monitor.example.com:8443']]);
verify($guestAvatar->getStatusCode() === 302 && $guestAvatar->getHeader('Location') === ['https://monitor.example.com:8443/login'], 'Guest client avatar access/origin wrong');
verify($request('GET', '/clients/'.$clientRecord->id.'/logo/'.str_repeat('0', 64))->getStatusCode() === 404, 'Stale client avatar hash not refused');
echo "PASS: normalized client PNG avatar served from S3 with canonical HTTPS origin and raw private immutable headers; guest and stale hash refused\n";
// example.com is a real public target; production SSRF policy remains unchanged.
$site = app(CreateSite::class)($clientRecord->id, ['url' => 'https://example.com', 'friendly_name' => 'Ops acceptance', 'checks' => ['uptime']]);
$deadline = time() + 120;
while (! CheckRun::query()->where('check_id', $site->checks()->sole()->id)->exists() && time() < $deadline) {
    usleep(250_000);
}
$run = CheckRun::query()->where('check_id', $site->checks()->sole()->id)->latest('id')->first();
verify($run !== null && $run->result === RunResult::Succeeded, 'Dispatched uptime check did not succeed');
echo "PASS: nginx login/session, WebAuthn rpId, signed URL, mail host, URL-only DB/Redis, browser off, readonly S3 write, real dispatched uptime check\n";
