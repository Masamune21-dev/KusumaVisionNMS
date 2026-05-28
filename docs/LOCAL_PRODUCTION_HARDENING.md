# Local Production Hardening

Dokumen ini mencatat baseline production lokal untuk server KusumaVision NMS.
Targetnya adalah aplikasi tetap bisa diakses dari jaringan operasional, tetapi file secret,
debug output, SSH password login, dan port publik yang tidak perlu tetap tertutup.

## Current Baseline

- App URL: `http://192.168.99.61`
- Web root: `/var/www/KusumaVisionNMS/public`
- Runtime: Nginx, PHP-FPM 8.3, PostgreSQL, Redis, Supervisor
- Queue: `php artisan queue:work redis --tries=1`
- Scheduler: `php artisan schedule:work`
- Firewall: UFW active, default incoming deny
- SSH: key-only login

## Laravel Production Settings

`.env` production lokal:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://192.168.99.61
LOG_LEVEL=warning
SESSION_DRIVER=redis
SESSION_ENCRYPT=true
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

Permission secret:

```bash
chown root:www-data /var/www/KusumaVisionNMS/.env
chmod 640 /var/www/KusumaVisionNMS/.env
```

Deploy/refresh production cache:

```bash
cd /var/www/KusumaVisionNMS
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
chown -R www-data:www-data storage bootstrap/cache
php artisan queue:restart
```

Saat menjalankan test, clear config cache lebih dulu agar `phpunit.xml` dapat memakai
environment testing:

```bash
php artisan config:clear --ansi
php artisan test
php artisan optimize
```

## Nginx

Site production berada di:

```text
/etc/nginx/sites-available/kusumavision-nms
```

Baseline penting:

- `root /var/www/KusumaVisionNMS/public;`
- `server_tokens off;`
- deny dotfiles dan file sensitif seperti `.env`, `.sql`, `.bak`, `.log`, `.yml`
- security headers:
  - `X-Frame-Options: SAMEORIGIN`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- HTTP allow-list:
  - `127.0.0.1`
  - `10.0.0.0/8`
  - `172.16.0.0/12`
  - `192.168.0.0/16`
  - `103.189.248.0/24`
  - `103.189.249.0/24`

Validate and reload:

```bash
nginx -t
systemctl reload nginx
```

## PHP-FPM

Production override:

```text
/etc/php/8.3/fpm/conf.d/99-kusumavision-production.ini
```

Current settings:

```ini
expose_php = Off
display_errors = Off
log_errors = On
cgi.fix_pathinfo = 0
session.cookie_httponly = 1
session.use_strict_mode = 1
```

Validate and restart:

```bash
php-fpm8.3 -t
systemctl restart php8.3-fpm
```

## SSH

Hardening drop-in:

```text
/etc/ssh/sshd_config.d/99-kusumavision-hardening.conf
```

Current baseline:

```text
PubkeyAuthentication yes
PasswordAuthentication no
KbdInteractiveAuthentication no
ChallengeResponseAuthentication no
PermitRootLogin prohibit-password
PermitEmptyPasswords no
MaxAuthTries 3
LoginGraceTime 30
X11Forwarding no
ClientAliveInterval 300
ClientAliveCountMax 2
```

Validate effective config:

```bash
sshd -t
sshd -T | grep -E '^(passwordauthentication|permitrootlogin|pubkeyauthentication|x11forwarding|maxauthtries)'
```

Important: SSH password login is disabled. Keep a valid private key for an entry
in `/root/.ssh/authorized_keys`.

## UFW Firewall

Default policy:

```bash
ufw default deny incoming
ufw default allow outgoing
```

Allowed inbound traffic:

```bash
ufw allow from 192.168.0.0/16 to any port 22 proto tcp comment 'SSH private LAN'
ufw allow from 10.0.0.0/8 to any port 22 proto tcp comment 'SSH private LAN'
ufw allow from 172.16.0.0/12 to any port 22 proto tcp comment 'SSH private LAN'
ufw allow from 103.189.248.0/24 to any port 22 proto tcp comment 'SSH trusted public subnet'
ufw allow from 103.189.249.0/24 to any port 22 proto tcp comment 'SSH trusted public subnet'

ufw allow from 192.168.0.0/16 to any port 80 proto tcp comment 'KusumaVision HTTP private LAN'
ufw allow from 10.0.0.0/8 to any port 80 proto tcp comment 'KusumaVision HTTP private LAN'
ufw allow from 172.16.0.0/12 to any port 80 proto tcp comment 'KusumaVision HTTP private LAN'
ufw allow from 103.189.248.0/24 to any port 80 proto tcp comment 'KusumaVision HTTP trusted public subnet'
ufw allow from 103.189.249.0/24 to any port 80 proto tcp comment 'KusumaVision HTTP trusted public subnet'
```

Check:

```bash
ufw status verbose
```

## Supervisor

Process files:

```text
/etc/supervisor/conf.d/kusumavision-worker.conf
/etc/supervisor/conf.d/kusumavision-scheduler.conf
```

Check:

```bash
supervisorctl status
```

Expected:

```text
kusumavision-worker:kusumavision-worker_00   RUNNING
kusumavision-scheduler                       RUNNING
```

## Dependency Audits

Composer security patching should be done with IPv4 resolution if Packagist IPv6 times out:

```bash
COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_IPRESOLVE=4 composer audit --no-interaction
COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_IPRESOLVE=4 composer update symfony/http-foundation symfony/polyfill-intl-idn symfony/routing --with-dependencies --no-interaction
```

Node runtime audit:

```bash
npm audit --omit=dev --audit-level=moderate
```

## Smoke Tests

```bash
curl -sS -o /dev/null -w 'home %{http_code}\n' http://192.168.99.61/
curl -sS -o /dev/null -w 'dashboard %{http_code} %{redirect_url}\n' http://192.168.99.61/dashboard
curl -sS -o /dev/null -w '.env %{http_code}\n' http://192.168.99.61/.env
systemctl is-active nginx php8.3-fpm postgresql redis-server supervisor ssh.socket
supervisorctl status
```

Expected:

- home returns `200`
- dashboard returns `302` to `/login`
- `.env` returns `403`
- services are active
- worker and scheduler are running
