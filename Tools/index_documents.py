#!/usr/bin/env python3
"""Optional, bounded, incremental Kanboard attachment indexer (SQLite/local files only).

Run manually from the *homelab host*, never through an HTTP request. It reads
Kanboard's SQLite DB in read-only mode and writes only to its own sidecar DB.
It deliberately does not OCR, scan external object storage or index project-only
files: only files attached to tasks can be returned in card search results.
"""
import argparse
import fcntl
import hashlib
import os
from pathlib import Path
from html.parser import HTMLParser
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time
import zipfile
from xml.etree import ElementTree as ET

SUPPORTED = {'.pdf', '.docx', '.xlsx', '.pptx', '.txt', '.csv', '.md', '.markdown', '.html', '.htm'}
MAX_ZIP_FILES = 800
MAX_ZIP_UNCOMPRESSED = 24 * 1024 * 1024
MAX_XML_BYTES = 6 * 1024 * 1024
NS_W = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'
NS_X = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
NS_A = '{http://schemas.openxmlformats.org/drawingml/2006/main}'


def truncate_text(text, limit):
    return re.sub(r'[\x00-\x08\x0b\x0c\x0e-\x1f]+', ' ', text)[:limit]


def safe_zip(src):
    zf = zipfile.ZipFile(src)
    entries = zf.infolist()
    if len(entries) > MAX_ZIP_FILES or sum(e.file_size for e in entries) > MAX_ZIP_UNCOMPRESSED:
        zf.close()
        raise ValueError('zip limits exceeded')
    for e in entries:
        if e.file_size > MAX_XML_BYTES or (e.compress_size and e.file_size / e.compress_size > 250):
            # Other large binary media may exist in a perfectly valid office
            # document; only process XML entries, and cap those individually.
            if e.filename.lower().endswith('.xml'):
                zf.close()
                raise ValueError('XML member exceeds safe limits')
    return zf


def extract_docx(path, limit):
    with safe_zip(path) as zf:
        names = zf.namelist()
        selected = [n for n in names if n == 'word/document.xml' or
                    re.fullmatch(r'word/(header\d+|footer\d+|footnotes|endnotes)\.xml', n)]
        if 'word/document.xml' not in selected:
            raise ValueError('missing DOCX main document')
        pieces = []
        for name in selected:
            if zf.getinfo(name).file_size > MAX_XML_BYTES:
                continue
            root = ET.fromstring(zf.read(name))
            for paragraph in root.iter(NS_W + 'p'):
                line = ''.join(x.text or '' for x in paragraph.iter(NS_W + 't'))
                if line.strip():
                    pieces.append(line)
                if sum(map(len, pieces)) >= limit:
                    break
            if sum(map(len, pieces)) >= limit:
                break
        return truncate_text('\n'.join(pieces), limit)


def extract_xlsx(path, limit):
    with safe_zip(path) as zf:
        names = zf.namelist()
        if 'xl/workbook.xml' not in names:
            raise ValueError('missing XLSX workbook')
        shared = []
        if 'xl/sharedStrings.xml' in names:
            info = zf.getinfo('xl/sharedStrings.xml')
            if info.file_size > MAX_XML_BYTES:
                raise ValueError('large shared-string XML')
            root = ET.fromstring(zf.read('xl/sharedStrings.xml'))
            for si in root.iter(NS_X + 'si'):
                shared.append(''.join(t.text or '' for t in si.iter(NS_X + 't')))
        out, length = [], 0
        sheets = sorted(n for n in names if re.fullmatch(r'xl/worksheets/sheet\d+\.xml', n))
        for sheet in sheets:
            info = zf.getinfo(sheet)
            if info.file_size > MAX_XML_BYTES:
                raise ValueError('large worksheet XML')
            root = ET.fromstring(zf.read(sheet))
            for cell in root.iter(NS_X + 'c'):
                typ = cell.attrib.get('t', '')
                if typ == 'inlineStr':
                    value = ' '.join(t.text or '' for t in cell.iter(NS_X + 't'))
                else:
                    v = cell.find(NS_X + 'v')
                    value = (v.text or '') if v is not None else ''
                    if typ == 's' and value.isdigit():
                        i = int(value)
                        value = shared[i] if i < len(shared) else ''
                if not value.strip():
                    continue
                out.append(value)
                length += len(value) + 1
                if length >= limit:
                    break
            if length >= limit:
                break
        return truncate_text('\n'.join(out), limit)



