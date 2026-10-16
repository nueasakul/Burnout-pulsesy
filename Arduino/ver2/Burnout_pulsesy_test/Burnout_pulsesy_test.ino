#include <Arduino.h>
#include <Wire.h>
#include "MAX30105.h"
#include "heartRate.h"
#include "DFRobotDFPlayerMini.h"
#include <SPI.h>
#include <MFRC522.h>

// ==================== Pin Definitions ====================
#define LED1_PIN    25
#define LED2_PIN    26
#define LED3_PIN    27
#define RELAY_PIN   33

#define SDA_PIN     21
#define SCL_PIN     22

#define DFPLAYER_RX 16  // ESP32 RX2 <- DFPlayer TX
#define DFPLAYER_TX 17  // ESP32 TX2 -> DFPlayer RX

#define SS_PIN      5   // SDA / CS
#define RST_PIN     4   // RST
#define SCK_PIN     18  // SCK
#define MISO_PIN    15  // MISO
#define MOSI_PIN    19  // MOSI

// ==================== Hardware Objects ====================
MFRC522 mfrc522(SS_PIN, RST_PIN);
HardwareSerial mySerial(1);
DFRobotDFPlayerMini myDFPlayer;
MAX30105 particleSensor;

uint8_t pcfAddress = 0x27;

// ตัวแปรสำหรับเช็กการกดปุ่ม (Edge Detection)
bool lastStart = false, lastHigh = false, lastMid = false, lastLow = false;

// ตัวแปรสำหรับหน่วงเวลาพิมพ์ค่า MAX30105
unsigned long lastMaxPrint = 0;
unsigned long lastBeatTime = 0;

// ==================== Helper Functions ====================
void testButtons() {
  Wire.requestFrom((int)pcfAddress, 1);
  if (Wire.available()) {
    uint8_t pinData = Wire.read();
    bool btnStart = !(pinData & (1 << 4)); // P4
    bool btnHigh  = !(pinData & (1 << 5)); // P5
    bool btnMid   = !(pinData & (1 << 6)); // P6
    bool btnLow   = !(pinData & (1 << 7)); // P7

    if (btnStart && !lastStart) Serial.println("🔘 [BUTTON] กดปุ่ม: START (P4)");
    if (btnHigh  && !lastHigh)  Serial.println("🔘 [BUTTON] กดปุ่ม: HIGH  (P5)");
    if (btnMid   && !lastMid)   Serial.println("🔘 [BUTTON] กดปุ่ม: MID   (P6)");
    if (btnLow   && !lastLow)   Serial.println("🔘 [BUTTON] กดปุ่ม: LOW   (P7)");

    lastStart = btnStart;
    lastHigh  = btnHigh;
    lastMid   = btnMid;
    lastLow   = btnLow;
  }
}

void testRFID() {
  if (mfrc522.PICC_IsNewCardPresent() && mfrc522.PICC_ReadCardSerial()) {
    String readUID = "";
    for (byte i = 0; i < mfrc522.uid.size; i++) {
      if (mfrc522.uid.uidByte[i] < 0x10) readUID += "0";
      readUID += String(mfrc522.uid.uidByte[i], HEX);
    }
    readUID.toUpperCase();
    readUID.trim();

    Serial.print("🎴 [RFID SCAN] อ่านค่า UID สำเร็จ: ");
    Serial.println(readUID); 

    mfrc522.PICC_HaltA();
    mfrc522.PCD_StopCrypto1();
  }
}

