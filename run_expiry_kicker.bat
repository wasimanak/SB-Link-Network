@echo off
title Auto Expiry Kicker (Do Not Close)
echo Starting Auto Expiry Kicker...
echo This window will check for expired users every minute and kick them.
:loop
php "C:\xampp\htdocs\SB Link Network\config\cron_expiry.php"
timeout /t 60 >nul
goto loop
