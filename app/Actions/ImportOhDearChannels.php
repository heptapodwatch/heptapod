<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Heptapod\Actions\Notifications\SaveNotificationChannel;
use Heptapod\Contracts\Schema\ValidatedConfig;
use Heptapod\Data\Import\ImportChannelRowData;
use Heptapod\Enums\ImportOperation;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Exceptions\InvalidOhDearResponse;
use Heptapod\Models\Client;
use Heptapod\Models\NotificationDestination;
use Heptapod\Support\OhDearDestinationMapper;
use Heptapod\Support\OhDearImportNotices;
use Illuminate\Validation\ValidationException;
use OhDear\PhpSdk\OhDear;
use OhDear\PhpSdk\Requests\Monitors\GetNotificationDestinationsRequest;
use OhDear\PhpSdk\Requests\NotificationDestinations\GetTagGroupNotificationDestinationsRequest;
use OhDear\PhpSdk\Requests\NotificationDestinations\GetTagNotificationDestinationsRequest;
use OhDear\PhpSdk\Requests\NotificationDestinations\GetTeamNotificationDestinationsRequest;
use OhDear\PhpSdk\Requests\TagGroups\GetTagGroupsRequest;
use Saloon\Http\Request;

final readonly class ImportOhDearChannels
{
    public function __construct(private OhDearDestinationMapper $mapper, private SaveNotificationChannel $save) {}

    /**
     * @param  list<int>  $monitors
     * @param  array<int, array<string, mixed>>|null  $approved
     * @param  array<int, int>  $clientIds  Explicit client assignments per channel; the CLI has none.
     * @param  (\Closure(): void)|null  $beforeWrite  Runs first in each channel's write transaction; throws to stop the import.
     * @return list<ImportChannelRowData>
     */
    public function __invoke(#[\SensitiveParameter] OhDear $sdk, array $monitors, bool $dryRun, ?array $approved, array $clientIds, ?\Closure $beforeWrite = null): array
    {
        $destinations = [];
        $collect = function (Request $request, string $level, array $groupTags = []) use ($sdk, &$destinations): void {
            foreach ($this->rows($sdk, $request) as $source) {
                $tag = $source['tag'] ?? null;
                /** @var list<string> $tags Group tags were normalized by tagNames before collection. */
                $tags = $level === 'tag' ? $this->tagNames([$tag]) : $groupTags;
                [$row, $kind, $config] = $this->mapper->map($source, $level, $tags);
                if (isset($destinations[$row->source_id])) {
                    throw new InvalidOhDearResponse('import.channels.invalid_response');
                }
                $destinations[$row->source_id] = [$row, $kind, $config];
            }
        };
        $collect(new GetTeamNotificationDestinationsRequest, 'team');
        $collect(new GetTagNotificationDestinationsRequest, 'tag');
        foreach ($this->rows($sdk, new GetTagGroupsRequest) as $group) {
            $id = $group['id'] ?? null;
            $tags = $group['tags'] ?? null;
            if (! is_int($id) || $id < 1 || ! is_array($tags) || ! array_is_list($tags)) {
                throw new InvalidOhDearResponse('import.channels.invalid_response');
            }
            $collect(new GetTagGroupNotificationDestinationsRequest($id), 'tag_group', $this->tagNames($tags));
        }
        foreach ($monitors as $id) {
            $collect(new GetNotificationDestinationsRequest($id), 'monitor');
        }
        $channels = NotificationDestination::query();
        $channels->getQuery()->whereIn('oh_dear_id', array_keys($destinations));
        $existing = $channels->get()->keyBy('oh_dear_id');
        $clients = Client::query();
        $clients->getQuery()->whereIn('id', array_values($clientIds))->whereNull('archived_at');
        $clientsById = $clients->get()->keyBy('id');
        $results = [];
        foreach ($destinations as $id => [$row, $kind, $config]) {
            $results[] = $row;
            if ($kind === null || ! $config instanceof ValidatedConfig) {
                continue;
            }
            $before = $existing->get($id);
            $planned = $approved[$id] ?? [];
            $clientId = $clientIds[$id] ?? null;
            $needsClient = $row->level !== 'team';
            $client = $clientId === null ? null : $clientsById->get($clientId);
            $reason = match (true) {
                $before?->removed_at !== null => 'removed',
                $approved !== null && (($planned['operation'] ?? 'skipped') === 'skipped' || ($planned['level'] ?? null) !== $row->level
                    || ($planned['tags'] ?? null) !== $row->tags || ($planned['kind_label'] ?? null) !== $row->kind_label) => 'plan_changed',
                $needsClient && ((! $dryRun && $client === null) || $row->tags === []) => 'no_client',
                default => null,
            };
            if ($reason !== null) {
                $this->skip($row, $reason);

                continue;
            }
            $row->operation = $before === null ? ImportOperation::Create : ImportOperation::Update;
            $row->client_name = $client?->displayName();
            if (! $dryRun) {
                try {
                    $channel = NotificationLocks::transaction(function () use ($beforeWrite, $id, $client, $kind, $row, $config): NotificationDestination {
                        if ($beforeWrite instanceof \Closure) {
                            $beforeWrite();
                        }

                        return $this->save->syncOhDear($id, $client?->id, $kind, __($row->kind_label).' · Oh Dear #'.$id, $row->enabled,
                            array_column($row->check_types, 'value'), $row->categories, $config);
                    });
                    $row->operation = $channel->wasRecentlyCreated ? ImportOperation::Create : ImportOperation::Update;
                } catch (ValidationException $exception) {
                    // Only a concurrent client archive or channel removal can invalidate an already validated write.
                    $this->skip($row, isset($exception->errors()['client']) ? 'no_client' : 'removed');
                }
            }
        }

        return $results;
    }

    private function skip(ImportChannelRowData $row, string $reason): void
    {
        $row->operation = ImportOperation::Skipped;
        $row->notices = [OhDearImportNotices::key('channel:'.$reason)];
    }

    /** @param list<mixed> $tags
     * @return list<string>
     */
    private function tagNames(array $tags): array
    {
        $names = [];
        foreach ($tags as $tag) {
            $name = is_array($tag) ? ($tag['name'] ?? null) : null;
            if (! is_string($name) || $name === '') {
                throw new InvalidOhDearResponse('import.channels.invalid_response');
            }
            $names[$name] = $name;
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /** Use SDK requests but retain enabled and tag scope, which the SDK destination DTO ignores.
     * @return \Generator<int, array<string, mixed>>
     */
    private function rows(#[\SensitiveParameter] OhDear $sdk, Request $request): \Generator
    {
        for ($page = 1; ; $page++) {
            if ($page > 1) {
                $request->query()->set(['page' => $page]);
            }
            try {
                $response = $sdk->send($request)->object();
            } catch (\JsonException) {
                throw new InvalidOhDearResponse('import.channels.invalid_response');
            }
            $items = $response instanceof \stdClass ? ($response->data ?? null) : $response;
            if (! is_array($items) || array_any($items, static fn (mixed $item): bool => ! $item instanceof \stdClass)) {
                throw new InvalidOhDearResponse('import.channels.invalid_response');
            }
            foreach ($items as $item) {
                /** @var array<string, mixed> $row */
                $row = json_decode(json_encode($item, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                yield $row;
            }
            $meta = $response instanceof \stdClass ? ($response->meta ?? null) : null;
            if (! $meta instanceof \stdClass || (! isset($meta->current_page) && ! isset($meta->last_page))) {
                return;
            }
            if (($meta->current_page ?? null) !== $page || ! is_int($meta->last_page ?? null) || $meta->last_page < $page) {
                throw new InvalidOhDearResponse('import.channels.invalid_response');
            }
            if ($page === $meta->last_page) {
                return;
            }
        }
    }
}
