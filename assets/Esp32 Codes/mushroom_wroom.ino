/*
 * MushroomOS — ESP32 WROOM (Main Controller)
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

// ================================================================
//  ⚙️  CONFIGURATION — edit these to match your setup
// ================================================================

const char* WIFI_SSID     = "Gelo";
const char* WIFI_PASSWORD = "12345678";

const char* SERVER_HOST   = "http://10.101.168.197";
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

// LCD I2C address — try 0x27 first, if blank screen try 0x3F
LiquidCrystal_I2C lcd(0x27, 16, 2);

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
//  SPRAYER SCHEDULE
// ================================================================

const unsigned long SPRAY_INTERVAL = 8UL * 60UL * 60UL * 1000UL; // every 8 hours
const unsigned long SPRAY_DURATION = 15UL * 1000UL;               // 15 seconds
unsigned long lastSpray = 0;
bool          spraying  = false;

// ================================================================
//  TIMING
// ================================================================

const unsigned long SENSOR_INTERVAL  = 5000;
const unsigned long SEND_INTERVAL    = 8000;
const unsigned long POLL_INTERVAL    = 6000;
const unsigned long WIFI_CHECK_MS    = 30000;
const unsigned long LCD_UPDATE_MS    = 2000;

unsigned long lastSensor  = 0;
unsigned long lastSend    = 0;
unsigned long lastPoll    = 0;
unsigned long lastWiFiChk = 0;
unsigned long lastLCD     = 0;

// ================================================================
//  STATE
// ================================================================

bool  manualMode = false;
bool  srvMist    = false;
bool  srvFan     = false;
bool  srvHeater  = false;
bool  srvSprayer = false;
bool  srvExhaust = false;
bool  srvBuzzer  = false;

float lastTemp = NAN;
float lastHum  = NAN;

// ── Buzzer timing (non-blocking) ──
unsigned long buzzerStart = 0;
bool          buzzerActive = false;
const unsigned long BUZZER_BEEP_MS = 30000UL;

// ================================================================
//  ENDPOINTS
// ================================================================

String ENDPOINT_SUBMIT;
String ENDPOINT_STATUS;

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
//  SENSOR
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
  if (WiFi.status() != WL_CONNECTED) return;
  if (isnan(lastTemp) || isnan(lastHum)) return;

  HTTPClient http;
  http.begin(ENDPOINT_SUBMIT);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.setTimeout(5000);

  String body = "temperature=" + String(lastTemp, 2)
              + "&humidity="   + String(lastHum,  2);

  int code = http.POST(body);
  Serial.printf("[HTTP] submit_data → %d\n", code);
  http.end();
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

    StaticJsonDocument<256> doc;
    if (!deserializeJson(doc, payload)) {
      manualMode = doc["manual_mode"].as<int>() == 1;
      srvMist    = doc["mist"].as<int>()    == 1;
      srvFan     = doc["fan"].as<int>()     == 1;
      srvHeater  = doc["heater"].as<int>()  == 1;
      srvSprayer = doc["sprayer"].as<int>() == 1;
      srvExhaust = doc["exhaust"].as<int>() == 1;
      srvBuzzer  = doc["buzzer"].as<int>()  == 1;

      // Sync thresholds from server
      if (doc.containsKey("temp_min"))        TEMP_MIN        = doc["temp_min"].as<float>();
      if (doc.containsKey("temp_max"))        TEMP_MAX        = doc["temp_max"].as<float>();
      if (doc.containsKey("hum_min"))         HUM_MIN         = doc["hum_min"].as<float>();
      if (doc.containsKey("hum_max"))         HUM_MAX         = doc["hum_max"].as<float>();
      if (doc.containsKey("emerg_temp_high")) EMERG_TEMP_HIGH = doc["emerg_temp_high"].as<float>();
      if (doc.containsKey("emerg_temp_low"))  EMERG_TEMP_LOW  = doc["emerg_temp_low"].as<float>();
      if (doc.containsKey("emerg_hum_high"))  EMERG_HUM_HIGH  = doc["emerg_hum_high"].as<float>();

      // Buzzer from server fault signal
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
    }
  }
  http.end();
}

// ================================================================
//  SPRAYER SCHEDULE
// ================================================================

void sprayerSchedule(unsigned long now) {
  if (!spraying && (now - lastSpray >= SPRAY_INTERVAL)) {
    spraying  = true;
    lastSpray = now;
    digitalWrite(RELAY_SPRAYER, LOW);
    Serial.println("[Auto] Sprayer ON (scheduled)");
  }
  if (spraying && (now - lastSpray >= SPRAY_DURATION)) {
    spraying = false;
    digitalWrite(RELAY_SPRAYER, HIGH);
    Serial.println("[Auto] Sprayer OFF");
  }
}

// ================================================================
//  BUZZER HANDLER (non-blocking beep pattern)
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
//  AUTO CONTROL (server is primary, local is fallback)
// ================================================================

void autoControl(unsigned long now) {
  if (isnan(lastTemp) || isnan(lastHum)) return;

  digitalWrite(RELAY_MIST,    srvMist    ? LOW : HIGH);
  digitalWrite(RELAY_FAN,     srvFan     ? LOW : HIGH);
  digitalWrite(RELAY_HEATER,  srvHeater  ? LOW : HIGH);
  digitalWrite(RELAY_EXHAUST, srvExhaust ? LOW : HIGH);

  if (!srvSprayer) sprayerSchedule(now);
  else             digitalWrite(RELAY_SPRAYER, LOW);

  // Local emergency fallback (runs even without WiFi)
  bool emergency = (lastTemp < EMERG_TEMP_LOW || lastTemp > EMERG_TEMP_HIGH || lastHum > EMERG_HUM_HIGH);
  if (emergency && !buzzerActive) {
    tone(BUZZER_PIN, 3000);
    Serial.println("[LOCAL EMERGENCY] Sensor out of safe range!");
  } else if (!emergency && !buzzerActive) {
    noTone(BUZZER_PIN);
  }
}

// ================================================================
//  MANUAL CONTROL
// ================================================================

void manualControl() {
  digitalWrite(RELAY_MIST,    srvMist    ? LOW : HIGH);
  digitalWrite(RELAY_FAN,     srvFan     ? LOW : HIGH);
  digitalWrite(RELAY_HEATER,  srvHeater  ? LOW : HIGH);
  digitalWrite(RELAY_SPRAYER, srvSprayer ? LOW : HIGH);
  digitalWrite(RELAY_EXHAUST, srvExhaust ? LOW : HIGH);
}

// ================================================================
//  SETUP
// ================================================================

void setup() {
  Serial.begin(115200);
  delay(500);

  // Init LCD
  lcd.init();
  lcd.backlight();
  lcd.setCursor(0, 0); lcd.print("MushroomOS");
  lcd.setCursor(0, 1); lcd.print("Starting...");
  delay(1500);

  ENDPOINT_SUBMIT = String(SERVER_HOST) + DB_PATH + "/submit_data.php";
  ENDPOINT_STATUS = String(SERVER_HOST) + DB_PATH + "/get_device_status.php";

  Serial.println("\n=== MushroomOS WROOM Main Controller ===");
  Serial.println("  Sensor  → " + ENDPOINT_SUBMIT);
  Serial.println("  Devices → " + ENDPOINT_STATUS);

  // Init relays — HIGH = OFF (active-low)
  int relays[] = {RELAY_MIST, RELAY_FAN, RELAY_HEATER, RELAY_SPRAYER, RELAY_EXHAUST};
  for (int pin : relays) {
    pinMode(pin, OUTPUT);
    digitalWrite(pin, HIGH);
  }

  pinMode(BUZZER_PIN, OUTPUT);
  noTone(BUZZER_PIN);

  dht.begin();
  connectWiFi();

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

  // Apply relay control
  if (manualMode) manualControl();
  else            autoControl(now);

  // Handle buzzer
  handleBuzzer(now);
}
