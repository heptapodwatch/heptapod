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
use Heptapod\Settings\InstanceSettings;
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
$health = $request('GET', '/up');
verify($health->getStatusCode() === 200 && $health->getHeader('Location') === [], 'Health endpoint unavailable: '.$health->getStatusCode().' '.$health->getHeaderLine('Location'));
verify($health->getHeader('Content-Type') === ['text/plain; charset=UTF-8'], 'Health endpoint Content-Type wrong: '.$health->getHeaderLine('Content-Type'));
verify(str_contains($health->getHeaderLine('Cache-Control'), 'no-store'), 'Health endpoint must not be cacheable: '.$health->getHeaderLine('Cache-Control'));
verify($health->getHeaderLine('Content-Security-Policy') === "default-src 'none'", 'Health endpoint CSP wrong: '.$health->getHeaderLine('Content-Security-Policy'));
verify((string) $health->getBody() === 'OK', 'Health endpoint must return plain OK body');
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
$emailGuest = $request('GET', '/settings/email');
verify($emailGuest->getStatusCode() === 302 && $emailGuest->getHeader('Location') === ['https://monitor.example.com:8443/login'], 'Email settings guest redirect must use the canonical HTTPS login origin');
// Servers are allowed by this stack's environment (ops.yaml) but switched off until an Owner turns them on: nothing exists yet.
foreach ([['GET', '/servers'], ['GET', '/servers/1'], ['POST', '/agent/v1/report']] as [$method, $path]) {
    $serversGuest = $request($method, $path);
    verify($serversGuest->getStatusCode() === 404 && $serversGuest->getHeader('Location') === [], "Switched-off servers: guest {$method} {$path} must answer a raw 404: ".$serversGuest->getStatusCode());
}
// The servers API, as a client without cookies sees it: switched off, every method answers before routing exactly like an
// unknown API path, so the addon cannot be detected. Compared: the status, the body (its own path written as {path}) and every
// header with its values. Values that differ between any two requests (the API entry throttle's remaining count, Content-Length
// with the path's length) are checked on their own; Date and the body framing are left out. Every api/* request counts against the 60-per-minute
// API entry throttle of this one client address, so each method's unknown-path answer is read once per round.
$apiCall = fn (string $method, string $path, array $headers = []): ResponseInterface => $http->request($method, $path,
    ['headers' => ['Host' => 'monitor.example.com:8443', 'Accept' => 'application/json', ...$headers]]);
