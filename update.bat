@echo off
REM KusumaVision NMS - perbarui ke versi kode terbaru (Windows).
REM Untuk mode source (ada Dockerfile). Data tetap aman di volume.
setlocal
cd /d "%~dp0"
REM --pull: ambil image dasar terbaru (php/node/go) supaya tambalan keamanan PHP ikut terpasang.
echo Membangun ulang dengan image dasar terbaru...
docker compose build --pull
if errorlevel 1 (
  echo GAGAL membangun. Pastikan Docker Desktop berjalan dan internet tersambung.
  pause
  exit /b 1
)
echo Menjalankan versi terbaru...
docker compose up -d
if errorlevel 1 (
  echo GAGAL. Pastikan Docker Desktop berjalan.
  pause
  exit /b 1
)
echo Selesai. Migrasi DB dijalankan otomatis saat container start.
pause
