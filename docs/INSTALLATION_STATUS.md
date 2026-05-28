# Installation Status

Tanggal instalasi: 2026-05-25
Update production lokal: 2026-05-28

## Installed Runtime

- PHP 8.3 CLI/FPM
- Composer 2.9
- Node.js 22
- npm 10
- PostgreSQL 14
- Redis
- Nginx
- Supervisor
- UFW
- Net-SNMP tools
- Go 1.18.1
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

- Production local URL: `http://192.168.99.61`
- Laravel environment: `production`
- Debug mode: off
- PostgreSQL database: `kusumavision_nms`
- Redis cache/session/queue
- Encrypted Redis-backed sessions
- Reverb broadcasting
- Indonesian locale
- Config/events/routes/views cache enabled

Sensitive values are stored in `.env`; `.env.example` only contains placeholders.
The `.env` file is ignored by Git and permissioned as `640 root:www-data`.

## Network & Host Hardening

- Nginx serves only `/var/www/KusumaVisionNMS/public`.
- Nginx denies dotfiles and common sensitive file extensions.
- Nginx security headers enabled: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, and `Permissions-Policy`.
- Nginx HTTP allow-list includes private LAN ranges plus `103.189.248.0/24` and `103.189.249.0/24`.
- UFW is active with default incoming deny and outgoing allow.
- UFW allows SSH `22/tcp` and HTTP `80/tcp` from private LAN ranges plus `103.189.248.0/24` and `103.189.249.0/24`.
- SSH is key-only: `PasswordAuthentication no`.
- Root SSH login is restricted to key authentication: `PermitRootLogin prohibit-password`.
- X11 forwarding is disabled.
- PHP-FPM production override disables PHP exposure and browser error display.

## Background Processes

- `kusumavision-worker` runs `php artisan queue:work redis --tries=1` via Supervisor as `www-data`.
- `kusumavision-scheduler` runs `php artisan schedule:work` via Supervisor as `www-data`.
- Scheduler dispatches `php artisan olts:poll` every minute; each OLT is polled only when its configured interval is due.

## Verified

- Composer dependencies installed
- Node dependencies installed
- PostgreSQL migration completed
- Frontend production build completed
- Laravel optimized caches enabled
- Composer audit passed with no advisories
- npm runtime audit passed with zero vulnerabilities
- PHPUnit suite passed: 73 tests, 393 assertions
- HTTP `/` returns 200
- `/dashboard` redirects to login
- `/.env` returns 403

## Run Production Local

The app is served by Nginx and PHP-FPM:

```bash
curl -I http://192.168.99.61/
```

Process checks:

```bash
systemctl is-active nginx php8.3-fpm postgresql redis-server supervisor ssh.socket
supervisorctl status
ufw status verbose
```

## Development Mode

Use separate terminals only for development:

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
