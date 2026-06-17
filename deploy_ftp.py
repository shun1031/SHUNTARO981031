#!/usr/bin/env python3
"""bMS FTP デプロイスクリプト

使い方:
  1. プロジェクトルートに .env を置く (.env.example 参照)
  2. python3 deploy_ftp.py

セキュリティ:
  - FTP 認証情報は .env から読み込む (ソースにハードコードしない)
  - .env / .env.example / archive/ はデプロイから除外する
  - サーバ側の .env は初回のみ別途手動で FTP アップロードする必要あり
"""

import ftplib
import os
import sys

LOCAL_BASE = os.path.dirname(os.path.abspath(__file__))

# ------------------------------------------------------------
# .env 読み込み（python-dotenv 非依存の簡易パーサ）
# ------------------------------------------------------------
def load_env(path: str) -> dict:
    env = {}
    if not os.path.isfile(path):
        return env
    with open(path, 'r', encoding='utf-8') as f:
        for line in f:
            stripped = line.strip()
            if not stripped or stripped.startswith('#'):
                continue
            if '=' not in stripped:
                continue
            key, _, value = stripped.partition('=')
            key = key.strip()
            value = value.strip()
            # クォート除去
            if len(value) >= 2 and ((value[0] == value[-1] == '"') or (value[0] == value[-1] == "'")):
                value = value[1:-1]
            env[key] = value
    return env


ENV = load_env(os.path.join(LOCAL_BASE, '.env'))

def env_required(key: str) -> str:
    value = ENV.get(key) or os.environ.get(key)
    if not value:
        print(f"ERROR: {key} not set in .env or environment", file=sys.stderr)
        sys.exit(1)
    return value


FTP_HOST        = env_required('FTP_HOST')
FTP_USER        = env_required('FTP_USER')
FTP_PASS        = env_required('FTP_PASS')
REMOTE_BASE     = env_required('FTP_REMOTE_BASE')

# デプロイから除外する項目
SKIP = {
    'deploy_ftp.py',
    'install.php',
    '.DS_Store',
    '.claude',
    '.git',
    '.gitignore',
    '.env',            # 認証情報: 誤って上書きしないよう除外 (サーバ側は手動配置)
    '.env.example',    # テンプレート: 本番に不要
    'docs',
    'archive',         # 過去のスクリプト群: 本番に不要
    '.playwright-mcp',
    'logs',
    'backups',
    'uploads',         # ユーザーアップロードデータ: 本番上書き禁止
}


def ensure_remote_dir(ftp, path):
    dirs = path.strip('/').split('/')
    current = ''
    for d in dirs:
        current += '/' + d
        try:
            ftp.cwd(current)
        except ftplib.error_perm:
            try:
                ftp.mkd(current)
                print(f"  [mkdir] {current}")
            except ftplib.error_perm:
                pass


def upload_directory(ftp, local_dir, remote_dir):
    ensure_remote_dir(ftp, remote_dir)
    entries = sorted(os.listdir(local_dir))
    for entry in entries:
        if entry in SKIP or (entry.startswith('.') and entry != '.htaccess'):
            continue
        local_path = os.path.join(local_dir, entry)
        remote_path = remote_dir + '/' + entry
        if os.path.isdir(local_path):
            if '{' in entry:
                continue
            upload_directory(ftp, local_path, remote_path)
        elif os.path.isfile(local_path):
            with open(local_path, 'rb') as f:
                ftp.storbinary(f'STOR {remote_path}', f)
            size = os.path.getsize(local_path)
            print(f"  [upload] {remote_path} ({size:,} bytes)")


def main():
    print(f"=== bMS FTP Deploy ===")
    print(f"Host: {FTP_HOST}")
    print(f"Remote: {REMOTE_BASE}")
    print()
    print("※ 注意: .env はデプロイに含まれません。サーバ側に .env が存在することを確認してください。")
    print()
    try:
        print("Connecting...")
        ftp = ftplib.FTP(FTP_HOST, timeout=30)
        ftp.login(FTP_USER, FTP_PASS)
        ftp.encoding = 'utf-8'
        print(f"Connected: {ftp.getwelcome()}\n")
        ensure_remote_dir(ftp, REMOTE_BASE)
        print("Uploading files...")
        upload_directory(ftp, LOCAL_BASE, REMOTE_BASE)
        # install.php も別途アップ
        install_path = os.path.join(LOCAL_BASE, 'install.php')
        if os.path.isfile(install_path):
            with open(install_path, 'rb') as f:
                ftp.storbinary(f'STOR {REMOTE_BASE}/install.php', f)
                print(f"  [upload] {REMOTE_BASE}/install.php")
        print("\nUpload complete!")
        ftp.cwd(REMOTE_BASE)
        print(f"\nRemote listing ({REMOTE_BASE}):")
        ftp.retrlines('LIST')
        ftp.quit()
        print("\nDone!")
    except ftplib.all_errors as e:
        print(f"FTP Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
