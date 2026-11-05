<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\ClientMessages\ClientMessageComposer;
use Heptapod\Contracts\Mail\MailDesign;
use Heptapod\Contracts\Mail\MailRenderer;
use Heptapod\Contracts\Notifications\ChannelRegistry;
use Heptapod\Enums\RecipientKind;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Jobs\SendNotification;
use Heptapod\Models\Check;
use Heptapod\Models\Client;
use Heptapod\Models\Delivery;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\NotificationDestination;
use Heptapod\Models\NotificationPreference;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Notifications\Client\ClientMessageNotification;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * "Send to client" (design §4): a person decides that a client hears about a check's findings or an incident now.
 * Preview writes nothing. Sending freezes everything at approval: the rendered facts and Reply-To in the event payload,
 * each recipient's address in deliveries.recipient_snapshot, the CSV on disk with its hash. One sender's idempotency key
 * names one message for 24 hours; a different request under the same key is a conflict.
 *
 * @phpstan-import-type Facts from ClientMessageComposer
 */
final readonly class SendClientMessage
{
    public const string Disk = 'local';

    public const string Directory = 'client-messages';

    public const int IdempotencyHours = 24;

    public const int SenderLimit = 20;

    public const int ClientLimit = 10;

    /** A one-off address gets exactly one attempt; its channel is archived after it (SendNotificationDelivery). */
    public const string OneOffLabel = 'client_messages.one_off_label';

    public function __construct(private ClientMessageComposer $composer) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{subject: string, html: string, locale: string, total: int|null, recipients: list<array{id: int, address: string, paused: bool}>}
     */
    public function preview(User $sender, Client $client, array $input): array
    {
        $request = $this->validated($client, $input, previewing: true);
        $facts = $this->facts($sender, $client, $request, null);
        $rendered = app(MailRenderer::class)->preview($this->composer->mail($facts));

        return ['subject' => $rendered['subject'], 'html' => $rendered['html'], 'locale' => $facts['locale'],
            'total' => $facts['kind'] === 'findings' ? $facts['total'] : null,
            'confirmed' => $facts['kind'] === 'findings' ? $facts['confirmed'] : null,
            'recipients' => array_map(static fn (NotificationDestination $channel): array => ['id' => $channel->id,
                'address' => $channel->destinationConfig()->string('email'), 'paused' => ! $channel->enabled], $request['channels'])];
    }

    /** @param array<string, mixed> $input
     * @return array{event_id: int, delivery_ids: list<int>, replayed: bool}
     */
    public function send(User $sender, Client $client, array $input, string $idempotencyKey): array
    {
        if (preg_match('/^[\x21-\x7E]{1,128}$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => __('client_messages.invalid_key')]);
        }
        // The key names what the person asked for, not the state it met: an identical retry replays the approved message
        // even after a newer run, or after a chosen channel was removed. So it is answered before any state is checked.
        $shape = $this->shape($input);
        $hash = hash('sha256', json_encode(['client' => $client->id, ...$shape], JSON_THROW_ON_ERROR));
        $replayed = $this->replay($sender, $idempotencyKey, $hash);
        if ($replayed !== null) {
            return $replayed;
        }
        $request = $this->validated($client, $input, previewing: false);
        // Fail fast before building a CSV; the reservation inside the transaction is the one that counts.
        $this->reserveQuota($sender, $client);
        $path = null;
        $file = null;
        $rows = 0;
        $bytes = 0;
        $tooLarge = false;
        if ($request['attach']) {
            $path = self::Directory.'/'.Str::uuid().'.csv';
            // php://temp keeps at most 2 MiB in memory and spills the rest to a temporary file.
            $file = fopen('php://temp', 'w+b');
            if ($file === false || fwrite($file, "\xEF\xBB\xBF") !== 3) {
                throw new \RuntimeException('Cannot write the client message CSV.');
            }
            $bytes = 3;
            $head = array_map(static fn (string $column): string => (string) __('client_messages.column.'.$column, locale: $client->locale), ['url', 'result', 'classification', 'found_on', 'link_text']);
            $bytes += $this->append($file, $this->csvLine($head));
        }
        $writeRow = null;
        if (is_resource($file)) {
            $writeRow = function (array $row) use ($file, &$rows, &$bytes, &$tooLarge): void {
                /** @var array{url: string, result: string, classification: string, found_on: string, link_text: string} $row */
                $rows++;
                $cells = [$row['url'], $row['result'], $row['classification'], $row['found_on'], $row['link_text']];
                // CSV encoding can only add bytes; reject an oversized raw row without allocating an encoded copy.
                $rawBytes = 0;
                foreach ($cells as $field) {
                    $rawBytes += strlen($field);
                }
                $tooLarge = $tooLarge || $rawBytes > ClientMessageComposer::MaxBytes - $bytes;
                $line = $tooLarge ? '' : $this->csvLine($cells);
                // A row that would cross the byte limit is never written. The refusal waits until the composer has counted
                // every row, so its row limit keeps precedence.
                if ($tooLarge || $bytes + strlen($line) > ClientMessageComposer::MaxBytes) {
                    $tooLarge = true;

                    return;
                }
                $bytes += $this->append($file, $line);
            };
        }
        $facts = $this->facts($sender, $client, $request, $writeRow);
        $attachment = null;
        if (is_resource($file)) {
            if ($tooLarge) {
                throw ValidationException::withMessages(['attach' => __('client_messages.too_large')]);
            }
            $digest = hash_init('sha256');
            rewind($file);
            hash_update_stream($digest, $file);
            rewind($file);
            // Written before the transaction; the daily sweep removes it if the transaction does not commit.
            $disk = Storage::disk(self::Disk);
            $disk->writeStream($path, $file);
            fclose($file);
            $handle = fopen($disk->path($path), 'r');
            if ($handle === false) {
                throw new \RuntimeException('Cannot reopen the client message CSV.');
            }
            fsync($handle);
            fclose($handle);
            $attachment = ['disk' => self::Disk, 'path' => $path, 'bytes' => $bytes, 'rows' => $rows, 'sha256' => hash_final($digest)];
        }

        return NotificationLocks::transaction(function () use ($sender, $client, $request, $facts, $attachment, $idempotencyKey, $hash): array {
            // Same-key submits of one sender serialize here; the second replays the first.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['client-message:'.$sender->id.':'.$idempotencyKey]);
            $replayed = $this->replay($sender, $idempotencyKey, $hash);
            if ($replayed !== null) {
                return $replayed;
            }
            // Quotas are reserved against committed messages while both counters are locked: the sender (advisory lock) and
            // the client (row lock), always in that order. Concurrent sends with different keys wait here and then count each other.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['client-message-sender:'.$sender->id]);
            $lockedClient = Client::query()->whereKey($client->id);
            NotificationLocks::forUpdate($lockedClient->getQuery());
            $lockedClient->firstOrFail();
            $this->reserveQuota($sender, $client);
            // NotificationLocks below the client: the sender's key (the deliveries name it as actor), the check, site and
            // incident the event refers to, then the chosen channels in ascending id (their deliveries are inserted below).
            NotificationLocks::users([$sender->id]);
            NotificationLocks::checks([$request['check']->id]);
            NotificationLocks::sites([$request['site']->id]);
            NotificationLocks::incidents([$request['incident']?->id]);
            $ids = array_map(static fn (NotificationDestination $channel): int => $channel->id, $request['channels']);
            NotificationLocks::channels($ids);
            $channelQuery = NotificationDestination::query()->with('client')->whereKey($ids);
            $channelQuery->getQuery()->orderBy('id');
            $channels = $channelQuery->get();
            if ($channels->count() !== count($ids) || $channels->contains(static fn (NotificationDestination $channel): bool => ! self::acceptsManualMessage($channel, $client))) {
                throw ValidationException::withMessages(['channel_ids' => __('client_messages.channel_unavailable')]);
            }
            $channels = $channels->all();
            if ($request['one_off'] !== null) {
                $oneOff = NotificationDestination::query()->create(['recipient_kind' => RecipientKind::Client, 'client_id' => $client->id, 'channel' => 'mail',
                    'label' => __(self::OneOffLabel, locale: $client->locale), 'enabled' => true,
                    'config' => app(ChannelRegistry::class)->get('mail')->configSchema()->validateConfig(['email' => $request['one_off']])]);
                NotificationPreference::writeInitialGrid($oneOff->id, NotificationPreference::defaults(RecipientKind::Client));
                $channels[] = $oneOff->load('client');
            }
            $at = CarbonImmutable::now('UTC');
            $event = Event::query()->create(['type' => 'client_message.'.$request['kind'], 'occurred_at' => $at, 'site_id' => $request['site']->id,
                'check_id' => $request['check']->id, 'payload' => [...$facts, 'idempotency_key' => $idempotencyKey, 'request_hash' => $hash,
                    'actor_id' => $sender->id, 'one_off_channel_id' => isset($oneOff) ? $oneOff->id : null, 'run' => $request['run']],
                'suppressed' => false, 'suppression_evaluated_at' => $at, 'notification_routed_at' => $at]);
            if ($attachment !== null) {
                DB::table('client_message_attachments')->insert([...$attachment, 'event_id' => $event->id, 'created_at' => $at]);
            }
            $deliveryIds = [];
            foreach ($channels as $channel) {
                $delivery = Delivery::query()->create(['event_id' => $event->id, 'destination_id' => $channel->id, 'next_attempt_at' => $at,
                    'suppressed' => false, 'suppression_evaluated_at' => $at, 'origin' => 'manual', 'actor_id' => $sender->id, 'actor_name' => $sender->name,
                    'recipient_snapshot' => ['address' => $channel->destinationConfig()->string('email'), 'name' => $channel->label, 'locale' => $client->locale]]);
                $deliveryIds[] = $delivery->id;
                DB::afterCommit(static fn () => SendNotification::dispatch($delivery->id));
            }
            activity('audit')->causedBy($sender)->performedOn($client)->event('notification.client_message')
                ->withProperties(['event_id' => $event->id, 'kind' => $request['kind'], 'check_id' => $request['check']->id, 'incident_id' => $request['incident']?->id,
                    'channel_ids' => array_map(static fn (NotificationDestination $channel): int => $channel->id, $channels), 'attachment' => $attachment !== null, 'note' => $request['note'] !== ''])
                ->log('notification.client_message');
            Delivery::announceEvents([$event->id]);

            return ['event_id' => $event->id, 'delivery_ids' => $deliveryIds, 'replayed' => false];
        });
    }

    /**
     * A sent message as its recipients read it, from the frozen payload only.
     *
     * @return array{subject: string, html: string}
     */
    public function render(Event $event): array
    {
        /** @var Facts $facts */
        $facts = $event->payload;

        return app(MailRenderer::class)->preview($this->composer->mail($facts));
    }

    /** The notification a client_message.* delivery sends: only frozen payload facts and the stored attachment. */
    public function notification(Event $event): ClientMessageNotification
    {
        /** @var Facts $facts */
        $facts = $event->payload;
        $row = DB::table('client_message_attachments')->where('event_id', $event->id)->first(['disk', 'path', 'sha256']);
        /** @var (\stdClass&object{disk: string, path: string, sha256: string})|null $row */

        return new ClientMessageNotification($this->composer->mail($facts), $facts['sender'],
            $row === null ? null : ['disk' => $row->disk, 'path' => $row->path, 'sha256' => $row->sha256,
                'filename' => $facts['attachment_filename'] ?? throw new \LogicException('A client message attachment needs its file name.')]);
    }

    /** A saved client mail channel that may receive a manual message: not archived; paused is allowed. */
    public static function acceptsManualMessage(NotificationDestination $channel, Client $client): bool
    {
        return $channel->recipient_kind === RecipientKind::Client && $channel->client_id === $client->id && $channel->channel === 'mail' && $channel->removed_at === null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{kind: string, site: Site, check: Check, incident: Incident|null, run: array{id: int, ended_at: string}|null, channels: list<NotificationDestination>, one_off: string|null, note: string, attach: bool, design: MailDesign}
     */
    private function validated(Client $client, array $input, bool $previewing): array
    {
        /** @var array{kind: string, check_id?: int, incident_id?: int, channel_ids?: list<int>, one_off?: string|null, note?: string|null, attach?: bool, design: string} $data */
        $data = $this->rules($input);
        if ($data['kind'] === 'incident') {
            $incident = Incident::query()->with(['site', 'check'])->findOrFail($data['incident_id'] ?? throw new \LogicException('Validated incident id missing.'));
            $site = $incident->site ?? abort(404);
            $check = $incident->check ?? abort(404);
        } else {
            $incident = null;
            $check = Check::query()->with('site')->findOrFail($data['check_id'] ?? throw new \LogicException('Validated check id missing.'));
            $site = $check->site ?? abort(404);
        }
        // Another client's site or channel is not found here (design §4: 404/422).
        abort_unless($site->client_id === $client->id, 404);
        $ids = $data['channel_ids'] ?? [];
        /** @var list<NotificationDestination> $channels */
        $channels = $ids === [] ? [] : array_values(NotificationDestination::query()->with('client')->whereKey($ids)->get()->sortBy('id')->all());
        if (count($channels) !== count($ids) || array_any($channels, static fn (NotificationDestination $channel): bool => ! self::acceptsManualMessage($channel, $client))) {
            throw ValidationException::withMessages(['channel_ids' => __('client_messages.channel_unavailable')]);
        }
        $oneOff = $this->oneOffAddress($data['one_off'] ?? null);
        if (! $previewing && $ids === [] && $oneOff === null) {
            throw ValidationException::withMessages(['channel_ids' => __('client_messages.no_recipient')]);
        }

        return ['kind' => $data['kind'], 'site' => $site, 'check' => $check, 'incident' => $incident,
            'run' => $incident === null && $check->latest_run_id !== null && $check->latest_run_ended_at !== null
                ? ['id' => $check->latest_run_id, 'ended_at' => $check->latest_run_ended_at->utc()->format('Y-m-d\TH:i:s.uP')] : null,
            'channels' => $channels, 'one_off' => $oneOff, 'note' => trim($data['note'] ?? ''),
            'attach' => $data['kind'] === 'findings' && ($data['attach'] ?? false), 'design' => MailDesign::from($data['design'])];
    }

    /**
     * The request as the person made it, normalized: what the idempotency key stands for. The design is part of it because it
     * changes what the client reads: the same key with another design is another message, a conflict rather than a replay.
     *
     * @param  array<string, mixed>  $input
     * @return array{kind: string, check_id: int|null, incident_id: int|null, channel_ids: list<int>, one_off: string|null, note: string, attach: bool, design: string}
     */
    private function shape(array $input): array
    {
        $data = $this->rules($input);
        $ids = array_map(intval(...), $data['channel_ids'] ?? []);
        sort($ids);
        $oneOff = $this->oneOffAddress($data['one_off'] ?? null);

        return ['kind' => $data['kind'], 'check_id' => isset($data['check_id']) ? (int) $data['check_id'] : null,
            'incident_id' => isset($data['incident_id']) ? (int) $data['incident_id'] : null, 'channel_ids' => $ids, 'one_off' => $oneOff,
            'note' => trim($data['note'] ?? ''), 'attach' => $data['kind'] === 'findings' && ($data['attach'] ?? false), 'design' => $data['design']];
    }

    /**
     * The one form of a one-off address: what the idempotency key covers and exactly what is stored and sent. Surrounding
     * space is dropped and the domain is written in lower case (domains are case-insensitive); the local part stays as typed, since a
     * mail server may tell Alice@ and alice@ apart.
     */
    private function oneOffAddress(?string $value): ?string
    {
        $address = trim($value ?? '');
        if ($address === '') {
            return null;
        }
        $at = strrpos($address, '@');

        return $at === false ? $address : substr($address, 0, $at).'@'.strtolower(substr($address, $at + 1));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{kind: string, check_id?: int|string, incident_id?: int|string, channel_ids?: list<int|string>, one_off?: string|null, note?: string|null, attach?: bool, design: string}
     */
    private function rules(array $input): array
    {
        /** @var array{kind: string, check_id?: int|string, incident_id?: int|string, channel_ids?: list<int|string>, one_off?: string|null, note?: string|null, attach?: bool, design: string} */
        return Validator::make($input, [
            'kind' => ['required', Rule::in(['findings', 'incident'])],
            'check_id' => ['required_if:kind,findings', 'prohibited_if:kind,incident', 'integer'],
            'incident_id' => ['required_if:kind,incident', 'prohibited_if:kind,findings', 'integer'],
            'channel_ids' => ['present', 'array', 'max:20'],
            'channel_ids.*' => ['integer', 'distinct'],
            'one_off' => ['nullable', 'string', 'email:strict', 'max:254'],
            'note' => ['nullable', 'string', 'max:2000'],
            'attach' => ['sometimes', 'boolean'],
            'design' => ['required', Rule::enum(MailDesign::class)],
        ], messages: ['one_off.email' => (string) __('client_messages.invalid_address')], attributes: array_map(static fn (string $key): string => (string) __($key), ['channel_ids' => 'client_messages.sheet.to',
            'channel_ids.*' => 'client_messages.sheet.to', 'one_off' => 'Email address', 'note' => 'client_messages.sheet.note',
            'attach' => 'client_messages.sheet.attach', 'design' => 'client_messages.sheet.design']))->validate();
    }

    /**
     * @param  array{kind: string, site: Site, check: Check, incident: Incident|null, note: string, attach: bool, design: MailDesign}  $request
     * @param  (\Closure(array{url: string, result: string, classification: string, found_on: string, link_text: string}): void)|null  $each
     * @return Facts
     */
    private function facts(User $sender, Client $client, array $request, ?\Closure $each): array
    {
        return $request['incident'] instanceof Incident
            ? $this->composer->incident($client, $request['site'], $request['check'], $request['incident'], $sender, $request['note'], $request['design'])
            : $this->composer->findings($client, $request['site'], $request['check'], $sender, $request['note'], $request['attach'], $request['design'], $each);
    }

    /** @return array{event_id: int, delivery_ids: list<int>, replayed: bool}|null */
    private function replay(User $sender, string $key, string $hash): ?array
    {
        $query = Event::query();
        $query->getQuery()->whereIn('type', ['client_message.findings', 'client_message.incident'])->whereRaw("payload->>'idempotency_key' = ?", [$key])->whereRaw("payload->>'actor_id' = ?", [(string) $sender->id])
            ->where('occurred_at', '>', CarbonImmutable::now('UTC')->subHours(self::IdempotencyHours)->format('Y-m-d H:i:s.uP'))->orderByDesc('id')->limit(1);
        $event = $query->first();
        if (! $event instanceof Event) {
            return null;
        }
        if (($event->payload['request_hash'] ?? null) !== $hash) {
            throw new ConflictHttpException(__('client_messages.idempotency_conflict'));
        }
        /** @var list<int> $deliveryIds */
        $deliveryIds = DB::table('deliveries')->where('event_id', $event->id)->orderBy('id')->pluck('id')->all();

        return ['event_id' => $event->id, 'delivery_ids' => $deliveryIds, 'replayed' => true];
    }

    /**
     * 20 messages per hour per sender and 10 per 24 hours per client (design §4), counted from the messages themselves.
     * Retry-After is when the oldest message in the full window leaves it.
     */
    private function reserveQuota(User $sender, Client $client): void
    {
        $now = CarbonImmutable::now('UTC');
        $limits = [
            [DB::table('events')->whereRaw("payload->>'actor_id' = ?", [(string) $sender->id]), self::SenderLimit, 3600],
            [DB::table('events')->where('client_id', $client->id), self::ClientLimit, 86400],
        ];
        foreach ($limits as [$query, $max, $seconds]) {
            $query->whereIn('type', ['client_message.findings', 'client_message.incident'])
                ->where('occurred_at', '>', $now->subSeconds($seconds)->format('Y-m-d H:i:s.uP'))->orderByDesc('occurred_at')->limit(1)->offset($max - 1);
            $oldestCounted = $query->value('occurred_at');
            if (is_string($oldestCounted)) {
                $retry = max(1, (int) ceil(CarbonImmutable::parse($oldestCounted)->addSeconds($seconds)->diffInSeconds($now, true)));
                throw new ThrottleRequestsException(__('client_messages.too_many'), headers: ['Retry-After' => (string) $retry]);
            }
        }
    }

    /**
     * One encoded CSV line, so its size is known before it is written.
     *
     * @param  list<mixed>  $cells  text only; anything else is a programming error
     */
    private function csvLine(array $cells): string
    {
        $safe = array_map(static function (mixed $value): string {
            $text = is_string($value) ? $value : throw new \LogicException('A CSV cell must be text.');

            return preg_match('/^(?:[\x00-\x20]*[=+@-]|[\t\r\n])/u', $text) === 1 ? "'".$text : $text;
        }, $cells);
        $buffer = fopen('php://memory', 'w+b');
        if ($buffer === false || fputcsv($buffer, $safe, ',', '"', '', "\r\n") === false || ! rewind($buffer)) {
            throw new \RuntimeException('Cannot encode a client message CSV row.');
        }
        $line = stream_get_contents($buffer);
        fclose($buffer);

        return $line === false ? throw new \RuntimeException('Cannot encode a client message CSV row.') : $line;
    }

    /**
     * @param  resource  $file
     * @return int the bytes written
     */
    private function append(mixed $file, string $line): int
    {
        return fwrite($file, $line) === strlen($line) ? strlen($line) : throw new \RuntimeException('Cannot write the client message CSV.');
    }
}
