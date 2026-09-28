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
| `hioso-epon.webp` | HiOSO / V-Sol EPON (HA7304 dan sejenis) |
| `hioso-ha7302.webp` | HiOSO HA7302 (2 port) |
| `hsairpo-epon.webp` | HsAirPo / HSGQ EPON |

Pemetaan OLT → nama berkas ada di `resources/js/lib/oltImage.js`.
