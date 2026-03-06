@echo off
echo ======= Starting URL Shortener Setup =======

echo [1/6] Building and starting Docker containers...
docker compose up -d --build

echo [2/6] Installing PHP dependencies...
docker compose exec app composer install

echo [3/6] Setting up the database schema via migrations...
docker compose exec app php bin/console doctrine:migrations:migrate -n

echo [4/6] Clearing the application cache...
docker compose exec app php bin/console cache:clear

echo [5/7] Preparing the test database and running the test suite...
docker compose exec app php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec app php bin/console doctrine:migrations:migrate -n --env=test
docker compose exec app php bin/phpunit

echo [6/7] Running static analysis (PHPStan)...
docker compose exec app vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=1G

echo [7/7] Starting the background worker for URL click tracking...
docker compose exec -d app php bin/console messenger:consume async

echo.
echo ======= Setup complete! =======
echo API Base URL: http://localhost:8000
echo Swagger Documentation: http://localhost:8000/api/doc
echo ------------------------------------------------------------------
pause
