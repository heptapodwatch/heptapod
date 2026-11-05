<?php

declare(strict_types=1);

namespace Heptapod\Support\Api;

use Heptapod\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\PersonalAccessToken;

final class CursorCodec
{
    /** @param list<mixed> $requestValues
     * @return array{user_id: int, token_id: int, client_id: int|null, request_hash: string}
     */
    public function binding(User $user, PersonalAccessToken $token, array $requestValues): array
    {
        return ['user_id' => $user->id, 'token_id' => $token->id, 'client_id' => $user->tokenClientConstraint(),
            'request_hash' => hash('sha256', json_encode($requestValues, JSON_THROW_ON_ERROR))];
    }

    /** @param array<string, mixed> $binding
     * @param  array<string, mixed>  $state
     */
    public function encode(string $endpoint, array $binding, array $state): string
    {
        return Crypt::encryptString(json_encode(['version' => 1, 'endpoint' => $endpoint, 'binding' => $binding,
            'state' => $state, 'expires_at' => now()->addMinutes(15)->getTimestamp()], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $binding
     * @return array<string, mixed>
     */
    public function decode(string $cursor, string $endpoint, array $binding): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new ApiProblem('invalid_cursor', 422);
        }
        if (! is_array($payload) || ($payload['version'] ?? null) !== 1 || ($payload['endpoint'] ?? null) !== $endpoint
            || ($payload['binding'] ?? null) !== $binding || ! is_int($payload['expires_at'] ?? null) || ! is_array($payload['state'] ?? null)) {
            throw new ApiProblem('invalid_cursor', 422);
        }
        if ($payload['expires_at'] <= now()->getTimestamp()) {
            throw new ApiProblem('cursor_expired', 410);
        }

        /** @var array<string, mixed> $state Encrypted application-owned payload, never plaintext client input. */
        $state = $payload['state'];

        return $state;
    }
}
