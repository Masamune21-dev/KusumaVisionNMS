@echo off
REM KusumaVision NMS - perbarui ke versi kode terbaru (Windows).
REM Untuk mode source (ada Dockerfile). Data tetap aman di volume.
setlocal
cd /d "%~dp0"
echo Membangun ulang & menjalankan versi terbaru...
docker compose up -d --build
if errorlevel 1 (
  echo GAGAL. Pastikan Docker Desktop berjalan.
  pause
  exit /b 1
)
echo Selesai. Migrasi DB dijalankan otomatis saat container start.
pause
