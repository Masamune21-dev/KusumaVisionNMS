# Local Production Hardening

Dokumen ini mencatat baseline production lokal untuk server KusumaVision NMS.
Targetnya adalah aplikasi tetap bisa diakses dari jaringan operasional, tetapi file secret,
debug output, SSH password login, dan port publik yang tidak perlu tetap tertutup.

> **Status per 1 Okt 2026:** baseline di bawah ditulis Mei 2026 untuk akses **HTTP polos di LAN** dan
> masih berguna sebagai contoh hardening instalasi `install.sh`. Yang perlu disesuaikan:
> - Tiga daemon Supervisor (daftar §Supervisor sudah diperbarui): `kusumavision-telnet-proxy`
>   (`php artisan telnet:proxy`, di-proxy nginx `/telnet-ws`) ikut di-restart setelah deploy.
> - Langkah deploy lengkap (composer, build, Go poller, migrate, cache, restart) ada di
>   [INSTALL.md §9](INSTALL.md#9-update-ke-versi-baru).
> - Untuk HTTPS (disarankan): `certbot --nginx`, lalu `SESSION_SECURE_COOKIE=true` di `.env`. Di belakang
>   Cloudflare "Flexible"/load balancer di host lain, isi `TRUSTED_PROXIES` (lihat INSTALL.md §9).
> - Aplikasi sendiri kini mengirim header **CSP** (`App\Http\Middleware\ContentSecurityPolicy`, termasuk
>   `frame-ancestors 'self'`); upload foto ODP butuh PHP-FPM `upload_max_filesize ≥ 12M`
>   (`99-kusumavision-uploads.ini`, ditulis `install.sh`).

## Current Baseline

- App URL: `http://<IP-LAN>
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
APP_URL=http://<IP-LAN>
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
supervisorctl restart kusumavision-telnet-proxy
```

Test **selalu** lewat skrip pembungkus, jangan `php artisan test` polos. Di mesin yang config-nya
ter-cache, cached config menang atas `phpunit.xml` sehingga test berjalan di DB produksi:

```bash
bash scripts/test.sh
```

Skrip itu mengalihkan `APP_CONFIG_CACHE`/`APP_ROUTES_CACHE` ke path non-eksisten (cache produksi
tidak disentuh) dan menolak jalan kecuali koneksi benar-benar sqlite `:memory:`.

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
  - `<subnet-admin>`

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
ufw allow from <SUBNET_ADMIN> to any port 22 proto tcp comment 'SSH trusted public subnet'

ufw allow from 192.168.0.0/16 to any port 80 proto tcp comment 'KusumaVision HTTP private LAN'
ufw allow from 10.0.0.0/8 to any port 80 proto tcp comment 'KusumaVision HTTP private LAN'
ufw allow from 172.16.0.0/12 to any port 80 proto tcp comment 'KusumaVision HTTP private LAN'
ufw allow from <SUBNET_ADMIN> to any port 80 proto tcp comment 'KusumaVision HTTP trusted public subnet'
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
/etc/supervisor/conf.d/kusumavision-telnet-proxy.conf
```

Check:

```bash
supervisorctl status
```

Expected:

```text
kusumavision-worker:kusumavision-worker_00   RUNNING
kusumavision-scheduler                       RUNNING
kusumavision-telnet-proxy                    RUNNING
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

## OLT Target Addresses

The server itself opens sockets to `ip:cli_port` for every OLT (CLI telnet, the browser telnet proxy,
and the HSGQ web client). Without a limit, any account allowed to add an OLT could point it at an
arbitrary address and use this server as a bridge into the local network — including services that
have no authentication of their own (ACS/TR-069, admin panels, databases) and loopback.

`App\Support\OltTargetGuard` closes that path. Always rejected: `0.0.0.0/8`, loopback, link-local
(including the cloud metadata address), multicast/reserved, and the NMS server's own addresses.
Addresses that should never be an OLT — your ACS host, management VLAN, office network — go in
`NMS_OLT_BLOCKED_CIDRS`:

```dotenv
NMS_OLT_BLOCKED_CIDRS=172.20.0.0/16,192.168.99.0/24
```

OLTs owned by a **partner** are additionally restricted to the paths you prepared for them, listed in
`NMS_OLT_PARTNER_CIDRS` (their WireGuard/tunnel subnet, or the public block NAT-ed to their OLTs):

```dotenv
NMS_OLT_PARTNER_CIDRS=100.64.10.0/24,203.0.113.0/28
```

Left empty — the default — partners cannot add their own OLT through the form; OLTs for them are
created by head-office staff and assigned to the partner, which keeps every other feature working.
Partner-owned rows stored before this rule are still re-checked when a CLI session or telnet proxy
socket is opened, so they cannot be used as a relay.

After changing either variable run `php artisan config:cache` (and restart the worker and the
telnet proxy: `supervisorctl restart kusumavision-worker kusumavision-telnet-proxy`).

## Smoke Tests

```bash
curl -sS -o /dev/null -w 'home %{http_code}\n' http://<IP-LAN>/
curl -sS -o /dev/null -w 'dashboard %{http_code} %{redirect_url}\n' http://<IP-LAN>/dashboard
curl -sS -o /dev/null -w '.env %{http_code}\n' http://<IP-LAN>/.env
systemctl is-active nginx php8.3-fpm postgresql redis-server supervisor ssh.socket
supervisorctl status
```

Expected:

- home returns `200`
- dashboard returns `302` to `/login`
- `.env` returns `403`
- services are active
- worker, scheduler, and telnet-proxy are running
