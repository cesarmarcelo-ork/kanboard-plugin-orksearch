# OrkSearch - Advanced Search & Filters for Kanboard

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Version:** `1.0.0` | **Developer:** OrkFlowTech | **Kanboard:** `>=1.2.50` | **Languages:** Portuguese (Brazil) and English

OrkSearch is a project-scoped search plugin for Kanboard, providing a compact search dialog next to the native filters. Search tasks with **eight cumulative refinements**, relevance ranking, multiple matching sources and, optionally, content from indexed attachments.

## Português (Brasil)

### Recursos

- Pesquisa em títulos, descrições, comentários (respeitando permissões), subtarefas, categorias, etiquetas, nomes de anexos e outros metadados.
- Oito filtros combináveis, na ordem: **Raia, Coluna, Categoria, Etiqueta, Responsável, Prioridade, Complexidade e Status**.
- Resultados por relevância, com 20 cartões por página; um cartão por resultado, com fontes correspondentes expansíveis.
- **Fixar busca** opcional: mantém os filtros e a consulta ao navegar pelo projeto na mesma aba. O recurso usa `sessionStorage` e não armazena documentos nem resultados no navegador.
- Interface em português e inglês, escolhida pelo idioma do Kanboard.
- **Opcional:** pesquisa no conteúdo de PDF com texto selecionável, DOCX, XLSX, PPTX, TXT, CSV, MD, MARKDOWN, HTML e HTM, mediante indexação externa de anexos.

### Instalação pelo catálogo oficial ou ZIP

Baixe o arquivo **`OrkSearch-1.0.0.zip`** de uma release oficial. O pacote deve conter `OrkSearch/Plugin.php` (e não uma pasta gerada automaticamente pelo GitHub com o nome da branch).

Extraia para a pasta de plugins do Kanboard, de forma que o caminho final seja:

```text
plugins/OrkSearch/Plugin.php
```

Recarregue o navegador (`Ctrl+Shift+R`). O mecanismo principal de pesquisa e os filtros funcionam **sem** serviços de indexação, cron, Python ou banco adicional.

**Atenção ao upgrade:** versões experimentais anteriores utilizavam a pasta `OrkFlowFilterStack`. **Não mantenha os dois plugins ativos ao mesmo tempo**. Veja [Migração da versão RC3](docs/MIGRATION-0.5.0-RC3.md) antes de atualizar sua instalação de teste/homelab.

### Indexação de documentos - opcional, não automática no ZIP

A pesquisa **dentro de anexos** requer um indexador Python executado no servidor. Um ZIP de plugin Kanboard **não** instala automaticamente processos de sistema, e isso não deve ser confundido com a instalação padrão do plugin.

**Requisitos desse recurso:** Kanboard com banco SQLite local (`data/db.sqlite`) e anexos no armazenamento local (`data/files`), Python 3 com SQLite FTS5; para PDFs com texto extraível, `pdftotext` (poppler-utils). Outros bancos, storage remoto e OCR não foram homologados para o indexador.

O indexador CLI é `plugins/OrkSearch/Tools/index_documents.py`. Exemplo de verificação sem indexar arquivos:

```bash
python3 plugins/OrkSearch/Tools/index_documents.py --data /CAMINHO/PARA/kanboard/data --check
```

Após conferir permissões e caminhos, execute manualmente um lote controlado:

```bash
python3 plugins/OrkSearch/Tools/index_documents.py \
  --data /CAMINHO/PARA/kanboard/data --reader-gid GID_DO_PHP \
  --max-files 5 --max-seconds 20 --max-mb 8 --max-chars 150000 --pdf-timeout 5
```

A indexação **automática de novos anexos** exige agendar esse comando em um serviço/temporizador `systemd` ou mecanismo equivalente, configurado explicitamente pelo administrador. O processo deve possuir acesso somente de leitura ao banco/anexos do Kanboard e escrita no índice separado, com os cuidados de SQLite WAL e as permissões do usuário PHP. **Não execute o indexador como rotina por requisição HTTP ou a cada tecla digitada.**

