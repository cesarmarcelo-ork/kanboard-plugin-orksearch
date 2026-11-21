# Official OrkSearch publication checklist

Complete items in this order. Do not submit an unavailable ZIP to the official Kanboard catalog.

- [x] Choose name **OrkSearch**, OrkFlowTech as author, MIT license.
- [x] Prepare new `OrkSearch/` folder, PHP namespace, hook registration and AJAX route, with the old index path retained.
- [x] Prepare Portuguese/English README, migration guide, tests, CI and versioned release scripts.
- [x] Verify local regression tests and ZIP structure.
- [ ] Verify the **renamed v1.0.0 plugin** in the notebook Kanboard 1.2.50 (the previous RC3 functional test does not prove the rename).
- [ ] Create the **public** GitHub repository `cesarmarcelo-ork/kanboard-plugin-orksearch`.
- [ ] Push the source to its `main` branch, confirm GitHub Actions CI, perform a real Kanboard 1.2.50 rename/migration smoke test, and **only then** manually run `Publish OrkSearch release` to publish/verify `OrkSearch-1.0.0.zip` in `v1.0.0`.
- [ ] Submit a PR to `kanboard/website` adding the alphabetical `OrkSearch` entry to `plugins.json`, with the verified release ZIP and clearly optional document indexer.
- [ ] Await the official maintainers' decision; do not claim the plugin appears in the Kanboard official directory before the PR is merged.
- [ ] Upgrade the homelab after validation, using backup and migration from the legacy folder.

**Installability vs. indexing:** The regular search UI needs only the Kanboard ZIP. Searching *inside document content* requires separate, explicitly configured Python FTS5 indexing and scheduler for supported local SQLite and local files installations.
