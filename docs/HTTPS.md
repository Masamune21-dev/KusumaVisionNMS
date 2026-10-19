# Menjalankan KusumaVision NMS lewat HTTPS

Panduan langkah demi langkah supaya NMS dibuka lewat `https://nms.domain-anda.com`, untuk instalasi
`install.sh`, manual, maupun Docker. `install.sh` hanya menyiapkan nginx **port 80** (HTTP). HTTPS
dipasang sesudahnya dengan salah satu skenario di bawah.

HTTPS bukan sekadar gembok di browser. Tiga hal di NMS **butuh** HTTPS:

- **Aplikasi Android (APK).** APK rilis tidak bisa memakai server `http://`, karena Android memblokir
  HTTP polos. `API_BASE_URL` saat build harus `https://…` ([BUILD_APK.md](BUILD_APK.md)).
- **Perintah bot Telegram (webhook).** Telegram menolak alamat webhook yang bukan `https://`.
- **Login lewat internet.** Tanpa HTTPS, sandi admin dan cookie sesi lewat jaringan tanpa enkripsi.

Contoh di dokumen ini memakai `nms.example.com`, IP publik `203.0.113.10`, dan IP LAN server
`192.168.88.10`. Ganti dengan milik Anda.

---

## Daftar isi

1. [Pilih skenario](#1-pilih-skenario)
2. [Cara NMS mengenali HTTPS (penyebab "redirect ke http")](#2-cara-nms-mengenali-https-penyebab-redirect-ke-http)
3. [Skenario A — IP publik + Let's Encrypt (certbot)](#3-skenario-a--ip-publik--lets-encrypt-certbot)
4. [Skenario B — Domain di Cloudflare, awan oranye (proxied)](#4-skenario-b--domain-di-cloudflare-awan-oranye-proxied)
5. [Skenario C — Cloudflare Tunnel (`cloudflared`), tanpa IP publik](#5-skenario-c--cloudflare-tunnel-cloudflared-tanpa-ip-publik)
6. [Skenario D — Docker appliance](#6-skenario-d--docker-appliance)
7. [Langkah wajib setelah HTTPS aktif (semua skenario)](#7-langkah-wajib-setelah-https-aktif-semua-skenario)
8. [Verifikasi](#8-verifikasi)
9. [Troubleshooting](#9-troubleshooting)
10. [Catatan keamanan](#10-catatan-keamanan)

---

## 1. Pilih skenario

| Kondisi server Anda | Skenario |
|---|---|
| Server punya **IP publik** (langsung, atau lewat port-forward/NAT di router) dan port 80 + 443 bisa dibuka | **[A](#3-skenario-a--ip-publik--lets-encrypt-certbot)**, paling sederhana |
| Domain dikelola **Cloudflare** dengan proxy aktif (awan oranye) | **[B](#4-skenario-b--domain-di-cloudflare-awan-oranye-proxied)** |
| **Tidak punya IP publik** (CGNAT), atau tidak mau membuka port apa pun ke internet | **[C](#5-skenario-c--cloudflare-tunnel-cloudflared-tanpa-ip-publik)** |
| NMS dipasang lewat **Docker** (`docker compose`) | **[D](#6-skenario-d--docker-appliance)** |
| Port 80 diblokir ISP, atau NMS hanya diakses dari LAN tapi tetap ingin sertifikat sah | [A — varian DNS-01](#a7-varian-dns-01-port-80-tertutup-atau-nms-hanya-di-lan) |

Semua skenario butuh **nama domain** (subdomain sudah cukup, mis. `nms.example.com`). Sertifikat publik
yang dipercaya browser praktis selalu diterbitkan untuk nama domain, bukan IP polos.

Apa pun skenarionya, setelah HTTPS jalan **wajib** lanjut ke [§7](#7-langkah-wajib-setelah-https-aktif-semua-skenario).

---

## 2. Cara NMS mengenali HTTPS (penyebab "redirect ke http")

Bagian ini menjelaskan masalah paling sering: halaman sudah `https://`, tapi setelah login malah dilempar
ke `http://`, login gagal **419 Page Expired**, atau terminal telnet "WebSocket error".

NMS (Laravel) membuat semua link, redirect, dan alamat WebSocket berdasarkan skema request yang **ia
lihat sendiri**. Ada dua cara request bisa dikenali sebagai HTTPS:

```
(1) TLS berakhir di nginx NMS sendiri — Skenario A, B1
    Browser ──https──▶ nginx NMS :443 ──▶ PHP-FPM        nginx memberi tahu PHP "HTTPS=on".
                                                           Tidak perlu setelan apa pun.

(2) TLS berakhir di proxy LAIN — Cloudflare Flexible (B2), Cloudflare Tunnel (C),
    Caddy/nginx di depan Docker (D), Nginx Proxy Manager, load balancer
    Browser ──https──▶ proxy ──http + header "X-Forwarded-Proto: https"──▶ nginx NMS :80 ──▶ PHP-FPM
```

Pada cara (2), Laravel **hanya mempercayai** header `X-Forwarded-Proto` bila alamat IP pengirimnya, yaitu
IP yang tercatat di log nginx NMS, ada di **`TRUSTED_PROXIES`** (`.env`). Bawaannya hanya
`127.0.0.1,::1`, artinya hanya proxy yang berjalan **di server yang sama** dan menyambung ke `localhost`.

Kalau IP proxy tidak ada di daftar itu, Laravel menganggap request-nya HTTP biasa. Akibatnya:

- link dan redirect jadi `http://…` → **"redirect ke http"**;
- form login yang dibuka di `https://` mengirim ke alamat `http://`, sehingga browser tidak menyertakan
  token CSRF → **419**;
- terminal telnet mencoba `ws://` dari halaman `https://` dan diblokir browser → **"WebSocket error"**.

**Mengapa tidak percayai semua proxy (`*`) saja?** Kalau server juga bisa diakses langsung tanpa lewat
proxy, siapa pun bisa memalsukan header `X-Forwarded-For`. Pembatas percobaan login bisa diakali dan IP di
Log Audit jadi palsu. Isi daftar seketat mungkin.

> Setiap kali `.env` diubah, jalankan `php artisan config:cache`. Tanpa itu NMS tetap memakai nilai lama.
> Ini penyebab nomor satu "sudah diisi tapi masih http".

---

## 3. Skenario A — IP publik + Let's Encrypt (certbot)

Untuk server yang bisa dijangkau dari internet di port 80 dan 443. Sertifikat Let's Encrypt gratis dan
diperpanjang otomatis.

### A.1 Arahkan domain ke IP publik

Di panel DNS domain Anda, buat record:

| Tipe | Nama | Isi |
|---|---|---|
| `A` | `nms` | `203.0.113.10` (IP publik server/router) |

Bila DNS di Cloudflare: set **DNS only** (awan abu-abu) untuk skenario ini. Untuk awan oranye, lihat
[Skenario B](#4-skenario-b--domain-di-cloudflare-awan-oranye-proxied).

Cek dari server (tunggu propagasi bila belum muncul):

```bash
getent hosts nms.example.com      # harus menampilkan 203.0.113.10
```

### A.2 Buka port 80 dan 443

**Firewall server** (bila `ufw` aktif):

```bash
sudo ufw allow 'Nginx Full'       # = 80/tcp + 443/tcp
sudo ufw status
```

**Server di belakang router (NAT).** Teruskan port 80 dan 443 dari IP publik ke IP LAN server. Contoh
MikroTik:

```
/ip firewall nat add chain=dstnat dst-address=203.0.113.10 protocol=tcp dst-port=80,443 \
    action=dst-nat to-addresses=192.168.88.10 comment="NMS HTTPS"
```

Selalu isi `dst-address` dengan IP publik itu. Di router ISP, aturan tanpa `dst-address` ikut membelokkan
trafik port 80/443 menuju IP publik pelanggan.

**Akses dari kantor (LAN yang sama).** Bila domain bisa dibuka dari luar tapi tidak dari kantor, router
tidak mendukung *hairpin NAT*. Solusi paling mudah: arahkan domain ke IP LAN lewat DNS router, mis.
MikroTik `/ip dns static add name=nms.example.com address=192.168.88.10`. Pastikan klien kantor memakai
DNS router. Sertifikat tetap sah karena nama domainnya sama.

### A.3 Pastikan nginx mengenal domain Anda

certbot mencari blok `server` nginx yang `server_name`-nya memuat domain tersebut. Bila saat instalasi
`APP_URL` diisi IP (bawaan `install.sh`), domainnya belum ada di sana:

```bash
grep -n server_name /etc/nginx/sites-available/kusumavision-nms
```

Bila domain belum tercantum, edit baris itu menjadi, misalnya:

```nginx
    server_name nms.example.com 192.168.88.10 _;
```

lalu:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

### A.4 Pasang sertifikat

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d nms.example.com
```

certbot menanyakan email (untuk peringatan kedaluwarsa) dan persetujuan ToS. Bila ditanya soal
pengalihan, pilih **Redirect** (HTTP → HTTPS).

Yang diubah certbot di `/etc/nginx/sites-available/kusumavision-nms`:

- blok asli kini `listen 443 ssl` + baris `ssl_certificate …` (bertanda `# managed by Certbot`);
- muncul blok baru di port 80 yang mengalihkan `nms.example.com` ke `https://` dan menjawab **404**
  untuk nama lain. Artinya `http://192.168.88.10` kini 404. Itu **wajar**: buka NMS lewat
  `https://nms.example.com`.

### A.5 Sesuaikan `.env` NMS

Lanjut ke [§7](#7-langkah-wajib-setelah-https-aktif-semua-skenario). `TRUSTED_PROXIES` **tidak perlu**
diubah di skenario ini.

### A.6 Pastikan perpanjangan otomatis jalan

Sertifikat Let's Encrypt berlaku 90 hari. Paket certbot memasang timer yang memperpanjangnya sendiri:

```bash
systemctl list-timers | grep certbot      # harus ada certbot.timer
sudo certbot renew --dry-run              # simulasi perpanjangan, harus "succeeded"
```

> ⚠️ **Jangan jalankan ulang `install.sh` setelah certbot.** Skrip itu menulis ulang berkas site nginx
> sehingga konfigurasi HTTPS hilang. Bila terlanjur, ulangi langkah A.3–A.4: certbot memakai ulang
> sertifikat yang ada, pilih *reinstall*. Prosedur update biasa
> ([INSTALL.md §9](INSTALL.md#9-update-ke-versi-baru)) tidak menyentuh nginx.

### A.7 Varian DNS-01 (port 80 tertutup, atau NMS hanya di LAN)

Bila port 80 tidak bisa dijangkau dari internet (diblokir ISP, atau NMS sengaja hanya untuk LAN dengan
domain diarahkan ke IP LAN lewat DNS static), Let's Encrypt bisa memverifikasi lewat record DNS. Contoh
untuk domain di Cloudflare:

1. Cloudflare → **My Profile → API Tokens → Create Token** → template **Edit zone DNS** → pilih zona
   domain Anda → buat, salin token.
2. Di server:

   ```bash
   sudo apt install -y certbot python3-certbot-nginx python3-certbot-dns-cloudflare
   sudo install -d -m 700 /root/.secrets
   echo 'dns_cloudflare_api_token = TEMPEL_TOKEN_DI_SINI' | sudo tee /root/.secrets/cloudflare.ini >/dev/null
   sudo chmod 600 /root/.secrets/cloudflare.ini
   sudo certbot -a dns-cloudflare -i nginx \
       --dns-cloudflare-credentials /root/.secrets/cloudflare.ini -d nms.example.com
   ```

   Langkah A.3 (`server_name`) tetap berlaku. Perpanjangan otomatis juga memakai DNS, jadi port 80 tidak
   perlu dibuka sama sekali.

DNS provider lain punya plugin certbot sendiri (cari `python3-certbot-dns-<provider>`).

---

## 4. Skenario B — Domain di Cloudflare, awan oranye (proxied)

Pengunjung terhubung ke Cloudflare, lalu Cloudflare meneruskan ke server Anda. Server tetap butuh IP
publik yang bisa dijangkau Cloudflare. Mode enkripsi diatur di **SSL/TLS → Overview**:

| Mode | Cloudflare → server | Pakai? |
|---|---|---|
| **Full (strict)** | HTTPS, sertifikat origin diperiksa | ✅ **Disarankan (B1)** |
| Full | HTTPS, sertifikat tidak diperiksa | Hindari. Tetap butuh port 443 di server. |
| Flexible | **HTTP polos** | Hanya bila terpaksa **(B2)** |

### B1. Full (strict) + Cloudflare Origin Certificate (disarankan)

Origin Certificate gratis, berlaku hingga 15 tahun (tanpa perpanjangan), dan dipercaya oleh Cloudflare
(bukan oleh browser, tapi browser memang hanya bicara dengan Cloudflare).

1. Cloudflare → **SSL/TLS → Origin Server → Create Certificate**. Biarkan bawaan (RSA, hostname
   `*.example.com` dan `example.com`, atau isi `nms.example.com`), masa berlaku 15 tahun → **Create**.
2. Simpan dua teks yang muncul ke server. Kunci privat hanya ditampilkan sekali:

   ```bash
   sudo install -d -m 755 /etc/ssl/cloudflare
   sudo nano /etc/ssl/cloudflare/nms.example.com.pem    # tempel "Origin Certificate"
   sudo nano /etc/ssl/cloudflare/nms.example.com.key    # tempel "Private Key"
   sudo chmod 600 /etc/ssl/cloudflare/nms.example.com.key
   ```

3. Edit `/etc/nginx/sites-available/kusumavision-nms`. **Di dalam** blok `server` yang sudah ada, di
   bawah `listen [::]:80;`, tambahkan:

   ```nginx
       listen 443 ssl;
       listen [::]:443 ssl;
       ssl_certificate     /etc/ssl/cloudflare/nms.example.com.pem;
       ssl_certificate_key /etc/ssl/cloudflare/nms.example.com.key;
   ```

   Pastikan juga `server_name` memuat domain Anda (lihat [A.3](#a3-pastikan-nginx-mengenal-domain-anda)).

   ```bash
   sudo nginx -t && sudo systemctl reload nginx
   sudo ufw allow 'Nginx Full'          # bila ufw aktif; lihat §10 untuk membatasi ke IP Cloudflare
   ```

4. Cloudflare → **SSL/TLS → Overview** → **Full (strict)**. Lalu **SSL/TLS → Edge Certificates →
   Always Use HTTPS** → **On**.
5. Record DNS `nms` → awan **oranye** (Proxied).
6. **Dianjurkan:** isi `TRUSTED_PROXIES` dengan rentang IP Cloudflare (langkah di B2 no. 1). HTTPS sudah
   terdeteksi tanpa itu, tetapi tanpa itu Log Audit dan pembatas login melihat IP Cloudflare, bukan IP
   pengguna sebenarnya.
7. Lanjut ke [§7](#7-langkah-wajib-setelah-https-aktif-semua-skenario).

Alternatif Origin Certificate: sertifikat Let's Encrypt lewat [varian DNS-01](#a7-varian-dns-01-port-80-tertutup-atau-nms-hanya-di-lan),
yang tetap bisa diperpanjang walau awan oranye.

### B2. Flexible (tanpa sertifikat di server)

Cloudflare → server lewat HTTP polos. Trafik antara Cloudflare dan server **tidak terenkripsi**, jadi
pakai hanya bila B1 tidak memungkinkan. Di mode ini `TRUSTED_PROXIES` **wajib** diisi (lihat
[§2](#2-cara-nms-mengenali-https-penyebab-redirect-ke-http)).

1. Isi `TRUSTED_PROXIES` dengan rentang IP Cloudflare. Dari folder proyek NMS:

   ```bash
   CF_IPS=$( (curl -fsS https://www.cloudflare.com/ips-v4; echo; curl -fsS https://www.cloudflare.com/ips-v6) \
             | grep -v '^$' | paste -sd, - )
   echo "$CF_IPS"                                   # periksa: daftar CIDR dipisah koma
   sed -i "s|^TRUSTED_PROXIES=.*|TRUSTED_PROXIES=127.0.0.1,::1,${CF_IPS}|" .env
   grep ^TRUSTED_PROXIES .env
   ```

   Cloudflare jarang mengubah daftar ini, tapi cek ulang sesekali.
2. **Jangan** pasang pengalihan HTTP → HTTPS di nginx server (mis. certbot dengan *Redirect*). Di mode
   Flexible itu membuat **redirect tanpa akhir** (`ERR_TOO_MANY_REDIRECTS`).
3. Cloudflare → **Always Use HTTPS** → **On**; record DNS awan oranye.
4. Lanjut ke [§7](#7-langkah-wajib-setelah-https-aktif-semua-skenario).

---

## 5. Skenario C — Cloudflare Tunnel (`cloudflared`), tanpa IP publik

`cloudflared` di server membuka koneksi **keluar** ke Cloudflare. Tidak perlu IP publik, port-forward,
maupun sertifikat di server. Syaratnya, domain sudah memakai nameserver Cloudflare.

### C.1 Buat tunnel

1. Cloudflare → **Zero Trust → Networks → Tunnels → Create a tunnel** (nama menu bisa sedikit berbeda
   antarversi dashboard) → tipe **Cloudflared** → beri nama, mis. `nms`.
2. Pilih sistem operasi server (Debian/Ubuntu, 64-bit) dan **salin perintah instalasi yang ditampilkan**.
   Perintah itu sudah berisi token tunnel. Jalankan **di server yang sama dengan NMS**. Perintahnya
   memasang paket `cloudflared` lalu `sudo cloudflared service install <TOKEN>`.
3. Tunggu status connector **Healthy**, lalu **Next**.

### C.2 Tambahkan Public Hostname

| Kolom | Isi |
|---|---|
| Subdomain | `nms` |
| Domain | `example.com` |
| Service → Type | **HTTP** (bukan HTTPS) |
| Service → URL | **`localhost:80`** |

Simpan. Cloudflare membuat record DNS-nya otomatis.

> Isi URL service dengan `localhost:80`, bukan IP LAN atau domain publik. Dengan `localhost`, nginx NMS
> melihat request datang dari `127.0.0.1`, yang sudah dipercaya bawaan. HTTPS langsung terdeteksi tanpa
> menyentuh `TRUSTED_PROXIES`.

### C.3 Bila `cloudflared` TIDAK di server yang sama

Bila `cloudflared` berjalan di container Docker, VM/LXC lain, atau mesin lain, nginx NMS melihat IP
lain, dan inilah penyebab umum **"pakai cloudflared tapi masih redirect ke http"**. Cari IP tersebut:

```bash
sudo tail -f /var/log/nginx/access.log
# buka https://nms.example.com di browser; kolom PERTAMA baris baru = IP pengirim (mis. 172.17.0.2)
```

Tambahkan IP/CIDR itu ke `TRUSTED_PROXIES` di `.env`, mis.:

```dotenv
TRUSTED_PROXIES=127.0.0.1,::1,172.16.0.0/12      # cloudflared di container Docker
TRUSTED_PROXIES=127.0.0.1,::1,192.168.88.20      # cloudflared di mesin LAN lain
```

lalu `php artisan config:cache` ([§7](#7-langkah-wajib-setelah-https-aktif-semua-skenario)).

### C.4 Setelah tunnel jalan

- Cloudflare → **Always Use HTTPS** → **On**. Mode SSL/TLS tidak berpengaruh pada tunnel.
- Port 80/443 server boleh **ditutup** dari internet. Tunnel tidak memerlukannya.
- Cek layanan: `systemctl status cloudflared`.
- Lanjut ke [§7](#7-langkah-wajib-setelah-https-aktif-semua-skenario).

---

## 6. Skenario D — Docker appliance

Container NMS hanya melayani HTTP di `APP_PORT` (bawaan `8080`). HTTPS dipasang di proxy **di luar**
container. Proxy itu menyambung lewat jaringan Docker (`172.16.0.0/12`), bukan `127.0.0.1`, sehingga
`TRUSTED_PROXIES` **wajib** diisi.

### D.1 `.env` di samping `docker-compose.yml`

```dotenv
APP_URL=https://nms.example.com
TRUSTED_PROXIES=172.16.0.0/12
SESSION_SECURE_COOKIE=true
# Opsional (host Linux): publish port hanya ke localhost supaya http://IP:8080 tak bisa dibuka
# dari LAN/internet. Docker melewati aturan ufw, jadi firewall host saja tidak cukup.
# APP_PORT=127.0.0.1:8080
```

Terapkan (`restart` **tidak** membaca ulang `.env`; `up -d` membuat ulang container):

```bash
docker compose up -d
```

### D.2 Pilih proxy di depan container

**Caddy di host** (HTTPS otomatis dari Let's Encrypt; butuh IP publik + port 80/443 seperti
[A.1–A.2](#a1-arahkan-domain-ke-ip-publik)). Pasang Caddy (lihat <https://caddyserver.com/docs/install>),
lalu isi `/etc/caddy/Caddyfile`:

```caddyfile
nms.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

```bash
sudo systemctl reload caddy
```

Caddy otomatis meneruskan `Host` asli, `X-Forwarded-Proto`, dan WebSocket (terminal telnet).

**nginx di host** (bila sudah memakai nginx). Buat `/etc/nginx/sites-available/nms-proxy`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name nms.example.com;
    client_max_body_size 20M;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;                       # WAJIB: tanpa ini link jadi 127.0.0.1:8080
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Real-IP $remote_addr;
    }

    location /telnet-ws {                                  # WebSocket terminal telnet
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/nms-proxy /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d nms.example.com        # seperti A.4
```

**Cloudflare Tunnel.** Ikuti [Skenario C](#5-skenario-c--cloudflare-tunnel-cloudflared-tanpa-ip-publik),
dengan URL service **`localhost:8080`** (atau nilai `APP_PORT` Anda). Ini juga jalan di Windows: Cloudflare
menyediakan `cloudflared` untuk Windows.

Lalu lanjut ke [§7](#7-langkah-wajib-setelah-https-aktif-semua-skenario). Perintah `php artisan` di sana
dijalankan lewat `docker compose exec app …`.

---

## 7. Langkah wajib setelah HTTPS aktif (semua skenario)

### 7.1 Ubah `.env`

Docker: sudah dikerjakan di [D.1](#d1-env-di-samping-docker-composeyml). Instalasi `install.sh`/manual:
edit `.env` di folder proyek.

```dotenv
APP_URL=https://nms.example.com
SESSION_SECURE_COOKIE=true
TELNET_PROXY_WS_URL=/telnet-ws
# TRUSTED_PROXIES → sesuai skenario (A: biarkan bawaan; B: rentang Cloudflare;
#                   C: bawaan bila cloudflared di localhost; D: 172.16.0.0/12)
```

| Variabel | Untuk apa |
|---|---|
| `APP_URL` | Alamat yang dipakai perintah konsol dan antrean, mis. `php artisan telegram:webhook set`. Bila masih `http://`, pendaftaran webhook Telegram ditolak. |
| `SESSION_SECURE_COOKIE` | Cookie sesi hanya dikirim lewat HTTPS. Akibatnya login lewat `http://IP` **tidak bisa lagi**, jadi selalu pakai `https://domain`. |
| `TELNET_PROXY_WS_URL` | Biarkan `/telnet-ws` (bawaan `install.sh`). Skema `ws://` atau `wss://` otomatis mengikuti halaman. |

### 7.2 Terapkan

```bash
php artisan config:cache
php artisan queue:restart
sudo supervisorctl restart kusumavision-telnet-proxy
sudo systemctl reload php8.3-fpm          # sesuaikan versi PHP Anda
```

Docker: `docker compose up -d` sudah cukup (cache dibangun ulang saat container start).

### 7.3 Daftarkan ulang webhook Telegram (bila memakai bot)

**Pengaturan → Telegram → Perintah Bot (Webhook) → Daftar Ulang Webhook**, atau
`php artisan telegram:webhook set`. Bot milik partner didaftarkan ulang dari halaman bot partner
masing-masing. Cek hasilnya dengan `php artisan telegram:webhook info`: `url` harus diawali `https://`.

### 7.4 Bangun APK dengan alamat HTTPS

```bash
API_BASE_URL=https://nms.example.com/api/v1 bash bin/build-apk.sh
```

APK yang dibangun dengan `http://…` harus dibangun ulang. Lihat [BUILD_APK.md](BUILD_APK.md).

---

## 8. Verifikasi

Dari mesin mana pun:

```bash
# 1. Halaman login 200 + header HSTS
curl -sI https://nms.example.com/login | grep -iE '^HTTP|strict-transport'

# 2. NMS sadar dirinya HTTPS: URL dasar route harus https (bukan http)
curl -s https://nms.example.com/login | grep -o '"url":"[^"]*"' | head -1
#    benar → "url":"https:\/\/nms.example.com"

# 3. CSP memuat upgrade-insecure-requests (hanya dikirim bila request terdeteksi HTTPS)
curl -sI https://nms.example.com/login | grep -io upgrade-insecure-requests
```

Lalu di browser:

1. Login berhasil, tanpa 419, dan alamat tetap `https://` setelah masuk.
2. Buka terminal telnet sebuah OLT: tersambung tanpa "WebSocket error".
3. DevTools (F12) → Console: tidak ada pesan **Mixed Content**.

Bila langkah 2 di curl menampilkan `http:\/\/`, NMS belum mempercayai proxy Anda. Lihat
[§2](#2-cara-nms-mengenali-https-penyebab-redirect-ke-http) dan baris pertama tabel di §9.

---

## 9. Troubleshooting

| Gejala | Penyebab | Solusi |
|---|---|---|
| Setelah login dilempar ke `http://…`, login **419**, atau terminal "WebSocket error", padahal halaman dibuka lewat `https://` | Proxy di depan nginx NMS tidak dipercaya, atau `config:cache` belum dijalankan | Cari IP proxy di `/var/log/nginx/access.log` (kolom pertama), tambahkan ke `TRUSTED_PROXIES`, lalu `php artisan config:cache` ([§2](#2-cara-nms-mengenali-https-penyebab-redirect-ke-http), [C.3](#c3-bila-cloudflared-tidak-di-server-yang-sama)) |
| `ERR_TOO_MANY_REDIRECTS` | Cloudflare **Flexible** + server juga mengalihkan HTTP → HTTPS | Naikkan ke **Full (strict)** ([B1](#b1-full-strict--cloudflare-origin-certificate-disarankan)), atau hapus pengalihan di server |
| Cloudflare **521** | Server mati, atau firewall memblokir IP Cloudflare | `systemctl status nginx`; izinkan rentang IP Cloudflare di firewall |
| Cloudflare **522** | Cloudflare tak bisa menjangkau server (timeout) | Cek port-forward/NAT router dan firewall |
| Cloudflare **525** | Mode Full/Full (strict) tapi server tidak melayani HTTPS di 443 | Pasang sertifikat di server ([B1](#b1-full-strict--cloudflare-origin-certificate-disarankan)) |
| Cloudflare **526** | Full (strict) tapi sertifikat server tidak sah (self-signed, kedaluwarsa, nama salah) | Pakai Origin Certificate atau Let's Encrypt yang memuat nama domain |
| Cloudflare **1033** | Tunnel tidak tersambung | `systemctl status cloudflared`; cek status connector di dashboard |
| **502 Bad Gateway** lewat tunnel | URL service salah (`https://`, port keliru) atau nginx/container mati | Service `HTTP` → `localhost:80` (Docker: `localhost:8080`) |
| certbot: *Could not automatically find a matching server block* | `server_name` nginx belum memuat domain | [A.3](#a3-pastikan-nginx-mengenal-domain-anda) |
| certbot: *Timeout during connect* / *Connection refused* | Port 80 tak terjangkau dari internet (ufw, router, ISP), DNS belum mengarah ke server, atau record masih awan oranye | Periksa A.1–A.2; atau pakai [varian DNS-01](#a7-varian-dns-01-port-80-tertutup-atau-nms-hanya-di-lan) |
| `http://IP-server` menjawab **404** setelah certbot | Blok port 80 buatan certbot hanya mengalihkan nama domain | Wajar. Buka lewat `https://nms.example.com` |
| Dari luar bisa, dari kantor tidak bisa | Router tak mendukung hairpin NAT | DNS static domain → IP LAN ([A.2](#a2-buka-port-80-dan-443)) |
| HTTPS hilang setelah menjalankan ulang `install.sh` | `install.sh` menulis ulang site nginx | Ulangi [A.3–A.4](#a3-pastikan-nginx-mengenal-domain-anda) |
| Webhook Telegram: *An HTTPS URL must be provided for webhook* | `APP_URL` masih `http://` (perintah konsol), atau proxy belum dipercaya (tombol di web) | [§7.1](#71-ubah-env) lalu daftarkan ulang |
| APK tidak bisa login, *Cleartext HTTP traffic not permitted* | APK dibangun dengan `API_BASE_URL=http://…` | Bangun ulang dengan `https://` ([§7.4](#74-bangun-apk-dengan-alamat-https)) |
| Login **419** setelah `SESSION_SECURE_COOKIE=true`, lewat `http://` | Cookie aman tidak dikirim lewat HTTP | Buka lewat `https://domain` |
| Sertifikat kedaluwarsa | Timer perpanjangan mati, atau port 80 kini tertutup | `sudo certbot renew --dry-run`, lihat pesan errornya |

Masalah 419 yang lebih rinci (cara diagnosa dari log nginx):
[handbook 13 — 419 di belakang Cloudflare](handbook/13-troubleshooting-maintenance.md).

---

## 10. Catatan keamanan

- **Isi `TRUSTED_PROXIES` seketat mungkin.** Jangan `*` bila server juga bisa diakses langsung tanpa
  lewat proxy.
- **Skenario B: batasi port 80/443 hanya untuk IP Cloudflare** supaya server tidak bisa diakses langsung
  dengan melewati Cloudflare. Contoh `ufw` (setelah menghapus aturan `Nginx Full` yang umum):

  ```bash
  for ip in $(curl -fsS https://www.cloudflare.com/ips-v4) $(curl -fsS https://www.cloudflare.com/ips-v6); do
      sudo ufw allow from "$ip" to any port 80,443 proto tcp comment 'Cloudflare'
  done
  ```

  Jangan sampai mengunci akses SSH Anda sendiri; aturan SSH tetap terpisah.
- **Port 6002 (daemon telnet) tetap lokal.** Browser menjangkaunya lewat nginx di `/telnet-ws`; jangan
  dibuka ke internet atau dipasang di tunnel.
- **NBI GenieACS (port 7557) jangan pernah dipublikasikan** lewat proxy atau tunnel, karena tanpa
  autentikasi ([handbook 20 §1](handbook/20-genieacs-tr069.md#1-topologi--peringatan-keamanan)).
- **HSTS.** nginx buatan `install.sh` mengirim `Strict-Transport-Security` (1 tahun,
  `includeSubDomains`). Begitu browser pernah membuka NMS lewat HTTPS, ia menolak HTTP untuk nama itu
  selama setahun. Pakai **subdomain** khusus (mis. `nms.example.com`), jangan domain utama, supaya
  subdomain lain yang masih HTTP tidak ikut terkunci.
- **Cloudflare Tunnel + Cloudflare Access** (opsional) bisa menambah lapisan login sebelum halaman NMS
  tampil. Ingat bahwa APK dan webhook Telegram juga harus bisa lewat, jadi kecualikan `/api/*` dan
  `/telegram/webhook*` bila memakai Access.
