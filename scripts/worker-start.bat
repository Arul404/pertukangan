@echo off
REM Menyalakan worker antrean kirim Tukang Kirim (jendela ini menampilkan lognya).
REM Tutup jendela ini untuk menghentikan worker, atau jalankan worker-stop.bat.
cd /d "%~dp0.."
title Tukang Kirim - Worker Antrean
echo ================================================================
echo  Worker antrean Tukang Kirim berjalan.
echo  Biarkan jendela ini terbuka. Tutup jendela = worker berhenti.
echo  Untuk berhenti dengan rapi, jalankan: scripts\worker-stop.bat
echo ================================================================
php spark tukangkirim:work
echo.
echo Worker berhenti.
pause
