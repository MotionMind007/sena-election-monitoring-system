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

FROM caddy:2-alpine

COPY public /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build
COPY deploy/staging/Caddyfile /etc/caddy/Caddyfile
