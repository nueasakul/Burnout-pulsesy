#include <Arduino.h>
#include <Wire.h>
#include "MAX30105.h"
#include "heartRate.h"
#include "DFRobotDFPlayerMini.h"
#include <SPI.h>
#include <MFRC522.h>

// ==================== Pin Definitions ====================
#define SDA_PIN 6
#define SCL_PIN 7

#define DFPLAYER_RX 1  // ESP32 RX <- DFPlayer TX
#define DFPLAYER_TX 0  // ESP32 TX -> DFPlayer RX

#define SS_PIN 5
#define RST_PIN 2
#define SCK_PIN 8
#define MOSI_PIN 9
#define MISO_PIN 10
MFRC522 mfrc522(SS_PIN, RST_PIN);
SemaphoreHandle_t i2cMutex = NULL;

uint8_t pcfAddress = 0x27;

// ==================== Hardware Objects ====================
HardwareSerial mySerial(1);
DFRobotDFPlayerMini myDFPlayer;
MAX30105 particleSensor;

// ==================== Control Variables ====================
bool isBtnPressed = false;
bool isRunning = false;
volatile bool Pulseruning = false;

const int TOTAL_QUESTIONS = 10;  // คลังคำถามทั้งหมดใน SD Card (10 ข้อ)
const int NUM_ASKED = 3;         // สุ่มนำมาถาม 3 ข้อ

int selectedQ[NUM_ASKED];
int answers[NUM_ASKED] = { 0, 0, 0 };
float scores[NUM_ASKED] = { 0.0, 0.0, 0.0 };
int attempts[NUM_ASKED] = { 0, 0, 0 };

// ระยะเวลาเล่นเสียงบังคับฟังของคำถามแต่ละข้อ (ข้อ 1 - 10)
const unsigned long trackDelays[10] = {
  5000, 3000, 7000, 6000, 5000, 4000, 3000, 5000, 3000, 5000
};

String MASTER_CARD = "F8A56BE";
String VISITOR_CARD = "31DC230";

// ==================== Heart Rate & HRV Variables ====================
const byte HRV_SIZE = 10;
float rrIntervals[HRV_SIZE];
byte hrvCount = 0;

unsigned long lastBeat = 0;
float beatsPerMinute = 0;

unsigned long totalBeatSum = 0;
unsigned int totalBeatCount = 0;
float sessionAvgBpm = 0.0;
float rmssd = 0.0;
int hrvScore = 0;
// ==================== Function Declarations ====================
void Assessment();
void playTrackAndWait(int trackNumber, unsigned long delayTime);
bool wait_input_with_timeout(int i);
void send();
void resetSystem();
void breathingTask(void *pvParameters);

struct ButtonState {
  bool start;
  bool high;
  bool mid;
  bool low;
};

// ฟังก์ชันสำหรับอ่านค่าสถานะขา P4 จากชิป PCF8574
ButtonState readButtons() {
  ButtonState btn = { false, false, false, false };
  if (i2cMutex != NULL && xSemaphoreTake(i2cMutex, pdMS_TO_TICKS(50)) == pdTRUE) {
    Wire.requestFrom((int)pcfAddress, 1);
    if (Wire.available()) {
      uint8_t pinData = Wire.read();
      btn.start = !(pinData & (1 << 4));  // P4: START
      btn.high = !(pinData & (1 << 5));   // P5: HIGH
      btn.mid = !(pinData & (1 << 6));    // P6: MID
      btn.low = !(pinData & (1 << 7));    // P7: LOW
    }
    xSemaphoreGive(i2cMutex);
  }
  return btn;
}

void setup() {
  Serial.begin(115200);
  mySerial.begin(9600, SERIAL_8N1, DFPLAYER_RX, DFPLAYER_TX);
  i2cMutex = xSemaphoreCreateMutex();
  randomSeed(esp_random());

  SPI.begin(SCK_PIN, MISO_PIN, MOSI_PIN, SS_PIN);
  mfrc522.PCD_Init();
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
  } else {
    Serial.println("❌ ไม่พบบอร์ด PCF8574!");
  }

  // 1. เริ่มต้น I2C และ MAX30105
  if (particleSensor.begin(Wire, I2C_SPEED_FAST)) {
    particleSensor.setup();
    particleSensor.setPulseAmplitudeRed(0x0A);
    particleSensor.setPulseAmplitudeGreen(0);
  }

  // 2. เริ่มต้น DFPlayer Mini
  if (myDFPlayer.begin(mySerial)) {
    myDFPlayer.volume(30);
  }

  // 3. เริ่ม Task ทำงานเบื้องหลัง (วัดชีพจร & HRV)
  xTaskCreate(
    breathingTask,
    "BreathingLED_And_Pulse",
    4096,
    NULL,
    1,
    NULL);
}

