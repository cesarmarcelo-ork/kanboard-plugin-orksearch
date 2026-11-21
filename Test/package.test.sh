#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
find . -name '*.php' -print0 | while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done
node --check Assets/orksearch-v1.0.0.js
php Test/search-engine.test.php
for expected in 'template:project:header:after' 'template:layout:js' 'template:layout:css'; do grep -Fq "$expected" Plugin.php; done
grep -Fq 'projectAccessMap->add' Plugin.php
for expected in 'isUserAllowed' 'isLogged'; do grep -Fq "$expected" Controller/SmartSearchController.php; done
! grep -RqE '<script|unsafe-inline|eval\(' Template Assets
printf '\nStatic hook and CSP checks: OK\n'
php Test/schema-regression.test.php

# Visual regression contract: native classes and exact Portuguese title case.
grep -Fq 'ofss-anchor input-addon-item' Template/project/header.php
grep -Fq 'dropdown-menu dropdown-menu-link-icon ofss-launch' Template/project/header.php
grep -Fq 'Busca Inteligente' Template/project/header.php
grep -Fq "open: 'Busca Inteligente'" Assets/orksearch-v1.0.0.js
! grep -Fq 'border: 1px solid #d7dde3' Assets/orksearch-v1.0.0.css
! grep -Fq 'smart-search-v0.3.0' Plugin.php

# Status terminology + selection are UI-only; SQL search engine stays untouched.
node Test/smart-ui.test.js
php Test/document-index.test.php
python3 Test/indexer.test.py
! grep -Fq "status: 'Situação'" Assets/orksearch-v1.0.0.js

# Opening only counts cards; results are fetched in explicit pages.
grep -Fq "mode = facetsOnly ?" Assets/orksearch-v1.0.0.js
grep -Fq "MAX_RESULTS = 20" Service/SearchEngine.php
grep -Fq "compareRelevance" Service/SearchEngine.php

# Native priority + complexity, no date refinements introduced.
grep -Fq "'tasks.priority', 'tasks.score'" Service/SearchEngine.php
grep -Fq "'priority', 'complexity', 'status'" Controller/SmartSearchController.php
grep -Fq "'swimlane', 'column', 'category', 'tag', 'assignee', 'priority', 'complexity', 'status'" Assets/orksearch-v1.0.0.js
! grep -Fq "date_start" Assets/orksearch-v1.0.0.js
! grep -Fq "smart-search-v0.3.3" Plugin.php

php Test/integration-facets.test.php

# Combined source list, markdown and CSV support.
grep -Fq 'mergeMatches' Service/SearchEngine.php
grep -Fq 'MAX_MATCHES_PER_TASK' Service/DocumentIndex.php
grep -Fq "'.markdown'" Tools/index_documents.py
grep -Fq 'ofss-matches-summary' Assets/orksearch-v1.0.0.js

# HTML/HTM extension: no new web process or runtime service; indexer-only.
grep -Fq "'.html'" Tools/index_documents.py
grep -Fq "'.htm'" Tools/index_documents.py
grep -Fq 'class VisibleHTMLText' Tools/index_documents.py
grep -Fq "'html-v1'" Tools/index_documents.py
grep -Fq "return '1.0.0'" Plugin.php

python3 Test/service-sqlite.test.py

# Locale regression: HTML label before client JavaScript runs.
php Test/i18n-template.test.php

# OrkSearch release identity and migration safety.
php Test/release-identity.test.php
php Test/plugin-bootstrap.test.php
