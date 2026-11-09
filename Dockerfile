
# Partimos de PHP 8.3 con Apache instalado
FROM php:8.3-apache

# Instalar dependencias necesarias para PostgreSQL
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Activar el módulo rewrite de Apache
RUN a2enmod rewrite

# El nombre que le asignamos al servidor Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Permite apache aplicar reglas configuracion de .htacaccess
RUN sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Establecer public como directorio público
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Copiar el proyecto al contenedor
COPY . /var/www/html/

# Establecer permisos básicos
RUN chown -R www-data:www-data /var/www/html

# Puerto HTTP de Apache
EXPOSE 80