void loop() {
  if (mfrc522.PICC_IsNewCardPresent() && mfrc522.PICC_ReadCardSerial()) {
    // 1. อ่านค่า UID
    String readUID = "";
    for (byte i = 0; i < mfrc522.uid.size; i++) {
      if (mfrc522.uid.uidByte[i] < 0x10) readUID += "0";  // เติม 0 ข้างหน้าถ้าค่าต่ำกว่า 16
      readUID += String(mfrc522.uid.uidByte[i], HEX);
    }
    readUID.toUpperCase();
    readUID.trim();  // ตัดช่องว่างหัว-ท้ายออก

    // แสดงค่า UID ที่อ่านได้
    // Serial.print("📌 บัตรที่แตะ UID: [");
    // Serial.print(readUID);
    // Serial.println("]");

    mfrc522.PICC_HaltA();
    mfrc522.PCD_StopCrypto1();

    // 2. ส่ง UID ไปถาม Python ผ่าน Serial
    Serial.print("RFID_SCAN|");
    Serial.println(readUID);

    // 3. รอ
    unsigned long startWait = millis();
    bool authorized = false;
    while (millis() - startWait < 2000) {
      if (Serial.available() || readUID == MASTER_CARD || readUID == VISITOR_CARD) {
        String response = Serial.readStringUntil('\n');
        response.trim();

        if (response == "RFID_OK" || readUID == MASTER_CARD) {
          Serial.println("RFID_OK");
          authorized = true;
          break;
        } else if (response == "RFID_VISITOR" || readUID == VISITOR_CARD) {
          Serial.println("RFID_VISITOR");
          isRunning = true;
          playTrackAndWait(15, 2000);
          isRunning = false;
          Serial.println(isRunning);
          break;
        } else if (response == "RFID_DENIED") {
          Serial.println("RFID_DENIED");
          authorized = false;
          break;
        }
      }
    }

    if (authorized) {
      if (!isBtnPressed) {
        isBtnPressed = true;
        delay(50);

        if (!isRunning) {
          isRunning = true;
          Assessment();
        } else {
          resetSystem();
          return;
        }
      } else {
        isBtnPressed = false;
      }
    }
  }
  delay(20);
}

