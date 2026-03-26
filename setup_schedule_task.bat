@echo off
REM Setup Windows Task Scheduler for MushroomOS Schedule Runner
REM This will create a task that runs every minute

echo Setting up Windows Task Scheduler for MushroomOS...
echo.

REM Delete existing task if it exists
schtasks /delete /tn "MushroomOS Schedule Runner" /f >nul 2>&1

REM Create new task that runs every minute
schtasks /create /tn "MushroomOS Schedule Runner" /tr "C:\xampp\htdocs\mushroom_system\schedule_runner.bat" /sc minute /mo 1 /f

if %ERRORLEVEL% EQU 0 (
    echo.
    echo SUCCESS: Task created successfully!
    echo The schedule runner will now execute every minute.
    echo.
    echo To verify: Open Task Scheduler and look for "MushroomOS Schedule Runner"
    echo To test manually: Run C:\xampp\htdocs\mushroom_system\schedule_runner.php
    echo.
    pause
) else (
    echo.
    echo ERROR: Failed to create task.
    echo Please run this script as Administrator.
    echo.
    pause
)