$apiSeen = static function (ResponseInterface $response, string $path): array {
    $body = (string) $response->getBody();
    $headers = array_change_key_case($response->getHeaders());
    $limit = $headers['x-ratelimit-limit'][0] ?? '';
    $remaining = $headers['x-ratelimit-remaining'][0] ?? '';
    verify($limit === '60' && ctype_digit($remaining) && (int) $remaining < 60 && ($headers['content-length'] ?? [(string) strlen($body)]) === [(string) strlen($body)]
        && ! isset($headers['location']), "API {$path}: throttle headers, Content-Length or a redirect wrong: {$limit}/{$remaining} ".($headers['content-length'][0] ?? '-'));
    // Date, the body framing (Content-Length or chunked) and the remaining count belong to the request, not the route: checked above or not at all.
    $stable = array_diff_key($headers, ['date' => true, 'content-length' => true, 'transfer-encoding' => true, 'x-ratelimit-remaining' => true]);
    ksort($stable);

    return [$response->getStatusCode(), $stable, str_replace(ltrim($path, '/'), '{path}', $body)];
};
$serverApiOperations = [['GET', '/api/servers'], ['GET', '/api/servers/1'], ['GET', '/api/servers/1/issues'], ['POST', '/api/servers/1/snooze'], ['POST', '/api/servers/1/unsnooze']];
$serverApiSwitchedOff = function (string $when) use ($apiCall, $apiSeen, $serverApiOperations): void {
    $bearer = ['Authorization' => 'Bearer 1|not-a-token'];
    // Every operation as a guest, OPTIONS and two methods no server route takes (which the router would answer with Allow),
    // and one with a bearer token, which must not reach authentication either.
    $requests = [...array_map(static fn (array $operation): array => [...$operation, []], $serverApiOperations),
        ['OPTIONS', '/api/servers', []], ['PUT', '/api/servers/1', []], ['DELETE', '/api/servers/1/snooze', []], ['GET', '/api/servers', $bearer]];
    $unknown = [];
    foreach ($requests as [$method, $path, $headers]) {
        $seen = $apiSeen($apiCall($method, $path, $headers), $path);
        $key = $method.($headers === [] ? '' : ' bearer');
        $unknown[$key] ??= $apiSeen($apiCall($method, '/api/no-such-ops-route', $headers), '/api/no-such-ops-route');
        verify($seen[0] === 404 && $seen === $unknown[$key],
            "Switched-off servers API ({$when}): {$key} {$path} must answer exactly like an unknown API path: ".$seen[0].' '.implode(',', array_keys($seen[1])));
    }
};
$serverApiSwitchedOff('before first use');
$devLoginPage = $request('GET', '/dev-login');
verify($devLoginPage->getStatusCode() === 404 && $devLoginPage->getHeader('Location') === [], 'Production must not serve /dev-login: '.$devLoginPage->getStatusCode());
$loginLink = $request('POST', '/laravel-login-link-login', ['form_params' => ['email' => $user->email]]);
verify(in_array($loginLink->getStatusCode(), [404, 405], true) && $loginLink->getHeader('Location') === [], 'Production must not serve the login-link route: '.$loginLink->getStatusCode().' '.$loginLink->getHeaderLine('Location'));
$findingsGuest = $request('GET', '/sites/1/checks/1/findings.csv?run=1&observed_at=2026-10-14T00%3A00%3A00Z');
// Every export route takes a {format}; guests get the canonical login redirect and no export body for each one.
$exportGuests = [
    'findings Markdown' => $request('GET', '/sites/1/checks/1/findings.md?run=1&observed_at=2026-10-14T00%3A00%3A00Z'),
    'incidents CSV' => $request('GET', '/incidents/export.csv'),
    'client message attachment' => $request('GET', '/clients/1/client-messages/1/attachment.txt'),
];
$request('GET', '/login');
$guestXsrf = urldecode(substr($cookies['XSRF-TOKEN'], strlen('XSRF-TOKEN=')));
$restoreGuest = $request('POST', '/clients/1/restore', ['headers' => ['X-XSRF-TOKEN' => $guestXsrf]]);
verify($restoreGuest->getStatusCode() === 302 && $restoreGuest->getHeader('Location') === ['https://monitor.example.com:8443/login'], 'Client restore guest redirect must use the canonical HTTPS login origin');
verify(route('clients.restore', ['client' => 1]) === 'https://monitor.example.com:8443/clients/1/restore', 'Client restore action must use the canonical HTTPS origin');
$moveBlockersGuest = $request('GET', '/clients/1/move-blockers');
verify($moveBlockersGuest->getStatusCode() === 302 && $moveBlockersGuest->getHeader('Location') === ['https://monitor.example.com:8443/login'], 'Client move check guest redirect must use the canonical HTTPS login origin');
$cookies = $loginCookies;
verify($findingsGuest->getStatusCode() === 302 && $findingsGuest->getHeaderLine('Location') === 'https://monitor.example.com:8443/login', 'Findings export guest redirect must use the canonical HTTPS login origin');
verify(! str_starts_with($findingsGuest->getHeaderLine('Content-Type'), 'text/csv'), 'Unauthenticated findings export must not disclose CSV');
foreach ($exportGuests as $name => $exportGuest) {
    verify($exportGuest->getStatusCode() === 302 && $exportGuest->getHeader('Location') === ['https://monitor.example.com:8443/login'], ucfirst($name).' guest redirect must use the canonical HTTPS login origin');
    verify(preg_match('#^text/(csv|markdown|plain)#', $exportGuest->getHeaderLine('Content-Type')) !== 1, 'Unauthenticated '.$name.' export must not disclose an export body');
}
$findingsApiGuest = $request('GET', '/api/findings?incident_id=1', ['headers' => ['Accept' => 'application/json']]);
verify($findingsApiGuest->getStatusCode() === 401, 'Findings API must require a persistent bearer token');
verify($findingsApiGuest->getHeader('Location') === [] && $findingsApiGuest->getHeader('Content-Type') === ['application/json'], 'Findings API guest response must be raw JSON, not a redirect to an internal HTTP origin');
verify(json_decode((string) $findingsApiGuest->getBody(), true, flags: JSON_THROW_ON_ERROR) === ['error' => 'Unauthenticated.'], 'Findings API guest must not disclose monitoring evidence');
// The client API, writes included, answers a guest as raw JSON like the findings API: a bearer token is the only credential.
foreach ([['GET', '/api/clients'], ['GET', '/api/clients/1'], ['POST', '/api/clients'], ['PUT', '/api/clients/1'], ['POST', '/api/clients/1/archive'], ['POST', '/api/clients/1/restore']] as [$method, $path]) {
    $clientApiGuest = $request($method, $path, ['headers' => ['Accept' => 'application/json']]);
    verify($clientApiGuest->getStatusCode() === 401 && $clientApiGuest->getHeader('Location') === [] && $clientApiGuest->getHeader('Content-Type') === ['application/json']
        && json_decode((string) $clientApiGuest->getBody(), true, flags: JSON_THROW_ON_ERROR) === ['error' => 'Unauthenticated.'], "{$method} {$path} must answer a raw 401 JSON without a bearer token, not a redirect to an internal HTTP origin");
}
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
$emailPage = $request('GET', '/settings/email');
verify($emailPage->getStatusCode() === 200 && $emailPage->getHeader('Location') === [], 'Owner email settings page must be served without redirect');
verify(str_contains((string) $emailPage->getBody(), 'https://monitor.example.com:8443'), 'Email settings page must use the canonical HTTPS origin');
$emailPreview = $request('GET', '/settings/email/preview?kind=alert&design=branded', ['headers' => ['Accept' => 'application/json']]);
verify($emailPreview->getStatusCode() === 200 && $emailPreview->getHeader('Location') === [] && $emailPreview->getHeader('Content-Type') === ['application/json'], 'Email preview must answer raw JSON without redirect');
verify(str_contains(json_decode((string) $emailPreview->getBody(), true, flags: JSON_THROW_ON_ERROR)['html'] ?? '', 'data:image/png;base64,'), 'Email preview must inline the mail images');
$emailXsrf = urldecode(substr($cookies['XSRF-TOKEN'], strlen('XSRF-TOKEN=')));
$emailSave = $request('POST', '/settings/email', ['headers' => ['X-XSRF-TOKEN' => $emailXsrf], 'form_params' => ['_method' => 'PATCH', 'mail_design' => 'branded']]);
verify($emailSave->getStatusCode() === 302 && $emailSave->getHeader('Location') === ['https://monitor.example.com:8443/settings/email'], 'Email settings save must redirect to the canonical HTTPS settings origin: '.$emailSave->getStatusCode().' '.$emailSave->getHeaderLine('Location'));
$signedInMissing = $request('GET', '/missing-ops-page');
verify($signedInMissing->getStatusCode() === 404 && $signedInMissing->getHeader('Location') === [], 'Signed-in missing page must retain raw 404 without redirect');
$signedInMissingBody = (string) $signedInMissing->getBody();
verify(preg_match('#<script\b[^>]*\bdata-page="app"[^>]*>(.*?)</script>#s', $signedInMissingBody, $signedInMissingMatch) === 1, 'Signed-in missing page must embed Inertia page data');
$signedInMissingPage = json_decode(html_entity_decode($signedInMissingMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, flags: JSON_THROW_ON_ERROR);
verify(($signedInMissingPage['component'] ?? null) === 'errors/show' && ($signedInMissingPage['props']['status'] ?? null) === 404, 'Signed-in missing page must render the in-shell Inertia 404 component');
verify(($signedInMissingPage['props']['recovery_url'] ?? null) === 'https://monitor.example.com:8443/sites', 'Signed-in error recovery must use the canonical HTTPS sites origin');
verify(($signedInMissingPage['props']['secondary_url'] ?? null) === 'https://monitor.example.com:8443/incidents', 'Signed-in error secondary recovery must use the canonical HTTPS incidents origin');
$legacyAppearance = $request('GET', '/settings/appearance');
verify($legacyAppearance->getStatusCode() === 301 && $legacyAppearance->getHeaderLine('Location') === 'https://monitor.example.com:8443/settings/profile', 'Appearance alias origin wrong: '.$legacyAppearance->getStatusCode().' '.$legacyAppearance->getHeaderLine('Location'));
$legacyDashboard = $request('GET', '/dashboard');
verify($legacyDashboard->getStatusCode() === 301 && $legacyDashboard->getHeaderLine('Location') === '/sites', 'Dashboard alias wrong: '.$legacyDashboard->getStatusCode().' '.$legacyDashboard->getHeaderLine('Location'));
foreach (['csv' => 'text/csv; charset=UTF-8', 'md' => 'text/markdown; charset=UTF-8'] as $format => $contentType) {
    $export = $request('GET', '/incidents/export.'.$format.'?filter%5Bstatus%5D=all');
    verify($export->getStatusCode() === 200 && $export->getHeader('Location') === [], 'Owner incidents '.$format.' export must be served without redirect: '.$export->getStatusCode());
    verify($export->getHeader('Content-Type') === [$contentType], 'Incidents '.$format.' export Content-Type wrong: '.$export->getHeaderLine('Content-Type'));
    verify(str_contains($export->getHeaderLine('Cache-Control'), 'no-store') && str_contains($export->getHeaderLine('Cache-Control'), 'private'), 'Incidents '.$format.' export must not be cached: '.$export->getHeaderLine('Cache-Control'));
    verify(preg_match('#^attachment; filename=incidents-\d{4}-\d{2}-\d{2}\.'.$format.'$#', $export->getHeaderLine('Content-Disposition')) === 1, 'Incidents '.$format.' export must download as a dated file: '.$export->getHeaderLine('Content-Disposition'));
    verify($format !== 'csv' || str_starts_with((string) $export->getBody(), "\xEF\xBB\xBFID,"), 'Incidents CSV must start with the byte order mark and its header row');
}
$xsrf = urldecode(substr($cookies['XSRF-TOKEN'], strlen('XSRF-TOKEN=')));
$serversSwitch = function (bool $on) use ($request, &$cookies): void {
    $response = $request('PATCH', '/settings/instance', ['headers' => ['X-XSRF-TOKEN' => urldecode(substr($cookies['XSRF-TOKEN'], strlen('XSRF-TOKEN=')))],
        'json' => ['name' => app(InstanceSettings::class)->name, 'servers_enabled' => $on]]);
    verify($response->getStatusCode() === 302 && $response->getHeader('Location') === ['https://monitor.example.com:8443/settings/instance'], 'Servers switch must save and redirect to the canonical HTTPS settings origin: '.$response->getStatusCode().' '.$response->getHeaderLine('Location'));
};
// As the agent sends it: a JSON body and the bearer token, no Accept header.
$serverReport = fn (?string $token): ResponseInterface => $request('POST', '/agent/v1/report', ['headers' => $token === null ? [] : ['Authorization' => 'Bearer '.$token],
    'json' => ['sample_id' => (string) Str::uuid(), 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'agent' => ['version' => '0.1.0', 'os' => 'linux', 'arch' => 'amd64', 'distro' => 'Debian 13', 'kernel' => '6.12.0-1-amd64'],
        'host' => ['hostname' => 'ops-server', 'uptime_seconds' => 3600, 'reboot_required' => null, 'ips' => ['192.0.2.10']],
        'cpu' => ['cores' => 2, 'used_percent' => 12.5], 'load' => ['1' => 0.4, '5' => 0.3, '15' => 0.2],
        'memory' => ['total_bytes' => 4294967296, 'available_bytes' => 2147483648],
        'filesystems' => [['mount' => '/', 'total_bytes' => 42949672960, 'free_bytes' => 21474836480]], 'updates' => ['pending' => null, 'security' => null]]]);
$agentError = fn (ResponseInterface $response, int $status, string $error): bool => $response->getStatusCode() === $status && $response->getHeader('Location') === []
    && $response->getHeader('Content-Type') === ['application/json'] && json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR) === ['error' => $error];