O índice é armazenado em `data/orkflow_smartsearch/documents.sqlite`: o caminho histórico foi **mantido intencionalmente** para preservar os dados de instalações anteriores. Nunca exponha a pasta `data/` publicamente.

Apenas anexos de cartões são indexados. São usados limites por lote e por arquivo para reduzir consumo de recursos; anexos novos podem aparecer após o próximo ciclo. Sem índice, a busca nos campos normais do Kanboard continua funcionando.

### Compatibilidade, segurança e limites

- O **OrkSearch v1.0.0** foi homologado funcionalmente no **Kanboard 1.2.50** (notebook), incluindo os oito filtros combináveis e a correção de cartões sem responsável no PHP 8.2+. A versão experimental anterior **0.5.0-rc3** também foi testada no Kanboard **1.2.57** (homelab). A nova versão 1.0.0 ainda não foi homologada na homelab.
- Escopo de pesquisa limitado ao projeto autorizado e aos anexos existentes; observações com visibilidade restrita seguem as permissões aplicáveis.
- O plugin não altera o núcleo, o banco principal, nem cria tabelas no Kanboard. O índice documental é um banco SQLite lateral opcional.
- No máximo 2.000 tarefas por consulta no mecanismo de pesquisa atual (limite de desempenho). Não há OCR, indexação de anexos somente do projeto, nem suporte homologado para arquivos legados DOC/XLS.
- Migração de instalações RC3: veja [`docs/MIGRATION-0.5.0-RC3.md`](docs/MIGRATION-0.5.0-RC3.md).

## English

### Features

- Project-wide search over tasks, comments (visibility-aware), subtasks, descriptions, tags, categories, attachment filenames and metadata.
- Eight cumulative filters: **Swimlane, Column, Category, Tag, Assignee, Priority, Complexity and Status**.
- Relevance ranking, 20 results per page, one entry per task with expandable matching sources.
- Optional **Pin search** preserves query and filter state within the browser tab for each project.
- Optional *document-content search* for text-layer PDF, DOCX, XLSX, PPTX, TXT, CSV, MD, MARKDOWN, HTML and HTM files using a separate incremental index.

### Installation

Install the official release archive so that the plugin is in `plugins/OrkSearch/` with `Plugin.php` directly in that folder. The regular task search and filters work immediately. The plugin archive **does not install a system scheduler or enable document-content indexing automatically**. Administrators who want this optional feature must configure the Python worker and a periodic scheduler separately, using local SQLite/local task storage (details in the Portuguese section above, with command examples).

**Important:** earlier RC3 builds used the plugin directory/namespace `OrkFlowFilterStack`. Disable and remove the old plugin when migrating, and keep the separate document index (`data/orkflow_smartsearch`). See [migration instructions](docs/MIGRATION-0.5.0-RC3.md). Both plugin folders must not remain active at the same time.

### Compatibility and security

Kanboard `>=1.2.50` is the declared minimum. OrkSearch v1.0.0 passed functional acceptance testing on Kanboard `1.2.50`, including all eight cumulative filters and a PHP 8.2+ fix for unassigned tasks. The legacy RC3 was also tested on `1.2.57`; the renamed v1.0.0 has not yet been functionally tested on that homelab version. Access checks enforce project permissions and comment visibility. No Kanboard schema changes, template overrides, external APIs or telemetry. The document index is read-only from PHP and writable only from the separately operated indexer. OCR and remote attachments are unsupported.

## Development and licensing

Source code is MIT licensed, Copyright (c) 2026 OrkFlowTech. See [LICENSE](LICENSE). Dependencies for optional PDF text extraction (`pdftotext` / poppler) are provided by the host and are not bundled with this plugin.

To run the tests and generate the release package on a development system with PHP, Python 3 and Node.js:

```bash
bash Test/package.test.sh
bash scripts/build-release.sh
```

The output archive is `dist/OrkSearch-1.0.0.zip`; the root folder **inside** it must be `OrkSearch/`. GitHub Actions validates pushes to `main`; publishing the versioned GitHub Release requires manually running the **Publish OrkSearch release** workflow after functional acceptance testing.
