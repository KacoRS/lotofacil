FROM php:8.2-apache

# Habilita o cURL e extensões necessárias para o servidor
RUN apt-get update && apt-get install -y libcurl4-openssl-dev pkg-config libssl-dev && docker-php-ext-install curl

# Copia os arquivos do seu site para a pasta do servidor
COPY index.php /var/www/html/index.php

# Ajusta as permissões para o servidor ler os arquivos
RUN chown -R www-data:www-data /var/www/html

# Expõe a porta padrão do servidor web
EXPOSE 80
