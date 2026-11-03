FROM composer:2 AS dependencies

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts

FROM node:22-bookworm-slim AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources resources
COPY public public
COPY vite.config.js tailwind.config.js postcss.config.js tsconfig.json ./
COPY --from=dependencies /app/vendor/tightenco/ziggy /app/vendor/tightenco/ziggy
RUN npm run build

FROM php:8.4-fpm-bookworm

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libfreetype6-dev libjpeg62-turbo-dev libpng-dev libpq-dev libwebp-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd pdo_pgsql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
COPY --from=dependencies /app/vendor ./vendor

COPY . .
COPY --from=assets /app/public/build ./public/build
COPY deploy/staging/www-p4.conf /usr/local/etc/php-fpm.d/www.conf

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
