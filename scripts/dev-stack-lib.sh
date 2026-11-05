# Guards shared by scripts/dev-up.sh and scripts/smoke.sh. Sourced, not executed.
# Callers cd into the checkout first. Host values stay in .env and the environment; nothing here names a host.

die() {
    echo "$(basename "$0"): $*" >&2
    exit 1
}

env_value() {
    local line
    line=$(grep -E "^$1=" .env | tail -n 1 || true)
    line=${line#*=}
    line=${line%\"}
    printf '%s' "${line#\"}"
}

# APP_URL is the only origin smoke and dev-up send requests to; smoke sends the demo password there.
# Accepts only the loopback origin on the published APP_PORT: no credentials, path, query or unresolved
# interpolation. Pure string check, so a refusal never contacts the value it refuses.
validate_app_origin() {
    # Refusals name the rule, never the rejected value: a credentials-bearing URL must not leak into logs.
    local raw=$1 port=$2 origin
    [[ $port =~ ^[0-9]+$ ]] || die 'APP_PORT must be a port number (value withheld)'
    origin=${raw//\$\{APP_PORT\}/$port}
    [[ $origin != *'$'* ]] || die 'APP_URL has unresolved interpolation (value withheld)'
    [[ $origin != *@* ]] || die 'APP_URL carries credentials (value withheld)'
    [[ $origin =~ ^http://(localhost|127\.0\.0\.1):([0-9]+)$ ]] ||
        die 'APP_URL must be http://localhost:APP_PORT or http://127.0.0.1:APP_PORT with no path or query (value withheld)'
    [ "${BASH_REMATCH[2]}" = "$port" ] || die 'APP_URL port differs from APP_PORT (values withheld)'
    printf '%s\n' "$origin"
}

# Runs before any Compose command that can start or recreate containers. The effective project name
# (after shell overrides) and the app's /workspace bind source must both belong to this checkout.
require_owned_stack() {
    [ -f .env ] || die 'missing .env; copy .env.example and set the host values there'
    local config project mount existing foreign
    config=$(docker compose config --format json)
    project=$(jq -r '.name' <<<"$config")
    [ -n "$project" ] && [ "$project" = "$(env_value COMPOSE_PROJECT_NAME)" ] ||
        die "effective Compose project '$project' differs from COMPOSE_PROJECT_NAME in .env; refusing"
    mount=$(jq -r '.services.app.volumes[] | select(.target == "/workspace") | .source' <<<"$config")
    [ "$mount" = "$PWD" ] || die "app /workspace is bound from '$mount', not this checkout; refusing"
    # Desired config cannot see containers that already exist under this project name; `up` would recreate them.
    existing=$(docker ps -a --filter "label=com.docker.compose.project=$project" --format '{{.Names}}\t{{.Label "com.docker.compose.project.working_dir"}}')
    # A missing working-directory label is not proof of ownership, so it fails closed like a foreign one.
    foreign=$(awk -F'\t' -v dir="$PWD" '$2 != dir {print $1}' <<<"$existing")
    [ -z "$foreign" ] || die "containers under project '$project' belong to another checkout: $(paste -sd' ' <<<"$foreign"); refusing"
}

# Hash of every file the dev image takes from this checkout: everything under docker/dev (Dockerfile included), plus the
# extension list and npm manifests it COPYs. Node and PHP base images are pinned by tag in the Dockerfile and are not covered.
dev_image_input_hash() {
    local -a inputs
    mapfile -t inputs < <({ find docker/dev -type f; printf '%s\n' docker/php-extensions.txt package.json package-lock.json; } | LC_ALL=C sort)
    # An empty list would make sha256sum read stdin and hang.
    [ "${#inputs[@]}" -gt 0 ] || return 1
    sha256sum -- "${inputs[@]}" | sha256sum | cut -d' ' -f1
}

# Refuses to reset or start a stack whose dev-image services were not built from the current dev inputs. An image without
# the label predates it and cannot be proven current, so it is stale too. Services are read from the effective Compose config,
# so a Compose override that pins an image reference is checked as well; a rebuild does not change such a pin.
require_fresh_dev_images() {
    local scenario=$1 allow_stale=$2 config expected service image_ref source built label finding
    local -a stale=()
    local pinned_stale=0
    docker info >/dev/null 2>&1 || die 'Docker is not reachable; refusing'
    expected=$(dev_image_input_hash) || die 'could not hash the dev image inputs; refusing'
    config=$(docker compose config --format json) || die 'could not resolve Compose services; refusing'
    # The dev-image services are named by the checkout's own compose.yaml, so an override that drops `build:` cannot hide one.
    # Each image is read from the resolved config, so an override's image pin is what gets checked.
    base=$(docker compose -f compose.yaml config --format json) || die 'could not resolve compose.yaml services; refusing'
    rows=$(jq -r --argjson base "$base" '
        .name as $project
        | ($base.services | to_entries | map(select(.value.build.dockerfile == "docker/dev/Dockerfile") | .key)) as $devServices
        | .services | to_entries[]
        | .key as $service
        | select(($devServices | any(. == $service)) or .value.build.dockerfile == "docker/dev/Dockerfile")
        | [$service, (.value.image // ($project + "-" + $service)), (if .value.image then "pinned" else "derived" end)] | @tsv' <<<"$config") ||
        die 'could not list dev-image services; refusing'
    # Rows come in on fd 3 and docker gets /dev/null: a stdin-reading CLI call here would swallow the next row unnoticed.
    while IFS=$'\t' read -r service image_ref source <&3; do
        if ! built=$(docker image inspect "$image_ref" --format '{{index .Config.Labels "heptapod.dev-input-hash"}}|{{.Created}}' </dev/null 2>/dev/null); then
            finding="service $service: image '$image_ref' is not built locally"
        else
            label=${built%%|*}
            [ "$label" = "$expected" ] && continue
            finding="service $service: image '$image_ref' built ${built#*|}; input hash ${label:-none} != expected $expected"
        fi
        if [ "$source" = pinned ]; then
            finding+=' (pinned by Compose config)'
            pinned_stale=1
        fi
        stale+=("$finding")
    done 3<<<"$rows"
    [ "${#stale[@]}" -gt 0 ] || return 0
    if [ "$allow_stale" = 1 ]; then
        printf 'dev-up: WARNING: --allow-stale-image: starting with a stale dev image: %s\n' "${stale[@]}" >&2
        return 0
    fi
    printf 'dev-up: stale dev image: %s\n' "${stale[@]}" >&2
    echo "dev-up: rebuild: DEV_IMAGE_INPUT_HASH=\$(scripts/dev-image-hash.sh) docker compose build && scripts/dev-up.sh $scenario" >&2
    if [ "$pinned_stale" = 1 ]; then
        echo 'dev-up: a pinned service keeps its pinned image after a rebuild; remove the pin from the Compose override first' >&2
    fi
    die 'refusing before any reset; pass --allow-stale-image to start anyway'
}

# Boots environment and configuration only: no provider boot, no connection opened, no migrations.
# Callers append the PHP that reads config and echoes JSON.
CONFIG_BOOTSTRAP_PHP='require "/workspace/vendor/autoload.php";
$app = Illuminate\Foundation\Application::configure(basePath: "/workspace")->create();
if ($app->configurationIsCached()) {
    throw new LogicException("Development probes refuse cached configuration.");
}
$app->bootstrapWith([Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class, Illuminate\Foundation\Bootstrap\LoadConfiguration::class]);
'

# What one service runs with: .env plus that service's own Compose environment, after Laravel parses DB_URL and
# REDIS_URL. Emits non-secret target names and booleans only; the heartbeat URL itself is never emitted.
SERVICE_CONFIG_PHP='$parse = fn (array $connection): array => (new Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($connection);
$literal = function ($name): string {
    if (!is_string($name) || preg_match("/^[A-Za-z0-9_-]+$/D", $name) !== 1) {
        throw new InvalidArgumentException("Queue reset requires literal connection and queue names.");
    }
    return $name;
};
$pairs = [];
$add = function ($connection, $queue) use (&$pairs, $literal): void {
    $connection = $literal($connection);
    $queue = $literal($queue);
    $pairs[$connection.":".$queue] = ["connection" => $connection, "queue" => $queue];
};
$default = $literal(config("queue.default"));
$add($default, config("queue.connections.$default.queue"));
$supervisors = collect(Laravel\Horizon\ProvisioningPlan::get("dev-reset")->parsed)
    ->first(fn ($plan, $environment) => Illuminate\Support\Str::is($environment, config("app.env")));
if (empty($supervisors)) {
    throw new InvalidArgumentException("Queue reset requires an effective Horizon plan.");
}
foreach ($supervisors as $supervisor) {
    $connection = $literal($supervisor->connection);
    $add($connection, config("queue.connections.$connection.queue"));
    foreach (explode(",", $supervisor->queue) as $queueName) {
        $add($connection, $queueName);
    }
}
ksort($pairs);
$queuePlan = [];
foreach ($pairs as $pair) {
    $connection = config("queue.connections.".$pair["connection"]);
    if (!is_array($connection) || ($connection["driver"] ?? null) !== "redis") {
        throw new InvalidArgumentException("Queue reset supports configured Redis queues only.");
    }
    $redisName = $literal($connection["connection"] ?? null);
    $redisConfig = config("database.redis.$redisName");
    if (!is_array($redisConfig) || config("database.redis.client") !== "phpredis") {
        throw new InvalidArgumentException("Queue reset requires an unambiguous standalone phpredis connection.");
    }
    $redisConfig = $parse($redisConfig);
    $options = array_merge(config("database.redis.options", []), $redisConfig["options"] ?? []);
    $queuePlan[] = $pair + ["host" => $redisConfig["host"] ?? null, "port" => (string) ($redisConfig["port"] ?? ""),
        "database" => (string) ($redisConfig["database"] ?? ""), "prefix" => $redisConfig["prefix"] ?? $options["prefix"] ?? null];
}
$database = $parse(config("database.connections.".config("database.default")));
$queue = $parse(config("database.redis.".config("queue.connections.redis.connection")));
$cache = $parse(config("database.redis.".config("cache.stores.redis.connection")));
echo json_encode([
    "env" => config("app.env"),
    "queuePlan" => $queuePlan,
    "database" => ["driver" => $database["driver"] ?? null, "host" => $database["host"] ?? null, "port" => (string) ($database["port"] ?? ""), "name" => $database["database"] ?? null],
    "queue" => ["default" => config("queue.default"), "host" => $queue["host"] ?? null, "port" => (string) ($queue["port"] ?? "")],
    "cache" => ["default" => config("cache.default"), "host" => $cache["host"] ?? null, "port" => (string) ($cache["port"] ?? "")],
    "updatesEnabledByServer" => config("heptapod.updates.enabled_by_server"),
    "instanceHeartbeatUrlSet" => config("instance.heartbeat_url") !== null && config("instance.heartbeat_url") !== "",
]);'

# Separate image preparation from PHP stdout. Some Compose backends put implicit build progress on stdout
# even with --quiet-build. The probe model cannot build or pull, and pins the verified local image ID.
prepare_probe_model() {
    local service=$1 config single image images image_id
    config=$(docker compose config --format json) || die 'could not resolve probe configuration; refusing'
    single=$(jq --arg service "$service" '.services = {($service): (.services[$service] | del(.depends_on, .links))}' <<<"$config") ||
        die 'could not isolate probe service; refusing'
    image=$(docker compose --project-directory "$PWD" -f - config --images <<<"$single") ||
        die 'could not resolve probe image; refusing'
    [ -n "$image" ] && [ "$(wc -l <<<"$image")" -eq 1 ] || die 'probe requires exactly one image; refusing'
    if [[ $image == sha256:* ]]; then
        images=$(docker image ls --no-trunc --quiet) || die 'could not list local probe images; refusing'
        images=$(grep -xF "$image" <<<"$images" || true)
    else
        images=$(docker image ls --no-trunc --quiet --filter "reference=$image") || die 'could not list local probe image; refusing'
    fi
    if [ -z "$images" ]; then
        jq -e --arg service "$service" '.services[$service].build != null' >/dev/null <<<"$config" ||
            die 'probe image is missing and has no build recipe; refusing to pull'
        docker compose build "$service" >&2 || die 'probe image build failed; refusing'
    fi
    image_id=$(docker image inspect "$image" --format '{{.Id}}') || die 'local probe image could not be verified; refusing'
    [[ $image_id =~ ^sha256:[a-f0-9]{64}$ ]] || die 'probe image ID is invalid; refusing'
    jq --arg service "$service" --arg image "$image_id" '
        .services[$service] |= (del(.build) | .image = $image | .pull_policy = "never")
    ' <<<"$config" || die 'could not prepare image-only probe; refusing'
}

# Prints one service's configuration. "pre" runs the image with that service's own Compose environment, entrypoint
# bypassed and no dependencies started, so the service has not started yet. "checkout" clears the image/Compose
# environment so only the mounted checkout .env/config defines the namespace. "running" reads the started container.
# No mode migrates, writes or opens a database or Redis connection.
probe_service_config() {
    local service=$1 mode=$2 probe model
    case "$mode" in
        pre|checkout)
            model=$(prepare_probe_model "$service") || die "$service: probe image preparation failed; refusing"
            if [ "$mode" = checkout ]; then
                probe=$(docker compose --project-directory "$PWD" -f - run --pull never --rm -T --interactive=false --no-deps --entrypoint env "$service" -i /usr/local/bin/php -r "$CONFIG_BOOTSTRAP_PHP$SERVICE_CONFIG_PHP" <<<"$model") ||
                    die 'checkout: configuration probe did not run; refusing'
            else
                probe=$(docker compose --project-directory "$PWD" -f - run --pull never --rm -T --interactive=false --no-deps --entrypoint php "$service" -r "$CONFIG_BOOTSTRAP_PHP$SERVICE_CONFIG_PHP" <<<"$model") ||
                    die "$service: configuration probe did not run before start; refusing"
            fi
            ;;
        running) probe=$(docker compose exec -T "$service" php artisan tinker --no-interaction --execute "$SERVICE_CONFIG_PHP") || die "$service: configuration probe did not run in the started container; refusing" ;;
        *) die "probe_service_config: unknown mode '$mode'" ;;
    esac
    printf '%s\n' "$probe"
}

# Prints the names of the checks a service fails, never the values: a refused host, URL or credential stays out of output.
# Scope "targets" is the app's check. Scope "targets+external" also requires external calls off, which live workers need.
failed_config_checks() {
    local service=$1 probe=$2 scope=$3 services failed
    # Empty or non-JSON probe output must fail here, not read as "no failed checks".
    jq -e -s 'length == 1 and (.[0] | has("database") and has("queue") and has("cache") and has("queuePlan") and has("updatesEnabledByServer") and has("instanceHeartbeatUrlSet"))' >/dev/null <<<"$probe" ||
        die "$service: configuration probe returned no configuration; refusing"
    services=$(docker compose config --services | jq -R -s 'split("\n") | map(select(length > 0))')
    failed=$(jq -r --argjson services "$services" --arg scope "$scope" \
        --arg dbHost "$(env_value DB_HOST)" --arg redisHost "$(env_value REDIS_HOST)" --arg database "$(env_value DB_DATABASE)" '
        [
            {name: "APP_ENV", ok: (.env == "local")},
            {name: "database driver", ok: (.database.driver == "pgsql")},
            {name: "database host", ok: (.database.host == $dbHost and ($dbHost | length > 0) and ($services | index($dbHost)) != null)},
            {name: "database name", ok: (.database.name == $database and ($database | length > 0))},
            {name: "queue reset namespace", ok: ((.queuePlan | type) == "array" and (.queuePlan | length) > 0 and
                all(.queuePlan[]; .host == $redisHost and .port == "6379" and (.database | test("^[0-9]+$")) and
                    (.prefix | type) == "string" and (.prefix | length) > 0) and
                ([.queuePlan[] | [.host, .port, .database, .prefix]] | unique | length) == 1)},
            {name: "queue connection", ok: (.queue.default == "redis")},
            {name: "queue host", ok: (.queue.host == $redisHost and ($redisHost | length > 0) and ($services | index($redisHost)) != null)},
            {name: "cache store", ok: (.cache.default == "redis")},
            {name: "cache host", ok: (.cache.host == $redisHost and ($redisHost | length > 0) and ($services | index($redisHost)) != null)}
        ]
        + (if $scope == "targets+external" then [
            {name: "update check", ok: (.updatesEnabledByServer == false)},
            {name: "instance heartbeat URL", ok: (.instanceHeartbeatUrlSet == false)}
        ] else [] end)
        | map(select(.ok | not) | .name) | join(", ")' <<<"$probe") || die "$service: could not evaluate configuration; refusing"
    printf '%s\n' "$failed"
}

# Refuses before the app starts, migrates or seeds anything unless its database and Redis are this stack's own services.
require_local_targets() {
    local probe failed checkout_probe
    checkout_probe=$(probe_service_config app checkout) || die 'checkout: configuration probe failed; refusing'
    failed=$(failed_config_checks checkout "$checkout_probe" targets) || die 'checkout: configuration check failed; refusing'
    [ -z "$failed" ] || die "checkout: effective targets are not local ($failed); refusing (values withheld)"
    probe=$(probe_service_config app pre) || die 'app: configuration probe failed; refusing'
    failed=$(failed_config_checks app "$probe" targets) || die 'app: configuration check failed; refusing'
    [ -z "$failed" ] || die "app: effective targets are not this stack's own services ($failed); refusing (values withheld)"
    app_queue_plan=$(jq -c .queuePlan <<<"$probe")
    [ "$app_queue_plan" = "$(jq -c .queuePlan <<<"$checkout_probe")" ] ||
        die 'app: queue plan differs from checkout configuration; refusing (values withheld)'
}

# Live workers need the same target checks as the app, plus external calls off. Runs before any worker starts,
# so a refusal leaves the running stack, its database and its Redis untouched.
require_live_worker_config() {
    local service=$1 scope=${2:-targets+external} probe failed
    probe=$(probe_service_config "$service" pre) || die "$service: configuration probe failed before start; refusing"
    failed=$(failed_config_checks "$service" "$probe" "$scope") || die "$service: configuration check failed before start; refusing"
    [ -z "$failed" ] || die "$service: effective configuration fails ($failed); refusing before start (values withheld)"
    [ "$(jq -c .queuePlan <<<"$probe")" = "$app_queue_plan" ] ||
        die "$service: queue plan differs from the app; refusing before start (values withheld)"
}

# Post-start check inside the started worker. Defense in depth only: the pre-start check is the gate.
require_running_worker_config() {
    local service=$1 probe failed overrides
    probe=$(probe_service_config "$service" running) || die "$service: configuration probe failed in the started container"
    failed=$(failed_config_checks "$service" "$probe" targets+external) || die "$service: configuration check failed in the started container"
    [ -z "$failed" ] || die "$service: started container configuration fails ($failed) (values withheld)"
    [ "$(jq -c .queuePlan <<<"$probe")" = "$app_queue_plan" ] ||
        die "$service: started queue plan differs from the app (values withheld)"
    overrides=$(docker compose exec -T "$service" sh -c 'tr "\0" "\n" < /proc/1/environ | grep "^HEPTAPOD_UPDATE_CHECK_ENABLED=" || true')
    [ -z "$overrides" ] || [ "$overrides" = 'HEPTAPOD_UPDATE_CHECK_ENABLED=false' ] ||
        die "$service: container environment overrides the update check; refusing"
}

# Producers and consumers must already be stopped. Laravel clears only these literal queue keys,
# including delayed/reserved/notify, never a Redis database or a cache/Horizon prefix.
clear_owned_queues() {
    local probe failed connection queue
    probe=$(probe_service_config app running) || die 'app: running queue configuration probe failed; refusing'
    failed=$(failed_config_checks app "$probe" targets) || die 'app: running queue configuration check failed; refusing'
    [ -z "$failed" ] && [ "$(jq -c .queuePlan <<<"$probe")" = "$app_queue_plan" ] ||
        die 'app: running queue configuration differs from preflight; refusing to clear'
    while IFS=$'\t' read -r connection queue; do
        docker compose exec -T --interactive=false app php artisan queue:clear "$connection" --queue="$queue" --no-interaction ||
            die 'owned queue clear failed; database has not been reset'
    done < <(jq -r '.[] | [.connection, .queue] | @tsv' <<<"$app_queue_plan")
    docker compose exec -T -e DEV_QUEUE_PLAN="$app_queue_plan" app php -r '
        require "/workspace/vendor/autoload.php";
        $app = require "/workspace/bootstrap/app.php";
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $pairs = json_decode(getenv("DEV_QUEUE_PLAN"), true, 512, JSON_THROW_ON_ERROR);
        foreach ($pairs as $pair) {
            $queue = \Illuminate\Support\Facades\Queue::connection($pair["connection"]);
            if ($queue->size($pair["queue"]) !== 0) {
                throw new LogicException("Owned queue is not empty after reset.");
            }
        }
        echo "dev-up: owned queues verified zero at ".now()->toIso8601String()." before seeding; later jobs may be new".PHP_EOL;
    ' || die 'owned queue zero checkpoint failed; database has not been reset'
}

# The checkout's untracked .env is the only place the dev update check is pinned off. Only that key changes.
pin_update_checks_off() {
    if [ "$(env_value HEPTAPOD_UPDATE_CHECK_ENABLED)" = false ] && [ "$(grep -c '^HEPTAPOD_UPDATE_CHECK_ENABLED=' .env)" = 1 ]; then
        return 0
    fi
    local tmp
    tmp=$(mktemp)
    awk '!/^HEPTAPOD_UPDATE_CHECK_ENABLED=/' .env > "$tmp"
    echo 'HEPTAPOD_UPDATE_CHECK_ENABLED=false' >> "$tmp"
    cat "$tmp" > .env
    rm -f "$tmp"
}

# After `up`: the running app must read this checkout's .env and publish the APP_PORT on loopback.
require_checkout_source() {
    [ "$(cksum < .env | cut -d' ' -f1,2)" = "$(docker compose exec -T app cksum /workspace/.env | cut -d' ' -f1,2)" ] ||
        die 'the app container does not read this checkout .env; refusing'
    [ "$(docker compose port app 80)" = "127.0.0.1:$(env_value APP_PORT)" ] ||
        die 'the app port differs from APP_PORT in .env; refusing'
}
