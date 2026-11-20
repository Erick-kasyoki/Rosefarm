# POS - PHP 8.3 + Apache. PostgreSQL runs as a separate service (see docker-compose.yml).
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libonig-dev \
 && docker-php-ext-install pdo_pgsql pgsql mbstring opcache \
 && a2enmod headers deflate rewrite \
 && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini     /usr/local/etc/php/conf.d/zz-pos.ini
COPY docker/apache.conf /etc/apache2/conf-available/pos.conf
RUN a2enconf pos

COPY public/ /var/www/html/
COPY config/ /var/www/config/
COPY docker/entrypoint.sh /usr/local/bin/pos-entrypoint
RUN chmod +x /usr/local/bin/pos-entrypoint \
 && chown -R www-data:www-data /var/www/html /var/www/config

ENV APP_ENV=production
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/api/index.php?route=health") === false ? 1 : 0);'

ENTRYPOINT ["pos-entrypoint"]
CMD ["apache2-foreground"]
