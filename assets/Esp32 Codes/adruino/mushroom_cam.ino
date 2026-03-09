/*
 * MushroomOS — ESP32-CAM (Camera Only)
 * Board: AI Thinker ESP32-CAM
 *
 * Handles:
 *   ✅ Auto photo capture every 30 min → uploads to process_image.php
 *   ✅ First capture on boot (after WiFi connects)
 *   ✅ Dashboard Chamber Camera Analysis shows images automatically
 *
 * ================================================================
 *  WIRING GUIDE
 * ================================================================
 *
 *  ESP32-CAM-MB (DIYMORE development board):
 *    USB-C → laptop (for flashing only)
 *    5V    → shared 5V power supply (same as WROOM)
 *    GND   → shared GND (same as WROOM)
 *
 *  Camera (OV2640):
 *    Built-in ribbon cable — already connected on board
 *    Flash LED → GPIO4 (built-in, auto-controlled)
 *
 *  ⚠️  GPIO0: Built-in IO0 button on MB board handles flash mode.
 *             Press IO0 + RESET to enter flash mode.
 *             Release IO0 after upload starts.
 *
 * ================================================================
 *  FLASHING INSTRUCTIONS (DIYMORE ESP32-CAM-MB)
 * ================================================================
 *  1. Connect USB-C to laptop
 *  2. Arduino IDE → Tools → Board → "AI Thinker ESP32-CAM"
 *  3. Tools → Port → select correct COM port
 *  4. Press and HOLD IO0 button, then press RESET once, release IO0
 *  5. Click Upload
 *  6. When done, press RESET to run
 *
 * ================================================================
 *  REQUIRED LIBRARIES (Arduino Library Manager)
 * ================================================================
 *  - ESP32 board package by Espressif (includes esp_camera.h)
 *  - ArduinoJson by Benoit Blanchon (v6.x)
 */

#include "esp_camera.h"
#include <WiFi.h>
#include <HTTPClient.h>

// ================================================================
//  ⚙️  CONFIGURATION — edit these to match your setup
// ================================================================

const char* WIFI_SSID     = "Gelo";
const char* WIFI_PASSWORD = "12345678";

const char* SERVER_HOST   = "http://10.101.168.197";
const char* DB_PATH       = "/mushroom_system";

const unsigned long CAPTURE_INTERVAL = 30UL * 60UL * 1000UL; // Every 30 minutes

// ================================================================
//  AI THINKER ESP32-CAM CAMERA PIN MAP — DO NOT CHANGE
// ================================================================

#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27
#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM        5
#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22

#define FLASH_LED_PIN      4

// ================================================================
//  TIMING
// ================================================================

const unsigned long WIFI_CHECK_MS = 30000;

unsigned long lastCapture = 0;
unsigned long lastWiFiChk = 0;
bool firstCaptureDone     = false;

// ================================================================
//  ENDPOINTS
// ================================================================

String ENDPOINT_UPLOAD;

// ================================================================
//  WiFi
// ================================================================

void connectWiFi() {
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.print("[WiFi] Connecting");
  int tries = 0;
  while (WiFi.status() != WL_CONNECTED && tries < 30) {
    delay(500); Serial.print("."); tries++;
  }
  if (WiFi.status() == WL_CONNECTED)
    Serial.printf("\n[WiFi] Connected — IP: %s\n", WiFi.localIP().toString().c_str());
  else
    Serial.println("\n[WiFi] Failed — will retry in loop.");
}

// ================================================================
//  CAMERA INIT
// ================================================================

bool initCamera() {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer   = LEDC_TIMER_0;
  config.pin_d0       = Y2_GPIO_NUM;
  config.pin_d1       = Y3_GPIO_NUM;
  config.pin_d2       = Y4_GPIO_NUM;
  config.pin_d3       = Y5_GPIO_NUM;
  config.pin_d4       = Y6_GPIO_NUM;
  config.pin_d5       = Y7_GPIO_NUM;
  config.pin_d6       = Y8_GPIO_NUM;
  config.pin_d7       = Y9_GPIO_NUM;
  config.pin_xclk     = XCLK_GPIO_NUM;
  config.pin_pclk     = PCLK_GPIO_NUM;
  config.pin_vsync    = VSYNC_GPIO_NUM;
  config.pin_href     = HREF_GPIO_NUM;
  config.pin_sscb_sda = SIOD_GPIO_NUM;
  config.pin_sscb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn     = PWDN_GPIO_NUM;
  config.pin_reset    = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;

  if (psramFound()) {
    config.frame_size   = FRAMESIZE_UXGA;
    config.jpeg_quality = 10;
    config.fb_count     = 2;
  } else {
    config.frame_size   = FRAMESIZE_SVGA;
    config.jpeg_quality = 12;
    config.fb_count     = 1;
  }

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("[Camera] Init failed: 0x%x\n", err);
    return false;
  }

  sensor_t* s = esp_camera_sensor_get();
  s->set_brightness(s, 1);
  s->set_contrast(s, 1);
  s->set_saturation(s, 0);
  s->set_whitebal(s, 1);
  s->set_awb_gain(s, 1);
  s->set_exposure_ctrl(s, 1);
  s->set_aec2(s, 1);
  s->set_gain_ctrl(s, 1);
  s->set_gainceiling(s, (gainceiling_t)2);
  s->set_vflip(s, 0);
  s->set_hmirror(s, 0);

  Serial.println("[Camera] Init OK");
  return true;
}

