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
# 起動前に自動マイグレーションを実行
CMD bash -c "php /var/www/html/startup_migrate.php && php -d display_errors=1 -d error_reporting=E_ALL -S 0.0.0.0:\${PORT:-8080} -t /var/www/html /var/www/html/router.php"
