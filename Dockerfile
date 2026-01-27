FROM php:8.2-apache

RUN apt-get update \
 && apt-get install -y libpq-dev \
 && docker-php-ext-install pdo pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

# Copy all your PHP files to Apache web root
COPY index.php /var/www/html/index.php
COPY verify.php /var/www/html/verify.php
COPY verify_api.php /var/www/html/verify_api.php

EXPOSE 80