// ================================================================
//  CAPTURE AND UPLOAD → process_image.php
// ================================================================

void captureAndUpload() {
  Serial.println("[Camera] Capturing...");

  digitalWrite(FLASH_LED_PIN, HIGH);
  delay(300);
  camera_fb_t* fb = esp_camera_fb_get();
  digitalWrite(FLASH_LED_PIN, LOW);

  if (!fb) {
    Serial.println("[Camera] Failed — no frame buffer");
    return;
  }
  Serial.printf("[Camera] Captured %zu bytes\n", fb->len);

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[Upload] No WiFi — skipping");
    esp_camera_fb_return(fb);
    return;
  }

  const String boundary   = "MushroomOSBoundary12345";
  const String partHeader =
    "--" + boundary + "\r\n"
    "Content-Disposition: form-data; name=\"image\"; filename=\"chamber.jpg\"\r\n"
    "Content-Type: image/jpeg\r\n\r\n";
  const String partFooter = "\r\n--" + boundary + "--\r\n";

  size_t totalLen = partHeader.length() + fb->len + partFooter.length();
  uint8_t* body = (uint8_t*)malloc(totalLen);

  if (!body) {
    Serial.println("[Upload] malloc failed — not enough RAM");
    esp_camera_fb_return(fb);
    return;
  }

  size_t offset = 0;
  memcpy(body + offset, partHeader.c_str(), partHeader.length()); offset += partHeader.length();
  memcpy(body + offset, fb->buf,            fb->len);             offset += fb->len;
  memcpy(body + offset, partFooter.c_str(), partFooter.length());
  esp_camera_fb_return(fb);

  HTTPClient http;
  http.begin(ENDPOINT_UPLOAD);
  http.addHeader("Content-Type", "multipart/form-data; boundary=" + boundary);
  http.setTimeout(15000);

  int code = http.POST(body, totalLen);
  free(body);

  if (code == 200)
    Serial.printf("[Upload] Success: %s\n", http.getString().c_str());
  else
    Serial.printf("[Upload] Failed: HTTP %d\n", code);

  http.end();
}

// ================================================================
//  SETUP
// ================================================================

void setup() {
  Serial.begin(115200);
  delay(500);

  ENDPOINT_UPLOAD = String(SERVER_HOST) + DB_PATH + "/process_image.php";

  Serial.println("\n=== MushroomOS ESP32-CAM (Camera Only) ===");
  Serial.println("  Upload → " + ENDPOINT_UPLOAD);
  Serial.printf( "  Capture every %lu min\n", CAPTURE_INTERVAL / 60000UL);

  pinMode(FLASH_LED_PIN, OUTPUT);
  digitalWrite(FLASH_LED_PIN, LOW);

  if (!initCamera()) {
    Serial.println("[FATAL] Camera init failed — check ribbon cable!");
    // Blink flash LED to signal error
    for (int i = 0; i < 10; i++) {
      digitalWrite(FLASH_LED_PIN, HIGH); delay(200);
      digitalWrite(FLASH_LED_PIN, LOW);  delay(200);
    }
  }

  connectWiFi();

  // Single blink = ready
  digitalWrite(FLASH_LED_PIN, HIGH); delay(100);
  digitalWrite(FLASH_LED_PIN, LOW);

  Serial.println("[Setup] CAM Ready!");
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

  // First capture after boot
  if (!firstCaptureDone) {
    if (WiFi.status() == WL_CONNECTED && now > 5000) {
      firstCaptureDone = true;
      lastCapture = now;
      captureAndUpload();
    }
  }
  // Recurring capture every 30 minutes
  else if (now - lastCapture >= CAPTURE_INTERVAL) {
    lastCapture = now;
    captureAndUpload();
  }
}
