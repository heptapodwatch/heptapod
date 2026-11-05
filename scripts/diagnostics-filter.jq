# Retain facts, never messages. Native JSON framing keeps message text separate
# from normalized exception metadata; ambiguous legacy LineFormatter records fail closed.
def integer_or_null: if type == "number" and floor == . and . >= 0 then . else null end;
def service: if IN("app", "horizon", "dispatcher", "scheduler", "reverb") then . else null end;
def container: if type == "string" and test("^[a-f0-9]{64}$") then . else null end;
def boolean_or_null: if type == "boolean" then . else null end;
def timestamp:
    try (capture("^(?<date>[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(\\.[0-9]+)?(?<zone>Z|[+-][0-9]{2}:[0-9]{2})$") as $t |
        (($t.date + "Z" | fromdateiso8601) -
            (if $t.zone == "Z" then 0 else (($t.zone[1:3] | tonumber) * 3600 + ($t.zone[4:6] | tonumber) * 60) * (if $t.zone[0:1] == "-" then -1 else 1 end) end)) | todateiso8601) catch null;
def base: {timestamp: $at, container: $id, service: $service, stream: $mode};
def job_class:
    if IN("Heptapod\\Jobs\\BroadcastLiveSignal", "Heptapod\\Jobs\\DispatchNotifications", "Heptapod\\Jobs\\RunBackupNow", "Heptapod\\Jobs\\RunCheck", "Heptapod\\Jobs\\SendNotification", "Heptapod\\Jobs\\TerminateLiveConnections") then . else null end;
def message_key:
    if type != "string" then null
    elif test("^Allowed memory size of [0-9]+ bytes exhausted \\(tried to allocate [0-9]+ bytes\\)$") then "php.memory_exhausted"
    elif test("^Maximum execution time of [0-9]+ seconds exceeded$") then "php.execution_timeout"
    elif IN("Segmentation fault", "Segmentation fault (core dumped)") then "engine.segmentation_fault"
    elif . == "Killed" then "engine.killed"
    else null end;
