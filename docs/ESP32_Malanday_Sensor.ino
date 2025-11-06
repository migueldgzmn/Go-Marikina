#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include "DHT.h"
#include <Wire.h>
#include <LiquidCrystal_I2C.h>

// ==== WiFi Configuration ====
const char* ssid = "iPhonexxx";
const char* password = "asd12345";

// ==== Server Configuration (HTTPS endpoint) ====
const char* endpoint = "https://go-marikina.wasmer.app/api/save_sensor_data.php"; // change if needed

// ==== Sensor Configuration ====
#define DHTPIN 4
#define DHTTYPE DHT22
#define FLOAT1_PIN 13
#define FLOAT2_PIN 14
#define FLOAT3_PIN 27
#define MQ135_PIN 35
#define BUZZER_PIN 19   // Buzzer connected to pin 19
#define LED_PIN 23      // LED connected to pin 23

// ==== Objects ====
DHT dht(DHTPIN, DHTTYPE);
WiFiServer server(80);
LiquidCrystal_I2C lcd(0x27, 16, 4);  // Address 0x27 for 16x4 LCD

// ==== Alert Control ====
bool alertActive = false;
unsigned long alertStart = 0;
const unsigned long ALERT_DURATION = 5000;

// === Uplink (HTTP POST) Control ===
unsigned long lastSendTime = 0;
const unsigned long POST_INTERVAL_MS = 60000; // 60 seconds

// === LCD Rotation Control ===
unsigned long lastDisplaySwitch = 0;
bool showAltScreen = false;  // false = main screen, true = alternate screen

// === Functions ===
void startFloodAlert() {
  alertActive = true;
  alertStart = millis();
  digitalWrite(BUZZER_PIN, HIGH);
  digitalWrite(LED_PIN, HIGH);
}

void updateFloodAlert() {
  if (alertActive && millis() - alertStart >= ALERT_DURATION) {
    alertActive = false;
    digitalWrite(BUZZER_PIN, LOW);
    digitalWrite(LED_PIN, LOW);
  }
}

// === Setup ===
void setup() {
  Serial.begin(115200);
  WiFi.setSleep(false);
  WiFi.begin(ssid, password);

  Serial.print("Connecting to WiFi");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nWiFi Connected!");

  while (WiFi.localIP().toString() == "0.0.0.0") {
    delay(500);
    Serial.println("Waiting for IP...");
  }

  Serial.print("IP Address: ");
  Serial.println(WiFi.localIP());

  lcd.init();
  lcd.backlight();
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("ESP32 IoT System");
  lcd.setCursor(0, 1);
  lcd.print("Connecting WiFi");
  delay(2000);
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("WiFi Connected!");
  lcd.setCursor(0, 1);
  lcd.print(WiFi.localIP().toString());
  delay(2000);
  lcd.clear();

  dht.begin();

  // Float sensors use INPUT_PULLUP (LOW when water detected)
  pinMode(FLOAT1_PIN, INPUT_PULLUP);
  pinMode(FLOAT2_PIN, INPUT_PULLUP);
  pinMode(FLOAT3_PIN, INPUT_PULLUP);

  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);
  digitalWrite(LED_PIN, LOW);

  server.begin();
  Serial.println("HTTP server started");
}

// ---- Helper: Convert gas analog to coarse AQI-like bucket (optional, server accepts numeric) ----
static inline int computeAirQualityIndex(int gasAnalog) {
  // Map raw 0..4095 to 0..500 (approximate)
  int aqi = map(gasAnalog, 0, 4095, 0, 500);
  if (aqi < 0) aqi = 0; if (aqi > 500) aqi = 500;
  return aqi;
}

// ---- Helper: Robust HTTPS POST to server ----
bool sendToServer(float temperature, float humidity, int airQuality, const String& floodLevel, int waterPercent, int gasAnalog, float gasVoltage) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi not connected; skipping POST");
    return false;
  }

  WiFiClientSecure client;
  client.setInsecure(); // Accept all certs (you can pin a CA/fingerprint later)

  HTTPClient http;
  bool ok = false;
  if (http.begin(client, endpoint)) {
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept", "application/json");
    http.setUserAgent("ESP32-IoT/1.0");
    http.setTimeout(12000); // 12s

    String payload = "{";
    payload += "\"barangay\":\"Malanday\",";
    payload += "\"device_ip\":\"" + WiFi.localIP().toString() + "\",";
    payload += "\"temperature\":" + String(temperature, 1) + ",";
    payload += "\"humidity\":" + String(humidity, 1) + ",";
    payload += "\"airQuality\":" + String(airQuality) + ","; // numeric is fine
    payload += "\"gasAnalog\":" + String(gasAnalog) + ",";
    payload += "\"gasVoltage\":" + String(gasVoltage, 2) + ",";
    payload += "\"floodLevel\":\"" + floodLevel + "\",";
    payload += "\"waterPercent\":" + String(waterPercent) + ",";
    payload += "\"status\":\"online\",\"source\":\"esp32\"";
    payload += "}";

    int httpCode = http.POST(payload);
    String response = http.getString();
    Serial.printf("\n📡 POST %d: %s\n", httpCode, response.c_str());
    ok = (httpCode >= 200 && httpCode < 300);
    http.end();
  } else {
    Serial.println("❌ HTTP begin() failed");
  }
  return ok;
}

