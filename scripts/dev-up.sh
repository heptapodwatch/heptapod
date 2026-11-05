#!/usr/bin/env bash
# Brings up this checkout's Compose stack, rebuilds its local database and seeds one demo scenario.
# Non-stopped scenarios then run the real scheduler and dispatcher, so heartbeats stay current for a review.
# Horizon never runs here: queued check jobs wait in Redis and no check runs over HTTP.
# Every run resets the database and its configured queues, so re-running a scenario converges to the same state.
# Host values (Docker context, ports) come from the environment and .env, never from this file.
# Refuses when a dev-image service would run an image not built from the current dev inputs (scripts/dev-image-hash.sh).
# --allow-stale-image starts anyway with a warning.
set -euo pipefail

started=$(date +%s)
fail() { echo "dev-up: $*" >&2; exit 1; }

usage='usage: scripts/dev-up.sh [--allow-stale-image] <scenario>'
allow_stale_image=0
positional=()
for arg in "$@"; do
    case $arg in
        --allow-stale-image) allow_stale_image=1 ;;
        *) positional+=("$arg") ;;
    esac
done
[ "${#positional[@]}" -eq 1 ] || fail "$usage"
scenario=${positional[0]}

# Validate the scenario before any side effect; the seeder constant is the single source.
checkout=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
cd "$checkout"
scenarios=$(grep -oE 'SCENARIOS = \[[^]]*\]' database/seeders/DemoSeeder.php | grep -oE "'[^']+'" | tr -d "'")
grep -qxF -- "$scenario" <<<"$scenarios" || fail "unknown scenario '$scenario'; expected one of: $(paste -sd' ' <<<"$scenarios")"

# shellcheck source=scripts/dev-stack-lib.sh
. "$checkout/scripts/dev-stack-lib.sh"
[ "$(env_value APP_ENV)" = local ] || fail 'APP_ENV in .env must be local; refusing to reset'
[ ! -e .env.prod ] || fail '.env.prod exists beside this development checkout; refusing to reset'
case "${COMPOSE_FILE:-}$(env_value COMPOSE_FILE)" in
    *compose.prod.yaml*) fail 'COMPOSE_FILE includes compose.prod.yaml; refusing to reset' ;;
esac

# Order is the safety contract: each step refuses before the next can touch a database or container.
app_origin=$(validate_app_origin "$(env_value APP_URL)" "$(env_value APP_PORT)")
require_owned_stack
require_fresh_dev_images "$scenario" "$allow_stale_image"
# A new volume hides the image's dependencies. Bootstrap without package scripts
# or migrations before the config probes; app startup still performs its stamped
# full install after the local targets are proven. Share startup's install lock.
docker compose run --rm -T --interactive=false --no-deps --entrypoint sh app -eu -c '
    exec 9> bootstrap/cache/.dev-dependencies.lock
    flock 9
    if [ ! -f vendor/autoload.php ]; then
        composer install --no-interaction --prefer-dist --no-scripts
    fi
    if [ ! -f node_modules/.package-lock.json ]; then
        npm ci --ignore-scripts
    fi
'
require_local_targets
# Live workers are proven before anything starts. A refusal here leaves the running stack and its database untouched.
if [ "$scenario" != stopped ]; then
    # A configured heartbeat URL in .env is refused outright; the worker probe also catches one set in a Compose environment.
    [ -z "$(env_value INSTANCE_HEARTBEAT_URL)" ] ||
        fail 'INSTANCE_HEARTBEAT_URL is set in .env; live scheduler refuses an external heartbeat endpoint; refusing'
    pin_update_checks_off
    require_live_worker_config scheduler
    require_live_worker_config dispatcher
else
    # Stopped workers still define queues to clear; their namespaces must agree before any mutation.
    require_live_worker_config scheduler targets
    require_live_worker_config dispatcher targets
fi
# Entrypoint migrations and the attention notification command run only after the targets are proven local.
docker compose up -d --wait --wait-timeout 300 app reverb mailpit
require_checkout_source

# Workers would claim checks or jobs while fixtures are being written.
docker compose stop -t 30 horizon dispatcher scheduler

# Scoped reset: queues are cleared before the database; caches are forgotten by their own keys only.
clear_owned_queues
docker compose exec -T app php artisan migrate:fresh --force --no-interaction
docker compose exec -T app php artisan permission:cache-reset --no-interaction
docker compose exec -T app php artisan settings:clear-cache --no-interaction

# Bundled assets pull in translation catalogs and route inputs that no short list covers, so always build.
docker compose exec -T app npm run build

docker compose exec -T app php artisan heptapod:seed-demo --scenario="$scenario" --no-interaction

# Live workers start only after the fixture is seeded; their configuration was proven before the reset.
# Started containers are checked again. A failed step stops the workers this run started, then exits with the original failure.
run_or_stop_workers() {
    if ! ( "$@" ); then
        docker compose stop -t 30 scheduler dispatcher >/dev/null ||
            fail 'post-start step failed, and stopping scheduler and dispatcher also failed; check docker compose ps before reuse (original failure above)'
        fail 'post-start step failed; stopped the scheduler and dispatcher this run started (original failure above)'
    fi
}
if [ "$scenario" != stopped ]; then
    run_or_stop_workers docker compose up -d --wait --wait-timeout 120 scheduler dispatcher
    run_or_stop_workers require_running_worker_config scheduler
    run_or_stop_workers require_running_worker_config dispatcher
fi

