FROM php:8.2-apache

RUN a2enmod rewrite

# Copy app
COPY . /var/www/html/

# Apache listens on 80 by default on Render Docker
EXPOSE 80
