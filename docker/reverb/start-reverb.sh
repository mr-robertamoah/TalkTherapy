#!/bin/bash

# Wait for database to be ready
echo "Waiting for database to be ready..."
max_attempts=30
attempt=0

while [ $attempt -lt $max_attempts ]; do
    if php artisan migrate:status > /dev/null 2>&1; then
        echo "Database is ready!"
        break
    fi
    
    echo "Database not ready yet, waiting... (attempt $((attempt + 1))/$max_attempts)"
    sleep 2
    attempt=$((attempt + 1))
done

if [ $attempt -eq $max_attempts ]; then
    echo "Database not ready after $max_attempts attempts, starting Reverb anyway..."
fi

# Start Laravel Reverb
echo "Starting Laravel Reverb server..."
php artisan reverb:start --host=0.0.0.0 --port=8080
