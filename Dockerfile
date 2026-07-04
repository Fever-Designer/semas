FROM php:8.2-apache

# --- System deps + PHP extensions SEMAS needs (pdo_mysql, gd, mbstring, curl, opcache) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev unzip zip git curl \
        libpng-dev libjpeg-dev libfreetype6-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_mysql mysqli gd mbstring zip opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# --- Composer ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP deps first (better layer caching) — skip autoloader for now,
# since composer.json's classmap points at includes/ which isn't copied in yet
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-autoloader --no-scripts

# Copy the rest of the app
COPY . .

# Now that includes/ exists, generate the optimized autoloader
RUN composer dump-autoload --no-dev --optimize

# Apache should serve the public/ folder, not the repo root
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf

# Allow .htaccess overrides in the docroot
RUN { \
        echo '<Directory /var/www/html/public>'; \
        echo '    AllowOverride All'; \
        echo '    Require all granted'; \
        echo '</Directory>'; \
    } > /etc/apache2/conf-available/semas.conf \
    && a2enconf semas

# uploads/ must be writable by the web server
RUN chown -R www-data:www-data /var/www/html/public/uploads

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 10000
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]