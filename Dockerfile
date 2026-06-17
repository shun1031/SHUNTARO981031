FROM php:8.2-apache

# PDO MySQL 拡張をインストール
RUN docker-php-ext-install pdo pdo_mysql

# MPM競合を解消（mpm_event無効 → mpm_prefork有効）
RUN a2dismod mpm_event || true && a2enmod mpm_prefork

# mod_rewrite 有効化（.htaccess用）
RUN a2enmod rewrite

# ファイルをコピー
COPY . /var/www/html/

# .htaccess が効くように AllowOverride All に変更
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/sites-available/000-default.conf

# ログ・アップロードディレクトリを作成し書き込み権限を付与
RUN mkdir -p /var/www/html/logs /var/www/html/uploads/transport \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/logs /var/www/html/uploads

# Railway は $PORT 環境変数でポートを指定するため起動時に反映
CMD bash -c "sed -i \"s/Listen 80/Listen \${PORT:-80}/\" /etc/apache2/ports.conf \
    && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:\${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf \
    && apache2-foreground"