void testMAX30105() {
  long irValue = particleSensor.getIR();

  if (irValue > 50000) {
    if (checkForBeat(irValue)) {
      unsigned long currentMicros = micros();
      float delta = (currentMicros - lastBeatTime) / 1000.0;
      lastBeatTime = currentMicros;
      float bpm = 60.0 / (delta / 1000.0);

      if (bpm > 50 && bpm < 150) {
        Serial.print("💓 [MAX30105] ตรวจพบจังหวะหัวใจ! BPM: ");
        Serial.print(bpm, 1);
        Serial.print(" | IR: ");
        Serial.println(irValue);
      }
    } else if (millis() - lastMaxPrint > 1000) {
      Serial.print("🫀 [MAX30105] วางนิ้วอยู่... IR Value: ");
      Serial.println(irValue);
      lastMaxPrint = millis();
    }
  } else if (millis() - lastMaxPrint > 3000) {
    // แจ้งเตือนเมื่อยังไม่วางนิ้วทุกๆ 3 วินาที
    Serial.println("⚠️ [MAX30105] รอตาบนิ้วบนเซนเซอร์...");
    lastMaxPrint = millis();
  }
}

void printMenu() {
  Serial.println("\n==================================================");
  Serial.println("         🛠️ ESP32 HARDWARE DIAGNOSTIC TEST       ");
  Serial.println("==================================================");
  Serial.println("1. 🔘 ปุ่มกด PCF8574  : กดปุ่มที่บอร์ดเพื่อทดสอบ");
  Serial.println("2. 🎴 เครื่องอ่าน RFID : แตะบัตรที่ RC522 เพื่ออ่าน UID");
  Serial.println("3. 🫀 เซนเซอร์วัดชีพจร  : วางนิ้วบน MAX30105 เพื่อดูค่า IR/BPM");
  Serial.println("4. 🔊 DFPlayer เสียง  : พิมพ์เลขเพลง (เช่น 1, 11, 15) หรือ 'stop'");
  Serial.println("5. 💡 ไฟ LED / Relay  : พิมพ์ 'led1', 'led2', 'led3', 'ledall' หรือ 'relay'");
  Serial.println("==================================================\n");
}

void handleSerialCommands() {
  if (Serial.available()) {
    String cmd = Serial.readStringUntil('\n');
    cmd.trim();

    if (cmd.length() == 0) return;

    if (cmd.equalsIgnoreCase("stop")) {
      myDFPlayer.stop();
      Serial.println("🔊 [DFPLAYER] สั่งหยุดเล่นเสียงเรียบร้อย");
    } 
    // 🟢 [สั่งแยกไฟ LED ทีละดวง]
    else if (cmd.equalsIgnoreCase("led1")) {
      static bool led1State = false;
      led1State = !led1State;
      digitalWrite(LED1_PIN, led1State);
      Serial.print("💡 [LED 1 - GPIO 27] สถานะ: ");
      Serial.println(led1State ? "เปิด (HIGH)" : "ปิด (LOW)");
    } 
    else if (cmd.equalsIgnoreCase("led2")) {
      static bool led2State = false;
      led2State = !led2State;
      digitalWrite(LED2_PIN, led2State);
      Serial.print("💡 [LED 2 - GPIO 26] สถานะ: ");
      Serial.println(led2State ? "เปิด (HIGH)" : "ปิด (LOW)");
    } 
    else if (cmd.equalsIgnoreCase("led3")) {
      static bool led3State = false;
      led3State = !led3State;
      digitalWrite(LED3_PIN, led3State);
      Serial.print("💡 [LED 3 - GPIO 25] สถานะ: ");
      Serial.println(led3State ? "เปิด (HIGH)" : "ปิด (LOW)");
    } 
    // 🟢 [สั่งเปิด/ปิด ทั้ง 3 ดวงพร้อมกัน]
    else if (cmd.equalsIgnoreCase("led") || cmd.equalsIgnoreCase("ledall")) {
      static bool ledAllState = false;
      ledAllState = !ledAllState;
      digitalWrite(LED1_PIN, ledAllState);
      digitalWrite(LED2_PIN, ledAllState);
      digitalWrite(LED3_PIN, ledAllState);
      Serial.print("💡 [LED ALL] เปลี่ยนสถานะทุกดวงเป็น: ");
      Serial.println(ledAllState ? "เปิด (HIGH)" : "ปิด (LOW)");
    } 
    else if (cmd.equalsIgnoreCase("relay")) {
      static bool relayState = false;
      relayState = !relayState;
      digitalWrite(RELAY_PIN, relayState);
      Serial.print("⚡ [RELAY TEST] เปลี่ยนสถานะ Relay เป็น: ");
      Serial.println(relayState ? "เปิด (HIGH)" : "ปิด (LOW)");
    } 
    else if (cmd.equalsIgnoreCase("help")) {
      printMenu();
    } 
    else if (cmd.toInt() > 0) {
      int trackNum = cmd.toInt();
      Serial.print("🔊 [DFPLAYER] กำลังทดสอบสั่งเล่นไฟล์เพลงที่: ");
      Serial.println(trackNum);
      myDFPlayer.play(trackNum);
    } 
    else {
      Serial.println("❌ คำสั่งไม่ถูกต้อง! พิมพ์ 'led1', 'led2', 'led3', 'ledall', 'relay', เลขเพลง หรือ 'help'");
    }
  }
}

