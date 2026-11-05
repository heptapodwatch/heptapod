<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Composer\Semver\VersionParser;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class CheckForUpdates
{
    public function __construct(private OutboundHttp $http, private InstanceSettings $settings) {}

    public function __invoke(): void
    {
        if (! config()->boolean('heptapod.updates.enabled_by_server') || ! $this->settings->updates_enabled) {
            return;
        }
        $url = config()->string('heptapod.updates.release_url');
        $now = CarbonImmutable::now('UTC');
        $request = new FetchRequest($url, headers: ['Accept' => 'application/vnd.github+json'],
            maxWireBytes: 32768, maxDecodedBytes: 32768, redirects: RedirectPolicy::Untrusted, maxRedirects: 2,
            timeoutSeconds: 10, publicTargetsOnly: true);
        $context = new CheckContext(0, 0, 0, 'update-check', $url, $now, $now->addSeconds(10), [], [], null);
        $result = $this->http->fetch($request, $context);
        $release = null;
        if (! $result->failure instanceof FetchFailure && $result->status === 200) {
            try {
                /** @var mixed $payload */
                $payload = json_decode($result->body, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($payload) || ! is_string($payload['tag_name'] ?? null)
                    || ! is_string($payload['html_url'] ?? null) || ! is_string($payload['published_at'] ?? null)
                    || ! (new VersionParser)->isValid($payload['tag_name'])
                    || preg_match('~^'.preg_quote(config()->string('heptapod.updates.release_page_url'), '~').'/tag/[^/?#]+$~', $payload['html_url']) !== 1) {
                    throw new \UnexpectedValueException('GitHub release data is invalid.');
                }
                $release = ['latest_version' => $payload['tag_name'], 'release_url' => $payload['html_url'],
                    'published_at' => CarbonImmutable::parse($payload['published_at'])];
            } catch (\JsonException|\UnexpectedValueException|InvalidFormatException) {
                $release = null;
            }
        }
        $status = $release !== null ? 'reachable' : ($result->status === 200 ? 'invalid_response' : 'unreachable');
        $oldStatus = DB::table('update_checks')->where('id', 1)->value('status');
        if ($oldStatus !== $status && $status !== 'reachable') {
            Log::warning('Release update source status changed', ['state' => $status, 'http_status' => $result->status]);
        }
        DB::table('update_checks')->updateOrInsert(['id' => 1], [
            'status' => $status, 'http_status' => $result->status, 'checked_at' => $now,
            'latest_version' => $release['latest_version'] ?? null, 'release_url' => $release['release_url'] ?? null,
            'published_at' => $release['published_at'] ?? null,
            'last_success_at' => $release === null ? DB::table('update_checks')->where('id', 1)->value('last_success_at') : $now,
        ]);
    }
}
