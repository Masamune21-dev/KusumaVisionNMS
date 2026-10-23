# Gambar produk OLT

Taruh gambar di folder ini dengan nama persis seperti di bawah — halaman detail OLT
langsung memakainya tanpa perubahan kode (urutan coba: `.webp`, `.png`, `.jpg`).
Disarankan latar transparan, lebar ±1200 px, konversi ke WebP: `cwebp -q 85 in.png -o nama.webp`.

| Berkas | Dipakai untuk |
|---|---|
| `zte-c300.webp` | ZTE C300 (cadangan: `/img/c300.webp`) |
| `zte-c320.webp` | ZTE C320 (cadangan: `/img/c320.webp`) |
| `zte-c600.webp` | ZTE C600 / TITAN (cadangan: `/img/c600.webp`) |
| `cdata-epon.webp` | C-Data EPON (enterprise 17409) |
| `cdata-gpon.webp` | C-Data GPON (FD1608S dan model lain) |
| `cdata-gpon-fd1601s.webp` | C-Data GPON FD1601S (1 PON) |
| `cdata-gpon-fd1602s.webp` | C-Data GPON FD1602S (2 PON) |
| `hioso-epon.webp` | HiOSO EPON (HA7304, 4 port) |
| `hioso-ha7302.webp` | HiOSO HA7302 (2 port; dipilih dari `capabilities.is_ha7302` atau nama/vendor memuat `ha7302`) |
| `hsgq-gpon.webp` | HSGQ GPON 4 PON (HSGQ-G04L; juga bawaan HSGQ GPON tanpa kode model) |
| `hsgq-gpon-8.webp` | HSGQ GPON 8 PON (HSGQ-G08) |
| `hsgq-gpon-16.webp` | HSGQ GPON 16 PON (HSGQ-G16L) |
| `hsgq-gpon-mini.webp` | HSGQ GPON mini 1–2 PON (HSGQ-G01/G02, termasuk G02ID) |
| `hsgq-epon.webp` | HSGQ EPON 4 PON (HSGQ-XE04R/E04; bawaan HSGQ EPON) |
| `hsgq-epon-8.webp` | HSGQ EPON 8 PON (HSGQ-XE08R/E08) |

Gambar HSGQ (23 Okt 2026) = render produk resmi dari hsgq.com (halaman produk EPON/GPON) dan datasheet resmi
HSGQ-G01 (tampak depan saja), dipotong ke badan perangkat dengan latar dibuat transparan. Model dipilih dari kode
model di sysDescr (`HSGQ-G16L` → 16 PON) dan `capabilities.is_hsgq_epon`.

Pemetaan OLT → nama berkas ada di `resources/js/lib/oltImage.js`: C-Data GPON memilih `fd1601`/`fd1602` dari nama/vendor/model
faceplate, ZTE dari nama/vendor (`c320`, `c600`/`titan`, `c300`; ZTE lain → `zte-c300`), family lain → `generic` (placeholder).

Status per 1 Okt 2026: berkas `zte-*.webp` belum ada di folder ini, jadi ZTE memakai cadangan `/img/c300.webp` dst.
Tak ada lagi gambar HsAirPo (modul dihapus 29 Sep 2026) maupun V-Sol (tak dikenali driver HiOSO).
