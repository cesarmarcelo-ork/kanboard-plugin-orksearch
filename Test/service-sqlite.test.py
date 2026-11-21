#!/usr/bin/env python3
"""Regression: failure messages identify the specific SQLite database.

Check packaged source and basic read-only WAL semantics against a temporary
fixture; does not touch the Kanboard database or require systemd.
"""
import importlib.util
import sqlite3
import tempfile
import os
import subprocess
import sys
from pathlib import Path

mod = Path(__file__).resolve().parents[1] / 'Tools' / 'index_documents.py'
text = mod.read_text()
assert 'cannot open Kanboard SQLite read-only' in text
assert 'cannot open OrkFlow document index for writing' in text

with tempfile.TemporaryDirectory(prefix='ofss-rc2-wal-') as temp:
    db = Path(temp) / 'db.sqlite'
    con = sqlite3.connect(db)
    con.execute('PRAGMA journal_mode=WAL')
    con.execute('CREATE TABLE items(id INT)')
    con.execute('INSERT INTO items VALUES (17)')
    con.commit()
    con.close()
    con = sqlite3.connect('file:' + str(db) + '?mode=ro', uri=True)
    assert con.execute('SELECT id FROM items').fetchone()[0] == 17
    con.close()
print('WAL read-only SQLite smoke test: PASS')
