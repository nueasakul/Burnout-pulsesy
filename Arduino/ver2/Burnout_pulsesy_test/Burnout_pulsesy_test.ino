#include <Arduino.h>
#include <Wire.h>
#include "MAX30105.h"
#include "heartRate.h"
#include "DFRobotDFPlayerMini.h"

// ==================== Pin Definitions ====================
int BUTTON_START = 5;
int BUTTON_HIGH = 8;
int BUTTON_MID = 9;
int BUTTON_LOW = 10;

#define SDA_PIN 6
#define SCL_PIN 7

#define DFPLAYER_RX 1  // ESP32 RX <- DFPlayer TX
#define DFPLAYER_TX 0  // ESP32 TX -> DFPlayer RX

// ==================== Hardware Objects ====================
HardwareSerial mySerial(1);
DFRobotDFPlayerMini myDFPlayer;
MAX30105 particleSensor;

// ==================== Control Variables ====================
bool isBtnPressed = false;
bool isRunning = false;

const int TOTAL_QUESTIONS = 10;  // จำนวนคลังคำถามทั้งหมดใน SD Card (10 ข้อ)
const int NUM_ASKED = 3;         // สุ่มนำมาถามแค่ 3 ข้อ

int selectedQ[NUM_ASKED];                     // เก็บ Index ข้อที่สุ่มเลือกได้ (0-9)
int answers[NUM_ASKED] = { 0, 0, 0 };         // คำตอบ 3 ข้อ (0 = Timeout, 1 = LOW, 2 = MID, 3 = HIGH)
float scores[NUM_ASKED] = { 0.0, 0.0, 0.0 };  // คะแนน 3 ข้อ
int attempts[NUM_ASKED] = { 0, 0, 0 };        // จำนวนครั้งที่ถาม (สูงสุด 2 รอบ)

// ระยะเวลาเล่นเสียงบังคับฟังของคำถามแต่ละข้อ (ข้อ 1 - 10)
const unsigned long trackDelays[10] = {
  5000, 3000, 7000, 6000, 5000, 4000, 3000, 5000, 3000, 5000
};

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

