#!/usr/bin/env bash
set -euo pipefail

mkdir -p .phpunit.cache
if [[ ${PEST_LOCK_HELD:-} != 1 ]]; then
    if flock -n -E 75 -o .phpunit.cache/pest.lock env PEST_LOCK_HELD=1 bash "$0" "$@"; then
        exit 0
    else
        result=$?
        if [[ $result -eq 75 ]]; then
            echo 'another Pest run is active in this checkout — wait or stop it' >&2
            exit 1
        fi
        exit "$result"
    fi
fi

heavy_slots=${HEPTAPOD_HEAVY_SLOTS:-3}
case "$heavy_slots" in
    2|3|4) ;;
    *) echo 'HEPTAPOD_HEAVY_SLOTS must be 2, 3 or 4' >&2; exit 2 ;;
esac
heavy_lock_dir=/heavy-locks
if [[ ${CI:-} == true && ! -d $heavy_lock_dir ]]; then
    heavy_lock_dir=.phpunit.cache
fi
if [[ ! -d $heavy_lock_dir ]]; then
    echo 'Missing /heavy-locks; create heptapod-heavy-locks and recreate the app container' >&2
    exit 1
fi
heavy_slot=
for ((slot = 1; slot <= heavy_slots; slot++)); do
    exec 8>"$heavy_lock_dir/heptapod-heavy.$slot.lock"
    if flock -n 8; then
        heavy_slot=$slot
        break
    fi
    exec 8>&-
done
if [[ -z $heavy_slot ]]; then
    echo 'waiting for heavy slot …' >&2
    exec 8>"$heavy_lock_dir/heptapod-heavy.1.lock"
    flock 8
    heavy_slot=1
fi
printf 'acquired heavy slot %s\n' "$heavy_slot"

source scripts/orphaned-test-servers.sh
sweep_orphaned_test_servers

