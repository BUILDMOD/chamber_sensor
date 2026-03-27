/*
 * MushroomOS — ESP32 WROOM (Main Controller) - CLEAN VERSION
 * Board: ESP32 Dev Module (38-pin WROOM)
 *
 * Handles:
 *   ✅ DHT22 temperature & humidity → sends to submit_data.php
 *   ✅ 5 relay outputs (Mist, Fan, Heater, Sprayer, Exhaust)
 *   ✅ Auto control logic based on sensor readings
 *   ✅ Manual control from dashboard (via get_device_status.php)
 *   ✅ Buzzer activates when server detects fault/emergency
 *   ✅ I2C LCD 16x2 shows live temp & humidity
 *   ✅ Server-side fault detection forces devices OFF
 *   ✅ NTP time sync (Asia/Manila UTC+8)
 *   ✅ Sprayer schedule — ESP32-local, exact NTP timing
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include "DHT.h"
#include <LiquidCrystal_I2C.h>
#include <time.h>

// ================================================================
//  CONFIGURATION
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
#define BUZZER_PIN     26

// LCD I2C
LiquidCrystal_I2C lcd(0x27, 16, 2);

// ================================================================
//  THRESHOLDS (loaded from server)
// ================================================================

float TEMP_MIN        = 22.0;
float TEMP_MAX        = 28.0;
float HUM_MIN         = 85.0;
float HUM_MAX         = 95.0;
float EMERG_TEMP_HIGH = 35.0;
float EMERG_TEMP_LOW  = 15.0;
float EMERG_HUM_HIGH  = 98.0;

// ================================================================
//  NTP TIME SYNC
// ================================================================

#define NTP_SERVER    "pool.ntp.org"
#define UTC_OFFSET    28800   // UTC+8
bool ntpSynced = false;

// ================================================================
//  SPRAYER SCHEDULE
// ================================================================

struct SpraySlot {
  int  timeOfDay;
  int  durationSec;
  bool active;
  bool firedToday;
  unsigned long startMs;
};

#define MAX_SPRAY_SLOTS 10
SpraySlot spraySlots[MAX_SPRAY_SLOTS];
int spraySlotCount = 0;

// ================================================================
//  TIMING
// ================================================================

const unsigned long SENSOR_INTERVAL  = 5000;
const unsigned long SEND_INTERVAL    = 8000;
const unsigned long POLL_INTERVAL    = 6000;
const unsigned long WIFI_CHECK_MS    = 30000;
const unsigned long LCD_UPDATE_MS    = 2000;
const unsigned long SCHEDULE_FETCH_INTERVAL = 60000UL;

unsigned long lastSensor  = 0;
unsigned long lastSend    = 0;
unsigned long lastPoll    = 0;
unsigned long lastWiFiChk = 0;
unsigned long lastLCD     = 0;
unsigned long lastScheduleFetch = 0;
bool bootComplete = false;

// ================================================================
//  STATE VARIABLES
// ================================================================

bool manualMode = false;
bool srvMist    = false;
bool srvFan     = false;
bool srvHeater  = false;
bool srvSprayer = false;
bool srvExhaust = false;
bool srvBuzzer  = false;

float lastTemp = NAN;
float lastHum  = NAN;

// Buzzer timing
unsigned long buzzerStart = 0;
bool buzzerActive = false;
const unsigned long BUZZER_BEEP_MS = 30000UL;

// ================================================================
//  ENDPOINTS
// ================================================================

String ENDPOINT_SUBMIT;
String ENDPOINT_STATUS;
String ENDPOINT_SCHEDULES;

DHT dht(DHTPIN, DHTTYPE);

// ================================================================
//  RELAY STATE TRACKING
// ================================================================

bool prevMist    = false;
bool prevFan     = false;
bool prevHeater  = false;
bool prevSprayer = false;
bool prevExhaust = false;

// ================================================================
//  WIFI CONNECTION
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
  configTime(UTC_OFFSET, 0, NTP_SERVER);
  Serial.print("[NTP] Syncing time");
  struct tm ti;
  int tries = 0;
  while (!getLocalTime(&ti) && tries < 20) { delay(500); Serial.print("."); tries++; }
  if (getLocalTime(&ti)) {
    ntpSynced = true;
    Serial.printf("\n[NTP] Time synced: %02d:%02d:%02d\n", ti.tm_hour, ti.tm_min, ti.tm_sec);
  } else {
    Serial.println("\n[NTP] Sync failed");
  }
}

// ================================================================
//  FETCH SPRAYER SCHEDULES
// ================================================================

void fetchSpraySchedules() {
  if (WiFi.status() != WL_CONNECTED) return;
  
  HTTPClient http;
  http.begin(ENDPOINT_SCHEDULES);
  http.setTimeout(5000);
  int code = http.GET();
  if (code != 200) { http.end(); return; }

  String payload = http.getString();
  http.end();

  StaticJsonDocument<1024> doc;
  if (deserializeJson(doc, payload)) return;

  spraySlotCount = 0;
  JsonArray arr = doc["schedules"].as<JsonArray>();
  for (JsonObject s : arr) {
    if (spraySlotCount >= MAX_SPRAY_SLOTS) break;
    spraySlots[spraySlotCount].timeOfDay   = s["time_of_day"].as<int>();
    spraySlots[spraySlotCount].durationSec = s["duration_sec"].as<int>();
    spraySlots[spraySlotCount].active      = false;
    spraySlots[spraySlotCount].firedToday  = false;
    spraySlots[spraySlotCount].startMs     = 0;
    spraySlotCount++;
  }
  Serial.printf("[Schedule] Loaded %d spray slots\n", spraySlotCount);
}

// ================================================================
//  SPRAYER SCHEDULE HANDLER
// ================================================================

void handleSprayerSchedule() {
  if (!ntpSynced || spraySlotCount == 0) return;

  // Stop if manual mode
  if (manualMode) {
    for (int i = 0; i < spraySlotCount; i++) {
      if (spraySlots[i].active) {
        spraySlots[i].active = false;
        if (WiFi.status() == WL_CONNECTED) {
          HTTPClient h;
          h.begin(String(SERVER_HOST) + String(DB_PATH) + "/update_device_status.php?sprayer=0");
          h.GET(); h.end();
        }
        Serial.println("[Spray] Stopped — Manual Mode");
      }
    }
    return;
  }

  struct tm ti;
  if (!getLocalTime(&ti)) return;

  int nowSeconds = ti.tm_hour * 3600 + ti.tm_min * 60 + ti.tm_sec;

  // Reset at midnight
  if (ti.tm_hour == 0 && ti.tm_min == 0 && ti.tm_sec < 10) {
    for (int i = 0; i < spraySlotCount; i++) spraySlots[i].firedToday = false;
  }

  for (int i = 0; i < spraySlotCount; i++) {
    SpraySlot &slot = spraySlots[i];

    // Start spray
    if (!slot.active && !slot.firedToday &&
        nowSeconds >= slot.timeOfDay &&
        nowSeconds < slot.timeOfDay + 5) {
      slot.active     = true;
      slot.firedToday = true;
      slot.startMs    = millis();
      if (WiFi.status() == WL_CONNECTED) {
        HTTPClient h;
        h.begin(String(SERVER_HOST) + String(DB_PATH) + "/update_device_status.php?sprayer=1");
        h.GET(); h.end();
      }
      Serial.printf("[Spray] Slot %d ON — %d sec\n", i, slot.durationSec);
    }

    // Stop spray
    if (slot.active && (millis() - slot.startMs >= (unsigned long)slot.durationSec * 1000UL)) {
      slot.active = false;
      if (WiFi.status() == WL_CONNECTED) {
        HTTPClient h;
        h.begin(String(SERVER_HOST) + String(DB_PATH) + "/update_device_status.php?sprayer=0");
        h.GET(); h.end();
      }
      Serial.printf("[Spray] Slot %d OFF\n", i);
    }
  }
}

// ================================================================
//  SENSOR READING
// ================================================================

void readSensors() {
  float t = dht.readTemperature();
  float h = dht.readHumidity();
  if (!isnan(t)) lastTemp = t;
  if (!isnan(h)) lastHum  = h;
  Serial.printf("[Sensor] Temp: %.1f°C  Hum: %.1f%%\n", lastTemp, lastHum);
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
    lcd.print((char)223);
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
//  SEND SENSOR DATA
// ================================================================

void sendToServer() {
  if (WiFi.status() != WL_CONNECTED) return;
  if (isnan(lastTemp) || isnan(lastHum)) return;

  HTTPClient http;
  http.begin(ENDPOINT_SUBMIT);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.setTimeout(5000);

  String body = "temperature=" + String(lastTemp, 1)
              + "&humidity="   + String(lastHum, 1);

  int code = http.POST(body);
  Serial.printf("[HTTP] submit_data → %d\n", code);
  http.end();
}

// ================================================================
//  POLL DEVICE STATUS
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
      bool justSwitchedToManual = (!prevManual && newManual && bootComplete);

      manualMode = newManual;
      srvMist    = doc["mist"].as<int>()    == 1;
      srvFan     = doc["fan"].as<int>()     == 1;
      srvHeater  = doc["heater"].as<int>()  == 1;
      srvSprayer = doc["sprayer"].as<int>() == 1;
      srvExhaust = doc["exhaust"].as<int>() == 1;
      srvBuzzer  = doc["buzzer"].as<int>()  == 1;

      // Sync thresholds
      if (doc.containsKey("temp_min"))        TEMP_MIN        = doc["temp_min"].as<float>();
      if (doc.containsKey("temp_max"))        TEMP_MAX        = doc["temp_max"].as<float>();
      if (doc.containsKey("hum_min"))         HUM_MIN         = doc["hum_min"].as<float>();
      if (doc.containsKey("hum_max"))         HUM_MAX         = doc["hum_max"].as<float>();
      if (doc.containsKey("emerg_temp_high")) EMERG_TEMP_HIGH = doc["emerg_temp_high"].as<float>();
      if (doc.containsKey("emerg_temp_low"))  EMERG_TEMP_LOW  = doc["emerg_temp_low"].as<float>();
      if (doc.containsKey("emerg_hum_high"))  EMERG_HUM_HIGH  = doc["emerg_hum_high"].as<float>();

      // Buzzer control
      if (srvBuzzer && !buzzerActive) {
        buzzerActive = true;
        buzzerStart  = millis();
        Serial.println("[FAULT] Buzzer activated by server!");
      }
      if (!srvBuzzer && buzzerActive) {
        buzzerActive = false;
        noTone(BUZZER_PIN);
        Serial.println("[FAULT] Buzzer cleared by server.");
      }

      if (justSwitchedToManual) {
        Serial.printf("[MANUAL] Switched to manual\n");
      }
      bootComplete = true;
    }
  }
  http.end();
}

// ================================================================
//  RELAY CONTROL
// ================================================================

void applyRelays(bool mist, bool fan, bool heater, bool sprayer, bool exhaust) {
  if (mist    != prevMist)    { digitalWrite(RELAY_MIST,    mist    ? LOW : HIGH); prevMist    = mist;    }
  if (fan     != prevFan)     { digitalWrite(RELAY_FAN,     fan     ? LOW : HIGH); prevFan     = fan;     }
  if (heater  != prevHeater)  { digitalWrite(RELAY_HEATER,  heater  ? LOW : HIGH); prevHeater  = heater;  }
  if (sprayer != prevSprayer) { digitalWrite(RELAY_SPRAYER, sprayer ? LOW : HIGH); prevSprayer = sprayer; }
  if (exhaust != prevExhaust) { digitalWrite(RELAY_EXHAUST, exhaust ? LOW : HIGH); prevExhaust = exhaust; }
}

// ================================================================
//  AUTO CONTROL
// ================================================================

void autoControl(unsigned long now) {
  if (isnan(lastTemp) || isnan(lastHum)) return;

  // Follow server states
  applyRelays(srvMist, srvFan, srvHeater, srvSprayer, srvExhaust);

  // Local emergency fallback
  bool emergency = (lastTemp < EMERG_TEMP_LOW || lastTemp > EMERG_TEMP_HIGH || lastHum > EMERG_HUM_HIGH);
  if (emergency && !buzzerActive) {
    tone(BUZZER_PIN, 3000);
    Serial.println("[LOCAL EMERGENCY] Sensor out of range!");
  } else if (!emergency && !buzzerActive) {
    noTone(BUZZER_PIN);
  }
}

// ================================================================
//  MANUAL CONTROL
// ================================================================

void manualControl() {
  applyRelays(srvMist, srvFan, srvHeater, srvSprayer, srvExhaust);
}

// ================================================================
//  BUZZER HANDLER
// ================================================================

void handleBuzzer(unsigned long now) {
  if (!buzzerActive) return;
  if (now - buzzerStart >= BUZZER_BEEP_MS) {
    buzzerActive = false;
    noTone(BUZZER_PIN);
    return;
  }
  unsigned long elapsed = (now - buzzerStart) % 500UL;
  if (elapsed < 300) tone(BUZZER_PIN, 2500);
  else               noTone(BUZZER_PIN);
}

// ================================================================
//  BOOT RESET
// ================================================================

void resetServerDevices() {
  if (WiFi.status() != WL_CONNECTED) return;
  Serial.println("[Boot] Resetting device states...");

  HTTPClient h;
  h.begin(String(SERVER_HOST) + String(DB_PATH) +
          "/update_device_status.php?mode=0&mist=0&fan=0&heater=0&sprayer=0&exhaust=0");
  h.setTimeout(5000);
  int code = h.GET();
  Serial.printf("[Boot] Reset → HTTP %d\n", code);
  h.end();

  // Reset local states
  srvMist = srvFan = srvHeater = srvSprayer = srvExhaust = srvBuzzer = false;
  manualMode = false;
}

// ================================================================
//  SETUP
// ================================================================

void setup() {
  // Relay init first
  pinMode(RELAY_MIST,    OUTPUT); digitalWrite(RELAY_MIST,    HIGH);
  pinMode(RELAY_FAN,     OUTPUT); digitalWrite(RELAY_FAN,     HIGH);
  pinMode(RELAY_HEATER,  OUTPUT); digitalWrite(RELAY_HEATER,  HIGH);
  pinMode(RELAY_SPRAYER, OUTPUT); digitalWrite(RELAY_SPRAYER, HIGH);
  pinMode(RELAY_EXHAUST, OUTPUT); digitalWrite(RELAY_EXHAUST, HIGH);
  pinMode(BUZZER_PIN,    OUTPUT); noTone(BUZZER_PIN);

  Serial.begin(115200);
  delay(500);

  // LCD init
  lcd.init();
  lcd.backlight();
  lcd.setCursor(0, 0); lcd.print("MushroomOS");
  lcd.setCursor(0, 1); lcd.print("Starting...");
  delay(1500);

  ENDPOINT_SUBMIT    = String(SERVER_HOST) + DB_PATH + "/submit_data.php";
  ENDPOINT_STATUS    = String(SERVER_HOST) + DB_PATH + "/get_device_status.php";
  ENDPOINT_SCHEDULES = String(SERVER_HOST) + DB_PATH + "/get_spray_schedules.php";

  Serial.println("\n=== MushroomOS ESP32 Controller ===");
  dht.begin();
  connectWiFi();
  resetServerDevices();
  syncNTP();
  fetchSpraySchedules();

  Serial.println("[Setup] Ready!");
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print("System Ready!");
  delay(1000);
}

// ================================================================
//  MAIN LOOP
// ================================================================

void loop() {
  unsigned long now = millis();

  // WiFi check
  if (now - lastWiFiChk > WIFI_CHECK_MS) {
    lastWiFiChk = now;
    if (WiFi.status() != WL_CONNECTED) {
      Serial.println("[WiFi] Reconnecting...");
      WiFi.disconnect();
      WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
      delay(3000);
    }
  }

  // Read sensors
  if (now - lastSensor > SENSOR_INTERVAL) {
    lastSensor = now;
    readSensors();
  }

  // Send data
  if (now - lastSend > SEND_INTERVAL) {
    lastSend = now;
    sendToServer();
  }

  // Poll server
  if (now - lastPoll > POLL_INTERVAL) {
    lastPoll = now;
    pollServer();
  }

  // Update LCD
  if (now - lastLCD > LCD_UPDATE_MS) {
    lastLCD = now;
    updateLCD();
  }

  // Fetch schedules
  if (now - lastScheduleFetch > SCHEDULE_FETCH_INTERVAL) {
    lastScheduleFetch = now;
    fetchSpraySchedules();
  }

  // Handle sprayer
  handleSprayerSchedule();

  // Apply relay control
  if (bootComplete) {
    if (manualMode) manualControl();
    else            autoControl(now);
  }

  // Handle buzzer
  handleBuzzer(now);
}
