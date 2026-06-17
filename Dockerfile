FROM php:8.2-apache

# Step1: MPM競合を最初に解消（PHP拡張より先に実行）
RUN a2dismod mpm_event mpm_worker 2>/dev/null; a2enmod mpm_prefork; true

# Step2: PHP拡張インストール
RUN docker-php-ext-install pdo pdo_mysql

# Step3: Apacheモジュールと設定
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/sites-available/000-default.conf

# Step4: ファイルコピー
COPY . /var/www/html/

# Step5: ディレクトリ作成・パーミッション設定
RUN mkdir -p /var/www/html/logs /var/www/html/uploads/transport \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/logs /var/www/html/uploads

# Step6: 起動時にRailwayの$PORTをApacheに反映
CMD bash -c "sed -i \"s/Listen 80/Listen \${PORT:-80}/\" /etc/apache2/ports.conf \
    && sed -i \"s/<VirtualHost \*:80>/<VirtualHost *:\${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf \
    && apache2-foreground"