// === Loop ===
void loop() {
  updateFloodAlert();

  WiFiClient client = server.available();

  // === Read Sensors ===
  float humidity = dht.readHumidity();
  float temperature = dht.readTemperature();
  int gas = analogRead(MQ135_PIN);
  float gasVoltage = (gas / 4095.0) * 3.3;

  int f1 = digitalRead(FLOAT1_PIN);
  int f2 = digitalRead(FLOAT2_PIN);
  int f3 = digitalRead(FLOAT3_PIN);

  // Debugging float sensor states
  Serial.print("Float States -> F1: ");
  Serial.print(f1);
  Serial.print(" | F2: ");
  Serial.print(f2);
  Serial.print(" | F3: ");
  Serial.println(f3);

  // Handle NaN values from DHT sensor
  if (isnan(humidity)) humidity = 0;
  if (isnan(temperature)) temperature = 0;

  // Since LOW = water detected, count triggered sensors
  int floatCount = (1 - f1) + (1 - f2) + (1 - f3);

  String floodLevel, lcdMessage;
  int waterPercent = 0;

  // Determine flood level based on float sensor count
  if (floatCount == 0) {
    floodLevel = "No Flood";
    lcdMessage = "SAFE: No Flood";
    waterPercent = 0;
  } 

  // ---- Periodic HTTPS uplink ----
  if (millis() - lastSendTime >= POST_INTERVAL_MS) {
    int airQuality = computeAirQualityIndex(gas);
    bool posted = sendToServer(temperature, humidity, airQuality, floodLevel, waterPercent, gas, gasVoltage);
    if (!posted) {
      // Best-effort: quick retry once after short delay
      delay(500);
      posted = sendToServer(temperature, humidity, airQuality, floodLevel, waterPercent, gas, gasVoltage);
    }
    lastSendTime = millis();
  }
  else if (floatCount == 1) {
    floodLevel = "Level 1 (Gutter Deep)";
    lcdMessage = "CAUTION: Gutter";
    waterPercent = 33;
    if (!alertActive) startFloodAlert();
  } 
  else if (floatCount == 2) {
    floodLevel = "Level 2 (Knee Deep)";
    lcdMessage = "WARNING: Knee";
    waterPercent = 66;
    if (!alertActive) startFloodAlert();
  } 
  else if (floatCount == 3) {
    floodLevel = "Level 3 (Waist Deep)";
    lcdMessage = "DANGER: Waist!";
    waterPercent = 100;
    if (!alertActive) startFloodAlert();
  }

  // === LCD Display Rotation ===
  unsigned long now = millis();

  // Auto-switch LCD every 10 seconds (only when no flood alert)
  if (now - lastDisplaySwitch >= 10000 && floatCount == 0) {
    showAltScreen = !showAltScreen;
    lastDisplaySwitch = now;
  }

  lcd.clear();

  // If any water detected (Level 1, 2, 3) -> show alert display
  if (floatCount > 0) {
    lcd.setCursor(0, 0);
    lcd.print("!! FLOOD ALERT !!");
    lcd.setCursor(0, 1);
    lcd.print(floodLevel);
    lcd.setCursor(0, 2);
    lcd.print("Water: ");
    lcd.print(waterPercent);
    lcd.print("%");
    lcd.setCursor(0, 3);
    lcd.print(lcdMessage);
  } 
  else {
    // Normal rotation display
    if (!showAltScreen) {
      // Main Screen: Temperature, Humidity, Water Level
      lcd.setCursor(0, 0);
      lcd.print("Temp: ");
      lcd.print(temperature, 1);
      lcd.print("C");
      lcd.setCursor(0, 1);
      lcd.print("Hum:  ");
      lcd.print(humidity, 1);
      lcd.print("%");
      lcd.setCursor(0, 2);
      lcd.print("Water: ");
      lcd.print(waterPercent);
      lcd.print("%");
      lcd.setCursor(0, 3);
      lcd.print("Status: SAFE");
    } 
    else {
      // Alternate Screen: Air Quality, Gas Voltage
      int airQuality = map(gas, 0, 4095, 0, 500);
      lcd.setCursor(0, 0);
      lcd.print("Air Qual: ");
      lcd.print(airQuality);
      lcd.setCursor(0, 1);
      lcd.print("Gas Vol: ");
      lcd.print(gasVoltage, 2);
      lcd.print("V");
      lcd.setCursor(0, 2);
      lcd.print("All Systems OK");
      lcd.setCursor(0, 3);
      lcd.print("IP:");
      lcd.print(WiFi.localIP().toString());
    }
  }

  // === Handle API Request ===
  if (client && client.connected()) {
    String request = client.readStringUntil('\r');
    client.flush();

    if (request.indexOf("GET /api/data") >= 0) {
      // Calculate air quality
      int airQuality = map(gas, 0, 4095, 0, 500);
      
      // Build JSON response
      String json = "{";
      json += "\"temperature\":";
      json += String(temperature, 1);
      json += ",\"humidity\":";
      json += String(humidity, 1);
      json += ",\"waterPercent\":";
      json += String(waterPercent);
      json += ",\"floodLevel\":\"";
      json += floodLevel;
      json += "\",\"airQuality\":";
      json += String(airQuality);
      json += ",\"gasAnalog\":";
      json += String(gas);
      json += ",\"gasVoltage\":";
      json += String(gasVoltage, 2);
      json += "}";

      // Send HTTP response
      client.print("HTTP/1.1 200 OK\r\n");
      client.print("Content-Type: application/json\r\n");
      client.print("Access-Control-Allow-Origin: *\r\n");
      client.print("Connection: close\r\n");
      client.print("Content-Length: ");
      client.print(json.length());
      client.print("\r\n\r\n");
      client.print(json);
      client.stop();
      
      Serial.println("API request served");
      Serial.println(json);
      return;
    }

    // === HTML Dashboard ===
    client.print("HTTP/1.1 200 OK\r\n");
    client.print("Content-Type: text/html\r\n\r\n");
    client.print("<!DOCTYPE html><html><head>");
    client.print("<meta name='viewport' content='width=device-width, initial-scale=1'>");
    client.print("<title>ESP32 IoT Dashboard - Malanday</title>");
    client.print("<style>");
    client.print("body{font-family:Arial;text-align:center;background:#f0f0f0;margin-top:50px;}");
    client.print(".card{background:white;padding:20px;margin:auto;width:320px;border-radius:15px;box-shadow:0 4px 8px rgba(0,0,0,0.1);}");
    client.print("h2{color:#333;margin-bottom:20px;}");
    client.print("p{font-size:18px;margin:10px 0;}");
    client.print(".value{font-weight:bold;color:#0c3d87;}");
    client.print(".flood{color:#e74c3c;}");
    client.print("</style></head><body>");
    client.print("<div class='card'>");
    client.print("<h2>ESP32 IoT Dashboard</h2>");
    client.print("<p>Temperature: <span class='value' id='temp'>--</span> °C</p>");
    client.print("<p>Humidity: <span class='value' id='hum'>--</span> %</p>");
    client.print("<p class='flood'>Flood Level: <span class='value' id='level'>--</span></p>");
    client.print("<p>Water: <span class='value' id='water'>--</span> %</p>");
    client.print("<p>Air Quality: <span class='value' id='air'>--</span></p>");
    client.print("<p>Gas Voltage: <span class='value' id='volt'>--</span> V</p>");
    client.print("</div>");
    client.print("<script>");
    client.print("async function update(){");
    client.print("try{");
    client.print("const res=await fetch('/api/data');");
    client.print("const d=await res.json();");
    client.print("document.getElementById('temp').textContent=d.temperature;");
    client.print("document.getElementById('hum').textContent=d.humidity;");
    client.print("document.getElementById('water').textContent=d.waterPercent;");
    client.print("document.getElementById('level').textContent=d.floodLevel;");
    client.print("document.getElementById('air').textContent=d.airQuality;");
    client.print("document.getElementById('volt').textContent=d.gasVoltage;");
    client.print("}catch(e){console.error('Error:',e);}");
    client.print("}");
    client.print("setInterval(update,2000);update();");
    client.print("</script>");
    client.print("</body></html>");
    client.stop();
    
    Serial.println("Dashboard served");
  }

  delay(500);
}