test_pgid=
log=
worker_marker=
stop_test_group() {
    if [[ -n $test_pgid ]]; then
        kill -TERM -- "-$test_pgid" 2>/dev/null || true
        sleep 1
        kill -KILL -- "-$test_pgid" 2>/dev/null || true
        wait "$test_pgid" 2>/dev/null || true
        test_pgid=
    fi
}
cleanup() {
    stop_test_group
    sweep_orphaned_test_servers
    [[ -z $log ]] || rm -f "$log"
    [[ -z $worker_marker ]] || rm -f "$worker_marker"
    exec 8>&-
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
run_test_group() {
    local result=0
    setsid -w "$@" 8>&- &
    test_pgid=$!
    wait "$test_pgid" || result=$?
    stop_test_group
    return "$result"
}

fixture_hash=$( { cat docker/fixtures/nginx.conf; printf '\0'; cat docker/fixtures/routes.conf; } | md5sum)
fixture_hash=${fixture_hash%% *}
for fixture_host in fixtures.test second.fixtures.test; do
    if ! loaded_hash=$(curl -fsS --max-time 3 "http://$fixture_host/__fixtures-config-hash"); then
        echo "fixtures unreachable — run: docker compose --profile fixtures up -d --wait fixtures fixtures-alt dns-fixtures" >&2
        exit 1
    fi
    if [[ $loaded_hash != "$fixture_hash" ]]; then
        echo "fixtures stale — run: docker compose --profile fixtures restart fixtures fixtures-alt" >&2
        exit 1
    fi
done

if [[ ${1:-} == tia || ${1:-} == pre-push ]]; then
    base=$(git merge-base HEAD origin/main)
    changed_paths=$( { git diff --name-only --no-renames "$base" --; git ls-files --others --exclude-standard; } | sort -u)
    tia_bypass_paths=()
    while IFS= read -r path; do
        case "$path" in
            database/migrations/*|addons/*/database/migrations/*|addons/*/lang/*|addons/*/resources/views/*)
                tia_bypass_paths+=("$path")
                ;;
        esac
    done <<< "$changed_paths"
    if (( ${#tia_bypass_paths[@]} > 0 )); then
        printf 'TIA bypassed: schema/addon resource change: %s\n' "${tia_bypass_paths[@]}"
    fi

    tia_fingerprint=$( { sha256sum phpunit.browser.xml; find docker/fixtures -type f -print0 | sort -z | xargs -0 sha256sum; } | sha256sum)
    tia_fingerprint=${tia_fingerprint%% *}
    TIA_FRESH=
    if [[ -f .phpunit.cache/tia-fingerprint ]]; then
        if [[ $(cat .phpunit.cache/tia-fingerprint) != "$tia_fingerprint" ]]; then
            TIA_FRESH=--fresh
        fi
    elif [[ -f .pest/tia/graph.json || -f .pest/tia-browser/graph.json ]]; then
        TIA_FRESH=--fresh
    fi
    [[ -z $TIA_FRESH || ${#tia_bypass_paths[@]} -gt 0 ]] || echo 'Fixture or Browser config inputs changed — rebuilding the dependency graphs'
    export TIA_FRESH
fi

case "${1:-}" in
    test)
        coverage_flag=
        [[ ${HEPTAPOD_TEST_COVERAGE:-} != 1 ]] || coverage_flag=--coverage
        export coverage_flag
        run_test_group bash -c 'php -d memory_limit=1G vendor/bin/pest --testsuite=Arch && vendor/bin/pest --testsuite=Feature,Unit --parallel --processes=4 ${coverage_flag:+--coverage} && vendor/bin/pest --testsuite=Browser'
        ;;
    ci-php)
        run_test_group vendor/bin/pest --testsuite=Feature,Unit --parallel --processes=4 "${@:2}"
        ;;
    ci-arch)
        # Arch holds every parsed project AST in one process; it is serial and needs more than the default 512M.
        run_test_group php -d memory_limit=1G vendor/bin/pest --testsuite=Arch "${@:2}"
        ;;
    ci-browser)
        run_test_group vendor/bin/pest --testsuite=Browser "${@:2}"
        ;;
    focused)
        if (( $# < 2 )); then
            echo 'focused mode requires Pest arguments' >&2
            exit 2
        fi
        focused_args=("${@:2}")
        for argument in "${focused_args[@]}"; do
            case "$argument" in
                --parallel|--parallel=*|-p*)
                    # ParaTest uses the last process count, including its short alias.
                    focused_args+=(--processes=4)
                    break
                    ;;
            esac
        done
        run_test_group timeout --kill-after=10s 900 vendor/bin/pest "${focused_args[@]}"
        ;;
    benchmark)
        run_test_group timeout --kill-after=10s 900 vendor/bin/pest --group=benchmark "${@:2}"
        ;;
    tia)
        if (( ${#tia_bypass_paths[@]} > 0 )); then
            run_test_group bash -c 'php -d memory_limit=1G vendor/bin/pest --testsuite=Arch && vendor/bin/pest --testsuite=Feature,Unit --parallel --processes=4 && vendor/bin/pest --testsuite=Browser'
        else
            run_test_group bash -c 'php -d memory_limit=1024M vendor/bin/pest --tia ${TIA_FRESH:+--fresh} --parallel --processes=4 --passthru-php="-d memory_limit=1024M" && HEPTAPOD_TIA_BROWSER=1 vendor/bin/pest -c phpunit.browser.xml --tia ${TIA_FRESH:+--fresh}'
            printf '%s\n' "$tia_fingerprint" > .phpunit.cache/tia-fingerprint
        fi
        ;;
    pre-push)
        log=$(mktemp)
        worker_marker=$(mktemp)
        result=0
        test_command='php -d memory_limit=1024M vendor/bin/pest --tia ${TIA_FRESH:+--fresh} --parallel --processes=4 --passthru-php="-d memory_limit=1024M" && HEPTAPOD_TIA_BROWSER=1 vendor/bin/pest -c phpunit.browser.xml --tia ${TIA_FRESH:+--fresh}'
        if (( ${#tia_bypass_paths[@]} > 0 )); then
            test_command='php -d memory_limit=1G vendor/bin/pest --testsuite=Arch && vendor/bin/pest --testsuite=Feature,Unit --parallel --processes=4 && vendor/bin/pest --testsuite=Browser'
        fi
        run_test_group timeout --kill-after=10s 900 bash -c "$test_command" > "$log" 2>&1 || result=$?
        cat "$log"
        if [[ $result -eq 124 || $result -eq 137 ]]; then
            if [[ $result -eq 124 ]]; then
                echo 'pre-push tests exceeded 15 min — hung worker?' >&2
            else
                echo 'test run killed (137) — timeout or OOM, see worker logs' >&2
            fi
            find /tmp -maxdepth 1 -type f -name 'worker_*' -newer "$worker_marker" -print -exec tail -n 80 {} \; >&2
            exit 1
        fi
        if [[ $result -eq 0 && ${#tia_bypass_paths[@]} -eq 0 ]]; then
            printf '%s\n' "$tia_fingerprint" > .phpunit.cache/tia-fingerprint
        fi
        exit "$result"
        ;;
    *)
        echo 'Usage: scripts/run-tests.sh {test|tia|pre-push|ci-php [pest args...]|ci-arch [pest args...]|ci-browser [pest args...]|focused pest args...|benchmark [pest args...]}' >&2
        exit 2
        ;;
esac
