FROM dunglas/frankenphp:1.13.3-php8.5-trixie
RUN sed -i 's#http://deb.debian.org#https://mirror.nju.edu.cn#g' /etc/apt/sources.list.d/debian.sources \
  && apt-get update \
  && apt-get install -y --no-install-recommends \
        cron \
  && install-php-extensions \
    @composer-2 \
    gd \
    zip \
    pcntl \
    pdo_pgsql \
    pdo_mysql \
    pdo_dblib \
    oci8 \
    pdo_oci \
    redis \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*
HEALTHCHECK NONE
WORKDIR /app

