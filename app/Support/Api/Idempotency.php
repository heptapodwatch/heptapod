<?php

declare(strict_types=1);

namespace Heptapod\Support\Api;

use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Durable replay for creating API writes. The reservation, the work and the stored response share one
 * transaction, so a refused or failed request leaves no record and a concurrent same-key request waits
 * on the unique index and then replays the committed response.
 */
final class Idempotency
{
    private const int RetentionHours = 24;

    private const int PruneChunk = 1000;

    /** Deletes expired records in short index-backed chunks so the prune never holds long locks. */
    public function pruneExpired(): int
    {
        $deleted = 0;
        do {
            $chunk = DB::delete('DELETE FROM api_idempotency_records WHERE id IN (SELECT id FROM api_idempotency_records WHERE expires_at <= CURRENT_TIMESTAMP ORDER BY expires_at LIMIT ?)',
                [self::PruneChunk]);
            $deleted += $chunk;
        } while ($chunk === self::PruneChunk);

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $canonicalRequest  validated effective parameters, never raw input or secrets
     * @param  \Closure(): JsonResponse  $perform  authorization must already have passed for this caller
     */
    public function run(Request $request, string $operation, string $target, array $canonicalRequest, \Closure $perform): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null) {
            return NotificationLocks::transaction($perform);
        }
        if (preg_match('/^[\x21-\x7E]{1,128}$/', $key) !== 1) {
            throw new ApiProblem('invalid_idempotency_key', 422, ['rule' => '1 to 128 visible ASCII characters']);
        }
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 401); // @phpstan-ignore function.alreadyNarrowedType, instanceof.alwaysTrue
        $principal = ['user_id' => $user->id, 'token_id' => $token->id, 'key_hash' => hash('sha256', $key)];
        $requestHash = hash('sha256', json_encode($canonicalRequest, JSON_THROW_ON_ERROR));

        return NotificationLocks::transaction(function () use ($principal, $operation, $target, $requestHash, $perform): JsonResponse {
            $records = DB::table('api_idempotency_records');
            $records->clone()->where($principal)->where('expires_at', '<=', now())->delete();
            $records->clone()->insertOrIgnore([...$principal, 'operation' => $operation, 'target' => $target, 'request_hash' => $requestHash,
                'created_at' => now(), 'expires_at' => now()->addHours(self::RetentionHours)]);
            $record = $records->clone()->where($principal)->lockForUpdate()->first();
            if ($record === null) {
                throw new \LogicException('The idempotency reservation must exist after insert.');
            }
            if ($record->operation !== $operation || $record->target !== $target || $record->request_hash !== $requestHash) {
                throw new ApiProblem('idempotency_conflict', 409);
            }
            /** @var \stdClass&object{id: int, response_status: int|null, response_body: string|null, response_headers: string|null} $record */
            if ($record->response_status !== null) {
                /** @var array<string, mixed> $body */
                $body = json_decode($record->response_body ?? throw new \LogicException('Stored response body missing.'), true, flags: JSON_THROW_ON_ERROR);
                /** @var array<string, string> $headers */
                $headers = json_decode($record->response_headers ?? throw new \LogicException('Stored response headers missing.'), true, flags: JSON_THROW_ON_ERROR);

                return response()->json($body, $record->response_status, $headers);
            }
            $response = $perform();
            $headers = array_filter(['Location' => $response->headers->get('Location'), 'Retry-After' => $response->headers->get('Retry-After')],
                static fn (?string $value): bool => $value !== null);
            $records->clone()->where('id', $record->id)->update(['response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(), 'response_headers' => json_encode($headers, JSON_THROW_ON_ERROR)]);

            return $response;
        });
    }
}
