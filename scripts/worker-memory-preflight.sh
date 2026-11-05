# Sourced by install.sh and upgrade.sh. Memory profile selection and the worker memory preflight.

# Memory profiles. Each pair must match WorkerMemoryBudget::requiredMiB() for one crawl worker
# (tests/Feature/WorkerMemoryBudgetTest.php pins both): default 768 MiB crawl limit -> 1990m, small 512 MiB -> 1670m.
default_crawl_memory_limit_mb=768
default_horizon_memory_limit=1990m
small_crawl_memory_limit_mb=512
small_horizon_memory_limit=1670m
# Hosts that report less MemTotal than this get the small profile (a nominal 6 GiB VPS reports about 5.8 GiB).
small_profile_below_mib=6144
# RAM the host keeps outside all container caps, estimated from its parts: kernel, page tables and slab
# (~150 MiB), dockerd plus containerd (~150 MiB), one containerd shim per container (~10 x 10 MiB), and
# sshd/journald/cron (~50 MiB), rounded up to 512 MiB for page cache under load. An estimate, not a measurement.
host_memory_reserve_mib=512

# MemTotal in MiB. HEPTAPOD_HOST_MEMINFO replaces /proc/meminfo only for deployment tests.
host_memory_mib() {
    meminfo=${HEPTAPOD_HOST_MEMINFO:-/proc/meminfo}
    total_kib=$(awk '$1 == "MemTotal:" { print $2 }' "$meminfo") || total_kib=
    case "$total_kib" in
        ''|*[!0-9]*) echo "Cannot read MemTotal from $meminfo; memory profile and preflight need it." >&2; exit 1 ;;
    esac
    echo $((total_kib / 1024))
}

# Fresh installs only: replace the example's default profile with the small one on small hosts. One line tells why.
select_memory_profile() {
    total_mib=$(host_memory_mib)
    if [ "$total_mib" -lt "$small_profile_below_mib" ]; then
        sed -i \
            -e "s/^HORIZON_MEMORY_LIMIT=.*/HORIZON_MEMORY_LIMIT=$small_horizon_memory_limit/" \
            -e "s/^HEPTAPOD_CRAWL_MEMORY_LIMIT_MB=.*/HEPTAPOD_CRAWL_MEMORY_LIMIT_MB=$small_crawl_memory_limit_mb/" .env.prod
        echo "Small memory profile: this host has ${total_mib} MiB RAM (below ${small_profile_below_mib} MiB), so crawls stop at ${small_crawl_memory_limit_mb} MiB; with more RAM set HEPTAPOD_CRAWL_MEMORY_LIMIT_MB=${default_crawl_memory_limit_mb} and HORIZON_MEMORY_LIMIT=${default_horizon_memory_limit} in .env.prod."
    fi
}

# Refuses, before any service change, when the configured caps exceed host RAM minus the reserve, or when the
# Horizon cap is below the worker budget. The budget check runs in a throwaway Horizon container: same image,
# environment and mem_limit as the service, no dependencies started, no database access.
require_worker_memory() {
    total_mib=$(host_memory_mib)
    model=$(docker compose --env-file .env.prod config) || {
        echo 'Worker memory preflight could not read the Compose model; see the error above.' >&2
        exit 1
    }
    caps_mib=$(printf '%s\n' "$model" | awk '$1 == "mem_limit:" { value = $2; gsub(/"/, "", value); sum += value } END { printf "%d", sum / 1048576 }')
    available_mib=$((total_mib - host_memory_reserve_mib))
    if [ "$caps_mib" -gt "$available_mib" ]; then
        echo "Memory preflight failed: the enabled services' caps total ${caps_mib} MiB, but this host has ${total_mib} MiB RAM minus a ${host_memory_reserve_mib} MiB host reserve (${available_mib} MiB). Lower HORIZON_MEMORY_LIMIT and HEPTAPOD_CRAWL_MEMORY_LIMIT_MB to the small profile (${small_horizon_memory_limit}, ${small_crawl_memory_limit_mb}), disable browser services, or add RAM." >&2
        exit 1
    fi
    services=$(docker compose --env-file .env.prod config --services) || exit 1
    if ! printf '%s\n' "$services" | grep -qx horizon; then
        echo 'Worker memory preflight skipped: this Compose model has no horizon service.' >&2
        return 0
    fi
    # The command's verdict goes to stderr so it stays beside a refusal when callers capture stdout.
    if ! docker compose --env-file .env.prod run --rm --no-deps -T --entrypoint sh horizon -ec \
        'mkdir -p "$LARAVEL_STORAGE_PATH/framework/cache"; exec php artisan heptapod:worker-memory' >&2; then
        echo 'Worker memory preflight did not pass; see the message above (it names the required HORIZON_MEMORY_LIMIT when the cap is too small).' >&2
        exit 1
    fi
}
