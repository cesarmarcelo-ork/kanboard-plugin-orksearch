# Kanboard Plugin Directory submission (after the GitHub release exists)

Repository: https://github.com/cesarmarcelo-ork/kanboard-plugin-orksearch

Publish the GitHub release `v1.0.0` and verify that `OrkSearch-1.0.0.zip` downloads and contains `OrkSearch/Plugin.php` before opening a PR against https://github.com/kanboard/website (`plugins.json`). Insert the following JSON entry in alphabetical order:

```json
"OrkSearch": {
    "author": "OrkFlowTech",
    "compatible_version": ">=1.2.50",
    "description": "Project-scoped search over tasks, comments, subtasks, tags and attachments with eight cumulative filters. Optional document-content indexing (PDF/Office/text) requires a separate administrator-managed Python worker. Portuguese/English interface.",
    "download": "https://github.com/cesarmarcelo-ork/kanboard-plugin-orksearch/releases/download/v1.0.0/OrkSearch-1.0.0.zip",
    "has_hooks": true,
    "has_overrides": false,
    "has_schema": false,
    "homepage": "https://github.com/cesarmarcelo-ork/kanboard-plugin-orksearch",
    "is_type": "plugin",
    "last_updated": "2026-11-20",
    "license": "MIT",
    "readme": "https://github.com/cesarmarcelo-ork/kanboard-plugin-orksearch/blob/main/README.md",
    "remote_install": true,
    "title": "OrkSearch - Advanced Search & Filters for Kanboard",
    "version": "1.0.0"
}
```

The previous RC3 used the folder `OrkFlowFilterStack` and must not remain installed alongside the public `OrkSearch` plugin. Do not claim that the ZIP alone sets up background indexing.
