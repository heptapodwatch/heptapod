<?php

declare(strict_types=1);

namespace Heptapod\Support;

use Illuminate\Validation\ValidationException;

/**
 * Resolves the client of every site an import would create, from a server-held plan and the user's choices.
 * Order: a per-site choice, then the one client proposed by the site's assigned tags, then its team. Tags that
 * propose different clients and a site without any assignment are errors; nothing defaults silently to Internal.
 * Existing sites keep their client and need no assignment.
 *
 * @phpstan-type Target array{client_id: int|null, new_client: string|null}
 */
final class ImportAssignments
{
    /**
     * @param  list<array<string, mixed>>  $plan
     * @param  array<string, Target>  $groups  Keyed `team:<id>` or `tag:<name>`.
     * @param  array<string, Target>  $overrides  Keyed by source monitor id.
     * @return array<string, Target> Keyed by source monitor id.
     */
    public static function resolve(array $plan, array $groups, array $overrides): array
    {
        $resolved = [];
        $errors = [];
        foreach ($plan as $row) {
            if (! self::needsAssignment($row)) {
                continue;
            }
            $sourceId = self::string($row, 'source_id');
            if (isset($overrides[$sourceId])) {
                $resolved[$sourceId] = $overrides[$sourceId];

                continue;
            }
            $candidates = [];
            foreach (is_array($row['tags'] ?? null) ? $row['tags'] : [] as $tag) {
                if (is_string($tag) && isset($groups['tag:'.$tag])) {
                    $candidates[self::identity($groups['tag:'.$tag])] = $groups['tag:'.$tag];
                }
            }
            $team = $groups['team:'.(is_int($row['team_id'] ?? null) ? $row['team_id'] : '')] ?? null;
            if (count($candidates) > 1) {
                $errors['assignments.'.$sourceId] = __('Tags of this site point to different clients. Choose one.');
            } elseif ($candidates !== []) {
                $resolved[$sourceId] = array_first($candidates);
            } elseif ($team !== null) {
                $resolved[$sourceId] = $team;
            } else {
                $errors['assignments.'.$sourceId] = __('Choose a client');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /** @param array<string, mixed> $row */
    public static function needsAssignment(array $row): bool
    {
        return in_array($row['operation'] ?? null, ['create', 'partial'], true) && ($row['site_id'] ?? null) === null;
    }

    /** @param Target $target */
    private static function identity(array $target): string
    {
        return $target['client_id'] !== null ? 'client:'.$target['client_id'] : 'new:'.$target['new_client'];
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : throw new \LogicException('Plan row without '.$key.'.');
    }
}
