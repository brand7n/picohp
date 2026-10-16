# Build context must be the parent dir (../) so both picohp-ir2 and
# pico_runtime are available (composer.json uses a path repo at ../pico_runtime).

# ---- vendor stage: install composer deps ----
FROM composer:2 AS vendor
WORKDIR /app
# Full project needed: composer.json classmaps src/Instruction/Instructions.php
COPY picohp-ir2/ .
COPY pico_runtime /pico_runtime
RUN composer install --no-interaction --prefer-dist --no-scripts --no-progress

# ---- runtime stage: PHP + clang + deps ----
FROM php:8.4-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends clang git unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY picohp-ir2/ .
COPY pico_runtime /pico_runtime

CMD ["vendor/bin/pest"]
