<?php

declare(strict_types=1);

namespace Heptapod\Http\Resources\OhDear;

use Carbon\CarbonImmutable;
use Heptapod\Checks\FreshnessSnapshot;
use Heptapod\Checks\History\RollupReader;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Events\Notifications\AlertDelay;
use Heptapod\Models\Check;
use Heptapod\Models\Site;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Spatie\Tags\Tag;

/** @mixin Site */
final class MonitorResource extends JsonResource
{
    #[\Override]
    public static $wrap;

    /** @param array<int, float|null>|null $responseTimes */
    public function __construct(mixed $resource, private readonly ?array $responseTimes = null, private readonly ?FreshnessSnapshot $freshness = null)
    {
        parent::__construct($resource);
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array<int, float|null>
     */
    public static function responseTimes(Collection $sites): array
    {
        $uptimeIds = [];
        foreach ($sites as $site) {
            if ($site->checks->contains('type', CheckKey::Performance)) {
                $uptime = $site->checks->firstWhere('type', CheckKey::Uptime);
                if ($uptime instanceof Check) {
                    $uptimeIds[$site->id] = $uptime->id;
                }
            }
        }
        $now = CarbonImmutable::now('UTC');
        $averages = app(RollupReader::class)->recentResponseTimes(array_values($uptimeIds), $now->subMinutes(15), $now);
        $times = [];
        foreach ($uptimeIds as $siteId => $checkId) {
            $times[$siteId] = $averages[$checkId] ?? null;
        }

        return $times;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Site $site */
        $site = $this->resource;
        $checks = $site->checks;
        foreach ($checks as $check) {
            $check->setRelation('site', $site);
        }
        $delay = AlertDelay::for($site, app(InstanceSettings::class));
        $responseTimes = $this->responseTimes ?? self::responseTimes(collect([$site]));
        $result = CheckResult::Pending;
        foreach ([CheckResult::Failed, CheckResult::Errored, CheckResult::Warning, CheckResult::Succeeded, CheckResult::Pending] as $candidate) {
            if ($checks->where('enabled', true)->contains('latest_run_result', $candidate)) {
                $result = $candidate;
                break;
            }
        }
        $tags = $site->tags;
        /** @var CarbonImmutable|null $latest */
        $latest = $checks->max('latest_run_ended_at');
        $sortUrl = preg_replace('~^https?://~i', '', $site->url);
        $data = ['id' => $site->id, 'team_id' => 1, 'type' => 'http', 'url' => $site->url, 'uses_https' => strtolower((string) parse_url($site->url, PHP_URL_SCHEME)) === 'https',
            'sort_url' => $sortUrl, 'label' => $site->friendly_name ?? $sortUrl, 'group_name' => $site->group_name,
            'client_ref' => 'client:'.$site->client_id, 'client_name' => ($site->client ?? throw new \LogicException('Monitor client missing.'))->name,
            'tags' => $tags->map(function (Tag $tag): string {
                $name = $tag->getTranslation('name', 'en');
                if (! is_string($name)) {
                    throw new \LogicException('Canonical tag name must be a string');
                }

                return $name;
            })->values()->all(), 'description' => $site->description, 'notes' => $site->notes,
            'latest_run_date' => $latest?->utc()->format('Y-m-d H:i:s'), 'summarized_check_result' => $result->value,
            'alert_sensitivity' => ['preset' => $delay->preset?->value, 'alert_after_minutes' => $delay->alertAfterMinutes,
                'recovery_checks' => $delay->recoveryChecks, 'inherited' => $delay->inherited],
            'checks' => $checks->map(fn (Check $check): array => new CheckResource($check, $responseTimes, $this->freshness)->resolve($request))->all(),
            'badge_id' => $site->badge_id, 'marked_for_deletion_at' => $site->marked_for_deletion_at?->utc()->format('Y-m-d H:i:s'),
            'crawler_headers' => null, 'send_report_to_emails' => $site->send_report_to_emails, 'include_check_types_in_report' => $site->include_check_types_in_report,
            'created_at' => $site->created_at?->utc()->format('Y-m-d H:i:s'), 'updated_at' => $site->updated_at?->utc()->format('Y-m-d H:i:s')];
        foreach ($checks as $check) {
            if (! in_array($check->type, [CheckKey::MixedContent, CheckKey::Cron], true)) {
                $data[$check->type->value.'_check_settings'] = (object) CheckResource::settings($check);
            }
        }

        return $data;
    }
}
