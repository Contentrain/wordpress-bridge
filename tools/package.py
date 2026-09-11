#!/usr/bin/env python3
"""Produce an installable WordPress archive containing only runtime files."""
from pathlib import Path
import zipfile
root = Path(__file__).resolve().parents[1]
out = root / 'dist'
out.mkdir(exist_ok=True)
files = [root / n for n in ('contentrain-bridge.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'README.md', 'SECURITY.md')]
files += sorted((root / 'includes').glob('*.php'))
files += sorted((root / 'assets').glob('*'))
archive = out / 'contentrain-bridge.zip'
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
    for file in files:
        if file.is_file():
            z.write(file, 'contentrain-bridge/' + str(file.relative_to(root)))
print(archive)