def extract_pptx(path, limit):
    """Extract editable slide text and speaker notes, without rendering or OCR.

    PPTX is an OPC ZIP. Parse only slide/notes XML in numerical order; ignore
    slide layouts, themes, embedded assets, macros and external relationships.
    The existing safe_zip() bounds ZIP expansion and individual XML members.
    """
    with safe_zip(path) as zf:
        slides, notes = [], []
        for name in zf.namelist():
            match = re.fullmatch(r'ppt/slides/slide([1-9]\d*)\.xml', name)
            if match:
                slides.append((int(match.group(1)), name))
                continue
            match = re.fullmatch(r'ppt/notesSlides/notesSlide([1-9]\d*)\.xml', name)
            if match:
                notes.append((int(match.group(1)), name))
        if not slides:
            raise ValueError('missing PPTX slide XML')
        parts, length = [], 0
        for _, name in sorted(slides) + sorted(notes):
            # The ZIP preflight rejects individual oversized XML entries. Keep
            # this explicit check so no unbounded XML data reaches the parser.
            if zf.getinfo(name).file_size > MAX_XML_BYTES:
                raise ValueError('large PPTX slide XML')
            root = ET.fromstring(zf.read(name))
            for paragraph in root.iter(NS_A + 'p'):
                line = ''.join(node.text or '' for node in paragraph.iter(NS_A + 't'))
                if line.strip():
                    parts.append(line)
                    length += len(line) + 1
                if length >= limit:
                    break
            if length >= limit:
                break
        return truncate_text('\n'.join(parts), limit)


def extract_pdf(path, limit, seconds):
    cmd = shutil.which('pdftotext')
    if not cmd:
        raise RuntimeError('pdftotext not installed on host; PDF skipped')
    # Keep untrusted PDFs out of the Kanboard PHP request process. Apply process
    # memory, CPU and output-file limits when available (Linux host).
    def restrict():
        try:
            import resource
            resource.setrlimit(resource.RLIMIT_AS, (256 * 1024 * 1024,) * 2)
            resource.setrlimit(resource.RLIMIT_CPU, (max(2, seconds),) * 2)
            resource.setrlimit(resource.RLIMIT_FSIZE, (2 * 1024 * 1024,) * 2)
        except (ImportError, OSError, ValueError):
            pass
        # If administrator launched the indexer as root, never run the PDF
        # parser with root privileges. Give it only a bounded private copy.
        if os.name == 'posix' and os.geteuid() == 0:
            os.setgroups([])
            os.setgid(65534)
            os.setuid(65534)
    with tempfile.TemporaryDirectory(prefix='orkflow-index-') as tmp:
        tmp = Path(tmp)
        out = tmp / 'document.txt'
        input_copy = tmp / 'source.pdf'
        with path.open('rb') as src, input_copy.open('wb') as dest:
            shutil.copyfileobj(src, dest, length=1024 * 1024)
        if os.name == 'posix' and os.geteuid() == 0:
            # 65534 is the standard unprivileged nobody UID/GID on Linux.
            os.chown(tmp, 65534, 65534)
            os.chown(input_copy, 65534, 65534)
            os.chmod(input_copy, 0o600)
        proc = subprocess.run(
            [cmd, '-q', '-nopgbrk', '-enc', 'UTF-8', str(input_copy), str(out)],
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL, timeout=seconds,
            preexec_fn=restrict if os.name == 'posix' else None, check=False,
        )
        if proc.returncode != 0 or not out.is_file():
            raise RuntimeError('PDF extraction failed (encrypted, invalid or limited)')
        with out.open('rb') as f:
            return truncate_text(f.read(min(limit * 4, 2 * 1024 * 1024)).decode('utf-8', 'replace'), limit)



# Extract visible HTML text without a browser or remote resource loading.
# No JavaScript/CSS evaluation, network access, or external dependencies.
_HTML_IGNORED = frozenset(('head', 'script', 'style', 'template', 'noscript', 'svg', 'canvas'))
_HTML_BLOCKS = frozenset(('address', 'article', 'aside', 'blockquote', 'br', 'dd', 'div', 'dl',
                          'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2',
                          'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p',
                          'pre', 'section', 'table', 'tbody', 'td', 'th', 'thead', 'tr', 'ul'))
_HTML_VOID = frozenset(('area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
                         'meta', 'param', 'source', 'track', 'wbr'))


