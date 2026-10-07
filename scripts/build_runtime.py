#!/usr/bin/env python3
"""Explicit runtime allowlist. Does not read preserved app or secret files."""
from pathlib import Path
import argparse, shutil, re, hashlib, json

PUBLIC_PHP = [
    'index.php', 'auth/login.php', 'auth/logout.php',
    'partials/header.php', 'partials/footer.php',
    'modules/mat/m_data_editing.php', 'modules/mat/m_data_editing_backend.php',
    'modules/mat/m_data_statistics.php', 'modules/mat/m_data_statistics_backend.php',
    'modules/mat/m_data_statistics_pdf.php', 'modules/mat/m_upload_handler.php',
    'tools/download_auto_uploader.php',
]
FILES = ['config/auth.php'] + ['Public/' + p for p in PUBLIC_PHP] + [
    'Public/assets/img/tpc_logo.jpg', 'Public/assets/img/tpc_logo.png',
    'Public/tools/TPCAutoUploaderSetup.exe', 'Public/tools/TPCAutoUploaderSetup.zip',
    'tools/auto_uploader/tpc_auto_uploader.py',
    'tools/auto_uploader/run_tpc_auto_uploader.cmd', 'tools/auto_uploader/README.md',
] + ['Public/assets/css/' + p + '.css' for p in ['footer','header','login','m_data_editing','m_data_statistics']
] + ['Public/assets/js/' + p + '.js' for p in ['footer','header','login','m_data_editing','m_data_statistics','m_upload_handler']]

def build(src, dst, sha):
    if not re.fullmatch('[0-9a-f]{40}', sha):
        raise ValueError('Expected exact source commit SHA')
    src, dst = src.resolve(), dst.resolve()
    if dst == src or src in dst.parents or dst in src.parents:
        raise ValueError('Artifact must be isolated from source')
    if dst.exists():
        if not dst.is_dir() or any(dst.iterdir()):
            raise ValueError('Artifact destination must be empty')
    dst.mkdir(parents=True, exist_ok=True)
    for name in FILES:
        source = src / name
        if source.is_symlink() or not source.is_file() or source.resolve() != source:
            raise ValueError('Missing or unsafe allowlisted file: ' + name)
        target = dst / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, target)
    (dst / 'SOURCE_COMMIT').write_text(sha + '\n')
    manifest = [{'path':str(p.relative_to(dst)), 'bytes':p.stat().st_size,
                 'sha256':hashlib.sha256(p.read_bytes()).hexdigest()}
                for p in sorted(dst.rglob('*')) if p.is_file()]
    print(json.dumps(manifest, indent=2))

if __name__ == '__main__':
    p = argparse.ArgumentParser()
    p.add_argument('--source', type=Path, required=True)
    p.add_argument('--output', type=Path, required=True)
    p.add_argument('--sha', required=True)
    a = p.parse_args()
    build(a.source, a.output, a.sha)