verify($request('GET', '/servers')->getStatusCode() === 404 && $agentError($serverReport(null), 404, 'not_found'), 'Switched-off servers must answer 404 to the Owner and the agent');
verify($agentError($request('OPTIONS', '/agent/v1/report'), 404, 'not_found') && $agentError($request('PUT', '/agent/v1/report'), 404, 'not_found'), 'Switched-off agent endpoint must answer every method with the JSON 404');
$serversSwitch(true);
// Switched on, the servers API answers like every other API route: a raw JSON 401 without a bearer token.
foreach ($serverApiOperations as [$method, $path]) {
    $serverApiGuest = $apiCall($method, $path);
    verify($serverApiGuest->getStatusCode() === 401 && $serverApiGuest->getHeader('Location') === [] && $serverApiGuest->getHeader('Content-Type') === ['application/json']
        && json_decode((string) $serverApiGuest->getBody(), true, flags: JSON_THROW_ON_ERROR) === ['error' => 'Unauthenticated.'], "{$method} {$path} must answer a raw 401 JSON without a bearer token once servers are on: ".$serverApiGuest->getStatusCode());
}
$openApiOn = $apiCall('GET', '/api/openapi.json');
verify(str_contains((string) $openApiOn->getBody(), '"/servers/{server}/snooze"') && str_contains($openApiOn->getHeaderLine('Cache-Control'), 'no-cache'),
    'Switched on, the served OpenAPI document must list the servers API and be revalidated: '.$openApiOn->getHeaderLine('Cache-Control'));
