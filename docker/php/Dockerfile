FROM php:8.2-fpm

# Copy docker CLI binary so the worker can call `docker exec` on the scraper container
COPY --from=docker:24 /usr/local/bin/docker /usr/local/bin/docker

# Instala dependências necessárias
RUN apt-get update && apt-get install -y \
    zip unzip git curl libpng-dev libonig-dev libxml2-dev libzip-dev \
    libjpeg-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp \
    && docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip

# Redis (opcional)
RUN pecl install redis \
    && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Permissões Laravel
RUN mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]