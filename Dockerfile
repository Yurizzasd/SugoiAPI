FROM php:8.3-alpine

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apk add --no-cache \
    php-mbstring \
    php-xml \
    php-ctype \
    php-json \
    php-tokenizer \
    php-openssl \
    php-session \
    php-xmlwriter \
    php-xmlreader \
    php-simplexml \
    php-dom \
    php-xml \
    php-curl \
    php-phar \
    php-iconv \
    php-fileinfo \
    php-zip \
    php-gd \
    php-intl

WORKDIR /app

COPY composer.json composer.lock ./

RUN curl -sS https://getcomposer.org/installer | php

RUN php composer.phar install --no-dev --optimize-autoloader

COPY . .

# Start pronto pra Railway: usa $PORT (Railway injeta) e cai pra 1010 local.
# O docker-compose.yml local sobrescreve com `command:` próprio, então o dev não muda.
CMD chmod -R 777 /app/var/cache /app/var/log; php -S 0.0.0.0:${PORT:-1010} -t public/

EXPOSE 1010