void Assessment() {
  // 🟢 ส่งสัญญาณบอก Python ให้เริ่มเปิดกล้อง
  Serial.println("SYSTEM_START");
  playTrackAndWait(12, 2000);

  while (true) {
    if (!isRunning) return;

    ButtonState btn = readButtons();
    if (btn.start && !isBtnPressed) {
      isBtnPressed = true;
      resetSystem();
      while (readButtons().start) {
        delay(10);
      }
      playTrackAndWait(16, 5000);
      return;
    }

    if (!btn.start) {
      isBtnPressed = false;
    }
    if (Serial.available()) {
      String response = Serial.readStringUntil('\n');
      response.trim();
      if (response == "END_CAMERA") {
        break;
      }
    }
    delay(20);
  }

  // รีเซ็ตค่าชีพจรและข้อมูล 3 ข้อก่อนเริ่มเซสชันใหม่
  Serial.println("เริ่มวัดชีพจร!");

  totalBeatSum = 0;
  totalBeatCount = 0;
  hrvCount = 0;
  for (int k = 0; k < NUM_ASKED; k++) {
    answers[k] = 0;
    scores[k] = 0.0;
    attempts[k] = 0;
  }
  playTrackAndWait(13, 3000);
  Pulseruning = true;
  unsigned long startWait = millis();
  while (millis() - startWait < 30000) {
    delay(10);
  }
  Pulseruning = false;

  Serial.println("วัดชีพจร สำเร็จเเล้ว!");

  // สุ่มเลือกคำถาม 3 ข้อจาก 10 ข้อ (Fisher-Yates Shuffle)
  int pool[10] = { 0, 1, 2, 3, 4, 5, 6, 7, 8, 9 };
  for (int i = 9; i > 0; i--) {
    int j = random(0, i + 1);
    int temp = pool[i];
    pool[i] = pool[j];
    pool[j] = temp;
  }
  for (int i = 0; i < NUM_ASKED; i++) {
    selectedQ[i] = pool[i];
  }

  // เล่นไฟล์ 11 (Intro) ก่อนเป็นเวลา 13 วินาที
  playTrackAndWait(11, 13000);
  if (!isRunning) return;

  // ลูปทำแบบประเมิน 3 ข้อที่สุ่มได้ (รอบแรก)
  for (int i = 0; i < NUM_ASKED; i++) {
    if (!isRunning) return;

    int qIdx = selectedQ[i];
    attempts[i]++;

    playTrackAndWait(qIdx + 1, trackDelays[qIdx]);
    if (!isRunning) return;

    wait_input_with_timeout(i);
    if (!isRunning) return;

    delay(300);
  }

  // วนกลับมาถามซ้ำเฉพาะข้อที่ยังไม่ได้ตอบ (Timeout)
  for (int i = 0; i < NUM_ASKED; i++) {
    if (!isRunning) return;

    if (answers[i] == 0) {
      int qIdx = selectedQ[i];
      attempts[i]++;

      playTrackAndWait(qIdx + 1, trackDelays[qIdx]);
      if (!isRunning) return;

      wait_input_with_timeout(i);
      if (!isRunning) return;

      delay(300);
    }
  }

  // สรุปผลลัพธ์และส่งข้อมูลออกทาง Serial
  if (isRunning) {
    playTrackAndWait(14, 3000);
    send();
    resetSystem();
  }
}

void playTrackAndWait(int trackNumber, unsigned long delayTime) {
  if (!isRunning) return;

  Serial.print("กำลังเล่นไฟล์ที่: ");
  Serial.println(trackNumber);
  return;

  // myDFPlayer.play(trackNumber);

  unsigned long startTrackTime = millis();
  while (millis() - startTrackTime < delayTime) {
    if (!isRunning) return;

    ButtonState btn = readButtons();
    if (btn.start && !isBtnPressed) {
      isBtnPressed = true;
      resetSystem();
      while (readButtons().start) {
       delay(10);
      }
      playTrackAndWait(16, 5000);
      return;
    }

    if (!btn.start) {
      isBtnPressed = false;
    }
    delay(20);
  }
}

bool wait_input_with_timeout(int i) {
  if (!isRunning) return false;

  unsigned long startTime = millis();
  bool hasSelected = false;

  while (millis() - startTime < 10000) {
    if (!isRunning) return false;

    // 1. อ่านสถานะปุ่มกดทั้งหมดผ่าน I2C เพียงครั้งเดียวต่อรอบ
    ButtonState btn = readButtons();

    // 2. ตรวจสอบปุ่ม START (Reset)
    if (btn.start && !isBtnPressed) {
      isBtnPressed = true;
      resetSystem();

      // รอจนกว่าจะปล่อยปุ่ม START (อ่านจาก I2C)
      while (readButtons().start) {
        delay(10);
      }
      playTrackAndWait(16, 5000);
      return false;  // แก้ไขจาก return; เฉยๆ เป็น return false ให้ถูกต้องตามชนิด bool
    }
    if (!btn.start) {
      isBtnPressed = false;
    }

    // 3. ตรวจสอบปุ่มตัวเลือก (HIGH / MID / LOW) ผ่าน I2C
    int selectedBtn = 0;  // 1: LOW, 2: MID, 3: HIGH

    if (btn.high) {
      answers[i] = 3;
      scores[i] = 50.0;
      selectedBtn = 3;
    } else if (btn.mid) {
      answers[i] = 2;
      scores[i] = 33.33;
      selectedBtn = 2;
    } else if (btn.low) {
      answers[i] = 1;
      scores[i] = 16.7;
      selectedBtn = 1;
    }

    // 4. ถ้ามีการกดปุ่มตัวเลือก ให้รอจนกว่าจะปล่อยปุ่มนั้นๆ
    if (selectedBtn != 0) {
      hasSelected = true;

      while (true) {
        ButtonState currentBtn = readButtons();
        if ((selectedBtn == 3 && !currentBtn.high) || (selectedBtn == 2 && !currentBtn.mid) || (selectedBtn == 1 && !currentBtn.low)) {
          break;  // ปล่อยปุ่มแล้ว ออกจากลูป
        }
        delay(10);
      }
      delay(50);  // Debounce
      break;
    }

    delay(20);
  }

  // 5. สรุปผลการรอรับข้อมูล
  if (hasSelected) {
    return true;
  } else {
    if (attempts[i] > 1) {
      answers[i] = 0;
      scores[i] = 0.0;
    }
    return false;
  }
}

