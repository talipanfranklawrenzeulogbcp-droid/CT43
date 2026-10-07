FROM php:8.3-apache

# Install the native libraries and PHP extensions used by the application.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        gd \
        mbstring \
        opcache \
        pdo_mysql \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Allow the application's .htaccess routing and security headers.
RUN a2enmod headers rewrite \
    && { \
        echo '<VirtualHost *:8080>'; \
        echo '    ServerName localhost'; \
        echo '    ServerAlias *'; \
        echo '    DocumentRoot /var/www/html'; \
        echo '    <Directory /var/www/html>'; \
        echo '        AllowOverride All'; \
        echo '        Options -Indexes +FollowSymLinks'; \
        echo '        Require all granted'; \
        echo '    </Directory>'; \
        echo '    ErrorLog /dev/stderr'; \
        echo '    CustomLog /dev/stdout combined'; \
        echo '</VirtualHost>'; \
    } > /etc/apache2/sites-available/000-default.conf

# Production-oriented PHP defaults. HostForge supplies secrets at runtime.
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
        echo 'session.use_strict_mode=1'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=128'; \
    } > /usr/local/etc/php/conf.d/app-production.ini

WORKDIR /var/www/html
COPY . .

RUN mkdir -p storage/logs storage/exports storage/reports \
    && chown -R www-data:www-data storage \
    && chmod -R 0755 storage \
    && rm -f .env

EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=3s --start-period=5s --retries=3 \
    CMD php -r '$p=(int)(getenv("PORT")?:8080); $c=@file_get_contents("http://127.0.0.1:".$p."/health.php"); if($c===false) exit(1); $j=json_decode($c,true); exit(($j["status"]??"") === "ok" ? 0 : 1);'

ENV PORT=8080

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

CMD ["/usr/local/bin/docker-entrypoint.sh"]
