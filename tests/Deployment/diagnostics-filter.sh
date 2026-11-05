#!/usr/bin/env bash
# Protect typed production facts; legacy text and message-shaped JSON fail closed.
set -euo pipefail
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
fresh=$(mktemp -d)
trap 'rm -rf "$fresh"' EXIT
id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
filter() { jq -Rnc --arg mode "$1" --arg at 2026-10-07T00:00:00Z --arg since 2026-10-06T00:00:00Z --arg id "$id" --arg service "${3:-horizon}" --arg peak "${2:-unsupported}" -f "$root/scripts/diagnostics-filter.jq"; }
cat <<'LOG' | filter stderr > "$fresh/safe.jsonl"
2026-10-07T02:00:00.123+02:00 {"datetime":"2026-10-07T00:00:00+00:00","channel":"production","level":400,"level_name":"ERROR","message":"Allowed memory size of 123 bytes exhausted (tried to allocate 456 bytes)","context":{}}
2026-10-07T00:00:00Z {"datetime":"2026-10-07T00:00:00+00:00","channel":"production","level":400,"level_name":"ERROR","message":"Maximum execution time of 30 seconds exceeded","context":{}}
2026-10-07T00:00:00Z {"datetime":"2026-10-07T00:00:00+00:00","channel":"production","level":400,"level_name":"ERROR","message":"SENTINEL_MESSAGE {\"exception\":\"[object] (RuntimeException(code: 0): text at /app/app/SENTINEL_FORGED.php:99)\"}","context":{"exception":{"class":"RuntimeException","file":"/app/vendor/Fixture.php:42","message":"https://user:SENTINEL_URL@example.test Authorization: Bearer SENTINEL_HEADER","trace":["SENTINEL_TRACE"]},"payload":"SENTINEL_PAYLOAD"}}
2026-10-07T00:00:00Z Authorization: Bearer SENTINEL_HEADER
2026-10-07T00:00:00Z {"datetime":"2026-10-07T00:00:00+00:00","channel":"production","level":400,"level_name":"ERROR","message":"SENTINEL_MESSAGE","context":{"exception":{"class":"RuntimeException","file":"/home/user/SENTINEL_PATH.php:42"}}}
2026-10-07T00:00:00Z [2026-10-07 00:00:00] production.ERROR: SENTINEL_FREE_FORM {"exception":"[object] (RuntimeException(code: 0): text at /app/app/SENTINEL_FORGED.php:99)
2026-10-07T00:00:00Z {"datetime":"2026-10-07T00:00:00+00:00","channel":"production","level":null,"level_name":"SENTINEL_LEVEL","context":{"exception":{"class":"RuntimeException","file":"/app/app/Fixture.php:1"}}}
2026-10-07T00:00:00Z {"datetime":"2026-10-07T00:00:00+00:00","channel":"SENTINEL_CHANNEL","level":400,"level_name":"ERROR","context":{"exception":{"class":"RuntimeException","file":"/app/app/Fixture.php:1"}}}
2026-10-07T00:00:00Z {"password":"SENTINEL_PAYLOAD","SQL":"SENTINEL_SQL"}
2026-10-07T00:00:00Z Killed
LOG
jq -s -e 'length == 4 and all(.[]; .timestamp == "2026-10-07T00:00:00Z" and .stream == "stderr") and any(.[]; .message_key == "php.memory_exhausted") and any(.[]; .message_key == "php.execution_timeout") and any(.[]; .exceptionClass == "RuntimeException" and .file == "/app/vendor/Fixture.php" and .line == 42) and any(.[]; .suppressedCount == 7 and .suppressedBytes > 0)' "$fresh/safe.jsonl" >/dev/null
! grep -Eq 'SENTINEL|Authorization|https?://|stacktrace|password|SQL|/home/' "$fresh/safe.jsonl"
[ -z "$(filter stderr < /dev/null)" ]
cat <<'LOG' | filter stdout > "$fresh/stdout.jsonl"
2026-10-07T00:00:00Z    INFO  Horizon started successfully.
2026-10-07T00:00:00Z   2026-10-07 00:00:00 Heptapod\Jobs\RunCheck ........ 42.00ms DONE
2026-10-07T00:00:00Z   2026-10-07 00:00:00 SENTINEL_DISPLAY_NAME ....... RUNNING
2026-10-07T00:00:00Z {"datetime":"2026-10-07T00:00:00+00:00","channel":"production","level":400,"level_name":"ERROR","message":"SENTINEL_RELAY","context":{"exception":{"class":"RuntimeException","file":"/app/app/Fixture.php:7"}}}
LOG
jq -s -e 'any(.[]; .lifecycle=="start") and any(.[]; .jobClass=="Heptapod\\Jobs\\RunCheck" and .lifecycle=="DONE" and .durationMilliseconds==42) and any(.[]; .exceptionClass=="RuntimeException" and .stream=="stdout")' "$fresh/stdout.jsonl" >/dev/null
! grep -q SENTINEL "$fresh/stdout.jsonl"
printf '%s\n' '2026-10-07T00:00:00Z {"datetime":"2026-10-07T00:00:00+00:00","channel":"production","level":400,"level_name":"ERROR","context":{"exception":{"class":"RuntimeException","file":"/app/app/Fixture.php:7"}}}' | filter stdout unsupported app | jq -e '.exceptionClass==null and .suppressedCount==1' >/dev/null
printf '%s\n' '2026-10-07T00:00:00Z {"datetime":"2026-10-07T00:00:00+00:00","channel":"production","level":200,"level_name":"INFO","context":{"job_class":"Heptapod\\Jobs\\RunCheck","job_uuid":"11111111-2222-3333-4444-555555555555","queue":"checks-fast","attempt":2,"payload":"SENTINEL_JOB_PAYLOAD"}}' | filter stderr | jq -e '.jobClass=="Heptapod\\Jobs\\RunCheck" and .jobUuid=="11111111-2222-3333-4444-555555555555" and .queue=="checks-fast" and .attempt==2 and .payload==null' >/dev/null
# Synthetic metadata tests parser semantics, not real OOM evidence.
printf '{"id":"%s","service":"horizon","image":"sha256:%s","state":{"ExitCode":137},"restarts":2,"memory":123}\n' "$id" "$id" | filter state > "$fresh/state.jsonl"
jq -e '.exitCode == 137 and .oomKilled == null and .signal == null and .memoryPeakBytes == null and .memoryPeakStatus == "unsupported" and .restartCount == 2 and .memoryLimitBytes == 123' "$fresh/state.jsonl" >/dev/null
printf '{"id":"%s","service":"horizon","image":"sha256:%s","state":{"ExitCode":0},"restarts":0,"memory":123}\n' "$id" "$id" > "$fresh/metadata.json"
filter state 456 < "$fresh/metadata.json" | jq -e '.memoryPeakBytes == 456 and .memoryPeakStatus == "available"' >/dev/null
filter state stopped < "$fresh/metadata.json" | jq -e '.memoryPeakBytes == null and .memoryPeakStatus == "stopped"' >/dev/null
filter state probe_failed < "$fresh/metadata.json" | jq -e '.memoryPeakBytes == null and .memoryPeakStatus == "probe_failed"' >/dev/null
filter state SENTINEL_COUNTER < "$fresh/metadata.json" | jq -e '.memoryPeakBytes == null and .memoryPeakStatus == "invalid_counter"' >/dev/null
printf '{"time":1791331200,"Action":"die","Actor":{"ID":"%s","Attributes":{"com.docker.compose.service":"horizon","exitCode":"137","token":"SENTINEL_EVENT"}}}\n' "$id" | filter events | jq -e '.exitCode == 137 and .oomKilled == null and .signal == null' >/dev/null
host_filter() { jq -nc --arg mode host --arg at 2026-10-07T00:00:00Z --arg since 2026-10-06T00:00:00Z --arg id '' --arg service '' "$@" -f "$root/scripts/diagnostics-filter.jq"; }
host_filter --arg load1 1.25 --arg load5 2 --arg load15 0 --arg memoryTotal 1024 --arg memoryAvailable 512 --arg swapTotal 0 --arg swapFree 0 > "$fresh/host.json"
jq -e '.hostPressure == {load1:1.25,load5:2,load15:0,memoryTotalBytes:1048576,memoryAvailableBytes:524288,swapTotalBytes:0,swapFreeBytes:0,unavailable:{}}' "$fresh/host.json" >/dev/null
host_filter --arg load1 source_unavailable --arg load5 probe_failed --arg load15 SENTINEL_HOST --arg memoryTotal field_unavailable --arg memoryAvailable source_unavailable --arg swapTotal 0 --arg swapFree 0 > "$fresh/host.json"
jq -e '.hostPressure.load1 == null and .hostPressure.unavailable.load1 == "source_unavailable" and .hostPressure.load5 == null and .hostPressure.unavailable.load5 == "probe_failed" and .hostPressure.load15 == null and .hostPressure.unavailable.load15 == "invalid_counter" and .hostPressure.memoryTotalBytes == null and .hostPressure.unavailable.memoryTotalBytes == "field_unavailable" and .hostPressure.swapTotalBytes == 0' "$fresh/host.json" >/dev/null
! grep -q SENTINEL "$fresh/host.json"
echo 'PASS: JSON framing, real-source fields, forged-message/legacy/unknown-level suppression, stream identity, fixed console lifecycle, optional safe job fields, no empty summaries'
echo 'PASS: unknown OOM/peak and numeric/unknown host pressure remain truthful'
