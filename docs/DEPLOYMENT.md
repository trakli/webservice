# Production Docker Environment

This document describes the container setup for running this application in a production-like environment.

## Overview

The production environment is defined in the `docker-compose.prod.yml` file and consists of the following services:

-   **app:** The Laravel application running on FrankenPHP (includes built-in HTTP server).
-   **redis:** A Redis instance for caching and queues.

This setup is designed for easy deployment - just pull the image and run. It assumes you are using a **managed database service** (e.g., AWS RDS, Google Cloud SQL) for your database.

### Persistent Storage

-   `redis_data`: Docker volume for Redis data persistence.
-   `./storage`: Host-mounted directory for Laravel storage (logs, uploads, keys).

### Configuration

All configuration for the services is handled via a `.env` file. The `docker-compose.prod.yml` is configured to read this file. You should copy `.env.production.example` to `.env` and fill in your production secrets and configuration.

---

## Quick Start

### 1. Prepare the Environment

Create a directory for deployment and set up the required files:

```bash
mkdir trakli && cd trakli

# Download docker-compose.prod.yml
curl -O https://raw.githubusercontent.com/trakli/webservice/main/docker-compose.prod.yml

# Download .env template
curl -O https://raw.githubusercontent.com/trakli/webservice/main/.env.production.example
mv .env.production.example .env

# Create storage directory
mkdir -p storage/{app,framework/{cache,sessions,views},logs}
chmod -R 775 storage
```

### 2. Configure Environment

Edit the `.env` file with your production values:

```bash
nano .env
```

Key settings to configure:
- `DB_*` - Your database connection details
- `REDIS_PASSWORD` - Secure password for Redis
- `APP_KEY` - Generate and copy the output to your `.env`:
  ```bash
  # Using openssl (works anywhere)
  echo "base64:$(openssl rand -base64 32)"

  # Or using artisan via Docker
  docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show
  ```

### 3. Start the Services

```bash
docker compose -f docker-compose.prod.yml up -d
```

### 4. Run Migrations

```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

The app container is now running on the `trakli` network. Connect your reverse proxy (Traefik, nginx-proxy, etc.) to route traffic to `trakli-app:80`.

---

## Local Testing

To test locally, expose the container port to your host:

```bash
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml run --rm -p 8080:80 app
```

Or use the `--scale` approach with port publishing:

```bash
docker compose -f docker-compose.prod.yml run --service-ports -p 8080:80 app
```

Then access the app at `http://localhost:8080`.

---

## Building Locally (Development)

To build and test the image locally before pushing:

```bash
docker build -f docker/prod/Dockerfile -t ghcr.io/trakli/webservice:latest .
docker compose -f docker-compose.prod.yml up -d
```

---

## Scheduled Tasks & Background Jobs

The application uses Laravel's task scheduler for background processing. The following tasks are configured:

| Command | Schedule | Description |
|---------|----------|-------------|
| `reminders:process` | Every minute | Processes due reminders, creates notifications, sends push notifications via FCM |
| `insights:send --frequency=weekly` | Monday 8:00 AM | Sends weekly financial insights email to opted-in users |
| `insights:send --frequency=monthly` | 1st of month 8:00 AM | Sends monthly financial insights email to opted-in users |
| `engagement:send-inactivity-reminders` | Daily 10:00 AM | Sends encouraging emails to users inactive for 7+ days |

### Setting Up the Scheduler

Add this cron entry to run the Laravel scheduler:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

For Docker deployments, you can either:

**Option 1: Host cron (recommended)**
```bash
* * * * * docker compose -f /path/to/docker-compose.prod.yml exec -T app php artisan schedule:run >> /dev/null 2>&1
```

**Option 2: Separate scheduler container**
Add to `docker-compose.prod.yml`:
```yaml
scheduler:
  image: ghcr.io/trakli/webservice:latest
  command: >
    sh -c "while true; do php artisan schedule:run --verbose --no-interaction; sleep 60; done"
  depends_on:
    - app
  env_file:
    - .env
```

### Manual Execution

Run scheduled commands manually for testing:

```bash
# Process due reminders
php artisan reminders:process

# Send weekly insights
php artisan insights:send --frequency=weekly

# Send monthly insights
php artisan insights:send --frequency=monthly

# Send inactivity reminders
php artisan engagement:send-inactivity-reminders
```

### User Configuration

**Insights emails** - Users opt in by setting `insights-frequency`:
- `weekly` - Receive insights every Monday
- `monthly` - Receive insights on the 1st of each month
- Not set / other value - No insights emails

**Inactivity reminders** - Enabled by default. Users can opt out by setting `inactivity-reminders-enabled` to `false`.

Inactivity tiers:
- **7 days** - First gentle reminder
- **14 days** - Second reminder with encouragement
- **30 days** - Final reminder to re-engage

Minimum 7 days between reminder emails to avoid spam.
