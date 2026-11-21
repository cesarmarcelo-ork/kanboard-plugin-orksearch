#!/usr/bin/env python3
"""Integration test: no Kanboard server required, real SQLite FTS5 sidecar."""
import importlib.util
import hashlib
from pathlib import Path
import sqlite3
import shutil
import subprocess
import sys
import tempfile
import zipfile
from xml.sax.saxutils import escape

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'Tools' / 'index_documents.py'
spec = importlib.util.spec_from_file_location('index_documents', SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


def main():
    with tempfile.TemporaryDirectory(prefix='ofss-index-test-') as temp:
        data = Path(temp)
        files = data / 'files' / 'tasks' / '9'
        files.mkdir(parents=True)
        kb = sqlite3.connect(data/'db.sqlite')
        kb.executescript('''
          CREATE TABLE tasks (id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL);
          CREATE TABLE task_has_files (
             id INTEGER PRIMARY KEY, task_id INTEGER NOT NULL, name TEXT NOT NULL,
             path TEXT NOT NULL, size INTEGER NOT NULL, date INTEGER NOT NULL);
          INSERT INTO tasks VALUES (9, 42), (10, 43);
        ''')
        (files/'testdoc').write_text('Análise de fluoreto em águas subterrâneas',encoding='utf-8')
        docx = files/'docx'
        with zipfile.ZipFile(docx,'w',compression=zipfile.ZIP_DEFLATED) as z:
            z.writestr('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'+escape('Laudo de amônia com cobalto')+'</w:t></w:r></w:p></w:body></w:document>')
        xlsx = files/'xlsx'
        with zipfile.ZipFile(xlsx, 'w', compression=zipfile.ZIP_DEFLATED) as z:
            z.writestr('xl/workbook.xml', '<workbook/>')
            z.writestr('xl/sharedStrings.xml','<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Medicao de sulfato</t></si></sst>')
            z.writestr('xl/worksheets/sheet1.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>')
        pptx = files/'pptx'
        with zipfile.ZipFile(pptx, 'w', compression=zipfile.ZIP_DEFLATED) as z:
            ns = 'http://schemas.openxmlformats.org/drawingml/2006/main'
            for no, word in [(1, 'Fosfato em amostras'), (2, 'Relatorio de cloro')]:
                z.writestr(f'ppt/slides/slide{no}.xml',
                    f'<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="{ns}">'
                    f'<p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>{escape(word)}</a:t>'
                    f'</a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>')
            z.writestr('ppt/notesSlides/notesSlide1.xml',
                f'<p:notes xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" '
                f'xmlns:a="{ns}"><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r>'
                f'<a:t>Observacao de manganes</a:t></a:r></a:p></p:txBody></p:sp>'
                f'</p:spTree></p:cSld></p:notes>')
        assert 'Fosfato' in mod.extract_pptx(pptx, 150000)
        assert 'manganes' in mod.extract_pptx(pptx, 150000)
        csv = files/'csv'
        csv.write_bytes('Componente;Resultado\nAmônia;Fluoreto\n'.encode('cp1252'))
        md = files/'markdown'
        md.write_text('# Projeto de amônia\nVerificar níquel e cobre\n', encoding='utf-8-sig')
        markdown_long = files/'markdown_long'
        markdown_long.write_text('## Pesquisa\nLaudo de bário\n', encoding='utf-16')
        assert 'Amônia' in mod.extract(csv,'.csv',150000,5), 'CSV CP1252 decoding'
        assert 'amônia' in mod.extract(md,'.md',150000,5), 'Markdown UTF-8 BOM decoding'
        assert 'bário' in mod.extract(markdown_long,'.markdown',150000,5), 'Markdown UTF-16 BOM decoding'

        html = files/'html'
        html.write_text('''<!doctype html><html><head><title>Não pesquisar titulo-secreto</title>
          <style>.x{content:'estilo-invisivel'}</style></head><body>
          <h1>Relatório de cálcio &amp; magnésio</h1>
          <p>Água com condutividade 430 &micro;S.</p>
          <div hidden>elemento-oculto</div>
          <div style="display: none">invisivel-css</div>
          <div aria-hidden="true">invisivel-aria</div>
          <script>document.write('javascript-perigoso')</script>
          <noscript>invisivel-noscript</noscript>
          <template>invisivel-template</template>
          <svg><text>invisivel-svg</text></svg>
          <!-- comentario-privado -->
          <a href="https://site.invalid/externo-navegar">Dados de carbonato</a>
          <p>&lt;script&gt;literal nao executavel&lt;/script&gt;</p>
          </body></html>''', encoding='utf-8')
        html_cp1252 = files/'html_cp1252'
        html_cp1252.write_bytes('<html><body><p>Índice de alumínio na água</p></body></html>'.encode('cp1252'))
        # The parser must never index scripts, styles, HTML comments, hidden
        # elements, tag attributes or head metadata; it does keep visible text.
        rendered = mod.extract(html,'.html',150000,5)
        for present in ('Relatório de cálcio & magnésio','condutividade','Dados de carbonato',
                        '<script>literal nao executavel</script>'):
            assert present in rendered, ('visible content missing', present, rendered)
        for absent in ('titulo-secreto','estilo-invisivel','elemento-oculto',
                       'invisivel-css','invisivel-aria','javascript-perigoso',
                       'invisivel-noscript','invisivel-template','invisivel-svg',
                       'comentario-privado','site.invalid'):
            assert absent not in rendered, ('nonvisible or unsafe content indexed', absent)
        assert 'Índice de alumínio' in mod.extract(html_cp1252,'.htm',150000,5)
        assert len(mod.extract(html,'.html',65,5)) <= 65, 'HTML extraction limit'

        other = data/'files'/'tasks'/'10'
        other.mkdir(parents=True)
        (other/'outside').write_text('Risco de vazamento de dados confidenciais',encoding='utf-8')
        use_pdf = False
        try:
            from reportlab.pdfgen.canvas import Canvas
            if shutil.which('pdftotext'):
                c = Canvas(str(files/'pdf'))
                c.drawString(70, 700, 'O teor de cloreto foi confirmado no documento PDF')
                c.save()
                use_pdf = True
        except ImportError:
            pass
        rows=[(1,9,'laudo.txt','tasks/9/testdoc',1,10),
              (2,9,'documento.docx','tasks/9/docx',1,10),
              (3,9,'planilha.xlsx','tasks/9/xlsx',1,10),
              (6,9,'apresentacao.pptx','tasks/9/pptx',int(pptx.stat().st_size),10),
              (7,9,'amostras.csv','tasks/9/csv',int(csv.stat().st_size),10),
              (8,9,'anotacoes.md','tasks/9/markdown',int(md.stat().st_size),10),
              (9,9,'relatorio.markdown','tasks/9/markdown_long',int(markdown_long.stat().st_size),10),
              (10,9,'pagina.html','tasks/9/html',int(html.stat().st_size),10),
              (11,9,'pagina_antiga.HTM','tasks/9/html_cp1252',int(html_cp1252.stat().st_size),10),
              (4,10,'privado.txt','tasks/10/outside',1,10)]
        if use_pdf:
            rows.append((5,9,'laudo.pdf','tasks/9/pdf',int((files/'pdf').stat().st_size),10))
        kb.executemany('INSERT INTO task_has_files VALUES (?,?,?,?,?,?)',rows)
        kb.commit()
        kb.close()
        cmd = [sys.executable,str(SCRIPT),'--data',str(data),'--max-files','2','--max-seconds','15']
        first=subprocess.run(cmd,capture_output=True,text=True,check=True)
        print(first.stdout)
        index=data/'orkflow_smartsearch'/'documents.sqlite'
        db=sqlite3.connect(index)
        assert db.execute('SELECT count(*) FROM documents').fetchone()[0] == 2
        db.close()
        # Resume; same inputs are not processed again.
        for _ in range(10):
            batch = subprocess.run(cmd,capture_output=True,text=True,check=True)
            print(batch.stdout)
            if 'Remaining for another bounded run: 0' in batch.stdout:
                break
        else:
            raise AssertionError('bounded batch did not finish')
        db=sqlite3.connect(index)
        assert db.execute('SELECT count(*) FROM documents').fetchone()[0] == len(rows)
        for term in ['fluoreto','amônia','sulfato','fosfato','manganes','bário','cobre','cálcio','magnésio','alumínio','carbonato'] + (['cloreto'] if use_pdf else []):
            matches = db.execute('SELECT rowid FROM document_fts WHERE document_fts MATCH ?', (term,)).fetchall()
            assert matches, 'missing indexed term: '+term
        assert db.execute('SELECT count(*) FROM documents WHERE project_id=42').fetchone()[0]==9 + int(use_pdf)
        assert db.execute('SELECT count(*) FROM documents WHERE project_id=43').fetchone()[0]==1
        # Same card #9 is legitimately found by both DOCX and CSV + Markdown.
        assert db.execute("SELECT count(*) FROM documents WHERE task_id=9 AND state='indexed' AND content LIKE '%Amônia%'").fetchone()[0] >= 3
        assert db.execute("SELECT count(*) FROM document_fts WHERE document_fts MATCH 'amônia'").fetchone()[0] >= 3
        for hidden in ['titulo-secreto','estilo-invisivel','elemento-oculto',
                       'invisivel-css','invisivel-aria','javascript-perigoso',
                       'invisivel-noscript','invisivel-template','invisivel-svg',
                       'comentario-privado','externo-navegar']:
            assert not db.execute('SELECT 1 FROM documents WHERE file_id=10 AND content LIKE ?',
                                  ('%'+hidden+'%',)).fetchone(), hidden
        db.close()
        fourth=subprocess.run(cmd,capture_output=True,text=True,check=True)
        assert 'Updated: 0' in fourth.stdout, 'not incremental'
        # Regression: skipped PPTX from v0.4.0 must be retried automatically,
        # without reindexing unchanged Excel/Word documents.
        legacy_signature = hashlib.sha256((
            'v1|6|9|42|apresentacao.pptx|tasks/9/pptx|'+str(pptx.stat().st_size)+'|10|not-pdf'
        ).encode()).hexdigest()
        db = sqlite3.connect(index)
        db.execute("UPDATE documents SET signature=?,state='skipped',content='',error='file type not supported' WHERE file_id=6",
                   (legacy_signature,))
        db.commit()
        assert db.execute("SELECT count(*) FROM document_fts WHERE document_fts MATCH 'fosfato'").fetchone()[0] == 0
        db.close()
        retried = subprocess.run(cmd, capture_output=True, text=True, check=True)
        assert 'Updated: 1 (indexed: 1 skipped: 0 failed: 0 )' in retried.stdout, retried.stdout
        db = sqlite3.connect(index)
        assert db.execute("SELECT state FROM documents WHERE file_id=6").fetchone()[0]=='indexed'
        assert db.execute("SELECT count(*) FROM document_fts WHERE document_fts MATCH 'fosfato'").fetchone()[0] == 1
        db.close()
        # Upgrade from 0.4.2 must retry unchanged HTML previously marked
        # unsupported, without rebuilding an already indexed XLSX or TXT.
        legacy_html_signature = hashlib.sha256((
            'v1|10|9|42|pagina.html|tasks/9/html|'+str(html.stat().st_size)+'|10|not-pdf'
        ).encode()).hexdigest()
        db = sqlite3.connect(index)
        db.execute("UPDATE documents SET signature=?,state='skipped',content='',error='file type not supported' WHERE file_id=10",
                   (legacy_html_signature,))
        db.commit()
        assert db.execute("SELECT count(*) FROM document_fts WHERE document_fts MATCH 'magnésio'").fetchone()[0] == 0
        db.close()
        retried_html = subprocess.run(cmd, capture_output=True, text=True, check=True)
        assert 'Updated: 1 (indexed: 1 skipped: 0 failed: 0 )' in retried_html.stdout, retried_html.stdout
        db = sqlite3.connect(index)
        assert db.execute('SELECT state FROM documents WHERE file_id=10').fetchone()[0] == 'indexed'
        assert db.execute("SELECT count(*) FROM document_fts WHERE document_fts MATCH 'magnésio'").fetchone()[0] == 1
        db.close()
        # A file removed from Kanboard must be pruned on next run.
        kb=sqlite3.connect(data/'db.sqlite')
        kb.execute('DELETE FROM task_has_files WHERE id = 2')
        kb.commit(); kb.close()
        fifth=subprocess.run(cmd,capture_output=True,text=True,check=True)
        assert 'Removed stale references: 1' in fifth.stdout, fifth.stdout
        db=sqlite3.connect(index)
        assert db.execute('SELECT count(*) FROM document_fts WHERE document_fts MATCH ?',('cobalto',)).fetchone()[0]==0
        db.close()
        # Prevent path traversal even if an object storage key is compromised.
        assert mod.file_path(data/'files','tasks/9/../../../etc/passwd') is None
        print('PASS incremental batches, TXT/CSV/MD/MARKDOWN/HTML/HTM/DOCX/XLSX/PPTX'+('/PDF' if use_pdf else '')+', FTS, project scope, pruning, traversal protection')

if __name__ == '__main__': main()
