FROM php:8.2-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers expires

WORKDIR /var/www/html
COPY . /var/www/html/

# Production container must never ship local secrets.
RUN rm -f /var/www/html/.env \
    && mkdir -p /var/www/html/storage/logs /var/www/html/storage/exports /var/www/html/storage/reports \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 775 /var/www/html/storage

# PHP upload/runtime settings for asset pictures and normal requests.
RUN { \
      echo 'upload_max_filesize=8M'; \
      echo 'post_max_size=10M'; \
      echo 'max_execution_time=60'; \
      echo 'memory_limit=256M'; \
      echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/ct4-production.ini

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD php -r '$s=@file_get_contents("http://127.0.0.1/health.php"); exit($s === "CT4_OK" ? 0 : 1);'
