# Build context must be the parent dir (../) so both picohp-ir2 and
# pico_runtime are available (composer.json uses a path repo at ../pico_runtime).

# ---- vendor stage: install composer deps ----
FROM composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS vendor
WORKDIR /app
# Full project needed: composer.json classmaps src/Instruction/Instructions.php
COPY picohp-ir2/ .
COPY pico_runtime /pico_runtime
RUN composer install --no-interaction --prefer-dist --no-scripts --no-progress

# ---- rust stage: build the native runtime staticlib ----
FROM rust@sha256:e259494b14672950a909b4c54527e19d29cae6ebf980672114c8bb85e5594e4a AS runtime
WORKDIR /build
COPY pico_runtime/rust ./rust
RUN cargo build --release --locked --manifest-path rust/Cargo.toml

# ---- runtime stage: PHP + clang + deps ----
FROM php@sha256:1d2cf2b7d715b5b3aa959f0150ccbcc7b71e0181b637e443abf40a7fb56bf79a
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        clang=1:19.0-63 \
        git=1:2.47.3-0+deb13u1 \
        unzip=6.0-29+deb13u1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY --from=runtime /build/rust/target/release/libpico_runtime.a /usr/local/lib/
COPY picohp-ir2/ .
COPY pico_runtime /pico_runtime

CMD ["vendor/bin/pest"]
