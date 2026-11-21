#!/usr/bin/env bash
# Migration from OrkFlowFilterStack 0.5.0-rc3 to OrkSearch 1.0.0.
# Only the explicitly supported owner environments are recognized.
set -Eeuo pipefail
PROFILE=''
ZIP=''
ACTION=''
usage() {
  cat <<'HELP'
Use:
  bash migrate-legacy.sh --profile homelab|notebook --zip /path/OrkSearch-1.0.0.zip --check
  sudo bash migrate-legacy.sh --profile homelab|notebook --zip /path/OrkSearch-1.0.0.zip --apply

This tool does not change the Kanboard database or delete the existing index.
It refuses to install if either the host or folder layout is unexpected.
HELP
}
while [[ $# -gt 0 ]]; do
  case "$1" in
    --profile) PROFILE="${2:-}"; shift 2;;
    --zip) ZIP="${2:-}"; shift 2;;
    --check|--apply) [[ -z "$ACTION" ]] || { echo 'Select only one action' >&2; exit 2; }; ACTION="${1#--}"; shift;;
    -h|--help) usage; exit 0;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2;;
  esac
done
[[ $ACTION == check || $ACTION == apply ]] || { usage >&2; exit 2; }
[[ $PROFILE == homelab || $PROFILE == notebook ]] || { echo 'Invalid profile' >&2; exit 2; }
[[ -f "$ZIP" && ! -L "$ZIP" ]] || { echo 'ZIP not found or is symlink' >&2; exit 2; }
for prog in docker python3 sha256sum systemctl unzip; do
  command -v "$prog" >/dev/null || { echo "Missing prerequisite: $prog" >&2; exit 1; }
done
if [[ $ACTION == apply && $EUID -ne 0 ]]; then echo 'Use sudo with --apply' >&2; exit 2; fi
if [[ $PROFILE == homelab ]]; then
  CONTAINER='kanboard'
  PLUGINS='/opt/orkflowtech/kanboard/plugins'
  DATA='/opt/orkflowtech/kanboard/data'
  UNIT='orkflow-smartsearch-index.service'
  TIMER='orkflow-smartsearch-index.timer'
  BACKUPS='/opt/orkflowtech/kanboard/backup-plugins'
  VERSION='v1.2.57'
else
  CONTAINER='kanboard-lab-1250'
  VOL_DATA='kanboard-lab-1250_kanboard_data'
  VOL_PLUGINS='kanboard-lab-1250_kanboard_plugins'
  IMAGE="$(docker inspect --type container --format '{{.Config.Image}}' "$CONTAINER")" || exit 1
  [[ $IMAGE == kanboard/kanboard:v1.2.50 ]] || { echo "Unexpected LAB image: $IMAGE" >&2; exit 1; }
  PLUGINS="$(docker volume inspect "$VOL_PLUGINS" --format '{{.Mountpoint}}')" || exit 1
  DATA="$(docker volume inspect "$VOL_DATA" --format '{{.Mountpoint}}')" || exit 1
  [[ $PLUGINS == "/var/lib/docker/volumes/$VOL_PLUGINS/_data" && $DATA == "/var/lib/docker/volumes/$VOL_DATA/_data" ]] || { echo 'Unexpected Docker mounts' >&2; exit 1; }
  MOUNTS="$(docker inspect --format '{{range .Mounts}}{{println .Name .Destination}}{{end}}' "$CONTAINER")" || exit 1
  grep -Fxq "$VOL_DATA /var/www/app/data" <<<"$MOUNTS" || { echo 'Data volume not attached to expected container' >&2; exit 1; }
  grep -Fxq "$VOL_PLUGINS /var/www/app/plugins" <<<"$MOUNTS" || { echo 'Plugin volume not attached to expected container' >&2; exit 1; }
  UNIT='orkflow-smartsearch-notebook-index.service'
  TIMER='orkflow-smartsearch-notebook-index.timer'
  BACKUPS='/var/backups/orksearch-notebook-1250'
  VERSION='v1.2.50'
