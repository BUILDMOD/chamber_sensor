# TODO List for Fixing Temperature and Humidity Display Issue

## Completed Tasks
- [x] Added CREATE TABLE IF NOT EXISTS for sensor_data table in submit_data.php to ensure the table exists with proper schema (id, temperature FLOAT, humidity FLOAT, timestamp DATETIME)
- [x] Moved include 'send_email.php' to only load when sending alerts, preventing potential errors during data fetching
- [x] Modified the query in submit_data.php to filter for non-null temperature and humidity values (WHERE temperature IS NOT NULL AND humidity IS NOT NULL)
- [x] Updated reports.php SQL queries to use STR_TO_DATE for proper timestamp comparisons (both main query and hist_sql)

## Pending Tasks
- [ ] Test the dashboard to verify temperature and humidity values are now displaying correctly
- [ ] Test the reports page to ensure data is showing properly
- [ ] Verify that new sensor data is being inserted correctly into the database
- [ ] Check for any PHP errors in logs after the changes

## Notes
- The issue was likely caused by missing sensor_data table creation, leading to query failures or null results
- PHPMailer was being loaded unnecessarily, potentially causing errors during data retrieval
- Timestamp comparisons needed proper date parsing since the column is now DATETIME
- Dashboard clamps null values to 1.0, which users might perceive as missing data
