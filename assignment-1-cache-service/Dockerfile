FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev \
    libicu-dev \
    libzip-dev \
    libonig-dev \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql mysqli gd zip intl mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
RUN composer install --ignore-platform-reqs --no-scripts --no-autoloader 2>/dev/null || true

COPY . .

RUN composer dump-autoload --optimize 2>/dev/null || true

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "web", "web/index.php"]
