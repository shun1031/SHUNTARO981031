FROM php:8.2-apache

# MPM競合を直接ファイル削除で解消（a2dismodより確実）
RUN rm -f /etc/apache2/mods-enabled/mpm_event.conf \
         /etc/apache2/mods-enabled/mpm_event.load \
         /etc/apache2/mods-enabled/mpm_worker.conf \
         /etc/apache2/mods-enabled/mpm_worker.load \
    && ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf \
    && ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load

# PHP拡張インストール
RUN docker-php-ext-install pdo pdo_mysql

# mod_rewrite有効化・AllowOverride設定
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/sites-available/000-default.conf

# ファイルコピー
COPY . /var/www/html/

# ディレクトリ作成・パーミッション
RUN mkdir -p /var/www/html/logs /var/www/html/uploads/transport \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/logs /var/www/html/uploads

# Railway用ポート設定（起動時）
CMD bash -c "sed -i \"s/Listen 80/Listen \${PORT:-80}/\" /etc/apache2/ports.conf \
    && sed -i \"s/<VirtualHost \*:80>/<VirtualHost *:\${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf \
    && apache2-foreground"
