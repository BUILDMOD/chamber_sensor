@echo off
REM Windows Task Scheduler script for MushroomOS Schedule Runner
REM This script calls the PHP schedule runner every minute

REM Path to PHP (adjust if needed)
set PHP_PATH=C:\xampp\php\php.exe

REM Path to the schedule runner script
set SCRIPT_PATH=C:\xampp\htdocs\mushroom_system\schedule_runner.php

REM Execute the PHP script
"%PHP_PATH%" "%SCRIPT_PATH%"

REM Optional: Log the execution (uncomment to enable)
REM echo %date% %time% - Schedule runner executed >> C:\xampp\htdocs\mushroom_system\schedule_log.txt
