FROM php:8.2-apache

# Enable Apache rewrite (optional but useful)
RUN a2enmod rewrite

# Install PostgreSQL driver for PDO
RUN apt-get update \
 && apt-get install -y libpq-dev \
 && docker-php-ext-install pdo pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

# Copy your app
COPY index.php /var/www/html/index.php

# Apache listens on 80 inside container
EXPOSE 80
