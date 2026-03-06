# 🔗 URL Shortener - Symfony & PostgreSQL

A professional, containerized URL Shortener API built with **Symfony 8**, **PHP 8.4**, **PostgreSQL**, and **Redis**, following modern best practices (Controller-Service-Repository-Entity).

## 📑 Summary

This project is a high-performance URL shortening service designed for scalability and speed.

- **Architecture**: Domain-Driven Design principles with strict separation of concerns (Controller → DTO → Service → Repository).
- **Performance**: High-speed lookups using **Redis** caching for 24h retention of active links, falling back to PostgreSQL. Asynchronous click tracking processed via Redis queues (Symfony Messenger).
- **Database Refinement**: Cleaned up the database schema by removing unnecessary auto-generated tables (e.g., Doctrine's `messenger_messages` failure queue), relying purely on Redis for message passing.
- **Security**: Rate limiting (50 requests/hour per IP) on URL creation endpoints backed by Redis.
- **Documentation**: Fully interactive OpenAPI (Swagger) documentation available at `/api/doc`.
- **Validation**: Strict input validation using Symfony Validator and DTOs to ensure data integrity.
- **Code Quality**: Strict static analysis enforced using **PHPStan (Level 8)**, ensuring type safety and robust null-check handling across the codebase.
- **Dockerized**: Fully containerized environment with PHP, Database, and Redis services orchestrated via Docker Compose.

## 🌟 Key Features

- **Shorten URLs**: Generate unique alphanumeric codes for any valid URL.
- **Custom Vanity URLs**: Users can provide a specific `alias` (e.g., `my-portfolio`) instead of using a generated hash.
- **Link Expiration**: Create URLs that automatically expire and return a `410 Gone` after a specific datetime.
- **Self-Reference Protection**: Anti-loop security that automatically rejects users trying to shorten the application's own URLs.
- **Instant Redirection**: Low-latency redirection to original URLs.
- **Caching Layer**: Redis cache implementation, minimizing database hits for frequently accessed links.
- **Click Tracking**: Tracks the number of clicks per link (persisted to PostgreSQL via async background processor).
- **Auto-Validation**: Automatic 422 error responses for invalid URLs or malformed JSON.
- **Rate Limiting**: Protects against abuse and spam by limiting short link creation per IP address using Symfony RateLimiter.
- **Interactive docs**: Integrated Swagger UI for testing endpoints directly in the browser.

---

## 🚀 Getting Started

Follow these steps to get the project running locally in minutes.

### Prerequisites
- **Docker** & **Docker Compose**

### 1. Automatic Setup (Recommended)
You can set up the entire project using our automated scripts. This will build the containers, install PHP dependencies, update the database schema, clear the cache, run the test suite, and start background workers.

**For Mac/Linux:**
```bash
./setup.sh
```

**For Windows:**
Double-click `setup.bat` or run it from your command line:
```bat
setup.bat
```

### Async Message Queue (Failed messages)
Since we rely on Redis for background message processing, any failed messages are deposited into a dedicated Redis stream. To view and handle failed messages, you can use the built-in Symfony Messenger commands:

To list the failed messages (Note: Redis transport may direct you to consume instead of list depending on version):
```bash
docker compose exec app php bin/console messenger:failed:show
```

To automatically retry consuming the failed messages:
```bash
docker compose exec app php bin/console messenger:failed:retry
```

### 2. Manual Setup (Alternative)
If you prefer to run the commands manually:
```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php bin/console doctrine:migrations:migrate -n
# Clear the cache to ensure Nelmio/Swagger routes are loaded
docker compose exec app php bin/console cache:clear
# Prepare the test database and run the test suite
docker compose exec app php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec app php bin/console doctrine:migrations:migrate -n --env=test
docker compose exec app php bin/phpunit
# Start the background worker (in detached mode)
docker compose exec -d app php bin/console messenger:consume async
```

### 3. Access the Application
- **API Base URL**: `http://localhost:8000`
- **Swagger Documentation**: `http://localhost:8000/api/doc`

---

## 📖 API Endpoint Reference

### 🔗 Shorten URL
**POST** `/api/shorten`

Accepts a JSON payload with a long URL and returns a shortened version.

**Request Body:**
```json
{
  "url": "https://www.example.com/very/long/url",
  "alias": "optional-custom-name",
  "expiresAt": "2026-12-31T23:59:59+00:00"
}
```

**Response (201 Created):**
```json
{
  "shortCode": "AbCd12",
  "shortUrl": "http://localhost:8000/AbCd12"
}
```

**Response (429 Too Many Requests):**
*Occurs when the IP exceeds 50 link creations per hour.*
```json
{
  "title": "An error occurred",
  "status": 429,
  "detail": "Rate limit exceeded. Please try again later."
}
```

### 🔀 Redirect
**GET** `/{shortCode}`

Redirects the user to the original URL associated with the short code.

- **Success**: `302 Found` (Redirects to destination)
- **Not Found**: `404 Not Found`
- **Expired**: `410 Gone` (Returned if the `expiresAt` datetime has passed)

---

## 📊 Database Structure (PostgreSQL)

The application uses **Doctrine ORM** to manage the schema.

**Table: `url`**
| Column | Type | Description |
|--------|------|-------------|
| `id` | `INT` | Primary Key, Auto-increment |
| `original_url` | `TEXT` | The original long URL |
| `short_code` | `VARCHAR(50)` | Unique, random alphanumeric code or custom alias (Indexed: `idx_short_code`) |
| `click_count` | `INT` | Counter for total visits (Default: 0) |
| `expires_at` | `DATETIME` | (Optional) When the link should stop working and return 410 |
| `created_at` | `DATETIME` | Timestamp of creation (Indexed: `idx_created_at`) |

---

## 🚀 CI/CD & Deployment

This project uses **GitHub Actions** for Continuous Integration and Continuous Deployment, and is built with **multi-stage Docker builds** to ensure production readiness.

### Docker Multi-Stage Build
The setup provides completely separate stages depending on the environment:
- **`dev` Target**: Used for local environments via `docker compose`. Mounts code live and allows continuous modification.
- **`production` Target**: Compiles an immutable, pre-cached, and fully-optimized image (removes `dev` dependencies, generates optimized classmaps) natively ready for cloud deployment. To compile statically:
  ```bash
  docker build --target production -t your-org/url-shortener:latest .
  ```

### Workflows
- **Test**: Runs on Push/PR to `main`, `staging`, and `develop` branches.
  - Sets up PHP 8.4 environments.
  - Runs `composer install`.
  - Spins up Postgres and Redis services.
  - Executes **PHPUnit** tests.

- **Deploy**:
  - **Staging**: Triggers automatically on `push` to `staging` branch (after tests pass).
  - **Production**: Triggers automatically on `push` to `main` branch (after tests pass).
  - Executes deployment scripts located in `scripts/`.

### Secrets Configuration
To enable deployment, configure the following secrets in your GitHub repository settings:

| Secret Name | Description |
|---|---|
| `DEPLOY_TOKEN` | A shared secret token used to authenticate deployment webhook requests. |
| `DEPLOY_WEBHOOK_URL_STAGING` | The webhook URL for triggering deployment to the **Staging** environment. |
| `DEPLOY_WEBHOOK_URL` | The webhook URL for triggering deployment to the **Production** environment. |

*Note: The deployment scripts use `curl` to POST to these webhook URLs with the deployment token.*

---

## �🛠 Tech Stack

**Core Infrastructure**
- **Symfony 8**: The latest version of the high-performance PHP framework.
- **PHP 8.2**: Using the latest features like Attributes, Readonly classes, and constructor promotion.
- **PostgreSQL 16**: Robust relational database for persistent storage.
- **Redis**: In-memory data store for caching URL lookups (high speed).
- **Docker Compose**: Orchestration of the entire stack.

**Libraries & Bundles**
- **NelmioApiDocBundle**: Automatic OpenAPI / Swagger documentation generation.
- **Doctrine ORM**: Database interactions and entity management.
- **Symfony Validator**: Request validation via Attributes (`#[Assert\Url]`).

---

## 🔨 Development Commands

Here are some common commands you might need during development.

**run command inside container**
```bash
docker compose exec app <command>
```

**Clear Cache**
```bash
docker compose exec app php bin/console cache:clear
```

**Update Database Schema**
```bash
docker compose exec app php bin/console doctrine:schema:update --force
```

**Check Container Status**
```bash
docker compose ps
```

**View Logs**
```bash
docker compose logs -f app
```

**Run Tests**
```bash
docker compose exec app php bin/phpunit
```

**Run Static Analysis (PHPStan Level 8)**
```bash
docker compose exec app vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=1G
```

---

## 🧪 Testing

The project uses **PHPUnit** for automated testing.
We use a separate **SQLite** database for tests to ensure speed and isolation from your development data.

**Run all tests**
```bash
docker compose exec app php bin/phpunit
```

---

## 📁 Project Structure

```
.
├── config/             # Symfony configuration files (routes, packages, services)
├── public/             # Web server entry point
├── src/
│   ├── Controller/     # API Entry points (UrlController)
│   ├── Dto/            # Data Transfer Objects & Validation (UrlShortenRequest)
│   ├── Entity/         # Database Models (Url)
│   ├── Message/        # Asynchronous message payloads (UrlClick)
│   ├── MessageHandler/ # Consumers for async messages (UrlClickHandler)
│   ├── Repository/     # Database Queries (UrlRepository)
│   └── Service/        # Business Logic & Caching (UrlShortener)
├── tests/              # PHPUnit Tests
├── compose.yaml        # Docker services configuration
├── Dockerfile          # PHP multi-stage image definition
└── README.md           # Project documentation
```
