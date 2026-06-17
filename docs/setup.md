# bMS セットアップガイド（Xserver）

## 1. データベース作成

Xserver サーバーパネル → MySQL → データベース追加

1. データベース名を作成（例: `youraccount_bms`）
2. MySQL ユーザーを作成・権限付与
3. phpMyAdmin にログインし、`sql/schema.sql` を実行（**これ1本で全テーブル・マスタデータ・KLG会社・管理者まで作成されます**）

> `sql/schema.sql` には、売上管理・給与体系・EVP・SPI/ストレングス設問・評価シート等の全マスタが含まれています（個人情報・サンプル取引データは除く）。

## 2. `.env` ファイルの作成

プロジェクトルートの `.env.example` をコピーして `.env` を作成：

```bash
cp .env.example .env
```

`.env` を編集して実値を設定：

```ini
DB_HOST=localhost
DB_NAME=youraccount_bms
DB_USER=youraccount_bms
DB_PASS=your_password

BASE_PATH=/bms
DEBUG_MODE=false

FTP_HOST=sv904.xbiz.ne.jp
FTP_USER=your_ftp_user
FTP_PASS=your_ftp_password
FTP_REMOTE_BASE=/your_domain/public_html/bms
```

> ⚠️ **重要**: `.env` は Git にコミットしてはいけません（`.gitignore` で除外済み）。
> サーバ上の `.env` は FTP で**手動アップロード**してください（`deploy_ftp.py` の対象外）。

## 3. ファイルのアップロード（デプロイ）

### 初回のみ
1. `.env` を手動で FTP アップロード（`/bms/.env` に配置）
2. サーバ側で可能であれば `.env` のパーミッションを 600 に設定

### 通常デプロイ
ローカルで以下を実行：

```bash
python3 deploy_ftp.py
```

FTP 認証は `.env` から自動読み込み。`uploads/`, `logs/`, `.env` 等は自動的にデプロイ除外されます。

## 4. データベーススキーマの初期化（初回のみ）

ブラウザで `https://your-domain.com/bms/install.php` にアクセスすると、`sql/schema.sql` が自動実行されます。

> ⚠️ 実行後は `install.php` を削除または `.htaccess` でブロック（既にブロック済）。

## 5. 初回ログイン

本システムは KLG 専用（単一テナント）です。ログイン画面では会社IDの入力は不要で、ユーザーID／パスワードを直接入力します。

### システム管理者でログイン（初期セットアップ用）
URL: `https://your-domain.com/bms/login.php?admin=1`

- ユーザー名: `admin`
- パスワード: `admin123` ← **必ず変更してください**

ログイン後、管理画面の「社員ユーザー管理」から KLG の従業員アカウントを作成します。

### 従業員ログイン
URL: `https://your-domain.com/bms/login.php`（KLGのユーザーID／パスワードを入力）

### パスワード変更方法

PHP で新しいハッシュを生成：
```php
echo password_hash('新しいパスワード', PASSWORD_DEFAULT);
```

phpMyAdmin から実行：
```sql
UPDATE users
SET password_hash = '$2y$10$新しいハッシュ'
WHERE username = 'admin' AND role = 'super_admin';
```

## 6. 画像アップロードディレクトリのパーミッション

```
public/assets/images/employees/ → 755
uploads/                        → 755
```

## 7. データ入力の流れ

1. 管理画面 → 社員管理 → 社員を登録
2. 社員編集 → ストレングスファインダータブ → SF 資質のランクを入力
3. 社員編集 → SPI タブ → スコアを入力
4. チーム管理 → チームを作成してメンバーを設定

## 8. CSV インポート

管理画面 → CSV インポート → 社員情報 → SF → SPI の順でインポート

## 9. 自動バックアップ設定（オプション）

cron で `cron_backup.php` を定期実行（日次推奨）：

```
0 3 * * * /usr/bin/php /path/to/bms/cron_backup.php >> /path/to/bms/logs/backup.log 2>&1
```

14 日分のバックアップが `backups/` に保存されます（古いものは自動削除）。

## セキュリティ注意事項

- `.env` は Git にコミット禁止・Web アクセス禁止（`.htaccess` で遮断済）
- 本番環境では `DEBUG_MODE=false` に
- 定期的に各種パスワード・API キーをローテーション（四半期ごと推奨）

## ディレクトリ構造

```
/
├── .env                  ※ 認証情報（git除外、サーバ手動配置）
├── .env.example          ※ テンプレート
├── .gitignore
├── .htaccess
├── config/
│   ├── config.php        ※ 設定（.env から読み込み）
│   └── env.php           ※ .env ローダー
├── includes/
│   ├── db.php, auth.php, functions.php, header.php, nav.php, footer.php
│   ├── assessment_functions.php, eval_functions.php
│   ├── sales_functions.php          ※ ローダー
│   └── sales/            ※ 機能別に分割
│       ├── masters.php, cases.php, reports.php
│       ├── transport.php, invoices.php, shifts.php
│       ├── daily_reports.php, attendance.php, calendar.php
├── admin/                ※ 管理者画面
├── public/               ※ 従業員画面
│   └── api/              ※ REST API
├── sql/                  ※ スキーマ・マイグレーション
├── docs/                 ※ このドキュメント
├── uploads/              ※ ユーザーアップロード（.htaccess で遮断）
├── logs/                 ※ エラーログ等
├── login.php, logout.php
├── install.php           ※ 初期セットアップ（実行後は遮断）
├── cron_backup.php       ※ 日次バックアップ
└── deploy_ftp.py         ※ デプロイスクリプト（.env から認証）
```