class VisibleHTMLText(HTMLParser):
    """Collect textual content, excluding non-rendered and potentially active content.

    Parsing HTML here only extracts strings; it never loads remote URLs or runs
    scripts. The later Kanboard UI uses textContent, never innerHTML, for matches.
    """
    def __init__(self, limit):
        super().__init__(convert_charrefs=True)
        self.limit = limit
        self.parts = []
        self.size = 0
        self.skip_depth = 0

    def _append(self, value):
        if self.size >= self.limit:
            return
        value = value[:self.limit - self.size]
        if value:
            self.parts.append(value)
            self.size += len(value)

    def handle_starttag(self, tag, attrs):
        if self.skip_depth:
            if tag not in _HTML_VOID:
                self.skip_depth += 1
            return
        attributes = dict(attrs)
        style = (attributes.get('style') or '').lower()
        hidden = ('hidden' in attributes or
                  (attributes.get('aria-hidden') or '').lower() == 'true' or
                  bool(re.search(r'\bdisplay\s*:\s*none\b', style)) or
                  bool(re.search(r'\bvisibility\s*:\s*(?:hidden|collapse)\b', style)))
        if tag in _HTML_IGNORED or hidden:
            if tag not in _HTML_VOID:
                self.skip_depth = 1
            return
        if tag in _HTML_BLOCKS:
            self._append('\n' if tag not in ('td', 'th') else ' ')

    def handle_endtag(self, tag):
        if self.skip_depth:
            self.skip_depth -= 1
        elif tag in _HTML_BLOCKS:
            self._append('\n' if tag not in ('td', 'th') else ' ')

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        if tag not in _HTML_VOID:
            self.handle_endtag(tag)

    def handle_data(self, data):
        if not self.skip_depth:
            self._append(data)

    def get_text(self):
        # Collapse markup-derived whitespace while retaining word boundaries.
        return re.sub(r'\s+', ' ', ''.join(self.parts)).strip()


def decode_local_text(raw):
    """Decode locally supplied documents; covers UTF-8, UTF-16 BOM and CP1252."""
    if raw.startswith((b'\xff\xfe', b'\xfe\xff')):
        return raw.decode('utf-16', 'replace')
    try:
        return raw.decode('utf-8-sig')
    except UnicodeDecodeError:
        return raw.decode('cp1252', 'replace')


def extract_html(path, limit):
    # Reading is bounded by the configured extraction limit and the outer
    # per-attachment size check. No HTML is ever executed or rendered.
    with path.open('rb') as f:
        raw = f.read(min(limit * 4, 2 * 1024 * 1024))
    parser = VisibleHTMLText(limit)
    parser.feed(decode_local_text(raw))
    parser.close()
    return truncate_text(parser.get_text(), limit)


def extract(path, ext, limit, seconds):
    if ext == '.pdf':
        return extract_pdf(path, limit, seconds)
    if ext == '.docx':
        return extract_docx(path, limit)
    if ext == '.xlsx':
        return extract_xlsx(path, limit)
    if ext == '.pptx':
        return extract_pptx(path, limit)
    if ext in ('.html', '.htm'):
        return extract_html(path, limit)
    if ext in ('.txt', '.csv', '.md', '.markdown'):
        with path.open('rb') as f:
            raw = f.read(min(limit * 4, 2 * 1024 * 1024))
        return truncate_text(decode_local_text(raw), limit)
    raise ValueError('unsupported extension')


def init_index(db):
    db.execute('PRAGMA busy_timeout=1200')
    db.executescript('''
    CREATE TABLE IF NOT EXISTS documents (
      file_id INTEGER PRIMARY KEY, task_id INTEGER NOT NULL, project_id INTEGER NOT NULL,
      name TEXT NOT NULL, signature TEXT NOT NULL, content TEXT NOT NULL DEFAULT '',
      state TEXT NOT NULL DEFAULT 'indexed', updated_at INTEGER NOT NULL,
      error TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS idx_ofss_project ON documents(project_id);
    CREATE INDEX IF NOT EXISTS idx_ofss_task ON documents(task_id);
    CREATE VIRTUAL TABLE IF NOT EXISTS document_fts USING fts5(
      content, content='documents', content_rowid='file_id', tokenize='unicode61 remove_diacritics 2'
    );
    CREATE TRIGGER IF NOT EXISTS ofss_ai AFTER INSERT ON documents BEGIN
      INSERT INTO document_fts(rowid, content) VALUES(new.file_id, new.content);
    END;
    CREATE TRIGGER IF NOT EXISTS ofss_ad AFTER DELETE ON documents BEGIN
      INSERT INTO document_fts(document_fts,rowid,content) VALUES('delete',old.file_id,old.content);
    END;
    CREATE TRIGGER IF NOT EXISTS ofss_au AFTER UPDATE ON documents BEGIN
      INSERT INTO document_fts(document_fts,rowid,content) VALUES('delete',old.file_id,old.content);
      INSERT INTO document_fts(rowid,content) VALUES(new.file_id,new.content);
    END;
    ''')
    # DB is never requested through a public route. File-level access is still
    # restricted on disk (group set by --reader-gid).
    db.commit()


