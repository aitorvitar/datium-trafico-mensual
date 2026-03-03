FROM php:8.2-apache

ENV ACCEPT_EULA=Y

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg2 \
        unixodbc-dev \
        libgssapi-krb5-2 \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && echo "deb [arch=amd64 signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/microsoft-prod.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends msodbcsql18 \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && pecl install sqlsrv-5.12.0 pdo_sqlsrv-5.12.0 \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY assets/ ./assets/
COPY config/ ./config/
COPY includes/ ./includes/
COPY api/ ./api/
COPY index.php ./
COPY export.php ./
COPY reseller_detail.php ./
COPY .env.example ./
COPY README.md ./

RUN chown -R www-data:www-data /var/www/html