void setup() {
  Serial.begin(115200);
  mySerial.begin(9600, SERIAL_8N1, DFPLAYER_RX, DFPLAYER_TX);

  pinMode(BUTTON_START, INPUT_PULLUP);
  pinMode(BUTTON_HIGH, INPUT_PULLUP);
  pinMode(BUTTON_MID, INPUT_PULLUP);
  pinMode(BUTTON_LOW, INPUT_PULLUP);

  // สุ่ม Seed ตัวเลข
  randomSeed(esp_random());

  // 1. เริ่มต้น I2C และ MAX30105
  Wire.begin(SDA_PIN, SCL_PIN);
  if (!particleSensor.begin(Wire, I2C_SPEED_FAST)) {
    Serial.println("[-] MAX30105 เริ่มต้นไม่สำเร็จ!");
  } else {
    particleSensor.setup();
    particleSensor.setPulseAmplitudeRed(0x0A);
    particleSensor.setPulseAmplitudeGreen(0);
    Serial.println("[+] MAX30105 Connected!");
  }

  // 2. เริ่มต้น DFPlayer Mini
  if (!myDFPlayer.begin(mySerial)) {
    Serial.println("[-] DFPlayer เริ่มต้นไม่สำเร็จ!");
  } else {
    Serial.println("[+] DFPlayer Connected!");
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

  Serial.println("Setup completed!");
}

void loop() {
  if (digitalRead(BUTTON_START) == 0) {
    if (!isBtnPressed) {
      isBtnPressed = true;
      delay(50);

      if (!isRunning) {
        isRunning = true;
        Serial.println(">>> [START] เริ่มต้นการทำงาน!");
        Assessment();
      } else {
        Serial.println(">>> [STOP] สั่งหยุดการทำงาน!");
        resetSystem();
        while (digitalRead(BUTTON_START) == 0) {
          delay(10);
        }
        return;
      }
    }
  } else {
    isBtnPressed = false;
  }
  delay(20);
}

void Assessment() {
  // รีเซ็ตค่าชีพจรและข้อมูล 3 ข้อก่อนเริ่มเซสชันใหม่
  totalBeatSum = 0;
  totalBeatCount = 0;
  hrvCount = 0;
  for (int k = 0; k < NUM_ASKED; k++) {
    answers[k] = 0;
    scores[k] = 0.0;
    attempts[k] = 0;
  }

  // 1. สุ่มเลือกคำถาม 3 ข้อจาก 10 ข้อ (Fisher-Yates Shuffle)
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

  // 2. เล่นไฟล์ 11 (Intro) ก่อนเป็นเวลา 13 วินาที
  playTrackAndWait(11, 13000);
  if (!isRunning) return;

  // 3. ลูปทำแบบประเมิน 3 ข้อที่สุ่มได้ (รอบแรก)
  for (int i = 0; i < NUM_ASKED; i++) {
    if (!isRunning) return;

    int qIdx = selectedQ[i];  // ดรรชนีข้อใน SD Card (0 - 9)
    attempts[i]++;

    // เล่นไฟล์เสียงของข้อที่สุ่มได้
    playTrackAndWait(qIdx + 1, trackDelays[qIdx]);
    if (!isRunning) return;

    wait_input_with_timeout(i);  // ส่งตำแหน่งช่องบันทึก (0, 1, 2)
    if (!isRunning) return;

    delay(300);
  }

  // 4. วนกลับมาถามซ้ำเฉพาะข้อที่ยังไม่ได้ตอบ (Timeout)
  for (int i = 0; i < NUM_ASKED; i++) {
    if (!isRunning) return;

    if (answers[i] == 0) {
      int qIdx = selectedQ[i];
      Serial.print("\n>>> [RETRY] วนกลับมาถามคำถามลำดับที่ ");
      Serial.print(i + 1);
      Serial.print(" (ไฟล์เสียงที่ ");
      Serial.print(qIdx + 1);
      Serial.println(") อีกครั้ง...");

      attempts[i]++;
      playTrackAndWait(qIdx + 1, trackDelays[qIdx]);
      if (!isRunning) return;

      wait_input_with_timeout(i);
      if (!isRunning) return;

      delay(300);
    }
  }

  // 5. สรุปผลลัพธ์และส่งข้อมูลออกทาง Serial
  if (isRunning) {
    Serial.println("\nทำครบ 3 ข้อแล้ว! กำลังส่งข้อมูลให้ Python...");
    send();
    resetSystem();
  }
}

void playTrackAndWait(int trackNumber, unsigned long delayTime) {
  if (!isRunning) return;

  Serial.print("\n=== เริ่มเล่นไฟล์ที่ ");
  Serial.print(trackNumber);
  Serial.println(" ===");
  myDFPlayer.play(trackNumber);

  unsigned long startTrackTime = millis();
  while (millis() - startTrackTime < delayTime) {
    if (!isRunning) return;

    if (digitalRead(BUTTON_START) == 0 && !isBtnPressed) {
      isBtnPressed = true;
      Serial.println(">>> [STOP] หยุดทำงานระหว่างเล่นเพลง!");
      resetSystem();
      while (digitalRead(BUTTON_START) == 0) {
        delay(10);
      }
      return;
    }
    if (digitalRead(BUTTON_START) == 1) {
      isBtnPressed = false;
    }
    delay(20);
  }
}

bool wait_input_with_timeout(int i) {
  if (!isRunning) return false;

  Serial.println("--> ปลดล็อกปุ่ม: รอรับคำตอบ (สูงสุด 10 วินาที)...");
  unsigned long startTime = millis();
  bool hasSelected = false;

  while (millis() - startTime < 10000) {
    if (!isRunning) return false;

    if (digitalRead(BUTTON_START) == 1) isBtnPressed = false;
    if (digitalRead(BUTTON_START) == 0 && !isBtnPressed) {
      isBtnPressed = true;
      Serial.println(">>> [STOP] หยุดทำงานระหว่างรอคำตอบ!");
      resetSystem();
      return false;
    }

    int selectedBtn = 0;
    if (digitalRead(BUTTON_HIGH) == 0) {
      answers[i] = 3;
      scores[i] = 50.0;
      selectedBtn = BUTTON_HIGH;
    } else if (digitalRead(BUTTON_MID) == 0) {
      answers[i] = 2;
      scores[i] = 33.33;
      selectedBtn = BUTTON_MID;
    } else if (digitalRead(BUTTON_LOW) == 0) {
      answers[i] = 1;
      scores[i] = 16.7;
      selectedBtn = BUTTON_LOW;
    }

    if (selectedBtn != 0) {
      hasSelected = true;
      while (digitalRead(selectedBtn) == 0) {
        delay(10);
      }
      delay(50);
      break;
    }
    delay(20);
  }

  if (hasSelected) {
    Serial.print("[+] บันทึกข้อมูลเรียบร้อย: ข้อที่ ");
    Serial.print(i + 1);
    Serial.print(" = ตอบ ");
    Serial.println(answers[i]);
    return true;
  } else {
    if (attempts[i] == 1) {
      Serial.print("[-] หมดเวลาข้อที่ ");
      Serial.print(i + 1);
      Serial.println(" (รอบแรก) -> เตรียมเอากลับมาถามใหม่ท้ายสุด");
    } else {
      answers[i] = 0;
      scores[i] = 0.0;
      Serial.print("[-] หมดเวลาข้อที่ ");
      Serial.print(i + 1);
      Serial.println(" (รอบ Retry) -> บันทึกเป็น 0");
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

  // คำนวณคะแนนเฉลี่ยคำถาม (%) จาก 3 ข้อ
  float totalScore = 0;
  for (int k = 0; k < NUM_ASKED; k++) {
    totalScore += scores[k];
  }
  float avgQuestionScore = totalScore / (float)NUM_ASKED;

  // ส่งผลลัพธ์ออก Serial Monitor (ส่งเฉพาะคำตอบ 3 ข้อ)
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
  myDFPlayer.stop();
  for (int k = 0; k < NUM_ASKED; k++) {
    answers[k] = 0;
    scores[k] = 0.0;
    attempts[k] = 0;
  }
  Serial.println("--- รีเซ็ตระบบ รอรับแบบประเมินถัดไป ---");
  delay(300);
}

// ==================== FreeRTOS Background Task ====================
void breathingTask(void *pvParameters) {
  while (1) {
    long irValue = particleSensor.getIR();

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