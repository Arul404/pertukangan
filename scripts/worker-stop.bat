@echo off
REM Menghentikan worker antrean dengan rapi (worker keluar setelah pesan berjalan selesai).
cd /d "%~dp0.."
php spark tukangkirim:stop
echo.
pause