def json_record($utc):
    (try fromjson catch null) as $log |
    {DEBUG:100, INFO:200, NOTICE:250, WARNING:300, ERROR:400, CRITICAL:500, ALERT:550, EMERGENCY:600} as $levels |
    if ($log | type) != "object" or $log.channel != "production" or ($log.level_name | type) != "string" then null
    elif $levels[$log.level_name] == null or $levels[$log.level_name] != $log.level or (($log.datetime | timestamp) // null) == null then null
    else (if ($log.context | type) == "object" then $log.context else {} end) as $context |
        (if ($context.exception | type) == "object" then $context.exception else {} end) as $exception |
        ((try ($exception.file | capture("^(?<file>/app/(app|vendor)/[A-Za-z0-9_./-]+):(?<line>[0-9]+)$")) catch null) // null) as $location |
        ({message_key: ($log.message | message_key)} |
            with_entries(select(.value != null))) +
        (if ($exception.class | type) == "string" and ($exception.class | test("^[A-Za-z_][A-Za-z0-9_\\\\]{0,255}$")) and $location != null and ($location.file | contains("..") | not) then
            {exceptionClass: $exception.class, file: $location.file, line: ($location.line | tonumber)} else {} end) +
        ({jobClass: ($context.job_class | job_class),
          jobUuid: (if ($context.job_uuid | type) == "string" and ($context.job_uuid | test("^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$")) then $context.job_uuid else null end),
          queue: (if $context.queue | IN("checks-fast", "checks-crawl", "checks-browser", "notifications", "reports", "broadcasts") then $context.queue else null end),
          attempt: (if ($context.attempt | integer_or_null) != null and $context.attempt > 0 then $context.attempt else null end)} |
            with_entries(select(.value != null))) as $facts |
        if ($facts | length) == 0 then null else base + {timestamp:$utc, datetime:($log.datetime | timestamp), level:$log.level, level_name:$log.level_name, channel:"production"} + $facts end
    end;
def console_record($utc):
    if test("^   INFO  (Horizon started successfully\\.|Shutting down\\.|Sending TERM signal to processes\\.|No processes to terminate\\.) *$") then
        base + {timestamp:$utc, lifecycle:(if contains("started") then "start" else "terminate" end)}
    else (try capture("^ +(?<jobAt>[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}) (?<jobClass>Heptapod\\\\Jobs\\\\[A-Za-z0-9_]+) +\\.+ +((?<duration>[0-9]+(\\.[0-9]+)?)ms )?(?<state>RUNNING|DONE|FAIL) *$") catch null) as $job |
        if $job != null and ($job.jobClass | job_class) != null then
            base + {timestamp:$utc, jobClass:$job.jobClass, lifecycle:$job.state,
                durationMilliseconds:(if $job.duration == null then null else ($job.duration | tonumber) end)} else null end
    end;
def diagnostic_record:
    (try capture("^(?<at>[0-9TZ:.+-]+) (?<text>.*)$") catch null) as $line |
    if $line == null or ($line.at | timestamp) == null then null
    else ($line.at | timestamp) as $utc | $line.text as $text |
        if $mode == "stderr" or ($mode == "stdout" and $service == "horizon") then
            ($text | json_record($utc)) //
            (if $mode == "stdout" then $text | console_record($utc) else null end)
        elif $mode == "stdout" then $text | console_record($utc)
        else null end
    end;
if $mode == "stderr" or $mode == "stdout" then
    foreach (inputs, null) as $line (
        {suppressedCount:0, suppressedBytes:0, record:null};
        if $line == null then .record = (if .suppressedCount > 0 then base + {windowStart:$since, suppressedCount, suppressedBytes} else null end)
        else (($line | diagnostic_record) // null) as $record |
            .record = $record |
            if $record == null then .suppressedCount += 1 | .suppressedBytes += (($line | utf8bytelength) + 1) else . end
        end;
        .record | select(. != null)
    )
elif $mode == "state" then
    inputs | fromjson | ($ARGS.named.peak // "unsupported") as $peak |
    {timestamp: $at, container: (.id | container), service: (.service | service),
     imageRevision: (if .image | test("^(sha256:)?[a-f0-9]{64}$") then .image else null end),
     exitCode: (.state.ExitCode | integer_or_null), signal: null,
     oomKilled: (.state.OOMKilled | boolean_or_null), restartCount: (.restarts | integer_or_null),
     memoryLimitBytes: (.memory | integer_or_null),
     memoryPeakBytes: (if $peak | test("^[0-9]{1,20}$") then ($peak | tonumber | integer_or_null) else null end),
     memoryPeakStatus: (if $peak | test("^[0-9]{1,20}$") then "available" elif $peak | IN("stopped", "unsupported", "probe_failed") then $peak else "invalid_counter" end),
     status: (if .state.Status | IN("created", "running", "paused", "restarting", "removing", "exited", "dead", "stopped") then .state.Status else null end)} |
    select(.container != null and .service != null)
elif $mode == "events" then
    inputs | fromjson |
    (.Action // .status) as $action |
    select($action | IN("start", "stop", "kill", "die", "died", "oom", "restart", "destroy", "remove")) |
    {timestamp: ((.time // .Time) | integer_or_null | if . == null then null else todateiso8601 end),
     container: ((.Actor.ID // .id // .ID) | container),
     service: ((.Actor.Attributes["com.docker.compose.service"] // .Attributes["com.docker.compose.service"]) | service),
     lifecycle: $action,
     exitCode: (try ((.Actor.Attributes.exitCode // .Attributes.exitCode) | tonumber | integer_or_null) catch null),
     signal: (try ((.Actor.Attributes.signal // .Attributes.signal) | tonumber | integer_or_null) catch null),
     oomKilled: (if $action == "oom" then true else null end)} |
    select(.container != null and .service != null)
elif $mode == "host" then
    # Sources are host counters. Emit only numeric values and fixed failure reasons.
    def measurement($raw; $scale):
        if $raw | type == "string" and test("^[0-9]{1,12}(\\.[0-9]{1,6})?$") then
            {value: (($raw | tonumber) * $scale), reason: null}
        else {value: null, reason: (if $raw | IN("source_unavailable", "field_unavailable", "probe_failed") then $raw else "invalid_counter" end)} end;
    {load1: measurement($ARGS.named.load1; 1), load5: measurement($ARGS.named.load5; 1), load15: measurement($ARGS.named.load15; 1),
     memoryTotalBytes: measurement($ARGS.named.memoryTotal; 1024), memoryAvailableBytes: measurement($ARGS.named.memoryAvailable; 1024),
     swapTotalBytes: measurement($ARGS.named.swapTotal; 1024), swapFreeBytes: measurement($ARGS.named.swapFree; 1024)} as $metrics |
    {timestamp: $at, windowStart: $since,
     hostPressure: (($metrics | with_entries(.value = .value.value)) +
        {unavailable: ($metrics | with_entries(select(.value.reason != null) | .value = .value.reason))})}
else error("Unknown diagnostics input mode") end
