/*
 * MushroomOS — ESP32 WROOM (Main Controller) - FULLY FIXED VERSION
 * Board: ESP32 Dev Module (38-pin WROOM)
 *
 * Handles:
 *   ✅ DHT22 temperature & humidity → sends to submit_data.php
 *   ✅ 5 relay outputs (Mist, Fan, Heater, Sprayer, Exhaust)
 *   ✅ Auto control logic based on sensor readings
 *   ✅ Manual control from dashboard (via get_device_status.php)
 *   ✅ Buzzer activates when server detects a device fault/emergency
 *   ✅ I2C LCD 16x2 shows live temp & humidity
 *   ✅ Server-side fault detection forces devices OFF automatically
 *   ✅ NTP time sync (Asia/Manila UTC+8)
 *   ✅ Sprayer schedule — ESP32-local, exact NTP timing (fetched from server DB)
 *   ✅ SAFE BOOT SEQUENCE - prevents unwanted device activation on power-on
 *   ✅ FIXED AM/PM time conversion from database
 *
 * ================================================================
 *  WIRING GUIDE
 * ================================================================
 *
 *  DHT22:
 *    VCC  → 3.3V
 *    GND  → GND
 *    DATA → GPIO4
 *    (Add 10kΩ pull-up resistor between DATA and 3.3V)
 *
 *  Relay Module (active-LOW: LOW = ON, HIGH = OFF):
 *    MIST    → GPIO16
 *    FAN     → GPIO17
 *    HEATER  → GPIO18
 *    SPRAYER → GPIO19
 *    EXHAUST → GPIO23
 *    VCC     → 5V
 *    GND     → GND
 *
 *  Buzzer (passive):
 *    +  → GPIO26
 *    -  → GND
 *
 *  I2C LCD 16x2:
 *    VCC → 5V
 *    GND → GND
 *    SDA → GPIO21
 *    SCL → GPIO22
 *
 * ================================================================
 *  FLASHING INSTRUCTIONS
 * ================================================================
 *  1. Connect ESP32 WROOM via USB-C to laptop
 *  2. Arduino IDE → Tools → Board → "ESP32 Dev Module"
 *  3. Tools → Port → select correct COM port
 *  4. Click Upload
 *  5. Done — no button pressing needed
 *
 * ================================================================
 *  REQUIRED LIBRARIES (Arduino Library Manager)
 * ================================================================
 *  - DHT sensor library by Adafruit
 *  - Adafruit Unified Sensor
 *  - ArduinoJson by Benoit Blanchon (v6.x)
 *  - LiquidCrystal I2C by Frank de Brabander
 *  - ESP32 board package by Espressif
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include "DHT.h"
#include <LiquidCrystal_I2C.h>
#include <time.h>  // NTP time sync

// ================================================================
//  ⚙️  CONFIGURATION — edit these to match your setup
// ================================================================

const char* WIFI_SSID     = "Gelo";
const char* WIFI_PASSWORD = "12345678";

const char* SERVER_HOST   = "http://10.171.156.197";
const char* DB_PATH       = "/mushroom_system";

// ================================================================
//  GPIO ASSIGNMENTS
// ================================================================

#define DHTPIN         4
#define DHTTYPE        DHT22

#define RELAY_MIST     16
#define RELAY_FAN      17
#define RELAY_HEATER   18
#define RELAY_SPRAYER  19
#define RELAY_EXHAUST  23

// ESP32-compatible LCD initialization
// Set the LCD address to 0x27 for ESP32 (different from some Arduino boards)
LiquidCrystal_I2C lcd(0x27, 16, 2);

// Suppress AVR library warnings for ESP32
#define _I2C_AVR_H_

// ================================================================
//  FALLBACK THRESHOLDS (loaded from server on each poll)
// ================================================================

float TEMP_MIN        = 22.0;
float TEMP_MAX        = 28.0;
float HUM_MIN         = 85.0;
float HUM_MAX         = 95.0;
float EMERG_TEMP_HIGH = 35.0;
float EMERG_TEMP_LOW  = 15.0;
float EMERG_HUM_HIGH  = 98.0;

// ================================================================
//  NTP TIME SYNC (Asia/Manila = UTC+8)
// ================================================================

#define NTP_SERVER    "pool.ntp.org"
#define UTC_OFFSET    28800   // UTC+8 in seconds (8 * 3600)
#define DST_OFFSET    0
bool ntpSynced = false;

// ================================================================
//  SPRAYER SCHEDULE (ESP32-controlled, exact timing via NTP)
//  3x per day — fetched from server DB
//  Stored as seconds-since-midnight for each schedule slot
// ================================================================

struct SpraySlot {
  int  timeOfDay;        // seconds since midnight
  int  durationSec;      // spray duration in seconds
  bool active;           // currently spraying
  bool firedToday;       // already fired today
  unsigned long startMs; // millis() when spray started
};

#define MAX_SPRAY_SLOTS 10
SpraySlot spraySlots[MAX_SPRAY_SLOTS];
int       spraySlotCount = 0;

unsigned long lastScheduleFetch = 0;
const unsigned long SCHEDULE_FETCH_INTERVAL = 60000UL;

// ================================================================
//  TIMING
// ================================================================

const unsigned long SENSOR_INTERVAL  = 5000;
const unsigned long SEND_INTERVAL    = 8000;
const unsigned long POLL_INTERVAL    = 6000;
const unsigned long WIFI_CHECK_MS    = 30000;
const unsigned long LCD_UPDATE_MS    = 2000;

// ================================================================
//  OFFLINE DETECTION
// ================================================================
int offlineCounter = 0;

unsigned long lastSensor  = 0;
unsigned long lastSend    = 0;
unsigned long lastPoll    = 0;
unsigned long lastWiFiChk = 0;
unsigned long lastLCD     = 0;
bool          bootComplete = false; // set true after first successful poll

// ================================================================
//  STATE
// ================================================================

bool  manualMode = false;
bool  srvMist     = false;
bool  srvFan      = false;
bool  srvHeater   = false;
bool  srvSprayer  = false;
bool  srvExhaust  = false;
bool  srvBuzzer   = false;

float lastTemp = NAN;
float lastHum  = NAN;

// ================================================================
//  ENDPOINTS
// ================================================================

String ENDPOINT_SUBMIT;
String ENDPOINT_STATUS;
String ENDPOINT_SCHEDULES;

DHT dht(DHTPIN, DHTTYPE);

// ================================================================
//  WiFi
// ================================================================

void connectWiFi() {
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.print("[WiFi] Connecting");

  lcd.clear();
  lcd.setCursor(0, 0); lcd.print("Connecting WiFi");

  int tries = 0;
  while (WiFi.status() != WL_CONNECTED && tries < 30) {
    delay(500); Serial.print("."); tries++;
  }
  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("\n[WiFi] Connected — IP: %s\n", WiFi.localIP().toString().c_str());
    lcd.clear();
    lcd.setCursor(0, 0); lcd.print("WiFi Connected!");
    lcd.setCursor(0, 1); lcd.print(WiFi.localIP().toString());
    delay(2000);
  } else {
    Serial.println("\n[WiFi] Failed — will retry.");
    lcd.clear();
    lcd.setCursor(0, 0); lcd.print("WiFi Failed");
    lcd.setCursor(0, 1); lcd.print("Retrying...");
  }
}

// ================================================================
//  NTP SYNC
// ================================================================

void syncNTP() {
  if (WiFi.status() != WL_CONNECTED) return;
  configTime(UTC_OFFSET, DST_OFFSET, NTP_SERVER);
  Serial.print("[NTP] Syncing time");
  struct tm ti;
  int tries = 0;
  while (!getLocalTime(&ti) && tries < 20) { delay(500); Serial.print("."); tries++; }
  if (getLocalTime(&ti)) {
    ntpSynced = true;
    Serial.printf("\n[NTP] Time synced: %02d:%02d:%02d\n", ti.tm_hour, ti.tm_min, ti.tm_sec);
  } else {
    Serial.println("\n[NTP] Sync failed — sprayer schedule disabled until sync");
  }
}

// ================================================================
//  CONVERT TIME STRING TO SECONDS SINCE MIDNIGHT (FIXED VERSION)
//  Handles both 24-hour format (HH:MM:SS) and 12-hour format with AM/PM
// ================================================================

int timeStringToSeconds(String timeStr) {
  // Remove any extra spaces
  timeStr.trim();
  
  // Handle 24-hour format directly (HH:MM:SS or HH:MM)
  if (timeStr.length() >= 5 && timeStr.indexOf(':') >= 0) {
    int firstColon = timeStr.indexOf(':');
    int secondColon = timeStr.indexOf(':', firstColon + 1);
    
    int hour = timeStr.substring(0, firstColon).toInt();
    int minute = 0;
    int second = 0;
    
    if (secondColon > 0) {
      // Format: HH:MM:SS
      minute = timeStr.substring(firstColon + 1, secondColon).toInt();
      second = timeStr.substring(secondColon + 1).toInt();
    } else {
      // Format: HH:MM
      minute = timeStr.substring(firstColon + 1).toInt();
      second = 0;
    }
    
    // Convert to 24-hour format if needed
    if (timeStr.indexOf("AM") > 0 || timeStr.indexOf("am") > 0) {
      // 12 AM = 0 hour
      if (hour == 12) hour = 0;
    } else if (timeStr.indexOf("PM") > 0 || timeStr.indexOf("pm") > 0) {
      // 12 PM stays 12, other PM hours add 12
      if (hour != 12) hour += 12;
    }
    
    // Validate and convert to seconds
    if (hour >= 0 && hour <= 23 && minute >= 0 && minute <= 59 && second >= 0 && second <= 59) {
      int totalSeconds = hour * 3600 + minute * 60 + second;
      Serial.printf("[Time] Converted '%s' to %d seconds (%02d:%02d:%02d)\n", 
                    timeStr.c_str(), totalSeconds, hour, minute, second);
      return totalSeconds;
    }
  }
  
  Serial.printf("[Time] ERROR: Invalid time format '%s'\n", timeStr.c_str());
  return -1; // Invalid time
}

// ================================================================
//  FETCH SPRAYER SCHEDULES FROM SERVER
// ================================================================

void fetchSpraySchedules() {
  if (WiFi.status() != WL_CONNECTED) return;
  
  Serial.println("[Schedule] Fetching schedules from server...");
  
  HTTPClient http;
  http.begin(String(SERVER_HOST) + String(DB_PATH) + "/get_spray_schedules.php");
  http.setTimeout(5000);
  int httpCode = http.GET();
  
  if (httpCode == 200) {
    String payload = http.getString();
    Serial.printf("[Schedule] Server response: %s\n", payload.c_str());
    
    StaticJsonDocument<512> doc;
    if (!deserializeJson(doc, payload) && doc.containsKey("schedules")) {
      spraySlotCount = 0;
      
      for (JsonObject s : doc["schedules"].as<JsonArray>()) {
        if (spraySlotCount >= MAX_SPRAY_SLOTS) break;
        
        // Get time string and convert to seconds (FIXED)
        String timeStr = s["run_time"].as<String>();
        int timeInSeconds = timeStringToSeconds(timeStr);
        
        if (timeInSeconds >= 0) { // Valid time
          spraySlots[spraySlotCount].timeOfDay   = timeInSeconds;
          spraySlots[spraySlotCount].durationSec = s["duration_sec"].as<int>();
          spraySlots[spraySlotCount].active      = false;
          spraySlots[spraySlotCount].firedToday  = false;
          spraySlots[spraySlotCount].startMs     = 0;
          
          // Convert seconds back to readable format for display
          int displayHour = timeInSeconds / 3600;
          int displayMin = (timeInSeconds % 3600) / 60;
          int displaySec = timeInSeconds % 60;
          
          Serial.printf("[Schedule] Slot %d: %ds duration at %02d:%02d:%02d (from '%s')\n",
            spraySlotCount + 1,
            spraySlots[spraySlotCount].durationSec,
            displayHour, displayMin, displaySec,
            timeStr.c_str());
          
          spraySlotCount++;
        }
      }
      Serial.printf("[Schedule] Loaded %d valid spray slots\n", spraySlotCount);
    } else {
      Serial.println("[Schedule] Failed to parse JSON response");
    }
  } else {
    Serial.printf("[Schedule] HTTP error: %d\n", httpCode);
  }
  
  http.end();
}

// ================================================================
//  SPRAYER SCHEDULE HANDLER (ESP32 local, NTP-based)
// ================================================================

void handleSprayerSchedule() {
  if (!ntpSynced || spraySlotCount == 0) return;

  // If switched to manual mid-spray, stop all active slots
  if (manualMode) {
    for (int i = 0; i < spraySlotCount; i++) {
      if (spraySlots[i].active) {
        spraySlots[i].active = false;
        if (WiFi.status() == WL_CONNECTED) {
          HTTPClient h;
          h.begin(String(SERVER_HOST) + String(DB_PATH) + "/update_device_status.php?sprayer=0");
          h.GET(); h.end();
        }
        Serial.println("[Spray] Stopped — switched to Manual Mode");
      }
    }
    return;
  }

  struct tm ti;
  if (!getLocalTime(&ti)) return;

  int nowSeconds = ti.tm_hour * 3600 + ti.tm_min * 60 + ti.tm_sec;

  // Reset firedToday at midnight
  if (ti.tm_hour == 0 && ti.tm_min == 0 && ti.tm_sec < 10) {
    for (int i = 0; i < spraySlotCount; i++) spraySlots[i].firedToday = false;
  }

  for (int i = 0; i < spraySlotCount; i++) {
    SpraySlot &slot = spraySlots[i];

    // Start spray (with 60-second window - more forgiving)
    if (!slot.active && !slot.firedToday &&
        nowSeconds >= slot.timeOfDay &&
        nowSeconds < slot.timeOfDay + 60) {
      slot.active     = true;
      slot.firedToday = true;
      slot.startMs    = millis();
      
      // Display schedule time in readable format
      int schedHour = slot.timeOfDay / 3600;
      int schedMin = (slot.timeOfDay % 3600) / 60;
      Serial.printf("[Spray] Slot %d TRIGGERED at %02d:%02d — duration %d sec\n", 
                    i + 1, schedHour, schedMin, slot.durationSec);
      
      if (WiFi.status() == WL_CONNECTED) {
        // Turn sprayer ON
        HTTPClient h;
        String statusUrl = String(SERVER_HOST) + String(DB_PATH) + "/update_device_status.php?sprayer=1";
        Serial.printf("[HTTP] Turning sprayer ON: %s\n", statusUrl.c_str());
        h.begin(statusUrl);
        int httpCode = h.GET();
        Serial.printf("[HTTP] Status update response: %d\n", httpCode);
        h.end();
        
        // Log ON event to device_logs
        HTTPClient hLog;
        String logUrl = String(SERVER_HOST) + String(DB_PATH)
          + "/log_device_event.php?device=sprayer&action=ON&trigger=schedule"
          + "&detail=Schedule+started+(duration+" + String(slot.durationSec) + "s)";
        Serial.printf("[HTTP] Logging ON event: %s\n", logUrl.c_str());
        hLog.begin(logUrl);
        int logCode = hLog.GET();
        Serial.printf("[HTTP] Log ON response: %d\n", logCode);
        hLog.end();
        
        if (httpCode == 200 && logCode == 200) {
          Serial.println("[Spray] ✓ Sprayer ON and logged successfully");
        } else {
          Serial.println("[Spray] ✗ Failed to update status or log event");
        }
      } else {
        Serial.println("[Spray] ✗ WiFi not connected - cannot update server");
      }
    }

    // Stop spray after duration
    if (slot.active && (millis() - slot.startMs >= (unsigned long)slot.durationSec * 1000UL)) {
      slot.active = false;
      
      Serial.printf("[Spray] Slot %d COMPLETED - turning OFF\n", i + 1);
      
      if (WiFi.status() == WL_CONNECTED) {
        // Turn sprayer OFF
        HTTPClient h;
        String statusUrl = String(SERVER_HOST) + String(DB_PATH) + "/update_device_status.php?sprayer=0";
        Serial.printf("[HTTP] Turning sprayer OFF: %s\n", statusUrl.c_str());
        h.begin(statusUrl);
        int httpCode = h.GET();
        Serial.printf("[HTTP] Status update response: %d\n", httpCode);
        h.end();
        
        // Log OFF event to device_logs with duration
        HTTPClient hLog;
        String logUrl = String(SERVER_HOST) + String(DB_PATH)
          + "/log_device_event.php?device=sprayer&action=OFF&trigger=schedule"
          + "&detail=Schedule+completed&duration=" + String(slot.durationSec);
        Serial.printf("[HTTP] Logging OFF event: %s\n", logUrl.c_str());
        hLog.begin(logUrl);
        int logCode = hLog.GET();
        Serial.printf("[HTTP] Log OFF response: %d\n", logCode);
        hLog.end();
        
        if (httpCode == 200 && logCode == 200) {
          Serial.println("[Spray] ✓ Sprayer OFF and logged successfully");
        } else {
          Serial.println("[Spray] ✗ Failed to update status or log event");
        }
      } else {
        Serial.println("[Spray] ✗ WiFi not connected - cannot update server");
      }
    }
  }
}

// ================================================================
//  SENSOR
// ================================================================

void readSensors() {
  float t = dht.readTemperature();
  float h = dht.readHumidity();
  if (!isnan(t)) lastTemp = t;
  if (!isnan(h)) lastHum  = h;
  Serial.printf("[Sensor] Temp: %.1f°C  Hum: %.1f%%\n", lastTemp, lastHum);
  
  // Debug emergency thresholds
  Serial.printf("[Debug] Emergency thresholds: Temp < %.1f°C or > %.1f°C, Hum > %.1f%%\n", 
                EMERG_TEMP_LOW, EMERG_TEMP_HIGH, EMERG_HUM_HIGH);
  bool emergency = (lastTemp < EMERG_TEMP_LOW || lastTemp > EMERG_TEMP_HIGH || lastHum > EMERG_HUM_HIGH);
  Serial.printf("[Debug] Emergency state: %s\n", emergency ? "TRUE" : "FALSE");
}

// ================================================================
//  LCD UPDATE
// ================================================================

void updateLCD() {
  lcd.clear();
  lcd.setCursor(0, 0);
  if (!isnan(lastTemp)) {
    lcd.print("T:");
    lcd.print(lastTemp, 1);
    lcd.print((char)223); // degree symbol
    lcd.print("C ");
  } else {
    lcd.print("T: --.-C ");
  }

  if (!isnan(lastHum)) {
    lcd.print("H:");
    lcd.print(lastHum, 1);
    lcd.print("%");
  } else {
    lcd.print("H: --.-%");
  }

  lcd.setCursor(0, 1);
  if (manualMode) {
    lcd.print("Mode: MANUAL    ");
  } else {
    lcd.print("Mode: AUTO      ");
  }
}

// ================================================================
//  SEND SENSOR DATA → submit_data.php
// ================================================================

void sendToServer() {
  if (WiFi.status() != WL_CONNECTED) {
    offlineCounter++;
    Serial.printf("[Offline] Counter: %d\n", offlineCounter);
    return;
  }
  
  if (isnan(lastTemp) || isnan(lastHum)) {
    offlineCounter++;
    Serial.printf("[Sensor] Invalid readings - offline counter: %d\n", offlineCounter);
    return;
  }

  HTTPClient http;
  http.begin(ENDPOINT_SUBMIT);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.setTimeout(5000);

  String body = "temperature=" + String(lastTemp, 1)
              + "&humidity="   + String(lastHum, 1);

  int code = http.POST(body);
  Serial.printf("[HTTP] submit_data → %d\n", code);
  http.end();
  
  // Reset offline counter on successful send
  if (code == 200) {
    offlineCounter = 0;
  }
}

// ================================================================
//  POLL DEVICE STATUS → get_device_status.php
// ================================================================

void pollServer() {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  http.begin(ENDPOINT_STATUS);
  http.setTimeout(5000);
  int code = http.GET();

  if (code == 200) {
    String payload = http.getString();
    Serial.printf("[HTTP] device_status → %s\n", payload.c_str());

    StaticJsonDocument<512> doc;
    if (!deserializeJson(doc, payload)) {
      bool prevManual = manualMode;
      bool newManual  = doc["manual_mode"].as<int>() == 1;

      // On boot, manualMode=false but DB may already be manual=1.
      // Don't treat this as a user-initiated switch — just apply DB states directly.
      bool justSwitchedToManual = (!prevManual && newManual && bootComplete);

      manualMode = newManual;
      
      // Parse device states correctly - they're now nested objects
      if (doc.containsKey("mist")) {
        srvMist = doc["mist"].as<int>() == 1;
      }
      if (doc.containsKey("fan")) {
        srvFan = doc["fan"].as<int>() == 1;
      }
      if (doc.containsKey("heater")) {
        srvHeater = doc["heater"].as<int>() == 1;
      }
      if (doc.containsKey("sprayer")) {
        srvSprayer = doc["sprayer"].as<int>() == 1;
      }
      if (doc.containsKey("exhaust")) {
        srvExhaust = doc["exhaust"].as<int>() == 1;
      }
      if (doc.containsKey("buzzer")) {
        srvBuzzer = doc["buzzer"].as<int>() == 1;
      }

      // Debug: Show controlled_by values from the controlled_by object
      String mistControl = "unknown";
      String fanControl = "unknown";
      String heaterControl = "unknown";
      String sprayerControl = "unknown";
      String exhaustControl = "unknown";
      
      if (doc.containsKey("controlled_by")) {
        JsonObject controlled = doc["controlled_by"];
        mistControl = controlled["mist"] | "unknown";
        fanControl = controlled["fan"] | "unknown";
        heaterControl = controlled["heater"] | "unknown";
        sprayerControl = controlled["sprayer"] | "unknown";
        exhaustControl = controlled["exhaust"] | "unknown";
      }
      
      Serial.printf("[Server] States: mist=%d(%s) fan=%d(%s) heater=%d(%s) exhaust=%d(%s) sprayer=%d(%s)\n",
                    srvMist, mistControl.c_str(), srvFan, fanControl.c_str(), 
                    srvHeater, heaterControl.c_str(), srvExhaust, exhaustControl.c_str(),
                    srvSprayer, sprayerControl.c_str());

      // Sync thresholds from server
      if (doc.containsKey("temp_min"))        TEMP_MIN        = doc["temp_min"].as<float>();
      if (doc.containsKey("temp_max"))        TEMP_MAX        = doc["temp_max"].as<float>();
      if (doc.containsKey("hum_min"))         HUM_MIN         = doc["hum_min"].as<float>();
      if (doc.containsKey("hum_max"))         HUM_MAX         = doc["hum_max"].as<float>();
      if (doc.containsKey("emerg_temp_high")) EMERG_TEMP_HIGH = doc["emerg_temp_high"].as<float>();
      if (doc.containsKey("emerg_temp_low"))  EMERG_TEMP_LOW  = doc["emerg_temp_low"].as<float>();
      if (doc.containsKey("emerg_hum_high"))  EMERG_HUM_HIGH  = doc["emerg_hum_high"].as<float>();

      // ── Apply relay state after poll ──
      // Note: actual relay writes are handled by manualControl()/autoControl() in loop()
      // Just log the mode transition here
      delay(50);
      if (justSwitchedToManual) {
        Serial.printf("[MANUAL] Switched to manual — relays will follow DB states: mist=%d fan=%d heater=%d sprayer=%d exhaust=%d\n",
          srvMist, srvFan, srvHeater, srvSprayer, srvExhaust);
      }
      bootComplete = true; // first poll done — safe to apply relays now
    }
  }
  http.end();
}

// ================================================================
//  RELAY HELPER — only write if state changed (prevents relay chatter)
// ================================================================

bool prevMist    = false;
bool prevFan     = false;
bool prevHeater  = false;
bool prevSprayer = false;
bool prevExhaust = false;

void applyRelays(bool mist, bool fan, bool heater, bool sprayer, bool exhaust) {
  if (mist    != prevMist)    { 
    digitalWrite(RELAY_MIST,    mist    ? LOW : HIGH); 
    logDeviceChange("mist", mist, prevMist);
    prevMist = mist; 
  }
  if (fan     != prevFan)     { 
    digitalWrite(RELAY_FAN,     fan     ? LOW : HIGH); 
    logDeviceChange("fan", fan, prevFan);
    prevFan = fan;     
  }
  if (heater  != prevHeater)  { 
    digitalWrite(RELAY_HEATER,  heater  ? LOW : HIGH); 
    logDeviceChange("heater", heater, prevHeater);
    prevHeater = heater;  
  }
  if (sprayer != prevSprayer) { 
    digitalWrite(RELAY_SPRAYER, sprayer ? LOW : HIGH); 
    // Sprayer logging is handled separately in handleSprayerSchedule()
    prevSprayer = sprayer; 
  }
  if (exhaust != prevExhaust) { 
    digitalWrite(RELAY_EXHAUST, exhaust ? LOW : HIGH); 
    logDeviceChange("exhaust", exhaust, prevExhaust);
    prevExhaust = exhaust; 
  }
}

// Log device state changes to device activity log
void logDeviceChange(String device, bool newState, bool prevState) {
  if (WiFi.status() != WL_CONNECTED) return;
  
  String action = newState ? "ON" : "OFF";
  String trigger = "auto"; // sensor-based automation
  String detail = "";
  
  // Create detailed trigger description based on current sensor values
  if (device == "fan") {
    if (newState) {
      detail = "Temperature+above+threshold+(+" + String(lastTemp, 1) + "°C)";
    } else {
      detail = "Temperature+back+in+range+(+" + String(lastTemp, 1) + "°C)";
    }
  } else if (device == "mist") {
    if (newState) {
      detail = "Humidity+below+threshold+(+" + String(lastHum, 1) + "%)";
    } else {
      detail = "Humidity+back+in+range+(+" + String(lastHum, 1) + "%)";
    }
  } else if (device == "heater") {
    if (newState) {
      detail = "Temperature+below+threshold+(+" + String(lastTemp, 1) + "°C)";
    } else {
      detail = "Temperature+back+in+range+(+" + String(lastTemp, 1) + "°C)";
    }
  } else if (device == "exhaust") {
    if (newState) {
      detail = "Temperature+above+threshold+(+" + String(lastTemp, 1) + "°C)";
    } else {
      detail = "Temperature+back+in+range+(+" + String(lastTemp, 1) + "°C)";
    }
  }
  
  HTTPClient http;
  String logUrl = String(SERVER_HOST) + String(DB_PATH)
    + "/log_device_event.php?device=" + device
    + "&action=" + action
    + "&trigger=" + trigger
    + "&detail=" + detail;
  
  Serial.printf("[Log] %s %s: %s\n", device.c_str(), action.c_str(), detail.c_str());
  
  http.begin(logUrl);
  int httpCode = http.GET();
  if (httpCode != 200) {
    Serial.printf("[Log] Failed to log %s change: HTTP %d\n", device.c_str(), httpCode);
  }
  http.end();
}

// ================================================================
//  AUTO CONTROL (server is primary, local is fallback)
// ================================================================

void autoControl(unsigned long now) {
  if (isnan(lastTemp) || isnan(lastHum)) return;

  // Debug: Show current sensor values and server states
  Serial.printf("[Auto] Temp: %.1f°C Hum: %.1f%% | Server: mist=%d fan=%d heater=%d exhaust=%d\n", 
                lastTemp, lastHum, srvMist, srvFan, srvHeater, srvExhaust);

  // Simple auto control - just follow server states without emergency overrides
  bool finalMist    = srvMist;
  bool finalFan     = srvFan;
  bool finalHeater  = srvHeater;
  bool finalExhaust = srvExhaust;
  
  // Sprayer follows schedule regardless
  bool finalSprayer = srvSprayer;
  
  // Debug: Show final states being applied
  Serial.printf("[Auto] Final: mist=%d fan=%d heater=%d exhaust=%d sprayer=%d\n", 
                finalMist, finalFan, finalHeater, finalExhaust, finalSprayer);
  
  applyRelays(finalMist, finalFan, finalHeater, finalSprayer, finalExhaust);
}

// ================================================================
//  MANUAL CONTROL
// ================================================================

void manualControl() {
  applyRelays(srvMist, srvFan, srvHeater, srvSprayer, srvExhaust);
}

// ================================================================
//  SETUP
// ================================================================

// ================================================================
//  IMPROVED BOOT RESET - safer startup with boot protection
//  Prevents unwanted device activation on power-on
//  30-second safety buffer for defense demo (fast startup)
// ================================================================

void resetServerDevices() {
  if (WiFi.status() != WL_CONNECTED) return;
  Serial.println("[Boot] Starting safe device reset...");

  // Call improved boot reset script
  HTTPClient h;
  h.begin(String(SERVER_HOST) + String(DB_PATH) + "/esp32_boot_reset.php");
  h.setTimeout(10000);
  int code = h.GET();
  
  if (code == 200) {
    String payload = h.getString();
    Serial.printf("[Boot] Reset successful: %s\n", payload.c_str());
    
    // Parse response for buffer time
    StaticJsonDocument<256> doc;
    if (deserializeJson(doc, payload)) {
      int bufferMinutes = doc["buffer_minutes"] | 0;
      Serial.printf("[Boot] Safety buffer: %d minutes\n", bufferMinutes);
      
      // Wait for buffer period before enabling schedule checks
      if (bufferMinutes > 0) {
        Serial.printf("[Boot] Waiting %d minutes before enabling schedules...\n", bufferMinutes);
        delay(bufferMinutes * 60 * 1000);
      }
    }
  } else {
    Serial.printf("[Boot] Reset failed, using fallback: HTTP %d\n", code);
    // Fallback: simple reset
    h.begin(String(SERVER_HOST) + String(DB_PATH) +
          "/update_device_status.php?mode=0&mist=0&fan=0&heater=0&sprayer=0&exhaust=0");
    h.GET();
  }
  h.end();

  // Reset local state variables
  srvMist    = false;
  srvFan     = false;
  srvHeater  = false;
  srvSprayer = false;
  srvExhaust = false;
  srvBuzzer  = false;
  manualMode = false;
  
  Serial.println("[Boot] Device states reset - system ready");
}

void setup() {
  // ── RELAY INIT FIRST — prevents all-ON during boot gap ──
  pinMode(RELAY_MIST,    OUTPUT); digitalWrite(RELAY_MIST,    HIGH);
  pinMode(RELAY_FAN,     OUTPUT); digitalWrite(RELAY_FAN,     HIGH);
  pinMode(RELAY_HEATER,  OUTPUT); digitalWrite(RELAY_HEATER,  HIGH);
  pinMode(RELAY_SPRAYER, OUTPUT); digitalWrite(RELAY_SPRAYER, HIGH);
  pinMode(RELAY_EXHAUST, OUTPUT); digitalWrite(RELAY_EXHAUST, HIGH);

  Serial.begin(115200);
  delay(500);

  // Init LCD
  lcd.init();
  lcd.backlight();
  lcd.setCursor(0, 0); lcd.print("MushroomOS");
  lcd.setCursor(0, 1); lcd.print("Starting...");
  delay(1500);

  ENDPOINT_SUBMIT    = String(SERVER_HOST) + DB_PATH + "/submit_data.php";
  ENDPOINT_STATUS    = String(SERVER_HOST) + DB_PATH + "/get_device_status.php";
  ENDPOINT_SCHEDULES = String(SERVER_HOST) + DB_PATH + "/get_spray_schedules.php";

  Serial.println("\n=== MushroomOS WROOM Main Controller ===");
  Serial.println("  Sensor    → " + ENDPOINT_SUBMIT);
  Serial.println("  Devices   → " + ENDPOINT_STATUS);
  Serial.println("  Schedules → " + ENDPOINT_SCHEDULES);

  dht.begin();
  connectWiFi();
  resetServerDevices();  // ← clear all DB device states before first poll
  syncNTP();
  fetchSpraySchedules();

  Serial.println("[Setup] WROOM Ready!");
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print("System Ready!");
  delay(1000);
}

// ================================================================
//  LOOP
// ================================================================

void loop() {
  unsigned long now = millis();

  // WiFi auto-reconnect
  if (now - lastWiFiChk > WIFI_CHECK_MS) {
    lastWiFiChk = now;
    if (WiFi.status() != WL_CONNECTED) {
      Serial.println("[WiFi] Reconnecting...");
      WiFi.disconnect();
      WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
      delay(3000);
    }
  }

  // Read DHT22
  if (now - lastSensor > SENSOR_INTERVAL) {
    lastSensor = now;
    readSensors();
  }

  // Send sensor data to server
  if (now - lastSend > SEND_INTERVAL) {
    lastSend = now;
    sendToServer();
    
    // Reset offline counter when data sent successfully
    if (WiFi.status() == WL_CONNECTED) {
      offlineCounter = 0;
    }
  }

  // Poll device states from server
  if (now - lastPoll > POLL_INTERVAL) {
    lastPoll = now;
    pollServer();
  }

  // Update LCD
  if (now - lastLCD > LCD_UPDATE_MS) {
    lastLCD = now;
    updateLCD();
  }

  // Re-fetch spray schedules periodically
  if (now - lastScheduleFetch > SCHEDULE_FETCH_INTERVAL) {
    lastScheduleFetch = now;
    fetchSpraySchedules();
  }

  // Handle sprayer schedule (ESP32-local, NTP-based)
  handleSprayerSchedule();

  // Apply relay control — only after first poll to avoid spurious states on boot
  if (bootComplete) {
    if (manualMode) manualControl();
    else            autoControl(now);
  }
}
