#!/bin/sh

role=${CONTAINER_ROLE:-none}

if [ ! -f /var/www/.env ]; then
    if [ -f /var/www/.env.example ]; then
        cp /var/www/.env.example /var/www/.env
        echo ".env file not found, copied .env.example to .env"
    else
        echo "Error: .env and .env.example files not found. Please create an .env file." >&2
        exit 1
    fi
fi

# Extract environment information
is_production=false
if grep -q "^APP_ENV=production" .env; then
    is_production=true
fi

# Configure Git to trust the working directory
git config --global --add safe.directory /var/www

# Only the "app" role needs root (chown + php-fpm master). Run every other
# role's PHP process as www-data so files they create (log rotation at
# midnight, cache files) stay writable by the php-fpm pool. Previously the
# root-owned scheduler created the daily log file first and php-fpm then
# failed to append to it, silently losing all application errors (#1642).
if [ "$role" != "app" ]; then
    chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
fi

if [ "$role" = "scheduler" ]; then
    while true; do
        su -s /bin/sh -c "php /var/www/artisan schedule:run --verbose --no-interaction" www-data
        sleep 60
    done
elif [ "$role" = "queue" ]; then
      su -s /bin/sh -c "php /var/www/artisan queue:work --verbose --no-interaction" www-data
elif [ "$role" = "reverb" ]; then
    su -s /bin/sh -c "php /var/www/artisan reverb:start --host=\"${REVERB_SERVER_HOST:-0.0.0.0}\" --port=\"${REVERB_SERVER_PORT:-8090}\"" www-data
elif [ "$role" = "app" ]; then
    # Check APP_ENV and run appropriate composer install
    if [ "$is_production" = true ]; then
        echo "Production environment detected. Running composer install --no-dev..."
        composer install --no-dev
    else
        echo "Development environment detected. Running composer install..."
        composer install
    fi

    # Generate APP_KEY if not set or empty in the .env file
    app_key=$(grep -E "^APP_KEY=" .env | cut -d '=' -f2 | tr -d '[:space:]' | tr -d '\r')
    if [ -z "$app_key" ]; then
        echo "APP_KEY is empty or not set. Generating a new key..."
        php artisan key:generate --force
    else
        echo "APP_KEY is set to: $app_key"
    fi

    # Compile rust modules
    chmod +x ./rust/compile.sh
    ./rust/compile.sh

    # Ensure storage directory has correct ownership for www-data
    chown -R www-data:www-data /var/www/storage

    # Run migrations as www-data to ensure log files are created with correct ownership
    su -s /bin/sh -c "php artisan migrate --force" www-data

    # Only run caching in production (as www-data to ensure correct file ownership)
    if [ "$is_production" = true ]; then
        echo "Production environment: Caching configurations..."
        su -s /bin/sh -c "php artisan cache:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache" www-data
    fi

    exec php-fpm
else
    echo "Could not match the container role \"$role\""
    exit 1
fi