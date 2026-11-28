<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Addons\Cron\CronDefinitions;
use Heptapod\Addons\Servers\Server;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunPhase;
use Heptapod\Enums\ClientScope;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Client;
use Heptapod\Models\Event;
use Heptapod\Models\ImportRun;
use Heptapod\Models\Incident;
use Heptapod\Models\Invitation;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\RecurringMaintenancePeriod;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who may reach which route: every authenticated web, settings and API route, each called once by each principal
 * against one shared fixture, answers the pinned status. Principals: Owner, Admin, a restricted Member assigned to the
 * fixture client, a restricted Member assigned to no client ("outsider"), an account without a role, a guest, and on the API an Owner's '*' token
 * limited to another client. Web principals sign in; API principals send a '*' token, so only roles and reach decide.
 * Requests carry no body, so an allowed write answers its validation error and a denied one 401/403/404, except where
 * the controller validates before it authorizes: there routeAccessBody() sends the input the access decision needs.
 */
const RouteAccessWebPrincipals = ['owner', 'admin', 'member', 'outsider', 'unroled', 'guest'];

const RouteAccessApiPrincipals = ['owner', 'admin', 'member', 'outsider', 'unroled', 'limited', 'guest'];

/** @var array<string, array<string, int>> */
const RouteAccess = [
    'DELETE api/cron-checks/{cronTask}' => ['owner' => 204, 'admin' => 204, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'DELETE api/internal/incidents/{incident}/snooze' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 403, 'limited' => 404, 'guest' => 401],
    'DELETE api/maintenance-periods/{maintenancePeriod}' => ['owner' => 204, 'admin' => 204, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'limited' => 404, 'guest' => 401],
    'DELETE api/monitors/{monitor}' => ['owner' => 204, 'admin' => 204, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'DELETE incidents/{incident}/snooze' => ['owner' => 302, 'admin' => 302, 'member' => 302, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'DELETE maintenance/series/{series}' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'DELETE maintenance/{window}' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'DELETE notification-channels/{channel}' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'DELETE notification-channels/{channel}/site-mutes/{site}' => ['owner' => 204, 'admin' => 204, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'DELETE servers/{server}' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'DELETE servers/{server}/sites/{site}' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'DELETE servers/{server}/snooze' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'DELETE settings/api-tokens/{token}' => ['owner' => 204, 'admin' => 204, 'member' => 204, 'outsider' => 204, 'unroled' => 204, 'guest' => 401],
    'DELETE settings/members/invitations/{invitation}' => ['owner' => 204, 'admin' => 204, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'DELETE settings/members/{member}' => ['owner' => 204, 'admin' => 204, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'DELETE settings/notifications/team/migration-report' => ['owner' => 204, 'admin' => 403, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'DELETE settings/profile' => ['owner' => 422, 'admin' => 422, 'member' => 422, 'outsider' => 422, 'unroled' => 422, 'guest' => 401],
    'DELETE sites/{site}' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'DELETE sites/{site}/checks/{check}/snooze' => ['owner' => 302, 'admin' => 302, 'member' => 302, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'GET /' => ['owner' => 301, 'admin' => 301, 'member' => 301, 'outsider' => 301, 'unroled' => 301, 'guest' => 401],
    'GET activity' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'guest' => 401],
    'GET api/activity' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'limited' => 200, 'guest' => 401],
    'GET api/clients' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'limited' => 200, 'guest' => 401],
    'GET api/clients/{client}' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 403, 'limited' => 404, 'guest' => 401],
    'GET api/findings' => ['owner' => 422, 'admin' => 422, 'member' => 422, 'outsider' => 422, 'unroled' => 422, 'limited' => 422, 'guest' => 401],
    'GET api/incidents' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'limited' => 200, 'guest' => 401],
    'GET api/incidents/{incident}' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 403, 'limited' => 404, 'guest' => 401],
    'GET api/internal/incidents/{incident}/note' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 403, 'limited' => 404, 'guest' => 401],
    'GET api/me' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 200, 'limited' => 200, 'guest' => 401],
    'GET api/monitors' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'limited' => 200, 'guest' => 401],
    'GET api/monitors/url/{url}' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'GET api/monitors/{monitor}' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'GET api/monitors/{monitor}/check-summary/{checkType}' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'GET api/monitors/{monitor}/checks/{checkType}/runs' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'GET api/monitors/{monitor}/cron-checks' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'GET api/monitors/{monitor}/downtime' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'GET api/monitors/{monitor}/http-uptime-metrics' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'GET api/monitors/{monitor}/maintenance-periods' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'GET api/monitors/{monitor}/uptime' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'GET api/runs/{attemptRef}' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'GET api/servers' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'limited' => 403, 'guest' => 401],
    'GET api/servers/{server}' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'limited' => 403, 'guest' => 401],
    'GET api/servers/{server}/issues' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'limited' => 403, 'guest' => 401],
    'GET api/tags' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'limited' => 200, 'guest' => 401],
    'GET api/upcoming' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'limited' => 200, 'guest' => 401],
    'GET clients' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'guest' => 401],
    'GET clients/create' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET clients/{client}' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'GET clients/{client}/client-messages' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'GET clients/{client}/client-messages/{message}' => ['owner' => 404, 'admin' => 404, 'member' => 404, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'GET clients/{client}/client-messages/{message}/attachment.{format}' => ['owner' => 404, 'admin' => 404, 'member' => 404, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'GET clients/{client}/logo/{hash}' => ['owner' => 404, 'admin' => 404, 'member' => 404, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'GET clients/{client}/move-blockers' => ['owner' => 200, 'admin' => 200, 'member' => 404, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'GET clients/{client}/notifications' => ['owner' => 200, 'admin' => 200, 'member' => 404, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'GET clients/{client}/notifications/data' => ['owner' => 200, 'admin' => 200, 'member' => 404, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'GET clients/{client}/settings' => ['owner' => 200, 'admin' => 200, 'member' => 404, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'GET dashboard' => ['owner' => 301, 'admin' => 301, 'member' => 301, 'outsider' => 301, 'unroled' => 301, 'guest' => 401],
    'GET incidents' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'guest' => 401],
    'GET incidents/export.{format}' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'guest' => 401],
    'GET incidents/{incident}' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'GET incidents/{incident}/alerts/{event}' => ['owner' => 404, 'admin' => 404, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET live/changes' => ['owner' => 422, 'admin' => 422, 'member' => 422, 'outsider' => 422, 'unroled' => 422, 'guest' => 401],
    'GET maintenance' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'guest' => 401],
    'GET notifications/explain' => ['owner' => 422, 'admin' => 422, 'member' => 422, 'outsider' => 422, 'unroled' => 422, 'guest' => 401],
    'GET runs' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'guest' => 401],
    'GET search' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'guest' => 401],
    'GET servers' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET servers/{server}' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET servers/{server}/sites' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings' => ['owner' => 302, 'admin' => 302, 'member' => 302, 'outsider' => 302, 'unroled' => 302, 'guest' => 401],
    'GET settings/alerts' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/api-tokens' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 200, 'guest' => 401],
    'GET settings/api-tokens/data' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 200, 'guest' => 401],
    'GET settings/appearance' => ['owner' => 301, 'admin' => 301, 'member' => 301, 'outsider' => 301, 'unroled' => 301, 'guest' => 401],
    'GET settings/backups/data' => ['owner' => 200, 'admin' => 403, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/email' => ['owner' => 200, 'admin' => 403, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/email/logo/{variant}/{file}' => ['owner' => 404, 'admin' => 403, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/email/preview' => ['owner' => 422, 'admin' => 403, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/import/ohdear' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/import/ohdear/runs/{run}' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/instance' => ['owner' => 200, 'admin' => 403, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/instance/data' => ['owner' => 200, 'admin' => 403, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/members' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/members/data' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/notifications' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'guest' => 401],
    'GET settings/notifications/data' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'guest' => 401],
    'GET settings/notifications/team' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/notifications/team/data' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/profile' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 200, 'guest' => 401],
    'GET settings/security' => ['owner' => 423, 'admin' => 423, 'member' => 423, 'outsider' => 423, 'unroled' => 423, 'guest' => 401],
    'GET settings/system' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/system/data' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/updates' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET settings/updates/data' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'GET sites' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'guest' => 401],
    'GET sites/create' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'guest' => 401],
    'GET sites/{site}' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'GET sites/{site}/checks/{check}' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'GET sites/{site}/checks/{check}/attempts/{attempt}' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'GET sites/{site}/checks/{check}/findings.{format}' => ['owner' => 422, 'admin' => 422, 'member' => 422, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'GET sites/{site}/icon/{hash}' => ['owner' => 404, 'admin' => 404, 'member' => 404, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'GET sites/{site}/maintenance' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'GET sites/{site}/settings' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'GET upcoming' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 200, 'unroled' => 403, 'guest' => 401],
    'PATCH clients/{client}' => ['owner' => 302, 'admin' => 302, 'member' => 404, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'PATCH notification-channels/{channel}' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'PATCH notification-channels/{channel}/enabled' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'PATCH notification-schedules/{kind}/{id?}' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'PATCH servers/{server}' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'PATCH settings/email' => ['owner' => 422, 'admin' => 403, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'PATCH settings/instance' => ['owner' => 422, 'admin' => 403, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'PATCH settings/members/{member}' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'PATCH settings/profile' => ['owner' => 422, 'admin' => 422, 'member' => 422, 'outsider' => 422, 'unroled' => 422, 'guest' => 401],
    'PATCH settings/updates' => ['owner' => 422, 'admin' => 403, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'PATCH sites/{site}' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'PATCH sites/{site}/checks/{check}' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'POST api/checks/{check}/disable' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/checks/{check}/enable' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/checks/{check}/request-run' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/checks/{check}/runs' => ['owner' => 202, 'admin' => 202, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/checks/{check}/snooze' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/checks/{check}/unsnooze' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/clients' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'limited' => 403, 'guest' => 401],
    'POST api/clients/{client}/archive' => ['owner' => 422, 'admin' => 422, 'member' => 404, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/clients/{client}/restore' => ['owner' => 422, 'admin' => 422, 'member' => 404, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/cron-checks/{cronTask}/snooze' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/cron-checks/{cronTask}/unsnooze' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/internal/incidents/{incident}/snooze' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 403, 'limited' => 404, 'guest' => 401],
    'POST api/maintenance-periods' => ['owner' => 201, 'admin' => 201, 'member' => 403, 'outsider' => 422, 'unroled' => 403, 'limited' => 422, 'guest' => 401],
    'POST api/monitors' => ['owner' => 201, 'admin' => 201, 'member' => 403, 'outsider' => 422, 'unroled' => 403, 'limited' => 422, 'guest' => 401],
    'POST api/monitors/{monitor}/cron-checks' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/monitors/{monitor}/cron-checks/sync' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/monitors/{monitor}/start-maintenance' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/monitors/{monitor}/stop-maintenance' => ['owner' => 204, 'admin' => 204, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'POST api/servers/{server}/snooze' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'limited' => 403, 'guest' => 401],
    'POST api/servers/{server}/unsnooze' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'limited' => 403, 'guest' => 401],
    'POST api/tags' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'limited' => 422, 'guest' => 401],
    'POST clients' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST clients/{client}/archive' => ['owner' => 302, 'admin' => 302, 'member' => 404, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'POST clients/{client}/client-messages' => ['owner' => 428, 'admin' => 428, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'POST clients/{client}/client-messages/preview' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'POST clients/{client}/restore' => ['owner' => 302, 'admin' => 302, 'member' => 404, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'POST incidents/{incident}/snooze' => ['owner' => 302, 'admin' => 302, 'member' => 302, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'POST maintenance' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST maintenance/{window}/end' => ['owner' => 409, 'admin' => 409, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST notification-channels' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST notification-channels/{channel}/test' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST servers' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST servers/{server}/sites' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST servers/{server}/snooze' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST servers/{server}/token' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST settings/api-tokens' => ['owner' => 422, 'admin' => 422, 'member' => 422, 'outsider' => 422, 'unroled' => 422, 'guest' => 401],
    'POST settings/backups' => ['owner' => 202, 'admin' => 403, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST settings/import/ohdear/plan' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST settings/import/ohdear/runs/{run}/retry' => ['owner' => 422, 'admin' => 403, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST settings/import/ohdear/runs/{run}/start' => ['owner' => 422, 'admin' => 403, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST settings/members/invitations' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST settings/members/invitations/{invitation}/resend' => ['owner' => 204, 'admin' => 204, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST sites' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'POST sites/probe' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'POST sites/{site}/checks/{check}/accept-dns' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'POST sites/{site}/checks/{check}/cancel' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'POST sites/{site}/checks/{check}/disable' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'POST sites/{site}/checks/{check}/enable' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'POST sites/{site}/checks/{check}/health/probe' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'POST sites/{site}/checks/{check}/run' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'POST sites/{site}/checks/{check}/snooze' => ['owner' => 302, 'admin' => 302, 'member' => 302, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'POST sites/{site}/checks/{check}/tasks/{task}/rotate-url' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'POST sites/{site}/icon' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'POST sites/{site}/maintenance/end' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'POST sites/{site}/pause' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'POST sites/{site}/resume' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'guest' => 401],
    'POST sites/{site}/run-all' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'PUT api/clients/{client}' => ['owner' => 200, 'admin' => 200, 'member' => 404, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'PUT api/cron-checks/{cronTask}' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'PUT api/internal/incidents/{incident}/note' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 404, 'unroled' => 403, 'limited' => 404, 'guest' => 401],
    'PUT api/maintenance-periods/{maintenancePeriod}' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 404, 'unroled' => 403, 'limited' => 404, 'guest' => 401],
    'PUT api/monitors/{monitor}' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 404, 'unroled' => 404, 'limited' => 404, 'guest' => 401],
    'PUT incidents/{incident}/note' => ['owner' => 302, 'admin' => 302, 'member' => 302, 'outsider' => 404, 'unroled' => 403, 'guest' => 401],
    'PUT maintenance/series/{series}' => ['owner' => 302, 'admin' => 302, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'PUT maintenance/{window}' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'PUT notification-channels/{channel}/site-mutes/{site}' => ['owner' => 204, 'admin' => 204, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'PUT settings/alerts' => ['owner' => 422, 'admin' => 422, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 401],
    'PUT settings/password' => ['owner' => 422, 'admin' => 422, 'member' => 422, 'outsider' => 422, 'unroled' => 422, 'guest' => 401],
    'POST broadcasting/auth presence-instance' => ['owner' => 200, 'admin' => 200, 'member' => 403, 'outsider' => 403, 'unroled' => 403, 'guest' => 403],
    'POST broadcasting/auth presence-client.{client}' => ['owner' => 200, 'admin' => 200, 'member' => 200, 'outsider' => 403, 'unroled' => 403, 'guest' => 403],
];

dataset('route access', function (): iterable {
    foreach (RouteAccess as $route => $statuses) {
        foreach ($statuses as $principal => $status) {
            yield $route.' as '.$principal => [$route, $principal, $status];
        }
    }
});

/**
 * @return array{users: array<string, User>, tokens: array<string, string>, tokenIds: array<string, int>, parameters: array<string, string>}
 */
function routeAccessFixture(): array
{
    $settings = app(InstanceSettings::class);
    $settings->servers_enabled = true;
    $settings->save();
    config(['heptapod.servers.enabled_by_server' => true]);

    $client = Client::factory()->create();
    $otherClient = Client::factory()->create();
    $users = [
        'owner' => User::factory()->owner()->create(),
        'admin' => User::factory()->admin()->create(),
        'member' => User::factory()->member()->create(['client_scope' => ClientScope::Restricted]),
        'outsider' => User::factory()->member()->create(['client_scope' => ClientScope::Restricted]),
        'unroled' => User::factory()->create(),
    ];
    $users['member']->clients()->attach($client);
    $colleague = User::factory()->member()->create(['client_scope' => ClientScope::Restricted]);
    $colleague->clients()->attach($client);

    $tokens = [];
    $tokenIds = [];
    foreach ($users as $principal => $user) {
        $token = $user->createToken('route access', ['*']);
        $tokens[$principal] = $token->plainTextToken;
        $tokenIds[$principal] = $token->accessToken->id;
    }
    $limited = $users['owner']->createToken('route access limited', ['*']);
    $limited->accessToken->forceFill(['client_id' => $otherClient->id])->save();
    $tokens['limited'] = $limited->plainTextToken;
    $tokenIds['limited'] = $limited->accessToken->id;
    $tokenIds['guest'] = $tokenIds['owner'];

    $site = Site::factory()->for($client)->create();
    $uptime = Check::factory()->for($site)->create();
    $dns = Check::factory()->for($site)->create(['type' => CheckKey::Dns]);
    $health = Check::factory()->for($site)->create(['type' => CheckKey::ApplicationHealth]);
    $cron = Check::factory()->for($site)->create(['type' => CheckKey::Cron]);
    $task = app(CronDefinitions::class)->create($cron->id, ['name' => 'Nightly backup', 'type' => 'simple', 'frequency_in_minutes' => 60, 'grace_time_in_minutes' => 5]);
    $attempt = CheckRunAttempt::query()->create(['check_id' => $uptime->id, 'claim_token' => (string) Str::uuid(), 'phase' => RunPhase::Queued,
        'queue' => 'checks', 'requested_at' => now(), 'due_at' => now()]);
    $incident = Incident::factory()->create(['check_id' => $uptime->id]);
    $event = Event::factory()->create(['check_id' => $uptime->id]);
    $window = MaintenancePeriod::factory()->create(['site_id' => $site->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    $series = RecurringMaintenancePeriod::factory()->create(['site_id' => $site->id]);
    $channel = NotificationDestination::factory()->create();
    $invitation = Invitation::factory()->create(['role' => 'Member', 'inviter_id' => $users['owner']->id]);
    $run = ImportRun::query()->create(['user_id' => $users['owner']->id, 'source' => 'ohdear', 'status' => 'planned', 'expected' => 1,
        'teams' => [], 'plan' => [['source_id' => '101', 'operation' => 'create', 'team_id' => 1]], 'results' => [], 'created_client_ids' => []]);
    $server = Server::named('route-access');
    $server->issueToken();
    $hash = str_repeat('a', 64);

    return ['users' => $users, 'tokens' => $tokens, 'tokenIds' => $tokenIds, 'parameters' => [
        'client' => (string) $client->id, 'hash' => $hash, 'message' => '999999', 'format' => 'csv',
        'series' => (string) $series->id, 'window' => (string) $window->id, 'maintenancePeriod' => (string) $window->id,
        'incident' => (string) $incident->id, 'event' => (string) $event->id,
        'site' => (string) $site->id, 'monitor' => (string) $site->id, 'url' => rawurlencode($site->url),
        'check' => (string) $uptime->id, 'checkType' => 'uptime', 'attempt' => (string) $attempt->id, 'attemptRef' => 'attempt:'.$attempt->id,
        'task' => (string) $task->id, 'cronTask' => (string) $task->id,
        'dns' => (string) $dns->id, 'health' => (string) $health->id, 'cron' => (string) $cron->id,
        'channel' => (string) $channel->id, 'kind' => 'team', 'id' => '',
        'invitation' => (string) $invitation->id, 'member' => (string) $colleague->id, 'run' => $run->id,
        'variant' => 'light', 'file' => $hash.'.png', 'server' => (string) $server->id,
    ]];
}

/** The authenticated application routes (not Fortify's and Passkeys' account routes), keyed "METHOD uri" with the first method other than HEAD. */
function routeAccessRoutes(): array
{
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        if (str_starts_with($route->getActionName(), 'Laravel\\') || array_filter($middleware, static fn (mixed $name): bool => is_string($name) && ($name === 'auth' || str_starts_with($name, 'auth:'))) === []) {
            continue;
        }
        $routes[array_first(array_diff($route->methods(), ['HEAD'])).' '.$route->uri()] = $route;
    }
    ksort($routes);

    return $routes;
}

function routeAccessUri(RoutingRoute $route, array $parameters): string
{
    // Checks of a type other than uptime are reached on the route that needs that type.
    $check = match (true) {
        str_ends_with($route->uri(), '/accept-dns') => $parameters['dns'],
        str_ends_with($route->uri(), '/health/probe') => $parameters['health'],
        str_contains($route->uri(), '/tasks/') => $parameters['cron'],
        default => $parameters['check'],
    };
    $uri = preg_replace_callback('/\{(\w+)\??\}/', static fn (array $match): string => $match[1] === 'check' ? $check : $parameters[$match[1]], $route->uri());

    return '/'.rtrim((string) $uri, '/');
}

/** @param array<string, string> $parameters */
function routeAccessBody(string $key, array $parameters): array
{
    $snooze = ['minutes' => 60];

    return match ($key) {
        'POST sites' => ['client_id' => (int) $parameters['client']],
        'POST api/monitors' => ['team_id' => 1, 'type' => 'http', 'url' => 'https://route-access.example.test', 'client_id' => (int) $parameters['client']],
        'POST api/maintenance-periods' => ['monitor_id' => (int) $parameters['site'], 'starts_at' => '2026-10-15 09:00', 'ends_at' => '2026-10-15 10:00'],
        'POST notification-channels' => ['recipient_kind' => 'team'],
        'PUT incidents/{incident}/note', 'PUT api/internal/incidents/{incident}/note' => ['note' => 'Pinned'],
        'POST incidents/{incident}/snooze', 'POST api/internal/incidents/{incident}/snooze', 'POST api/checks/{check}/snooze',
        'POST api/cron-checks/{cronTask}/snooze', 'POST sites/{site}/checks/{check}/snooze' => $snooze,
        default => [],
    };
}

function routeAccessStatus(TestCase $test, string $key, string $principal): int
{
    $fixture = routeAccessFixture();
    if (str_starts_with($key, 'POST broadcasting/auth ')) {
        config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret', 'broadcasting.connections.pusher.app_id' => 'test-app']);
        require base_path('routes/channels.php');
        $channel = str_replace('{client}', $fixture['parameters']['client'], substr($key, strlen('POST broadcasting/auth ')));
        if ($principal !== 'guest') {
            $test->actingAs($fixture['users'][$principal]);
        }

        return $test->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => $channel])->baseResponse->getStatusCode();
    }
    $route = routeAccessRoutes()[$key] ?? throw new RuntimeException('No authenticated route '.$key);
    [$method] = explode(' ', $key, 2);
    $body = routeAccessBody($key, $fixture['parameters']);
    $uri = str_replace('{token}', (string) $fixture['tokenIds'][$principal], routeAccessUri($route, ['token' => '{token}', ...$fixture['parameters']]));
    if (str_starts_with($route->uri(), 'api/')) {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($principal !== 'guest') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$fixture['tokens'][$principal];
        }

        return app(HttpKernel::class)->handle(Request::create($uri, $method, server: $server, content: $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR)))->getStatusCode();
    }
    if ($principal !== 'guest') {
        $test->actingAs($fixture['users'][$principal]);
    }

    return $test->json($method, $uri, $body)->baseResponse->getStatusCode();
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14T09:00:00Z'));
    Queue::fake();
});

it('pins every authenticated route for each principal', function (): void {
    $pinned = array_filter(array_keys(RouteAccess), static fn (string $key): bool => ! str_starts_with($key, 'POST broadcasting/auth '));
    expect($pinned)->toEqualCanonicalizing(array_keys(routeAccessRoutes()));
    foreach (RouteAccess as $key => $statuses) {
        expect(array_keys($statuses))->toBe(preg_match('/^\w+ api\//', $key) === 1 ? RouteAccessApiPrincipals : RouteAccessWebPrincipals);
    }
});

it('answers the pinned status', function (string $route, string $principal, int $status): void {
    expect(routeAccessStatus($this, $route, $principal))->toBe($status);
})->with('route access');
