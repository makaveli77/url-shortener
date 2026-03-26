#!/bin/bash

echo "🚀 Starting URL Shortener Setup..."

# Silence Docker Compose warnings about missing REDIS_URL
export REDIS_URL=redis://localhost:6379

echo "📦 Building and starting Docker containers..."
docker compose up -d --build
sleep 5
docker compose exec app php bin/console tailwind:init
docker compose exec app chmod +x var/tailwind/*/tailwindcss-*
# Tailwind:build can crash on ARM64 platforms with signal 11. 
# We ignore the failure here because the built CSS is usually provided or cached.
docker compose exec app php bin/console tailwind:build || echo "⚠️ Tailwind build failed (likely a platform compatibility issue). Using existing build if available."

echo "📥 Installing PHP dependencies..."
docker compose exec app composer install

echo "🗄️ Setting up the database schema via migrations..."
docker compose exec app php bin/console doctrine:migrations:migrate -n

echo "🧹 Clearing the application cache..."
docker compose exec app php bin/console cache:clear

# Delete the development database to ensure a fresh start
echo "🗄️ Resetting the development database..."
docker compose exec app php bin/console doctrine:database:drop --force --if-exists
docker compose exec app php bin/console doctrine:database:create
docker compose exec app php bin/console doctrine:migrations:migrate -n

echo "🧪 Preparing the test database..."
docker compose exec app php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec app php bin/console doctrine:migrations:migrate -n --env=test

echo "🧪 Running the test suite..."
docker compose exec app php bin/phpunit

echo "🔎 Running static analysis (PHPStan)..."
docker compose exec app vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=1G

echo "⚙️ Starting the background worker for URL click tracking..."
docker compose exec -d app php bin/console messenger:consume async

echo ""
echo "✅ Setup complete!"
echo "🌐 API Base URL: http://localhost:8000"
echo "📚 Swagger Documentation: http://localhost:8000/api/doc"
echo "------------------------------------------------------------------"
