<?php

declare(strict_types=1);

/**
 * Deterministic regression guard (Outcome 5): forbids bare abort*(409|410|413|422|428|429), explicit
 * Symfony/Laravel 4xx HTTP exceptions, and raw 4xx response() calls in web-reachable PHP outside an explicit
 * allowlist of reviewed Unexpected invariant guards and InvitationController's custom page flow.
 * Every Expected 4xx refusal must throw Heptapod\Exceptions\ExpectedRefusal so bootstrap/app.php handles it uniformly.
 */
test('web code uses ExpectedRefusal instead of bare 4xx aborts, HTTP exceptions or raw responses', function (): void {
    $root = dirname(__DIR__, 2);
    $allowlist = [
        // Caught by InvitationController::unavailable() and rendered via invitations/show (AuthLayout).
        'app/Actions/AcceptInvitation.php' => ['abort:409', 'abort:410'],
        'app/Http/Controllers/InvitationController.php' => ['abort:410'],
        // Unexpected invariant guards: client always sends Idempotency-Key; malformed cursor is a tampered query.
        'app/Http/Controllers/ClientMessagesController.php' => ['abort:428'],
        'app/Queries/IncidentsReader.php' => ['abort:422'],
        // API-only renderer; web RequestCheckRunController catches ManualRunRefused and throws ExpectedRefusal.
        'app/Checks/Execution/ManualRunRefused.php' => ['response:429'],
    ];
    $violations = [];

    foreach ([$root.'/app', $root.'/routes'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (
                str_starts_with($relative, 'app/Http/Controllers/Api/')
                || str_starts_with($relative, 'app/Testing/')
                || $relative === 'app/Http/ErrorResponses.php'
            ) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            expect($source)->not->toBeFalse();
            $allowed = $allowlist[$relative] ?? [];

            // 1. Bare abort / abort_if / abort_unless with Expected 4xx statuses.
            preg_match_all('/\babort(?:_if|_unless)?\s*\(([^;]+)\)/', $source, $abortMatches, PREG_OFFSET_CAPTURE);
            foreach ($abortMatches[0] as $index => $match) {
                if (preg_match('/\b(409|410|413|422|428|429)\b/', $abortMatches[1][$index][0], $statusMatch) !== 1) {
                    continue;
                }
                $tag = 'abort:'.$statusMatch[1];
                if (in_array($tag, $allowed, true)) {
                    continue;
                }
                $line = substr_count(substr($source, 0, $match[1]), "\n") + 1;
                $violations[] = "{$relative}:{$line} uses bare abort*({$statusMatch[1]}); throw Heptapod\\Exceptions\\ExpectedRefusal instead.";
            }

            // 2. Explicit Symfony/Laravel Expected-4xx HTTP exceptions (imported or fully-qualified).
            preg_match_all(
                '/\bthrow\s+new\s+(?:\\\\?[A-Za-z0-9_\\\\]+\\\\)?(?:ConflictHttpException|GoneHttpException|TooManyRequestsHttpException|UnprocessableEntityHttpException|PreconditionRequiredHttpException|ThrottleRequestsException)\b|\bthrow\s+new\s+(?:\\\\?[A-Za-z0-9_\\\\]+\\\\)?HttpException\s*\(\s*(409|410|413|422|428|429)\b/',
                $source,
                $throwMatches,
                PREG_OFFSET_CAPTURE,
            );
            foreach ($throwMatches[0] as $match) {
                $line = substr_count(substr($source, 0, $match[1]), "\n") + 1;
                $violations[] = "{$relative}:{$line} throws an HTTP refusal exception directly; throw Heptapod\\Exceptions\\ExpectedRefusal instead.";
            }

            // 3. Raw 4xx response()/json() status codes in web controllers/actions.
            preg_match_all(
                '/\bresponse\s*\([^;]*?\b(409|410|413|422|428|429)\b/',
                $source,
                $responseMatches,
                PREG_OFFSET_CAPTURE,
            );
            foreach ($responseMatches[0] as $index => $match) {
                $tag = 'response:'.$responseMatches[1][$index][0];
                if (in_array($tag, $allowed, true)) {
                    continue;
                }
                $line = substr_count(substr($source, 0, $match[1]), "\n") + 1;
                $violations[] = "{$relative}:{$line} returns a raw 4xx response(); throw Heptapod\\Exceptions\\ExpectedRefusal instead.";
            }
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
});
