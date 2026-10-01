FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libzip-dev curl \
    && docker-php-ext-install -j"$(nproc)" mbstring pdo_mysql zip opcache \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html/

RUN printf '%s\n' \
    'ServerName localhost' \
    > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && printf '%s\n' \
    '<VirtualHost *:80>' \
    '    DocumentRoot /var/www/html' \
    '    <Directory /var/www/html>' \
    '        AllowOverride All' \
    '        Require all granted' \
    '        Options -Indexes +FollowSymLinks' \
    '    </Directory>' \
    '    ErrorLog /dev/stderr' \
    '    CustomLog /dev/stdout combined' \
    '</VirtualHost>' \
    > /etc/apache2/sites-available/000-default.conf \
    && mkdir -p storage/logs storage/exports storage/reports \
    && chown -R www-data:www-data storage \
    && chmod -R 0755 storage

RUN { \
    echo 'expose_php=Off'; \
    echo 'display_errors=Off'; \
    echo 'log_errors=On'; \
    echo 'error_log=/proc/self/fd/2'; \
    echo 'memory_limit=256M'; \
    echo 'upload_max_filesize=64M'; \
    echo 'post_max_size=64M'; \
    echo 'session.cookie_httponly=1'; \
    echo 'session.cookie_samesite=Lax'; \
    echo 'opcache.enable=1'; \
    echo 'opcache.validate_timestamps=0'; \
} > /usr/local/etc/php/conf.d/app.ini

EXPOSE 80

HEALTHCHECK --interval=10s --timeout=5s --start-period=15s --retries=6 \
    CMD curl -fsS http://127.0.0.1/health.php || exit 1

CMD ["apache2-foreground"]
