#!/bin/bash

# ⭐ DODATO: Pročitaj APP_ENV iz Laravel .env fajla
if [ -f ".env" ]; then
    APP_ENV_VALUE=$(grep "^APP_ENV=" .env | cut -d= -f2)
    echo "Detected APP_ENV: $APP_ENV_VALUE"
else
    APP_ENV_VALUE="local"
    echo "No .env file found, defaulting to: $APP_ENV_VALUE"
fi

# ⭐ DODATO: Odaberi Apache config baziran na APP_ENV
if [ "$APP_ENV_VALUE" = "production" ]; then
    echo "Production environment - using SSL configuration"
    a2dissite 000-default.conf 2>/dev/null || true
    a2dissite laravel-dev.conf 2>/dev/null || true
    a2ensite laravel-ssl.conf 2>/dev/null || true
else
    echo "Development environment - using HTTP configuration"
    a2dissite 000-default.conf 2>/dev/null || true
    a2dissite laravel-ssl.conf 2>/dev/null || true
    a2ensite laravel-dev.conf 2>/dev/null || true
fi

# Install Composer dependencies if vendor folder doesn't exist
if [ ! -d "vendor" ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Generate Laravel key if not exists
if [ ! -f ".env" ]; then
    cp .env.example .env
fi

if [ -z "$(grep '^APP_KEY=' .env)" ] || [ "$(grep '^APP_KEY=' .env | cut -d= -f2)" = "" ]; then
    echo "Generating Laravel key..."
    php artisan key:generate --force
fi

# Set permissions
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Run database migrations (opciono)
#php artisan migrate --force
# php artisan db:seed --force

# Run the main command (Apache)
exec "$@"