# OrkSearch v1.0.0

Initial public release of **OrkSearch**, an OrkFlowTech plugin for advanced Kanboard task search.

- Project-scoped search over titles, descriptions, comments, subtasks, tags, categories, and attachment filenames.
- Eight cumulative facets: Swimlane, Column, Category, Tag, Assignee, Priority, Complexity, Status.
- Relevance ranking, 20 results per page and expandable multiple match sources per card.
- Optional pinned search state (per browser tab/project).
- Optional text search within PDF, DOCX, XLSX, PPTX, TXT, CSV, Markdown and HTML attachments via external incremental Python/SQLite FTS5 indexer.
- Portuguese (Brazil) and English interface; MIT license, Copyright OrkFlowTech.
- The precursor 0.5.0-rc3 was tested by the maintainer on Kanboard 1.2.50 and 1.2.57. Acceptance testing of the renamed 1.0.0 installation is required before publishing this release.

**Installation:** install `OrkSearch-1.0.0.zip` which must contain `OrkSearch/Plugin.php`. Installing this ZIP alone enables the regular task search but does **not** configure automatic document indexing, which requires a separate administrator-managed scheduler and compatible local storage.

**Migration note:** the prior RC3 was installed as `OrkFlowFilterStack`. Do not activate both simultaneously. Existing document index files at `data/orkflow_smartsearch/` can be reused without reindexing. See `docs/MIGRATION-0.5.0-RC3.md`.
