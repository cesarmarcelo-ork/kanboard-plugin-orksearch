# Changelog - OrkSearch

## 1.0.0 (2026-11-20)

- Public name and plugin namespace renamed to **OrkSearch** (previous development name: OrkFlow Smart Search; internal directory: `OrkFlowFilterStack`).
- Stable public packaging with folder `OrkSearch/`, MIT license, bilingual documentation, reproducible ZIP build and CI release validation.
- Preserves the historically located, optional FTS5 index at `data/orkflow_smartsearch/documents.sqlite` and the existing browser-tab search state keys for migration.
- Features retained from RC3: eight cumulative facets, 20 tasks per page, source-aware relevance, optional pinned search, Portuguese and English UI, external incremental document indexing.
- **Breaking packaging change for development installations:** remove the old plugin directory and update indexer service path. Follow the migration guide. The ZIP alone never provisions a scheduler.
