#!/bin/sh
set -e

echo "Starting PriceWatch container..."

# Wait for MySQL to be ready if DB_HOST is set
if [ -n "$DB_HOST" ]; then
    echo "Waiting for database connection at $DB_HOST..."
    until nc -z -v -w30 $DB_HOST 3306; do
        echo "Waiting for database to accept connections..."
        sleep 2
    done
    echo "Database is ready!"
fi

# Run migrations and initial seeds if enabled
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "Running migrations..."
    php artisan migrate --force --no-interaction
    
    if [ "$RUN_SEED" = "true" ]; then
        echo "Running seeders..."
        php artisan db:seed --force --no-interaction
    fi
fi

# Cache routes and configuration
echo "Optimizing application cache..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Start supervisor or php-fpm + nginx
echo "Starting services..."
exec "$@"
