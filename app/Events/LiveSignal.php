<?php

declare(strict_types=1);

namespace Heptapod\Events;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\RunPhase;
use Heptapod\Models\Client;
use Heptapod\Models\Site;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Support\Facades\DB;

final readonly class LiveSignal
{
    /**
     * @param  array<string, int>  $ids
     * @param  array{check_id: int, phase: string, queue_position: ?int, progress_n: ?int, progress_total: ?int, started_at: ?string, actor: ?string, outcome?: string, attempt_id?: int, check_run_id?: int, ended_at?: string, result?: string}|null  $run
     * @param  list<int>  $clientIds  Private fan-out targets for a coalesced change; never serialized into the payload.
     */
    public function __construct(
        public string $topic,
        public array $ids,
        public string $at,
        private ?int $clientId,
        public ?array $run = null,
        private ?array $clientIds = null,
    ) {}

    /** @param array<string, int> $ids */
    public static function forSite(Site $site, array $ids = [], ?int $clientId = null): void
    {
        self::afterCommit(new self('state.changed', ['client' => $clientId ?? $site->client_id, 'site' => $site->id, ...$ids], now()->toIso8601String(), $clientId ?? $site->client_id));
    }

    /** Import progress carries no ids; Owner/Admin import pages reload their run on it. */
    public static function forImport(): void
    {
        self::afterCommit(new self('import.progress', [], now()->toIso8601String(), null));
    }

    public static function forClient(Client $client): void
    {
        self::forClients([$client->id]);
    }

    /**
     * A target with no sites (an empty tag) affects no client channel, yet Owner and Admin still list it,
     * so the instance channel carries the change instead of dropping it.
     *
     * @param  list<int>  $clientIds
     */
    public static function forClients(array $clientIds): void
    {
        $clientIds = array_values(array_unique($clientIds));
        if ($clientIds === []) {
            self::afterCommit(new self('state.changed', [], now()->toIso8601String(), null));

            return;
        }
        foreach ($clientIds as $clientId) {
            self::afterCommit(new self('state.changed', ['client' => $clientId], now()->toIso8601String(), $clientId));
        }
    }

    public static function forRun(string $topic, Site $site, int $checkId, ?CarbonImmutable $startedAt = null, ?string $actor = null, ?int $queuePosition = null, ?int $progressN = null, ?int $progressTotal = null, ?RunPhase $outcome = null, ?int $attemptId = null, ?RecordedRunSignal $recorded = null): void
    {
        if (! in_array($topic, ['run.queued', 'run.started', 'run.progress', 'run.finished'], true)) {
            throw new \InvalidArgumentException('Unknown run signal topic.');
        }
        if (($topic === 'run.finished') !== in_array($outcome, [RunPhase::Done, RunPhase::Failed, RunPhase::Cancelled, RunPhase::Skipped], true)) {
            throw new \InvalidArgumentException('Only finished runs carry a terminal outcome.');
        }
        $payload = ['check_id' => $checkId, 'phase' => substr($topic, 4), 'queue_position' => $queuePosition,
            'progress_n' => $progressN, 'progress_total' => $progressTotal, 'started_at' => $startedAt?->toIso8601String(), 'actor' => $actor];
        if ($outcome instanceof RunPhase) {
            $payload['outcome'] = $outcome->value;
        }
        // The attempt id lets the tab that requested this run match the completion to its own request.
        if ($attemptId !== null) {
            $payload['attempt_id'] = $attemptId;
        }
        if ($recorded instanceof RecordedRunSignal) {
            if ($topic !== 'run.finished') {
                throw new \InvalidArgumentException('Only a finished run carries its recorded result.');
            }
            $payload['check_run_id'] = $recorded->checkRunId;
            $payload['ended_at'] = $recorded->endedAt->format('Y-m-d\TH:i:s.uP');
            $payload['result'] = $recorded->result->value;
        }
        self::afterCommit(new self($topic, ['site' => $site->id, 'check' => $checkId], now()->format('Y-m-d\TH:i:s.uP'), $site->client_id, $payload));
    }

    /**
     * One logical state change for many client scopes (a maintenance tick, not one event per window).
     * Public ids stay empty so no client learns another scope's identity; the instance channel gets the
     * event once, and each affected client channel is listed privately.
     *
     * @param  list<int>  $clientIds
     */
    public static function forCoalescedClients(array $clientIds): void
    {
        $clientIds = array_values(array_unique($clientIds));
        if ($clientIds === []) {
            self::forClients([]);

            return;
        }
        self::afterCommit(new self('state.changed', [], now()->toIso8601String(), null, null, $clientIds));
    }

    private static function afterCommit(self $signal): void
    {
        DB::afterCommit(static fn () => event($signal));
    }

    /** @return list<PresenceChannel> */
    public function broadcastOn(): array
    {
        // Signals queued before coalesced targeting existed deserialize without this property; `??` reads them as absent.
        $clientIds = $this->clientIds ?? [];
        if ($clientIds !== []) {
            return [new PresenceChannel('instance'), ...array_map(
                static fn (int $clientId): PresenceChannel => new PresenceChannel('client.'.$clientId),
                array_values(array_unique($clientIds)),
            )];
        }

        return $this->clientId === null
            ? [new PresenceChannel('instance')]
            : [new PresenceChannel('instance'), new PresenceChannel('client.'.$this->clientId)];
    }

    public function broadcastAs(): string
    {
        return 'LiveSignal';
    }

    /** @return array{topic: string, ids: array<string, int>, at: string, run?: array<string, int|string|null>} */
    public function broadcastWith(): array
    {
        return $this->run === null
            ? ['topic' => $this->topic, 'ids' => $this->ids, 'at' => $this->at]
            : ['topic' => $this->topic, 'ids' => $this->ids, 'at' => $this->at, 'run' => $this->run];
    }
}
