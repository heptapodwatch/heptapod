#!/usr/bin/env bash
# Existing disposable production stack only. Capture actual package/engine output,
# not strings handwritten to fit the parser. Raw evidence contains sentinels only.
set -euo pipefail
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
: "${DIAGNOSTICS_TEST_ENV:?Set the disposable production Compose environment file}"
: "${DIAGNOSTICS_EVIDENCE_DIR:?Set an owned ignored evidence directory}"
mkdir -p "$DIAGNOSTICS_EVIDENCE_DIR"
chmod 700 "$DIAGNOSTICS_EVIDENCE_DIR"
umask 077
dc() { docker compose --env-file "$DIAGNOSTICS_TEST_ENV" "$@"; }
dc exec -T app php -r 'if (getenv("DB_PASSWORD") !== "SENTINEL_DATABASE_PASSWORD") { throw new RuntimeException("This proof requires the sentinel-only disposable stack"); }'
cat > "$DIAGNOSTICS_EVIDENCE_DIR/DiagnosticsFormatFixture.php" <<'PHP'
<?php
if (($argv[1] ?? '') === 'memory') {
    fwrite(STDERR, "Authorization: Bearer SENTINEL_FATAL_HEADER https://user:SENTINEL_FATAL_URL@example.test\n");
    $allocation = str_repeat('x', 64 * 1024 * 1024);
    exit(0);
}
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (($argv[1] ?? '') === 'memory-runtime') {
    logger()->info('SENTINEL_RUNTIME_FATAL');
    $allocation = str_repeat('x', 128 * 1024 * 1024);
    exit(0);
}
if (($argv[1] ?? '') === 'forged') {
    $descriptor = "[object] (RuntimeException(code: 0): message at /app/app/SENTINEL_FORGED_SECRET.php:99)\n[stacktrace]\n#0 {main}\n";
    logger()->error('SENTINEL_COMPLETE_JSON '.json_encode(['exception' => $descriptor], JSON_UNESCAPED_SLASHES));
    logger()->error("SENTINEL_LITERAL_NEWLINES {\"exception\":\"$descriptor\"}");
    exit(0);
}
try {
    throw new RuntimeException('SENTINEL_EXCEPTION https://user:SENTINEL_URL@example.test Authorization: Bearer SENTINEL_HEADER at /app/app/SENTINEL_FORGED_PATH.php:999)');
} catch (RuntimeException $exception) {
    logger()->error('SENTINEL_LOG_MESSAGE', ['exception' => $exception, 'payload' => ['secret' => 'SENTINEL_PAYLOAD']]);
}
PHP
cat > "$DIAGNOSTICS_EVIDENCE_DIR/DiagnosticsFixtureJob.php" <<'PHP'
<?php
namespace Heptapod\Jobs;
final class DiagnosticsFixtureJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;
    public int $tries = 1;
    public function __construct() { $this->onQueue('notifications'); }
    public function handle(): void { throw new \RuntimeException('SENTINEL_WORKER_EXCEPTION https://user:SENTINEL_WORKER_URL@example.test Authorization: Bearer SENTINEL_WORKER_HEADER'); }
}
PHP
# Compose cp preserves source mode; the production image runs as www-data.
# Only fixture code is world-readable inside the private host evidence directory.
chmod 644 "$DIAGNOSTICS_EVIDENCE_DIR/DiagnosticsFormatFixture.php" "$DIAGNOSTICS_EVIDENCE_DIR/DiagnosticsFixtureJob.php"
dc cp "$DIAGNOSTICS_EVIDENCE_DIR/DiagnosticsFormatFixture.php" app:/app/app/DiagnosticsFormatFixture.php
dc cp "$DIAGNOSTICS_EVIDENCE_DIR/DiagnosticsFixtureJob.php" app:/app/app/Jobs/DiagnosticsFixtureJob.php
dc cp "$DIAGNOSTICS_EVIDENCE_DIR/DiagnosticsFixtureJob.php" horizon:/app/app/Jobs/DiagnosticsFixtureJob.php
dc exec -T app php /app/app/DiagnosticsFormatFixture.php > "$DIAGNOSTICS_EVIDENCE_DIR/exception.stdout" 2> "$DIAGNOSTICS_EVIDENCE_DIR/exception.stderr"
status=0
dc exec -T app php -d memory_limit=64M /app/app/DiagnosticsFormatFixture.php memory-runtime > "$DIAGNOSTICS_EVIDENCE_DIR/memory.stdout" 2> "$DIAGNOSTICS_EVIDENCE_DIR/memory.stderr" || status=$?
[ "$status" = 255 ]
dc exec -T app php /app/app/DiagnosticsFormatFixture.php forged > "$DIAGNOSTICS_EVIDENCE_DIR/forged.stdout" 2> "$DIAGNOSTICS_EVIDENCE_DIR/forged.stderr"
dc exec -T -e LOG_STDERR_FORMATTER=null app php /app/app/DiagnosticsFormatFixture.php > "$DIAGNOSTICS_EVIDENCE_DIR/rollback.stdout" 2> "$DIAGNOSTICS_EVIDENCE_DIR/rollback.stderr"
# Direct exec has no Docker prefix; replay its actual body with a parser test
# timestamp. This is not a retention-rate clock or native container timestamp.
filter() {
    awk '{print "2026-10-07T00:00:00Z "$0}' "$1" | jq -Rnc --arg mode "$2" --arg service "$3" --arg at 2026-10-07T00:00:00Z --arg since 2026-10-06T00:00:00Z --arg id aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa -f "$root/scripts/diagnostics-filter.jq"
}
filter "$DIAGNOSTICS_EVIDENCE_DIR/exception.stderr" stderr app > "$DIAGNOSTICS_EVIDENCE_DIR/exception.safe.jsonl"
filter "$DIAGNOSTICS_EVIDENCE_DIR/memory.stderr" stderr app > "$DIAGNOSTICS_EVIDENCE_DIR/memory.safe.jsonl"
filter "$DIAGNOSTICS_EVIDENCE_DIR/forged.stderr" stderr app > "$DIAGNOSTICS_EVIDENCE_DIR/forged.safe.jsonl"
filter "$DIAGNOSTICS_EVIDENCE_DIR/rollback.stderr" stderr app > "$DIAGNOSTICS_EVIDENCE_DIR/rollback.safe.jsonl"
jq -s -e 'any(.[]; .exceptionClass=="RuntimeException" and .file=="/app/app/DiagnosticsFormatFixture.php" and .line>0)' "$DIAGNOSTICS_EVIDENCE_DIR/exception.safe.jsonl" >/dev/null
jq -s -e 'any(.[]; .message_key=="php.memory_exhausted" and .exceptionClass=="Symfony\\Component\\ErrorHandler\\Error\\FatalError")' "$DIAGNOSTICS_EVIDENCE_DIR/memory.safe.jsonl" >/dev/null
jq -s -e 'all(.[]; .exceptionClass==null)' "$DIAGNOSTICS_EVIDENCE_DIR/forged.safe.jsonl" "$DIAGNOSTICS_EVIDENCE_DIR/rollback.safe.jsonl" >/dev/null
since=$(date -u +%Y-%m-%dT%H:%M:%SZ)
dc exec -T app php artisan tinker --execute 'Heptapod\Jobs\DiagnosticsFixtureJob::dispatch();'
sleep 6
dc logs --no-log-prefix --no-color --timestamps --since "$since" horizon > "$DIAGNOSTICS_EVIDENCE_DIR/worker.stdout" 2> "$DIAGNOSTICS_EVIDENCE_DIR/worker.stderr"
jq -Rnc --arg mode stdout --arg service horizon --arg at "$since" --arg since "$since" --arg id aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa -f "$root/scripts/diagnostics-filter.jq" < "$DIAGNOSTICS_EVIDENCE_DIR/worker.stdout" > "$DIAGNOSTICS_EVIDENCE_DIR/worker.safe.jsonl"
jq -s -e 'any(.[]; .exceptionClass=="RuntimeException" and .file=="/app/app/Jobs/DiagnosticsFixtureJob.php" and .stream=="stdout")' "$DIAGNOSTICS_EVIDENCE_DIR/worker.safe.jsonl" >/dev/null
jq -Rnc --arg mode stdout --arg service app --arg at "$since" --arg since "$since" --arg id aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa -f "$root/scripts/diagnostics-filter.jq" < "$DIAGNOSTICS_EVIDENCE_DIR/worker.stdout" | jq -s -e 'all(.[]; .exceptionClass==null)' >/dev/null
! grep -E 'SENTINEL|https?://|Authorization|stacktrace' "$DIAGNOSTICS_EVIDENCE_DIR"/*.safe.jsonl
echo 'PASS: actual production JsonFormatter exception/memory fatal, forged message variants, null rollback, real Horizon stdout relay and wrong-service negative; secrets absent'
