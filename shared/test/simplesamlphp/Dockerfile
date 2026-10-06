# A minimal SimpleSAMLphp 2.x IdP that serves cleanly over plain HTTP at the site root, with the router
# fallback SSP 2.x needs (every path goes through public/index.php). The pre-built images that exist serve
# HTTPS-only behind an alias with no fallback, which 403s every router path -- so this builds its own.
# The digest pins the exact base image, and Dependabot moves it when the tag gets a new image.
FROM php:8.3-apache@sha256:c2c73159da2f7a19167a2849bd9785ca9ad4f5eeff01f85295ef861a5e9bcf4d

# mbstring is the one required extension not already in the base image; the rest SSP needs (dom, openssl,
# json, libxml, ...) are compiled in. unzip is for composer's package extraction.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libonig-dev unzip \
 && docker-php-ext-install mbstring \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Pinned minor so a rebuild does not silently jump SSP versions; --no-dev keeps test tooling out.
RUN composer create-project simplesamlphp/simplesamlphp:~2.3.0 /var/simplesamlphp \
      --no-dev --no-interaction --no-progress

# Serve SSP's public/ at the root and route everything that is not a real file through its front controller.
RUN set -eux; \
    sed -ri 's#DocumentRoot /var/www/html#DocumentRoot /var/simplesamlphp/public#' /etc/apache2/sites-available/000-default.conf; \
    printf '<Directory /var/simplesamlphp/public>\n\tAllowOverride All\n\tRequire all granted\n\tFallbackResource /index.php\n</Directory>\n' > /etc/apache2/conf-available/ssp.conf; \
    a2enconf ssp; \
    a2enmod rewrite

# SSP writes its metadata/signature cache here (config.php's cachedir); it must exist and be writable by
# the web server user, or the SSO endpoint 500s the moment it is hit.
RUN mkdir -p /var/cache/simplesamlphp && chown -R www-data:www-data /var/cache/simplesamlphp

# The IdP's signing keypair. Generated at build time and thrown away with the image, so no private key --
# even a worthless dev one -- lives in the repository, matching how the implementations' own dev SAML keys
# are handled. The implementations fetch the matching certificate from this IdP's metadata at run time.
RUN openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
      -subj "/CN=trusted-attestation-dev-idp" \
      -keyout /var/simplesamlphp/cert/idp.key -out /var/simplesamlphp/cert/idp.crt \
 && chown -R www-data:www-data /var/simplesamlphp/cert

# Where SSP looks for config.php/authsources.php and the metadata/ + cert/ directories -- all mounted in.
ENV SIMPLESAMLPHP_CONFIG_DIR=/var/simplesamlphp/config

EXPOSE 80
