<?php

declare(strict_types=1);

/**
 * Deterministic regression guard (Outcome 5): forbids bare abort(409|410|413|422|428|429) in web-reachable PHP
 * outside an explicit allowlist of reviewed Unexpected invariant guards and InvitationController's custom page flow.
 * Every Expected 4xx refusal must throw Heptapod\Exceptions\ExpectedRefusal so bootstrap/app.php handles it uniformly.
 */
test('web code uses ExpectedRefusal instead of bare 4xx aborts outside the unexpected allowlist', function (): void {
    $root = dirname(__DIR__, 2);
    $allowlist = [
        // Caught by InvitationController::unavailable() and rendered via invitations/show (AuthLayout).
        'app/Actions/AcceptInvitation.php' => [409, 410],
        'app/Http/Controllers/InvitationController.php' => [410],
        // Unexpected invariant guards: client always sends Idempotency-Key; malformed cursor is a tampered query.
        'app/Http/Controllers/ClientMessagesController.php' => [428],
        'app/Queries/IncidentsReader.php' => [422],
    ];
    $watchedStatuses = [409, 410, 413, 422, 428, 429];
    $violations = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (str_starts_with($relative, 'app/Http/Controllers/Api/') || str_starts_with($relative, 'app/Testing/')) {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        expect($source)->not->toBeFalse();
        preg_match_all('/\babort(?:_if|_unless)?\s*\(([^;]+)\)/', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $index => $match) {
            $args = $matches[1][$index][0];
            if (preg_match('/\b(409|410|413|422|428|429)\b/', $args, $statusMatch) !== 1) {
                continue;
            }
            $status = (int) $statusMatch[1];
            if (! in_array($status, $watchedStatuses, true)) {
                continue;
            }
            if (in_array($status, $allowlist[$relative] ?? [], true)) {
                continue;
            }
            $line = substr_count(substr($source, 0, $match[1]), "\n") + 1;
            $violations[] = "{$relative}:{$line} uses bare abort*({$status}); throw Heptapod\\Exceptions\\ExpectedRefusal instead.";
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
});
