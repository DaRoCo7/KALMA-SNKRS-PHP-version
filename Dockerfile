# =============================================================================
# Imagen del backend de KALMA SNKRS (PHP 8.3 + Apache)
# Se construye en dos etapas: la primera solo prepara las dependencias con
# Composer y la imagen final no incluye Composer.
# =============================================================================

# -----------------------------------------------------------------------------
# Etapa 1: dependencias y autoload con Composer
# -----------------------------------------------------------------------------
FROM composer:2.8 AS dependencias

WORKDIR /app

# composer.lock deja fijadas las versiones: la construcción es reproducible
COPY composer.json composer.lock ./
COPY app/ app/

# --no-dev: sin herramientas de desarrollo · --optimize-autoloader: mapa de clases
# La imagen de Composer no trae pdo_pgsql; la imagen final sí (se comprueba al arrancar)
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader \
        --ignore-platform-req=ext-pdo_pgsql

# -----------------------------------------------------------------------------
# Etapa 2: imagen final con PHP 8.3 y Apache
# -----------------------------------------------------------------------------
FROM php:8.3-apache

# Instalar dependencias necesarias para PostgreSQL
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configuración de PHP para producción: los errores nunca se muestran en el
# navegador, se escriben en el log del contenedor (docker compose logs app)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'expose_php = Off'; \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_log = /dev/stderr'; \
        echo 'date.timezone = Europe/Madrid'; \
    } > "$PHP_INI_DIR/conf.d/kalma.ini"

# Activar el módulo rewrite de Apache
RUN a2enmod rewrite

# El nombre que le asignamos al servidor Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Permite a Apache aplicar las reglas de .htaccess
RUN sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Establecer public como directorio público
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Copiar solo lo que necesita la aplicación (ni docs/, ni database/, ni docker/).
# Los archivos quedan como propiedad de root: Apache (www-data) puede leerlos
# pero no modificarlos.
COPY --from=dependencias /app/vendor/ /var/www/html/vendor/
COPY app/    /var/www/html/app/
COPY config/ /var/www/html/config/
COPY public/ /var/www/html/public/

# Puerto HTTP de Apache
EXPOSE 80
