# Schedule Runner Setup Instructions

## Overview
The `schedule_runner.php` provides server-side backup for sprayer schedules when ESP32 is offline.

## Setup Instructions

### Windows (XAMPP)
1. Open Task Scheduler
2. Create Basic Task
3. Name: "MushroomOS Schedule Runner"
4. Trigger: Daily, repeat every 1 minute
5. Action: Start a program
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\mushroom_system\schedule_runner.php`
   - Start in: `C:\xampp\htdocs\mushroom_system\`

### Linux/Mac
Add to crontab:
```bash
* * * * * /usr/bin/php /path/to/mushroom_system/schedule_runner.php
```

## Features
- Runs every minute to check sprayer schedules
- Logs all activities to device_logs table
- Respects manual mode (won't override manual control)
- Handles duration locks properly
- Works alongside ESP32 schedule execution

## Logging
All schedule executions are logged in Device Activity Log with:
- Trigger Type: "schedule"
- Device: "sprayer"
- Action: "ON" or "OFF"
- Detail: Server schedule execution details

## Testing
Run manually: `php schedule_runner.php`
Check Device Activity Log in Automation page to see results.