state_json() {
    docker compose exec -T app php artisan tinker --no-interaction --execute 'echo json_encode(["counts" => ["users" => \Illuminate\Support\Facades\DB::table("users")->count(), "clients" => \Heptapod\Models\Client::query()->count(), "sites" => \Heptapod\Models\Site::query()->count(), "checks" => \Heptapod\Models\Check::query()->count(), "incidents" => \Heptapod\Models\Incident::query()->count(), "maintenance_windows" => \Heptapod\Models\MaintenancePeriod::query()->count()], "dispatcherSeenAt" => \Heptapod\Models\DispatcherHeartbeat::query()->orderBy("last_seen_at", "desc")->first()?->last_seen_at?->toIso8601String(), "dispatcherOkSeconds" => config("heptapod.health.dispatcher_ok_seconds"), "dispatcherWarnSeconds" => config("heptapod.health.dispatcher_warn_seconds"), "schedulerSeenAt" => \Illuminate\Support\Facades\Cache::store("redis")->get(config("heptapod.health.scheduler_cache_key")), "schedulerOkSeconds" => config("heptapod.health.scheduler_ok_seconds"), "schedulerWarnSeconds" => config("heptapod.health.scheduler_warn_seconds"), "checkDueFrom" => \Heptapod\Models\Check::query()->where("enabled", true)->min("next_run_at")]);'
}

# Fresh heartbeats are Ok only until the product's ok threshold; past it the dashboard shows Warn, then Down.
heartbeat_line() {
    local label=$1 seen=$2 ok=$3 warn=$4 seen_epoch
    if [ "$seen" = null ] || [ -z "$seen" ]; then
        echo "heartbeat $label: none recorded"
        return
    fi
    seen_epoch=$(date -u -d "$seen" +%s)
    if [ $((seen_epoch + ok)) -le "$(date -u +%s)" ]; then
        echo "heartbeat $label: seen $(date -u -d "@$seen_epoch" +%FT%TZ); already past the ok threshold (+${ok}s); the dashboard reports it as not current"
        return
    fi
    echo "heartbeat $label: seen $(date -u -d "@$seen_epoch" +%FT%TZ); ok until $(date -u -d "@$((seen_epoch + ok))" +%FT%TZ) (+${ok}s), warn until +${warn}s, then down"
}

# A live writer's first beat can lag the start: the dispatcher beats within seconds, the scheduler on its next minute tick.
# Waits for both to be fresh; fails loud if they are not, rather than printing a stale window as current.
wait_for_live_heartbeats() {
    local deadline=$(( $(date +%s) + 150 )) state dispatcher scheduler dispatcher_ok scheduler_ok now
    while :; do
        state=$(state_json)
        dispatcher=$(jq -r .dispatcherSeenAt <<<"$state")
        scheduler=$(jq -r .schedulerSeenAt <<<"$state")
        dispatcher_ok=$(jq -r .dispatcherOkSeconds <<<"$state")
        scheduler_ok=$(jq -r .schedulerOkSeconds <<<"$state")
        now=$(date -u +%s)
        if [ "$dispatcher" != null ] && [ -n "$dispatcher" ] && [ "$(( $(date -u -d "$dispatcher" +%s) + dispatcher_ok ))" -gt "$now" ] &&
            [ "$scheduler" != null ] && [ -n "$scheduler" ] && [ "$(( $(date -u -d "$scheduler" +%s) + scheduler_ok ))" -gt "$now" ]; then
            printf '%s\n' "$state"
            return 0
        fi
        [ "$now" -lt "$deadline" ] || fail "live heartbeats not fresh within 150s (dispatcher=$dispatcher scheduler=$scheduler); refusing to report ready"
        sleep 2
    done
}

if [ "$scenario" = stopped ]; then
    state=$(state_json)
else
    state=$(wait_for_live_heartbeats)
fi

app_url=$app_origin
# The readiness probe checks the page a reviewer logs in through; a 200 here does not prove login (smoke does that).
curl -fsS -o /dev/null --max-time 30 "$app_url/login" || fail "$app_url/login did not return 200"
# Coverage must stay on in the CLI and off in the web server (FrankenPHP segfault). Checked on the running app, so a wrong runtime config fails here even when the image hash matches.
docker compose exec -T app sh scripts/check-dev-pcov.sh || fail 'runtime coverage check failed (scripts/check-dev-pcov.sh); stack left running for inspection'

echo "dev-up: scenario=$scenario ready in $(( $(date +%s) - started ))s"
echo "app: $app_url/"
echo "login: $app_url/login (demo login test@example.test / password)"
echo "counts: $(jq -c .counts <<<"$state")"
heartbeat_line dispatcher "$(jq -r .dispatcherSeenAt <<<"$state")" "$(jq -r .dispatcherOkSeconds <<<"$state")" "$(jq -r .dispatcherWarnSeconds <<<"$state")"
heartbeat_line scheduler "$(jq -r .schedulerSeenAt <<<"$state")" "$(jq -r .schedulerOkSeconds <<<"$state")" "$(jq -r .schedulerWarnSeconds <<<"$state")"
if [ "$scenario" = stopped ]; then
    echo 'services: scheduler and dispatcher not started (stopped by design); heartbeats are stale fixture values; Horizon off'
else
    echo "services: scheduler and dispatcher running live; update check effectively off; no heartbeat URL; Horizon off"
    echo "checks scheduled from $(jq -r .checkDueFrom <<<"$state"); fixture state stable until then; Horizon off"
fi
