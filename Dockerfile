FROM dunglas/frankenphp:1.13-php8.5-trixie
RUN apt-get update \
  && apt-get install -y --no-install-recommends \
        cron \
        git \
  && install-php-extensions \
    @composer-2 \
    gd \
    zip \
    pcntl \
    bcmath \
    pdo_pgsql \
    pdo_mysql \
    redis \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*
HEALTHCHECK NONE
WORKDIR /app

