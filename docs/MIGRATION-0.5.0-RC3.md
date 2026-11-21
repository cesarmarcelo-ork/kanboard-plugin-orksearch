# Migrating from OrkFlow Smart Search 0.5.0-rc3 to OrkSearch 1.0.0

The previous plugin was installed in `plugins/OrkFlowFilterStack/`. The stable release uses `plugins/OrkSearch/` and the `Kanboard\Plugin\OrkSearch` namespace. **Running both at once is unsupported**: both attach a search button and asset handlers and can conflict.

## What is preserved

- The Kanboard main database, tasks and attachments are never modified by the plugin migration.
- The optional document index remains at `data/orkflow_smartsearch/documents.sqlite` with its existing FTS5 data. The new `OrkSearch` plugin reads that same index.
- `sessionStorage` keys for pinned searches retain the `ofss:search:v1` prefix intentionally, so existing browser-tab search state is not lost on the upgrade.
- Existing legacy indexer unit/timer names are not renamed by the migration script. Only the service's script path is updated.

## Existing environments

The included helper `scripts/migrate-legacy.sh` supports ONLY these validated layout identifiers and refuses others:

- `homelab`: `/opt/orkflowtech/kanboard/` with Docker container `kanboard`, Linux `systemd` unit `orkflow-smartsearch-index.service`.
- `notebook`: Docker container `kanboard-lab-1250` with volumes `kanboard-lab-1250_kanboard_data` and `kanboard-lab-1250_kanboard_plugins`, Linux `systemd` unit `orkflow-smartsearch-notebook-index.service`.

**Important:** this helper is supplied for testing on the owner's environments; it has not been executed on those hosts as part of release creation. Confirm its preflight output, current backups, permissions, and container paths before applying it.

1. Download the official `OrkSearch-1.0.0.zip` and the migration script into the same directory on the target host.
2. Read-only preflight (choose the exact profile):

```bash
bash migrate-legacy.sh --profile homelab --zip ./OrkSearch-1.0.0.zip --check
# On notebook instead:
bash migrate-legacy.sh --profile notebook --zip ./OrkSearch-1.0.0.zip --check
```

3. Migrate with root privileges, **only after confirming paths**:

```bash
sudo bash migrate-legacy.sh --profile homelab --zip ./OrkSearch-1.0.0.zip --apply
```

Or use `--profile notebook` on the notebook host. The helper creates a backup of the prior plugin directory and service unit, activates the new plugin, updates the indexer's `ExecStart` to the new location and triggers a bounded indexing run. A failed operation is rolled back.

4. Confirm version `1.0.0` and active timer, then refresh the browser (`Ctrl+Shift+R`). Recheck normal search, filters and newly indexed attachments.

Do not use the homelab profile on a different installation. Other administrators can migrate manually using the same pattern with their actual directory paths. Do not delete `data/orkflow_smartsearch` as part of a rename.
