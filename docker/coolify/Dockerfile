# Builds this fork straight from Git (for Coolify or any host that runs `docker build`).
#
# docker/prod/Dockerfile expects vendor/ and public/build to already exist (upstream CI
# builds them first). This file does those steps itself, and reuses the official
# solidtime image for PHP + FrankenPHP so nothing has to be compiled on the server.
#
# When you merge a newer upstream release, bump BASE_IMAGE to match it.

ARG BASE_IMAGE=solidtime/solidtime:0.22.1

# ---- PHP dependencies ----
FROM ${BASE_IMAGE} AS vendor
USER root
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress

# ---- Frontend build (needs vendor/ for Ziggy) ----
FROM node:20-bookworm-slim AS assets
WORKDIR /build
COPY . .
RUN rm -rf vendor public/build
COPY --from=vendor /build/vendor ./vendor
RUN npm ci --no-audit --no-fund && npm run build

# ---- Final image: official runtime + this repo's code ----
FROM ${BASE_IMAGE}
USER root
WORKDIR /var/www/html

# Drop the upstream app code that ships in the base image
RUN find /var/www/html -mindepth 1 -delete

COPY . .
RUN rm -rf vendor public/build node_modules .env
COPY --from=vendor /build/vendor ./vendor
COPY --from=assets /build/public/build ./public/build

# Same steps as docker/prod/Dockerfile and the upstream CI build
RUN cp .env.production .env \
    && rm -f .env.ci .env.example \
    && mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache \
        storage/framework/testing storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --apcu --no-dev \
    && chown -R 1000:1000 /var/www/html

USER laravel

EXPOSE 8000
