FROM php:8.3-apache-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1

# Debian + Apache: caminho padrão do Symfony (no Alpine a compilação quebra).
# Só extensões essenciais — intl/zip vinham quebrando o build.
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl unzip \
    libcurl4-openssl-dev libonig-dev libxml2-dev \
 && docker-php-ext-install -j$(nproc) \
    ctype curl dom fileinfo mbstring session simplexml tokenizer xml opcache \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Apache serve public/ e escuta na $PORT do Railway.
ENV APACHE_DOCUMENT_ROOT=/app/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /app

COPY composer.json composer.lock ./

RUN curl -sS https://getcomposer.org/installer | php \
 && php composer.phar install --no-dev --optimize-autoloader --no-scripts

COPY . .

# O docker-compose.yml local sobrescreve com `command:` próprio, então o dev não muda.
CMD chmod -R 777 /app/var/cache /app/var/log; \
    php bin/console cache:clear --env=${APP_ENV:-prod} || true; \
    sed -i "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf; \
    sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT:-80}>/" /etc/apache2/sites-available/000-default.conf; \
    apache2-foreground