$serversPage = $request('GET', '/servers');
verify($serversPage->getStatusCode() === 200 && str_contains((string) $serversPage->getBody(), 'https://monitor.example.com:8443'), 'Owner servers page must be served on the canonical HTTPS origin once switched on');
$addServer = $request('POST', '/servers', ['headers' => ['X-XSRF-TOKEN' => urldecode(substr($cookies['XSRF-TOKEN'], strlen('XSRF-TOKEN=')))], 'form_params' => ['name' => 'ops-server']]);
verify($addServer->getStatusCode() === 302 && preg_match('#^https://monitor\.example\.com:8443/servers/(\d+)$#', $addServer->getHeaderLine('Location'), $addedServer) === 1, 'Add server must redirect to the new server on the canonical HTTPS origin: '.$addServer->getHeaderLine('Location'));
$serverPage = $request('GET', '/servers/'.$addedServer[1]);
verify(preg_match('#<script\b[^>]*\bdata-page="app"[^>]*>(.*?)</script>#s', (string) $serverPage->getBody(), $serverPageMatch) === 1, 'Server page must embed Inertia page data');
$serverToken = json_decode(html_entity_decode($serverPageMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, flags: JSON_THROW_ON_ERROR)['flash']['server_token'] ?? '';
verify(preg_match('/^hpa_[0-9a-z]{26}_[A-Za-z0-9_-]{43}$/', $serverToken) === 1, 'The new server page must show its agent token once');
verify($agentError($serverReport(null), 401, 'unauthorized'), 'Agent report without a token must answer the raw JSON 401');
// Above 16 KiB the image's Caddy answers 413 with an empty body before PHP reads it; PHP's own 413 would be JSON.
// /agent is reserved for agent traffic; any path below it (a trailing slash too, which Laravel still routes) is capped.
foreach (['/agent/v1/report', '/agent/v1/report/', '/agent'] as $path) {
    $oversize = $request('POST', $path, ['headers' => ['Authorization' => 'Bearer '.$serverToken, 'Content-Type' => 'application/json'],
        'body' => json_encode(['padding' => str_repeat('x', 16 * 1024)], JSON_THROW_ON_ERROR)]);
    verify($oversize->getStatusCode() === 413 && (string) $oversize->getBody() === '' && $oversize->getHeader('Content-Type') === [], 'An oversize agent report must be refused by the web server before PHP: '.$path.' '.$oversize->getStatusCode());
}
$acceptedReport = $serverReport($serverToken);
verify($acceptedReport->getStatusCode() === 202 && json_decode((string) $acceptedReport->getBody(), true, flags: JSON_THROW_ON_ERROR) === ['interval_seconds' => 60], 'Agent report through nginx must be accepted: '.$acceptedReport->getStatusCode().' '.$acceptedReport->getBody());
$tooSoon = $serverReport($serverToken);
verify($agentError($tooSoon, 429, 'too_many_reports') && (int) $tooSoon->getHeaderLine('Retry-After') > 0, 'A second report within 20 s must answer the JSON 429 with Retry-After: '.$tooSoon->getStatusCode());
$serversSwitch(false);
verify($request('GET', '/servers/'.$addedServer[1])->getStatusCode() === 404 && $agentError($serverReport($serverToken), 404, 'not_found'), 'Switched-off servers must answer 404 again');
verify(DB::table('server_samples')->where('server_id', (int) $addedServer[1])->count() === 1, 'Switching servers off must keep their data');
$serverApiSwitchedOff('after use');
verify(! str_contains((string) $apiCall('GET', '/api/openapi.json')->getBody(), '/servers'), 'Switched off, the served OpenAPI document must not list the servers API');
echo "PASS: servers switched off answer 404s (agent JSON for every method, the API like an unknown API path); switched on, the API's raw 401, Add server, the shown-once token, the agent report, its JSON 401/429 and the web server's 413 before PHP work through nginx on the canonical HTTPS origin; off again keeps the data\n";
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