fi
ACTUAL_VERSION="$(docker exec "$CONTAINER" cat /var/www/app/app/version.txt | tr -d '\r\n')" || exit 1
[[ $ACTUAL_VERSION == "$VERSION" ]] || { echo "Unexpected Kanboard version: $ACTUAL_VERSION (expected $VERSION)" >&2; exit 1; }
[[ "$(docker inspect --type container --format '{{.State.Running}}' "$CONTAINER")" == 'true' ]] || { echo 'Kanboard container not running' >&2; exit 1; }
OLD="$PLUGINS/OrkFlowFilterStack"
NEW="$PLUGINS/OrkSearch"
UNIT_PATH="/etc/systemd/system/$UNIT"
TIMER_PATH="/etc/systemd/system/$TIMER"
for path in "$PLUGINS" "$DATA" "$OLD" "$DATA/orkflow_smartsearch"; do
  [[ ! -L "$path" ]] || { echo "Refusing symlink: $path" >&2; exit 1; }
done
[[ -d "$PLUGINS" && -d "$DATA" && -f "$DATA/db.sqlite" && -d "$OLD" ]] || { echo 'Existing RC3 structure not found' >&2; exit 1; }
[[ ! -e "$NEW" && ! -L "$NEW" ]] || { echo 'OrkSearch directory already exists; not replacing it' >&2; exit 1; }
[[ -f "$UNIT_PATH" && -f "$TIMER_PATH" ]] || { echo 'Legacy systemd indexer units not found' >&2; exit 1; }
[[ ! -L "$UNIT_PATH" && ! -L "$TIMER_PATH" ]] || { echo 'Unexpected systemd unit symlink' >&2; exit 1; }
OLD_EXEC="$OLD/Tools/index_documents.py"
NEW_EXEC="$NEW/Tools/index_documents.py"
grep -Fq "$OLD_EXEC" "$UNIT_PATH" || { echo 'Legacy indexer unit does not point at the old plugin' >&2; exit 1; }
docker exec "$CONTAINER" test -f /var/www/app/plugins/OrkFlowFilterStack/Plugin.php || { echo 'Legacy plugin not mounted in container' >&2; exit 1; }
# Integrity check also refuses directory traversal, symlinks or unexpected root.
python3 - "$ZIP" <<'PY'
import sys, zipfile, pathlib, stat
with zipfile.ZipFile(sys.argv[1]) as z:
    names = z.namelist()
    if 'OrkSearch/Plugin.php' not in names or 'OrkSearch/Tools/index_documents.py' not in names:
        raise SystemExit('Invalid plugin ZIP: missing required files')
    for i in z.infolist():
        p = pathlib.PurePosixPath(i.filename)
        mode = i.external_attr >> 16
        if p.is_absolute() or '..' in p.parts or '.' in p.parts or not i.filename.startswith('OrkSearch/') or stat.S_ISLNK(mode):
            raise SystemExit('Unsafe ZIP member: ' + i.filename)
        if i.file_size > 16*1024*1024:
            raise SystemExit('Oversized member: ' + i.filename)
    if len(names) > 1000 or sum(i.file_size for i in z.infolist()) > 25*1024*1024:
        raise SystemExit('ZIP contains too many entries or exceeds uncompressed size limit')
    bad = z.testzip()
    if bad:
        raise SystemExit('Invalid CRC: ' + bad)
print('ZIP validated: OrkSearch/Plugin.php')
PY
printf 'Environment: %s, Kanboard %s\nContainer: %s\nPlugins: %s\nData: %s\nLegacy: %s\nNew: %s\nIndexer unit: %s\nExisting index: %s\n' \
  "$PROFILE" "$ACTUAL_VERSION" "$CONTAINER" "$PLUGINS" "$DATA" "$OLD" "$NEW" "$UNIT" "$DATA/orkflow_smartsearch/documents.sqlite"
sha256sum "$ZIP"
if [[ $ACTION == check ]]; then
  echo 'READ-ONLY PREFLIGHT OK. Review paths before applying.'
  exit 0
