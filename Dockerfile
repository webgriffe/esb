ARG PHP_VERSION=7.2

FROM php:${PHP_VERSION}-cli-alpine

RUN set -eux; \
    docker-php-ext-install -j$(nproc) \
        pcntl \
    ;

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN set -eux; \
	composer global require "hirak/prestissimo:^0.3" --prefer-dist --no-progress --no-suggest --classmap-authoritative; \
	composer clear-cache
ENV PATH="${PATH}:/root/.composer/vendor/bin"

COPY .docker/php/php.ini /usr/local/etc/php/php.ini

RUN set -eux; \
	apk --no-cache add \
	    git \
    ;
