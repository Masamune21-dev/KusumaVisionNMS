# Installation Status

Tanggal instalasi: 2026-05-25

## Installed Runtime

- PHP 8.3 CLI/FPM
- Composer 2.9
- Node.js 22
- npm 10
- PostgreSQL 14
- Redis
- Nginx
- Supervisor
- Net-SNMP tools
- Go 1.26.3
- SQLite PHP extension for PHPUnit in-memory tests

## Laravel Stack

- Laravel 12
- Breeze Vue/Inertia
- Horizon
- Reverb
- Predis
- phpseclib
- ApexCharts
- vue3-apexcharts
- @lucide/vue

## Local App Configuration

The application is configured for:

- PostgreSQL database: `kusumavision_nms`
- Redis cache/session/queue
- Reverb broadcasting
- Indonesian locale

Sensitive values are stored in `.env`; `.env.example` only contains placeholders.

## Verified

- Composer dependencies installed
- Node dependencies installed
- PostgreSQL migration completed
- Frontend production build completed
- Laravel/Breeze test suite passed

## Run Locally

Use three terminals:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

```bash
npm run dev
```

```bash
php artisan queue:work
```

Optional realtime:

```bash
php artisan reverb:start
```

Optional queue dashboard:

```bash
php artisan horizon
```
