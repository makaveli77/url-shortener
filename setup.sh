#!/bin/bash

echo "🚀 Starting URL Shortener Setup..."

echo "📦 Building and starting Docker containers..."
docker compose up -d --build

echo "📥 Installing PHP dependencies..."
docker compose exec app composer install

echo "🗄️ Setting up the database schema via migrations..."
docker compose exec app php bin/console doctrine:migrations:migrate -n

echo "🧹 Clearing the application cache..."
docker compose exec app php bin/console cache:clear

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