// ==================== Setup & Loop ====================
void setup() {
  Serial.begin(115200);
  mySerial.begin(9600, SERIAL_8N1, DFPLAYER_RX, DFPLAYER_TX);

  // 1. ตั้งค่าดิจิทัลเอาต์พุต
  pinMode(LED1_PIN, OUTPUT);
  pinMode(LED2_PIN, OUTPUT);
  pinMode(LED3_PIN, OUTPUT);
  pinMode(RELAY_PIN, OUTPUT);

  // 2. เริ่มต้น I2C และ PCF8574
  Wire.begin(SDA_PIN, SCL_PIN);
  bool foundPCF = false;
  for (uint8_t addr = 1; addr < 127; addr++) {
    Wire.beginTransmission(addr);
    if (Wire.endTransmission() == 0) {
      if (addr == 0x27 || addr == 0x3F || (addr >= 0x20 && addr <= 0x27)) {
        pcfAddress = addr;
        foundPCF = true;
      }
    }
  }

  if (foundPCF) {
    Wire.beginTransmission(pcfAddress);
    Wire.write(0xFF);
    Wire.endTransmission();
    Serial.print("✅ [PCF8574] เชื่อมต่อสำเร็จที่ Address: 0x");
    Serial.println(pcfAddress, HEX);
  } else {
    Serial.println("❌ [PCF8574] ไม่พบบอร์ด PCF8574 บน I2C!");
  }

  // 3. เริ่มต้น RFID RC522
  SPI.begin(SCK_PIN, MISO_PIN, MOSI_PIN, SS_PIN);
  mfrc522.PCD_Init();
  Serial.println("✅ [RFID RC522] เริ่มต้นโมดูลเรียบร้อย");

  // 4. เริ่มต้น MAX30105
  if (particleSensor.begin(Wire, I2C_SPEED_FAST)) {
    particleSensor.setup();
    particleSensor.setPulseAmplitudeRed(0x0A);
    particleSensor.setPulseAmplitudeGreen(0);
    Serial.println("✅ [MAX30105] เชื่อมต่อเซนเซอร์วัดชีพจรสำเร็จ");
  } else {
    Serial.println("❌ [MAX30105] ไม่พบเซนเซอร์ MAX30105!");
  }

  // 5. เริ่มต้น DFPlayer Mini
  if (myDFPlayer.begin(mySerial)) {
    myDFPlayer.volume(13);
    Serial.println("✅ [DFPlayer Mini] เชื่อมต่อเครื่องเล่นเสียงสำเร็จ (Volume 30)");
  } else {
    Serial.println("❌ [DFPlayer Mini] เชื่อมต่อไม่สำเร็จ (เช็กการต่อสาย TX/RX หรือ SD Card)");
  }

  printMenu();
}

void loop() {
  testButtons();             // 1. อ่านปุ่มกด PCF8574
  testRFID();                // 2. อ่านการแตะบัตร RFID
  testMAX30105();            // 3. อ่านค่าเซนเซอร์ชีพจร
  handleSerialCommands();    // 4. รับคำสั่งทดสอบทาง Serial (เล่นเพลง/คุม LED)
  
  delay(10);
}