# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Xplendor is a B2B SaaS dealership management platform with multi-tenant architecture. It consists of three main services:
- **server/**: Laravel 12 REST API backend
- **web/**: React 19 SPA frontend (TypeScript)
- **scraper/**: Python service that collects market data from Standvirtual

## Development Commands

### Docker (preferred dev environment)
```bash
docker-compose up                        # Start all services (Nginx, PHP-FPM, MariaDB, Redis, Worker, Scheduler)
docker-compose -f docker-compose.prod.yml up  # Production stack
docker exec -it php php artisan migrate  # Run migrations inside container
```

### Backend (server/)
```bash
composer run setup    # Initial setup
composer run dev      # Concurrent: server + queue + logs + vite
composer run test     # Run PHPUnit tests
php artisan pint      # Lint/format PHP code
php artisan migrate   # Run migrations
php artisan queue:work
php artisan schedule:run
```

### Frontend (web/)
```bash
npm install
npm run dev           # Vite dev server
npm run build         # Production build to /build
npm test              # Jest tests
```

### Scraper (scraper/)
```bash
python -m venv venv && source venv/bin/activate
pip install -r requirements.txt
python main.py [preview N] [--filters...]
```

## Architecture

### Multi-tenant data model
All resources are scoped to a `company_id`. Every API route under `/v1/` enforces company ownership checks. A `Company` has many `User`s, `Car`s, and `CompanyIntegration`s.

### API structure (server/routes/api.php)
- `/api/v1/*` — Sanctum-authenticated routes for the SPA
- `/api/public/*` — Company API token middleware (for embedded catalogs, public car views/leads)
- `/market/snapshots` — Scraper token middleware (market data ingestion)

### Frontend ↔ Backend
- Axios with Bearer token from `sessionStorage.authUser`; auto-attaches via interceptors in `web/src/helpers/api_helper.ts`
- `REACT_APP_API_URL` env var sets the base URL
- Media files served via `/media/{path}` Laravel route (proxied through Nginx)

### Backend service layer
Business logic lives in `server/app/Services/`. Controllers are thin — they call services. Key services: `CarService`, `MetaAdsService`, `CarAnalyticsService`, `CarAiAnalysesService`. Form request validation in `Http/Requests/`. API response transformers in `Http/Resources/`.

### Frontend state management
Redux slices per feature in `web/src/slices/` (cars, leads, auth, dashboards, etc.). Thunks call the Axios helpers. React Router handles SPA navigation.

### Async processing
Jobs dispatched to Redis queue, processed by the `worker` container. The `scheduler` container runs `php artisan schedule:run` every 60s for recurring tasks like `FetchMetaAdsMetricsJob`.

### Database
MariaDB 10.6. Core tables: `cars`, `companies`, `users`, `car_performance_metrics`, `car_market_snapshots`, `meta_audience_insights`, `car_ai_analyses`. Change history tracked via `audits` table (owen-it/laravel-auditing). Models in `server/app/Models/`.

## Key Integrations
- **Meta Ads**: OAuth flow + campaign management via `CompanyIntegration` model storing tokens. Service: `MetaAdsService`.
- **Carmine**: Dealer platform integration (`carmine_connections` table).
- **Standvirtual**: Market data scraped via Python, ingested through `/market/snapshots`.
- **Google Maps**: `@react-google-maps/api` used in frontend.

## Notes
- PHP 8.2+ required. Laravel 12.
- The `scraper/README.md` has detailed documentation on the scraper CLI, filters, and cron scheduling.
- `server/README.md` documents the performance aggregation artisan command.
- Dev Nginx runs on port 8001; phpMyAdmin on port 8080 (dev only).
