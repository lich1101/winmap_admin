FROM php:8.3-cli-bookworm

ARG NODE_MAJOR=22

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    curl \
    ca-certificates \
    gnupg \
    default-mysql-client \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libsqlite3-dev \
    && docker-php-ext-install \
      bcmath \
      intl \
      mbstring \
      pcntl \
      pdo_mysql \
      pdo_sqlite \
      zip \
    && install -d -m 0755 /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/start-container.sh /usr/local/bin/winmap-admin-start
RUN chmod +x /usr/local/bin/winmap-admin-start
