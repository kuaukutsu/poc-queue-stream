FROM ghcr.io/kuaukutsu/php:8.3-cli AS app_setup

# Arguments
ARG UID=10001
ARG WORKDIR="/src"

# Configure
COPY conf/php.ini /usr/local/etc/php/php.ini
# Dependencies
COPY --from=composer:latest --link /usr/bin/composer /usr/bin/composer
# user
RUN <<EOF
    adduser -u ${UID} -G www-data -s /bin/sh -D developer www-data
EOF
# workdir
RUN <<EOF
    mkdir -p ${WORKDIR}
    chown -R ${UID}:www-data ${WORKDIR}
EOF
# composer
RUN <<EOF
    mkdir /var/.composer
    chown developer:www-data /var/.composer
EOF
ENV COMPOSER_CACHE_DIR=/var/.composer

FROM app_setup AS app_worker

RUN install-php-extensions opcache igbinary

USER $UID
WORKDIR $WORKDIR

FROM app_setup AS app_devel

RUN install-php-extensions opcache igbinary xdebug

USER $UID
WORKDIR $WORKDIR
