FROM php:8.3-alpine

ENV COMPOSER_ALLOW_SUPERUSER=1

# Extensões no PHP da imagem (docker-php-ext-*). Os pacotes apk php-*
# instalam OUTRO PHP do Alpine que o `php` da imagem não usa — por isso o 500.
RUN apk add --no-cache curl-dev icu-dev libxml2-dev libzip-dev oniguruma-dev \
 && docker-php-ext-install -j$(nproc) \
    ctype curl dom fileinfo intl mbstring session simplexml tokenizer xml xmlreader xmlwriter zip opcache

WORKDIR /app

COPY composer.json composer.lock ./

RUN curl -sS https://getcomposer.org/installer | php

RUN php composer.phar install --no-dev --optimize-autoloader --no-scripts

COPY . .

# Start pronto pra Railway: usa $PORT (Railway injeta) e cai pra 1010 local.
# O cache é limpo no start (com as envs de runtime), não no build.
# O docker-compose.yml local sobrescreve com `command:` próprio, então o dev não muda.
CMD chmod -R 777 /app/var/cache /app/var/log; php bin/console cache:clear --env=${APP_ENV:-prod} || true; php -S 0.0.0.0:${PORT:-1010} -t public/

EXPOSE 1010
