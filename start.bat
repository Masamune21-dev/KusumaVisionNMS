@echo off
REM KusumaVision NMS - nyalakan (Windows). Double-click file ini.
setlocal
cd /d "%~dp0"

if not exist ".env" (
  echo Membuat .env dari template...
  copy /Y ".env.docker.example" ".env" >nul
  echo PENTING: buka .env dan ubah DB_PASSWORD sebelum dipakai produksi.
)

echo Menjalankan KusumaVision NMS ^(docker compose^)...
docker compose up -d --build
if errorlevel 1 (
  echo.
  echo GAGAL. Pastikan Docker Desktop sudah berjalan lalu coba lagi.
  pause
  exit /b 1
)

echo.
echo KusumaVision NMS aktif. Membuka browser...
start "" http://localhost:8080
echo ^(Jika APP_PORT di .env diubah, buka http://localhost:PORT sesuai nilai itu^)
pause
