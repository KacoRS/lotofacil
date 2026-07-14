FROM php:8.3-apache

RUN a2enmod rewrite

COPY . /var/www/html/

WORKDIR /var/www/html

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 666 todos_sorteios.json

EXPOSE 80

CMD ["apache2-foreground"]