fi
# Mutations begin only after all preflight checks above succeed.
STAGE=''
BACKUP=''
UNIT_BACKUP=''
OLD_MOVED=0
NEW_INSTALLED=0
UNIT_CHANGED=0
TIMER_STOPPED=0
TIMER_WAS_ACTIVE=0
TIMER_WAS_ENABLED=0
if systemctl is-active --quiet "$TIMER"; then TIMER_WAS_ACTIVE=1; fi
if systemctl is-enabled --quiet "$TIMER"; then TIMER_WAS_ENABLED=1; fi
finish() {
  code=$?
  trap - EXIT
  if [[ $code -ne 0 ]]; then
    echo 'Migration failed. Restoring legacy plugin and indexer unit.' >&2
    if [[ $TIMER_STOPPED -eq 1 ]]; then systemctl stop "$TIMER" >/dev/null 2>&1 || true; fi
    if [[ $UNIT_CHANGED -eq 1 && -n $UNIT_BACKUP && -f $UNIT_BACKUP ]]; then
      cp -p "$UNIT_BACKUP" "$UNIT_PATH" || true
      systemctl daemon-reload || true
    fi
    if [[ $NEW_INSTALLED -eq 1 && -d "$NEW" ]]; then rm -rf -- "$NEW"; fi
    if [[ $OLD_MOVED -eq 1 && -d "$BACKUP" ]]; then mv -- "$BACKUP" "$OLD" || true; fi
  fi
  if [[ -n $STAGE && -d "$STAGE" ]]; then rm -rf -- "$STAGE" || true; fi
  if [[ $TIMER_STOPPED -eq 1 ]]; then
    if [[ $TIMER_WAS_ENABLED -eq 1 ]]; then systemctl enable "$TIMER" >/dev/null 2>&1 || true; fi
    if [[ $TIMER_WAS_ACTIVE -eq 1 ]]; then systemctl start "$TIMER" >/dev/null 2>&1 || true; fi
  fi
  exit "$code"
}
trap finish EXIT
STAGE="$(mktemp -d "$PLUGINS/.orksearch-upgrade.XXXXXXXX")"
unzip -q "$ZIP" -d "$STAGE"
[[ -f "$STAGE/OrkSearch/Plugin.php" ]] || exit 1
# The Docker container may lint PHP as its unprivileged web user.
# The staging directory contains only public plugin code, not user data.
chmod 0755 "$STAGE"
chmod -R a+rX "$STAGE/OrkSearch"
for relative in Plugin.php Controller/SmartSearchController.php Service/SearchEngine.php Service/DocumentIndex.php; do
  docker exec "$CONTAINER" php -l "/var/www/app/plugins/$(basename "$STAGE")/OrkSearch/$relative" >/dev/null
done
python3 "$STAGE/OrkSearch/Tools/index_documents.py" --data "$DATA" --check
install -d -m 0750 "$BACKUPS"
NOW="$(date +%Y%m%d-%H%M%S)"
BACKUP="$BACKUPS/OrkFlowFilterStack-${NOW}"
UNIT_BACKUP="$BACKUPS/${UNIT}.${NOW}.bak"
[[ ! -e $BACKUP && ! -e $UNIT_BACKUP ]] || { echo 'Backup name conflict' >&2; exit 1; }
cp -p "$UNIT_PATH" "$UNIT_BACKUP"
systemctl stop "$TIMER"
TIMER_STOPPED=1
mv -- "$OLD" "$BACKUP"
OLD_MOVED=1
mv -- "$STAGE/OrkSearch" "$NEW"
NEW_INSTALLED=1
chmod -R a+rX "$NEW"
UNIT_CHANGED=1
python3 - "$UNIT_PATH" "$OLD_EXEC" "$NEW_EXEC" <<'PY'
import sys, pathlib, os, tempfile
p=pathlib.Path(sys.argv[1])
t=p.read_text()
old,new=sys.argv[2],sys.argv[3]
if t.count(old)!=2:  # ConditionPathExists and ExecStart
    raise SystemExit('Unexpected number of legacy worker references in unit')
t=t.replace(old,new).replace('OrkFlow Smart Search','OrkSearch')
# Replace atomically. Existing mode and ownership of the unit are preserved.
st=p.stat()
fd,tmp=tempfile.mkstemp(prefix='.orksearch-', dir=str(p.parent))
try:
    os.fchmod(fd, st.st_mode)
    with os.fdopen(fd, 'w') as f: f.write(t)
    os.chown(tmp, st.st_uid, st.st_gid)
    os.replace(tmp, p)
finally:
    if os.path.exists(tmp): os.unlink(tmp)
PY
systemctl daemon-reload
systemctl reset-failed "$UNIT" >/dev/null 2>&1 || true
systemctl start "$UNIT"
docker exec "$CONTAINER" grep -Fq "return '1.0.0'" /var/www/app/plugins/OrkSearch/Plugin.php
# Do not delete or rebuild data/orkflow_smartsearch (historic index).
echo 'MIGRATION COMPLETED: OrkSearch 1.0.0 installed; existing index preserved.'
echo "Legacy backup: $BACKUP"
echo "Previous service backup: $UNIT_BACKUP"
echo "Timer: $TIMER"
echo 'Refresh the Kanboard browser with Ctrl+Shift+R.'