def file_path(base, key):
    # Paths in Kanboard's task_has_files are relative object storage keys.
    # A safe indexer must NEVER follow symlinks outside FILES_DIR.
    if not isinstance(key, str) or not key.startswith('tasks/'):
        return None
    base = base.resolve(strict=True)
    p = (base / key).resolve(strict=False)
    try:
        p.relative_to(base)
    except ValueError:
        return None
    return p


def run(args):
    if args.max_files < 1 or args.max_files > 100 or args.max_seconds < 2 or args.max_seconds > 600:
        raise ValueError('invalid process limits')
    if args.max_mb < 1 or args.max_mb > 100 or args.max_chars < 100 or args.max_chars > 500000:
        raise ValueError('invalid document limits')
    data = Path(args.data).resolve(strict=True)
    kanboard_db = Path(args.db).resolve() if args.db else data / 'db.sqlite'
    storage = Path(args.files_dir).resolve() if args.files_dir else data / 'files'
    index_dir = data / 'orkflow_smartsearch'
    target = index_dir / 'documents.sqlite'
    if not kanboard_db.is_file():
        raise RuntimeError('Kanboard db.sqlite was not found; SQLite/local-storage mode only')
    if not storage.is_dir():
        raise RuntimeError('local task file storage was not found')
    if args.check:
        print(f'Kanboard DB: {kanboard_db}\nFiles: {storage}\nIndex: {target}')
        print('PDF extraction:', 'available' if shutil.which('pdftotext') else 'unavailable (optional: poppler-utils)')
        print('PPTX extraction: available (built-in Python XML/ZIP reader)')
        print('HTML/HTM extraction: available (built-in Python HTML parser; no script execution)')
        print('Index present:', 'yes' if target.exists() else 'no')
        return
    if index_dir.is_symlink():
        raise RuntimeError('refusing a symlinked index directory')
    index_dir.mkdir(mode=0o750, parents=True, exist_ok=True)
    os.chmod(index_dir, 0o750)
    lockfile = index_dir / 'index.lock'
    with lockfile.open('a+b') as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise RuntimeError('another indexer is already running')
        # Data is readable only by owner and the explicit PHP process group.
        if args.reader_gid is not None:
            os.chown(index_dir, -1, args.reader_gid)
            os.chown(lockfile, -1, args.reader_gid)
        os.chmod(lockfile, 0o640)
        try:
            kb = sqlite3.connect('file:' + str(kanboard_db) + '?mode=ro', uri=True, timeout=1.2)
        except sqlite3.OperationalError as ex:
            raise RuntimeError('cannot open Kanboard SQLite read-only (WAL sidecars may need '
                               'directory access): ' + str(ex)) from ex
        kb.row_factory = sqlite3.Row
        kb.execute('PRAGMA query_only=ON')
        sql = '''SELECT f.id, f.task_id, t.project_id, f.name, f.path, f.size, f.date
                 FROM task_has_files f JOIN tasks t ON t.id = f.task_id'''
        params = []
        if args.project_id:
            sql += ' WHERE t.project_id = ?'
            params.append(args.project_id)
        sql += ' ORDER BY f.date ASC, f.id ASC'
        # Fully read metadata first: pruning must never use a partial snapshot.
        rows = kb.execute(sql, params).fetchall()
        kb.close()  # Release Kanboard database read access before extraction.
        if target.exists() and target.is_symlink():
            raise RuntimeError('refusing a symlinked index')
        try:
            idx = sqlite3.connect(str(target), timeout=1.2)
        except sqlite3.OperationalError as ex:
            raise RuntimeError('cannot open OrkFlow document index for writing: ' + str(ex)) from ex
        idx.row_factory = sqlite3.Row
        try:
            init_index(idx)
            if args.reader_gid is not None:
                os.chown(target, -1, args.reader_gid)
            os.chmod(target, 0o640)
            already = {int(r['file_id']):r['signature'] for r in idx.execute(
                'SELECT file_id,signature FROM documents' + (' WHERE project_id = ?' if args.project_id else ''),
                [args.project_id] if args.project_id else []
            )}
            by_id = {int(row['id']) for row in rows}
            # Remove deleted/moved attachments even when no work budget remains.
            stale = set(already).difference(by_id)
            for file_id in stale:
                idx.execute('DELETE FROM documents WHERE file_id = ?', (file_id,))
            idx.commit()
            deadline = time.monotonic() + args.max_seconds
            limit_bytes = args.max_mb * 1024 * 1024
            handled = indexed = skipped = failed = 0
            remaining = 0
            pdf_available = bool(shutil.which('pdftotext'))
            for row in rows:
                ext = Path(row['name']).suffix.casefold()
                signature = hashlib.sha256(('v1|' + str(row['id']) + '|' + str(row['task_id']) + '|' +
                    str(row['project_id']) + '|' + str(row['name']) + '|' + str(row['path']) + '|' +
                    str(row['size']) + '|' + str(row['date']) + '|' +
                    (('pdf-ok' if pdf_available else 'pdf-missing') if ext == '.pdf' else
                     ('pptx-v1' if ext == '.pptx' else ('html-v1' if ext in ('.html','.htm') else ('text-v2' if ext in ('.txt','.csv','.md','.markdown') else 'not-pdf'))))).encode()).hexdigest()
                if already.get(int(row['id'])) == signature:
                    continue
                if handled >= args.max_files or time.monotonic() >= deadline:
                    remaining += 1
                    continue
                handled += 1
                text, state, error = '', 'indexed', ''
                try:
                    p = file_path(storage, row['path'])
                    if ext not in SUPPORTED:
                        state, error = 'skipped', 'file type not supported'
                    elif not p or not p.is_file():
                        state, error = 'skipped', 'file absent from local storage'
                    elif row['size'] > limit_bytes or p.stat().st_size > limit_bytes:
                        state, error = 'skipped', 'file exceeds configured size'
                    else:
                        # Bound each PDF invocation by remaining worker time.
                        seconds = min(args.pdf_timeout, max(1, int(deadline - time.monotonic())))
                        text = extract(p, ext, args.max_chars, seconds)
                        if not text.strip():
                            state, error = 'skipped', 'no extractable text'
                except (OSError, RuntimeError, ValueError, zipfile.BadZipFile, ET.ParseError,
                        subprocess.TimeoutExpired, UnicodeError) as ex:
                    state, error = 'failed', str(ex)[:160]
                idx.execute('''INSERT INTO documents
                    (file_id,task_id,project_id,name,signature,content,state,updated_at,error)
                    VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(file_id) DO UPDATE SET
                    task_id=excluded.task_id, project_id=excluded.project_id,
                    name=excluded.name, signature=excluded.signature,
                    content=excluded.content, state=excluded.state,
                    updated_at=excluded.updated_at, error=excluded.error''',
                    (row['id'], row['task_id'], row['project_id'], row['name'], signature,
                     text if state == 'indexed' else '', state, int(time.time()), error))
                idx.commit()
                if state == 'indexed': indexed += 1
                elif state == 'skipped': skipped += 1
                else: failed += 1
            print('Documents examined:', len(rows))
            print('Updated:', handled, '(indexed:', indexed, 'skipped:', skipped, 'failed:', failed, ')')
            print('Removed stale references:', len(stale))
            print('Remaining for another bounded run:', remaining)
            print('Index:', target)
            print('Status: ready - Kanboard search stays independent if index unavailable')
        finally:
            idx.close()


def cli():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--data', required=True, help='Host path of Kanboard DATA_DIR')
    p.add_argument('--db', help='SQLite Kanboard DB, default DATA_DIR/db.sqlite')
    p.add_argument('--files-dir', help='Local object storage, default DATA_DIR/files')
    p.add_argument('--project-id', type=int, default=0, help='Optional scope: one project ID')
    p.add_argument('--max-files', type=int, default=5, help='Max documents processed per run (default 5)')
    p.add_argument('--max-seconds', type=int, default=25, help='Max wall-clock time budget (default 25)')
    p.add_argument('--max-mb', type=int, default=8, help='Max attachment size per file, MB (default 8)')
    p.add_argument('--max-chars', type=int, default=150000, help='Max extracted text characters')
    p.add_argument('--pdf-timeout', type=int, default=5, help='Max pdftotext seconds per document')
    p.add_argument('--reader-gid', type=int, help='Host numeric GID permitted to read search index (PHP group)')
    p.add_argument('--check', action='store_true', help='Inspect prerequisites without changing any files')
    args = p.parse_args()
    try:
        run(args)
    except (RuntimeError, ValueError, sqlite3.DatabaseError, PermissionError) as ex:
        print('Indexer error:', str(ex), file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    cli()
