@echo off
REM KusumaVision NMS - hentikan (Windows). Data tetap tersimpan di volume.
setlocal
cd /d "%~dp0"
echo Menghentikan KusumaVision NMS...
docker compose down
echo.
echo Dihentikan. Data (DB/upload/log) tetap aman.
echo Jalankan start.bat untuk menyalakan kembali.
pause
