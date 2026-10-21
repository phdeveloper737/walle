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

# Raiz ICP-Brasil v10: o web service da Sefaz GO usa cadeia ICP-Brasil, que o Debian não traz.
# Autenticidade conferida validando a cadeia apresentada pela própria Sefaz (sha256 6E:0B:FF:...:89:F6).
COPY docker/php/icp-brasil/*.crt /usr/local/share/ca-certificates/
RUN update-ca-certificates

# Certificado A1 com criptografia antiga (RC2): OpenSSL 3 precisa do provider "legacy" (ver o arquivo)
COPY docker/php/openssl-legado.cnf /usr/local/etc/openssl-legado.cnf
ENV OPENSSL_CONF=/usr/local/etc/openssl-legado.cnf

WORKDIR /var/www/html
