#!/bin/sh
# Real archive operations in an isolated /backups tmpfs; only Compose routing is stubbed.
# shellcheck disable=SC2016
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
fresh=$(mktemp -d)
evidence=${OPS_EVIDENCE_DIR:-$(mktemp -d)}
mkdir -p "$fresh/scripts" "$fresh/bin" "$fresh/.heptapod/upgrade/previous" "$evidence"
REAL_DOCKER=$(command -v docker)
export REAL_DOCKER
source_container=${RETENTION_APP_CONTAINER:-$(docker compose ps -q app)}
[ -n "$source_container" ] || { echo 'A running dev app container is required.' >&2; exit 1; }
image=$(docker inspect --format '{{.Image}}' "$source_container")
RETENTION_FIXTURE=$(docker run -d --network none --volumes-from "$source_container:ro" --tmpfs /backups:rw,size=16m --tmpfs /tmp:rw,size=16m -e APP_NAME=Fixture -e LOG_CHANNEL=stderr -e MAIL_MAILER=array -e BACKUP_DISK=backups -e BACKUP_PATH=/backups -e HEPTAPOD_UPGRADE_BACKUP_KEEP=3 --entrypoint sleep "$image" infinity)
export RETENTION_FIXTURE
cleanup() {
    status=$?
    trap - EXIT
    "$REAL_DOCKER" rm -f "$RETENTION_FIXTURE" >/dev/null
    cp -R "$fresh/.heptapod" "$evidence/final-state"
    rm -rf "$fresh"
    echo "Lifecycle retention evidence: $evidence"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP
cp "$root/scripts/expire-upgrade-backups.sh" "$root/scripts/upgrade-backup-retention.sh" "$root/scripts/upgrade-backup-retention.php" "$root/scripts/lifecycle-lock.sh" "$fresh/scripts/"
cat > "$fresh/bin/docker" <<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> docker-operations.log
[ "$1" = compose ] && [ "$2" = --env-file ] && [ "$3" = .env.prod ] && [ "$4" = exec ] && [ "$5" = -T ] && [ "$6" = app ]
shift 6
exec "$REAL_DOCKER" exec -i "$RETENTION_FIXTURE" "$@"
SH
chmod +x "$fresh/bin/docker"
export PATH="$fresh/bin:$PATH"
cd "$fresh"
: > .env.prod
state="$fresh/.heptapod/upgrade"
printf 'committed\n' > "$state/phase"
fixture() { "$REAL_DOCKER" exec -i "$RETENTION_FIXTURE" "$@"; }
fixture php -r '
mkdir("/backups/Fixture",0700,true); mkdir("/backups/upgrade-lifecycle/Fixture",0700,true);
for ($day=1;$day<=8;$day++) {
    $name=sprintf("pre-upgrade-abcdef123456-202610%02dT120000Z.zip",$day);
    $directory=$day<=2?"/backups/Fixture":"/backups/upgrade-lifecycle/Fixture";
    $z=new ZipArchive; $z->open("$directory/$name",ZipArchive::CREATE);
    $z->addFromString("db-dumps/postgresql-fixture.sql","SELECT 42;\n");
    $z->addFromString("app/storage/app/private/proof.txt","restored lifecycle proof");
    $z->setPassword("fixture-password");
    for($i=0;$i<$z->numFiles;$i++) {$z->setEncryptionIndex($i,ZipArchive::EM_AES_256);}
    $z->close();
}
file_put_contents("/backups/Fixture/pre-upgrade-abcdef123456-20260901T120000Z.zip","unreferenced legacy archive");
file_put_contents("/backups/upgrade-lifecycle/Fixture/not-a-lifecycle.zip","unrelated archive");
'
current=/backups/Fixture/pre-upgrade-abcdef123456-20261001T120000Z.zip
previous=/backups/Fixture/pre-upgrade-abcdef123456-20261002T120000Z.zip
current_sha=$(fixture sha256sum "$current" | awk '{print $1}')
previous_sha=$(fixture sha256sum "$previous" | awk '{print $1}')
printf 'BACKUP_PATH=%s\nBACKUP_SHA256=%s\n' "$current" "$current_sha" > "$state/backup.env"
printf 'BACKUP_SHA256=%s\nBACKUP_PATH=%s\n' "$previous_sha" "$previous" > "$state/previous/backup.env"
cp "$state/backup.env" current-before.env
cp "$state/previous/backup.env" previous-before.env
snapshot() { fixture sh -c 'find /backups -type f -exec sha256sum {} \; | sort'; }
snapshot > "$evidence/before.sha256"
./scripts/expire-upgrade-backups.sh --dry-run > "$evidence/dry-run.log" 2>&1
cat "$evidence/dry-run.log"
[ "$(grep -c '^DELETE ' "$evidence/dry-run.log")" = 3 ]
grep -q 'current generation reference' "$evidence/dry-run.log"
grep -q 'previous generation reference' "$evidence/dry-run.log"
grep -q 'newest 3' "$evidence/dry-run.log"
snapshot > "$evidence/after-dry-run.sha256"
cmp "$evidence/before.sha256" "$evidence/after-dry-run.sha256"
cmp current-before.env "$state/backup.env"
cmp previous-before.env "$state/previous/backup.env"
./scripts/expire-upgrade-backups.sh --apply > "$evidence/apply.log" 2>&1
cat "$evidence/apply.log"
[ "$(grep -c '^DELETED ' "$evidence/apply.log")" = 3 ]
sed -n 's/^DELETE \([^ ]*\) .*/\1/p' "$evidence/dry-run.log" | sort > "$evidence/planned-deletions"
sed -n 's/^DELETED \([^ ]*\) .*/\1/p' "$evidence/apply.log" | sort > "$evidence/actual-deletions"
cmp "$evidence/planned-deletions" "$evidence/actual-deletions"
cmp current-before.env "$state/backup.env.bak"
cmp previous-before.env "$state/previous/backup.env.bak"
new_current=$(sed -n 's/^BACKUP_PATH=//p' "$state/backup.env")
new_previous=$(sed -n 's/^BACKUP_PATH=//p' "$state/previous/backup.env")
[ "$new_current" = /backups/upgrade-lifecycle/Fixture/pre-upgrade-abcdef123456-20261001T120000Z.zip ]
[ "$new_previous" = /backups/upgrade-lifecycle/Fixture/pre-upgrade-abcdef123456-20261002T120000Z.zip ]
[ "$(fixture sha256sum "$new_current" | awk '{print $1}')" = "$current_sha" ]
[ "$(fixture sha256sum "$new_previous" | awk '{print $1}')" = "$previous_sha" ]
fixture test ! -e "$current"
fixture test ! -e "$previous"
fixture test -f /backups/Fixture/pre-upgrade-abcdef123456-20260901T120000Z.zip
fixture test -f /backups/upgrade-lifecycle/Fixture/not-a-lifecycle.zip
./scripts/expire-upgrade-backups.sh --apply > "$evidence/idempotent.log" 2>&1
! grep -q '^DELETED\|^MOVED' "$evidence/idempotent.log"
echo 'PASS dry-run, exact deletions, current/previous protection, legacy relocation/checksums/.bak, idempotency'
# The production standalone restore consumes the recorded path verbatim, including the new directory.
"$REAL_DOCKER" exec -e BACKUP_ARCHIVE_PASSWORD=fixture-password "$RETENTION_FIXTURE" php scripts/restore-backup.php "$new_current" /tmp/restored > "$evidence/restore.log"
fixture sh -ec '[ "$(cat /tmp/restored/db-dumps/postgresql-fixture.sql)" = "SELECT 42;" ]; [ "$(cat /tmp/restored/app/storage/app/private/proof.txt)" = "restored lifecycle proof" ]'
cat "$evidence/restore.log"
echo 'PASS encrypted lifecycle archive restores SQL and storage from the new directory'
# Spatie daily write, cleanup and monitoring use their configured namespace, not the lifecycle root.
fixture php > "$evidence/daily-isolation.log" 2>&1 <<'PHP'
<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$before=[];
foreach(glob('/backups/upgrade-lifecycle/Fixture/*') as $file) {$before[$file]=hash_file('sha256',$file);}
$zip=new ZipArchive; $zip->open('/tmp/daily-proof.zip',ZipArchive::CREATE); $zip->addFromString('daily.txt','daily'); $zip->close();
$destination=Spatie\Backup\BackupDestination\BackupDestination::create('backups',config('backup.backup.name'));
$destination->write('/tmp/daily-proof.zip');
if (!is_file('/backups/Fixture/daily-proof.zip')) {throw new RuntimeException('Daily write used the wrong directory');}
foreach($destination->fresh()->backups() as $backup) {
    if(str_contains($backup->path(),'upgrade-lifecycle')) {throw new RuntimeException('Daily collection includes lifecycle archives');}
}
config(['backup.notifications.notifications'=>[], 'backup.cleanup.default_strategy.keep_all_backups_for_days'=>0]);
Illuminate\Support\Facades\Artisan::call('backup:clean',['--disable-notifications'=>true]);
echo Illuminate\Support\Facades\Artisan::output();
Illuminate\Support\Facades\Artisan::call('backup:monitor');
echo Illuminate\Support\Facades\Artisan::output();
foreach($before as $file=>$hash) {if(!is_file($file) || hash_file('sha256',$file)!==$hash) {throw new RuntimeException('Daily cleanup or monitoring touched lifecycle archives');}}
echo "PASS daily destination, backup:clean and backup:monitor exclude lifecycle archives\n";
PHP
cat "$evidence/daily-isolation.log"
# Refusal must happen before any container action when another lifecycle actor owns the lock.
: > docker-operations.log
if flock "$fresh/.heptapod/lifecycle.lock" ./scripts/expire-upgrade-backups.sh --apply > "$evidence/locked.log" 2>&1; then exit 1; fi
grep -q 'lifecycle lock held' "$evidence/locked.log"
[ ! -s docker-operations.log ]
printf 'preparing\n' > "$state/phase"
if ./scripts/expire-upgrade-backups.sh --apply > "$evidence/unfinished.log" 2>&1; then exit 1; fi
grep -q 'Unfinished upgrade' "$evidence/unfinished.log"
[ ! -s docker-operations.log ]
printf 'committed\n' > "$state/phase"
# Bad metadata must refuse before any deletion, including a corrupt previous-generation archive.
fixture sh -c 'printf corrupt >> "$1"' sh "$new_previous"
snapshot > "$evidence/before-refusal.sha256"
if ./scripts/expire-upgrade-backups.sh --apply > "$evidence/checksum-refusal.log" 2>&1; then exit 1; fi
grep -q 'checksum mismatch' "$evidence/checksum-refusal.log"
snapshot > "$evidence/after-refusal.sha256"
cmp "$evidence/before-refusal.sha256" "$evidence/after-refusal.sha256"
echo 'PASS lock, unfinished phase and checksum mismatch refuse without deleting archives'
