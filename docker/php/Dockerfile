# walle: PHP 8.5-FPM com as extensões que o Laravel, o Horizon (pcntl) e o Valkey (redis) precisam.
# A mesma imagem roda o site (walle-app), as filas (walle-horizon) e o agendador (walle-scheduler).
FROM php:8.5-fpm

ENV TZ=America/Sao_Paulo

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN install-php-extensions pdo_mysql redis pcntl intl zip bcmath gd \
 && apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/* \
 && ln -sf /usr/share/zoneinfo/America/Sao_Paulo /etc/localtime \
 && echo America/Sao_Paulo > /etc/timezone

COPY docker/php/walle.ini /usr/local/etc/php/conf.d/zz-walle.ini

WORKDIR /var/www/html
