FROM php:8.5-cli-alpine
RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql pcntl posix bcmath \
    && apk del .build-deps
WORKDIR /app
