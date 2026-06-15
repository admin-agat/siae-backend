FROM php:8.2-apache

# Instalar extensiones necesarias para Laravel + PostgreSQL
RUN apt-get update && apt-get install -y \
    libpq-dev zip unzip git curl \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar Apache para apuntar a /public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN a2enmod rewrite

# Copiar todo el proyecto al contenedor
WORKDIR /var/www/html
COPY . .

# Instalar dependencias de Laravel sin paquetes de desarrollo
RUN composer install --no-dev --optimize-autoloader

# Dar permisos a Laravel para escribir en storage y cache
RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80