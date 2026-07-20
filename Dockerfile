FROM php:8.2-cli

# PDO MySQL拡張インストール
RUN docker-php-ext-install pdo pdo_mysql calendar

WORKDIR /var/www/html

# ファイルコピー
COPY . /var/www/html/

# ディレクトリ作成・パーミッション
RUN mkdir -p /var/www/html/logs /var/www/html/uploads/transport \
    && chmod -R 755 /var/www/html/logs /var/www/html/uploads

# PHPビルトインサーバーで起動（Railwayの$PORTを使用）
# アップロード上限はデフォルト(upload_max_filesize=2M/post_max_size=8M)のままだと
# 交通費エビデンス（10MB以下を許可）が2MBを超えた時点でUPLOAD_ERR_INI_SIZEとなり
# 「ファイルサイズが大きすぎます」エラーになるため、-dで明示的に引き上げる。
# 起動前に自動マイグレーションを実行し、出発報告スケジューラーをバックグラウンド起動
CMD bash -c "php /var/www/html/startup_migrate.php; php /var/www/html/scripts/departure_scheduler.php & php -d display_errors=1 -d error_reporting=E_ALL -d upload_max_filesize=12M -d post_max_size=40M -d max_file_uploads=20 -S 0.0.0.0:\${PORT:-8080} -t /var/www/html /var/www/html/router.php"
