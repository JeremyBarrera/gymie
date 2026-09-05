# ---------- Build: PHP extensions + composer deps + Vite assets ----------
# One stage does all compilation (PHP extensions, composer install, asset
# build) because `npm run build` runs `php artisan filament:assets`, which
# needs PHP, vendor and the app source together. The runtime stage then
# copies only the compiled artifacts onto a clean PHP image.

FROM php:8.5-fpm-alpine AS build

# Extension build dependencies + Node for the asset pipeline.
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT
ARG VITE_REVERB_SCHEME
RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        zlib-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
        $PHPIZE_DEPS \
        nodejs \
        npm \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        gd \
        intl \
        bcmath \
        zip \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Dependencies first for layer caching: composer.lock changes rarely
# compared to source.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --no-scripts \
        --no-autoloader \
        --no-progress

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && npm ci --no-audit --no-fund \
    && npm run build

# ---------- Runtime: clean PHP-FPM with only compiled artifacts ----------
FROM php:8.5-fpm-alpine AS app

# Runtime shared libraries the compiled extensions link against
# (icu/zip/libpng/libjpeg/freetype/oniguruma) - no -dev headers here.
RUN apk add --no-cache \
        icu \
        libzip \
        zlib \
        libpng \
        libjpeg-turbo \
        freetype \
        oniguruma

RUN apk update && apk upgrade --no-cache

COPY --from=build /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=build /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY --from=build /var/www /var/www

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["docker/entrypoint.sh"]

CMD ["php-fpm"]
