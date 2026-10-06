# Built from the repository root as context (so shared/ is in scope):
#   docker build -f php/Dockerfile -t astrana/ata-php .
# The image supports MySQL and PostgreSQL. For SQL Server support, which accepts Microsoft's licence terms
# for its ODBC driver, build your own image with the WITH_SQLSRV argument (see the runtime stage):
#   docker build --build-arg WITH_SQLSRV=true -f php/Dockerfile -t ata-php-sqlsrv .
#
# Stage 1: resolve Composer dependencies. Kept separate so Composer never reaches the final image.
# Only the resolved vendor/ tree is copied forward. Scripts are skipped here (the shared-asset sync has
# its own stage below, and package discovery runs in the runtime stage).
# The digest pins the exact base image, and Dependabot moves it when the tag gets a new image.
FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS vendor
WORKDIR /app
COPY php/composer.json php/composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --ignore-platform-reqs

# Stage 2: the shared single source (contract, schema, strings, stylesheet), synced into the paths the
# application reads. Its own stage so that shared/ itself is in no layer of the final image: a COPY
# followed by a later rm still leaves the copied tree in the COPY layer. Only the synced copies move on.
FROM php:8.4-cli@sha256:0177c7589c0715b63e5343342a7c1f1887db759ad0b0d6ad3bb949938bba6b47 AS shared-assets
WORKDIR /app
COPY php/scripts/sync-shared-assets.php scripts/
COPY shared/ /shared
RUN php scripts/sync-shared-assets.php

# Stage 3: the runtime image, PHP with the database drivers the server needs. The tag names the Debian
# release, which scripts/add-native-sbom-components.php reads for the bill of materials.
FROM php:8.4-cli-trixie@sha256:0177c7589c0715b63e5343342a7c1f1887db759ad0b0d6ad3bb949938bba6b47
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# The published image supports MySQL and PostgreSQL only. Microsoft's ODBC driver for SQL Server may be
# redistributed only under Microsoft's own licence terms, so an organisation that needs SQL Server builds
# its own image with --build-arg WITH_SQLSRV=true, and in doing so accepts those terms itself.
ARG WITH_SQLSRV=false

# Microsoft's SQL Server components, installed only when WITH_SQLSRV is true, and pinned to the versions
# this image was last built and tested with. Composer does not know about them, so
# scripts/add-native-sbom-components.php reads these lines to list them in sbom.json as optional. After
# changing a version here, run that script (README.md shows how).
ARG SQLSRV_VERSION=5.13.3
ARG PDO_SQLSRV_VERSION=5.13.3
ARG MSODBCSQL18_VERSION=18.7.1.1-1

# The PDO drivers for PostgreSQL and MySQL, and intl so that the language switcher orders language names
# the way the other two implementations do. When WITH_SQLSRV is true, also Microsoft's ODBC driver, with
# its licence file, and the sqlsrv and pdo_sqlsrv extensions, from Microsoft's repository for the image's
# own Debian release, which Microsoft signs with its 2025 key. No coverage or other test tooling is
# installed. The extensions are compiled in this one layer and the compilers, headers and download tools
# are removed again at the end of it, so that the image carries the runtime libraries only. The Debian
# packages this layer adds are recorded, for the third-party notices.
# hadolint ignore=DL3008
RUN dpkg-query -W -f '${Package}\n' | sort > /tmp/packages-before \
 && apt-get update \
 && apt-get install -y --no-install-recommends libpq5 libpq-dev libicu-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_mysql intl \
 && apt-mark manual libpq5 "$(apt-cache depends libicu-dev | awk '/Depends: libicu[0-9]/ {print $2; exit}')" \
 && if [ "$WITH_SQLSRV" = true ]; then \
      apt-get install -y --no-install-recommends unixodbc libodbc2 unixodbc-dev gnupg \
      && install -d -m 0755 /usr/share/keyrings \
      && curl -fsSL https://packages.microsoft.com/keys/microsoft-2025.asc \
         | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
      && curl -fsSL "https://packages.microsoft.com/config/debian/$(. /etc/os-release && echo "$VERSION_ID")/prod.list" \
         -o /etc/apt/sources.list.d/mssql.list \
      && echo 'path-include /usr/share/doc/msodbcsql18/*' > /etc/dpkg/dpkg.cfg.d/msodbcsql18 \
      && apt-get update \
      && ACCEPT_EULA=Y apt-get install -y --no-install-recommends "msodbcsql18=${MSODBCSQL18_VERSION}" \
      && pecl install "sqlsrv-${SQLSRV_VERSION}" "pdo_sqlsrv-${PDO_SQLSRV_VERSION}" \
      && docker-php-ext-enable sqlsrv pdo_sqlsrv \
      && apt-mark manual unixodbc libodbc2 msodbcsql18 \
      && apt-get purge -y --auto-remove unixodbc-dev gnupg; \
    fi \
 && apt-get purge -y --auto-remove libpq-dev libicu-dev curl $PHPIZE_DEPS \
 && rm -rf /var/lib/apt/lists/* /tmp/pear \
 && install -d /usr/local/share/astrana-trusted-attestation \
 && comm -13 /tmp/packages-before <(dpkg-query -W -f '${Package}\n' | sort) \
    > /usr/local/share/astrana-trusted-attestation/debian-packages \
 && rm /tmp/packages-before

WORKDIR /app

# Application source. The .dockerignore keeps vendor/, the synced shared copies, the tests, the
# demonstration stack and the development SAML material out of the context.
COPY php/ .
# The shared inputs, already synced into the paths the application reads (contract/, schema/,
# resources/, public/) by the shared-assets stage. shared/ itself never enters this image.
COPY --from=shared-assets /app/contract ./contract
COPY --from=shared-assets /app/schema ./schema
COPY --from=shared-assets /app/resources/ui-strings.json ./resources/ui-strings.json
COPY --from=shared-assets /app/public/ ./public/
# Resolved dependencies from the vendor stage. There is no Composer in this image.
COPY --from=vendor /app/vendor ./vendor
# The notices of the components bundled into the shared inputs, Bootstrap in the stylesheet, for the
# third-party notices below.
COPY --from=shared-assets /shared/third-party/bundled /usr/local/share/astrana-trusted-attestation/bundled

# Finish Laravel's package discovery (the vendor stage deferred it with --no-scripts), and write the
# third-party notices, which the vendor stage's --no-scripts skipped too, from the bundled components, the
# Composer packages in vendor/ and the Debian packages the layer above added. They go to
# public/THIRD-PARTY-NOTICES.txt, which the licence page links to, and to the fixed path
# /app/THIRD-PARTY-NOTICES.txt beside the application.
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
 && php artisan package:discover --ansi \
 && php scripts/generate-third-party-notices.php \
      --bundled=/usr/local/share/astrana-trusted-attestation/bundled \
      --debian-packages=/usr/local/share/astrana-trusted-attestation/debian-packages \
 && cp public/THIRD-PARTY-NOTICES.txt THIRD-PARTY-NOTICES.txt \
 && chown -R www-data:www-data storage bootstrap/cache

# The server refuses to serve without TLS, so a TLS-terminating proxy sits in front and
# TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY is set to true. PHP's built-in server serves public/ through
# the router in server.php on port 8000.
USER www-data
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "server.php"]