void send() {
  if (totalBeatCount > 0) {
    sessionAvgBpm = (float)totalBeatSum / totalBeatCount;
  } else {
    sessionAvgBpm = 0.0;
  }
  float totalScore = 0;
  for (int k = 0; k < NUM_ASKED; k++) {
    totalScore += scores[k];
  }
  float avgQuestionScore = totalScore / (float)NUM_ASKED;

  // 🟢 ส่งผลลัพธ์ข้อมูลสรุปบรรทัดเดียวให้ Python
  // โครงสร้าง: HW_RESULT|AvgBPM|RMSSD|HRVScore|QAvgScore|Ans1,Ans2,Ans3
  Serial.print("HW_RESULT|");
  Serial.print(sessionAvgBpm, 1);
  Serial.print("|");
  Serial.print(rmssd, 1);
  Serial.print("|");
  Serial.print(hrvScore);
  Serial.print("|");
  Serial.print(avgQuestionScore, 2);
  Serial.print("|");

  for (int k = 0; k < NUM_ASKED; k++) {
    Serial.print(answers[k]);
    if (k < NUM_ASKED - 1) Serial.print(",");
  }
  Serial.println();
}

void resetSystem() {
  isRunning = false;
  isBtnPressed = false;
  Pulseruning = false;
  myDFPlayer.stop();
  for (int k = 0; k < NUM_ASKED; k++) {
    answers[k] = 0;
    scores[k] = 0.0;
    attempts[k] = 0;
  }

  // 🔴 ส่งสัญญาณบอก Python ให้ปิดกล้อง/สั่งหยุด
  Serial.println("SYSTEM_STOP");
  delay(300);
}

// ==================== FreeRTOS Background Task ====================
void breathingTask(void *pvParameters) {
  while (1) {
    if (Pulseruning) {
      long irValue = 0;

      // ขอสิทธิ์ใช้ I2C ก่อนอ่านค่าจากเซนเซอร์
      if (i2cMutex != NULL && xSemaphoreTake(i2cMutex, pdMS_TO_TICKS(50)) == pdTRUE) {
        irValue = particleSensor.getIR();
        xSemaphoreGive(i2cMutex);  // คืนสิทธิ์การใช้ I2C
      }

      if (irValue > 50000) {
        if (checkForBeat(irValue) == true) {
          unsigned long currentMicros = micros();
          float delta = (currentMicros - lastBeat) / 1000.0;
          lastBeat = currentMicros;
          beatsPerMinute = 60.0 / (delta / 1000.0);

          if (beatsPerMinute < 150.0 && beatsPerMinute > 50.0) {
            totalBeatSum += beatsPerMinute;
            totalBeatCount++;

            if (hrvCount < HRV_SIZE) {
              rrIntervals[hrvCount++] = delta;
            } else {
              for (byte i = 0; i < HRV_SIZE - 1; i++) {
                rrIntervals[i] = rrIntervals[i + 1];
              }
              rrIntervals[HRV_SIZE - 1] = delta;
            }

            if (hrvCount >= 2) {
              float sumSquaredDiff = 0.0;
              for (byte i = 0; i < hrvCount - 1; i++) {
                float diff = rrIntervals[i + 1] - rrIntervals[i];
                sumSquaredDiff += (diff * diff);
              }
              rmssd = sqrt(sumSquaredDiff / (hrvCount - 1));
              float rawScore = ((60.0 - rmssd) / 45.0) * 100.0;
              hrvScore = (int)constrain(rawScore, 0.0, 100.0);
            }
          }
        }
      } else {
        beatsPerMinute = 0;
      }

      vTaskDelay(pdMS_TO_TICKS(10));
    }
  }
}