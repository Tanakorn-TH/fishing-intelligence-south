# คลื่นดี — Fishing Intelligence South

[![CI](https://github.com/Tanakorn-TH/fishing-intelligence-south/actions/workflows/ci.yml/badge.svg)](https://github.com/Tanakorn-TH/fishing-intelligence-south/actions/workflows/ci.yml)

แดชบอร์ดวางแผนทริปตกปลาสำหรับชายฝั่งภาคใต้ของไทย (อ่าวไทยและอันดามัน)
รวมข้อมูลน้ำขึ้นน้ำลง สภาพอากาศ ช่วง Solunar และชั้นข้อมูลความลึกท้องทะเล
ให้ออกมาเป็นคะแนน "Fishing Score" รายวันต่อหมายตกปลา

> **สถานะปัจจุบัน: Front-end prototype**
> ตัวเลขทั้งหมดในหน้าเว็บ (คะแนน ปฏิทิน กราฟน้ำ พยากรณ์รายชั่วโมง) เป็น **ข้อมูลตัวอย่างแบบ hard-code**
> ยังไม่มี backend และยังไม่ได้ต่อ API จริง — ดูแผนงานที่หัวข้อ [Roadmap](#roadmap)

---

## ภาพรวม

| ส่วน | คำอธิบาย |
|---|---|
| หน้าเว็บ | Vanilla HTML + CSS + JS ไม่มี build step ไม่มี dependency |
| ภาษา | UI ภาษาไทยทั้งหมด (`lang="th"`) ใช้ฟอนต์ Noto Sans Thai + DM Mono |
| ธีม | Dark ocean — พื้นน้ำเงินเข้ม เน้นสี aqua / lime / orange |
| ฐานข้อมูล | MySQL 8 (สคีมาเตรียมไว้แล้วใน `data-model.sql`) |

### ฟีเจอร์ในหน้าเว็บตอนนี้

- **ภาพรวมประจำวัน** — Fishing Score พร้อมวงแหวนความคืบหน้า และช่วง Major Time ที่กำลังจะมา
- **สภาพอากาศปัจจุบัน** — ลม คลื่น โอกาสฝน ความกดอากาศ
- **น้ำขึ้นน้ำลง** — กราฟ SVG ทั้งวัน พร้อมเส้นเวลาปัจจุบันและจุดน้ำขึ้น/น้ำลง
- **Solunar** — ข้างขึ้นข้างแรม เปอร์เซ็นต์แสงจันทร์ ช่วง Major / Minor Time
- **พยากรณ์รายชั่วโมง** — แถบเลื่อนแนวนอนพร้อมบาร์ความแรงลม
- **ปฏิทินทริป** (dialog 2 ขั้นตอน) — เลือกหมาย → ดูปฏิทินคะแนนรายวันทั้งเดือน → บันทึกทริป
- **บันทึกปลา** — ปุ่มเรียก toast (ยังไม่มีฟอร์มจริง)

---

## โครงสร้างไฟล์

```
fishing-intelligence-south/
├── index.html            # โครงหน้าเว็บทั้งหมด (sidebar, dashboard, planner dialog)
├── styles.css            # สไตล์ทั้งหมด แบบ minified บรรทัดเดียว ใช้ CSS custom properties
├── app.js                # ตัวจัดการ interaction: nav, planner, ปฏิทิน, toast
├── data-model.sql        # สคีมา MySQL 8 พร้อม seed ของ gear_rules
├── BATHYMETRY_IMPORT.md  # ขั้นตอนนำเข้าข้อมูลความลึกท้องทะเลจากกรมทรัพยากรธรณี
├── CONTRIBUTING.md       # แนวทางร่วมพัฒนา กติกา PR และสิ่งที่ CI ตรวจ
├── fonts.css             # @font-face ทั้งหมด สร้างจากสคริปต์ อย่าแก้ด้วยมือ
├── fonts/                # ไฟล์ฟอนต์ self-host ไม่พึ่ง Google Fonts
├── api/                  # backend PHP
│   ├── spots.php         # GET รายการหมายตกปลาสาธารณะ
│   ├── gear.php          # GET กติกาแนะนำอุปกรณ์ตาม style + depth
│   └── lib/              # config, PDO, JSON helper (ห้ามเรียกตรงจากเว็บ)
├── tests/                # ชุดทดสอบ API ที่ CI รัน
├── ops/                  # การนำขึ้นระบบจริง — deploy.sh และวิธีตั้งค่า
├── map/                  # ชั้นข้อมูลแผนที่ (JSON สแตติก) สร้างจากสคริปต์ อย่าแก้ด้วยมือ
├── db/                   # SQL สำหรับเติมข้อมูลลงตาราง สร้างจากสคริปต์
├── scripts/
│   ├── check-dom-ids.mjs # ตรวจว่า id ที่ app.js เรียกหา มีจริงใน index.html
│   ├── check-version.mjs # ตรวจว่าเลขเวอร์ชันตรงกันทุกที่ที่มันปรากฏ
│   ├── geo.mjs           # ตัดกรอบและลดจุด ใช้ร่วมกันระหว่างสคริปต์สร้างแผนที่
│   ├── build-coastline.mjs  # เส้นชายฝั่ง <- Natural Earth
│   ├── build-borders.mjs    # เขตแดนประเทศและจังหวัด <- Natural Earth
│   ├── build-bathymetry.mjs # เส้นความลึก <- NOAA ERDDAP
│   ├── build-places.py      # รายชื่อจุดอ้างอิง <- GeoThai + Open-Meteo
│   └── build-spots.py       # ปะการังเทียม <- กรมประมง · หมาย <- OpenStreetMap
├── .htmlvalidate.json    # ชุดกฎตรวจ HTML
├── .github/workflows/    # GitHub Actions
└── README.md
```

## API

PHP ล้วน ไม่ใช้ framework ไม่ต้อง composer ต่อฐานข้อมูลด้วย PDO + prepared statement

| endpoint | คืนอะไร |
|---|---|
| `GET /api/spots.php` | หมายตกปลาสาธารณะ พร้อมพิกัดและช่วงความลึก |
| `GET /api/gear.php?style=shore&depth=4.5` | กติกาอุปกรณ์ที่ครอบคลุมความลึกนั้น |

### ⚠️ การวางไฟล์บนเซิร์ฟเวอร์

**`.env` ต้องอยู่นอก document root** แล้วชี้ตำแหน่งด้วย environment variable `FIS_ENV_FILE`
หรือจะตั้งค่า `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` เป็น environment variable ตรง ๆ ก็ได้ (วิธีนี้ปลอดภัยกว่า)

เหตุผล: เซิร์ฟเวอร์ปลายทางเป็น nginx อยู่หน้า Apache ซึ่ง nginx มักเสิร์ฟไฟล์สแตติกเองโดยไม่ผ่าน Apache
แปลว่า `.htaccess` **อาจกัน `.env` ไม่ได้** ถ้าวางไว้ใน document root จะมีคนเปิด `https://โดเมน/.env`
อ่านรหัสผ่านฐานข้อมูลไปได้ทันที

## การรัน

ไม่ต้อง build ไม่ต้องติดตั้งอะไร เปิด `index.html` ตรง ๆ ได้เลย
หรือถ้าอยากรันผ่าน local server:

```bash
python -m http.server 5173
```

แล้วเปิด <http://localhost:5173>

## ร่วมพัฒนา

อ่าน [CONTRIBUTING.md](CONTRIBUTING.md) ก่อนเปิด PR — มีกติกา branch, สิ่งที่ CI ตรวจ,
วิธีรันตัวตรวจเองในเครื่อง และรายการหนี้ทางเทคนิคที่รู้อยู่แล้ว

---

## ฐานข้อมูล

**MySQL 8.0.13 ขึ้นไป** — ใช้ SRID บนคอลัมน์ geometry, CHECK constraint และ generated column
`data-model.sql` ออกแบบให้ความลึกท้องทะเลเป็น **ข้อมูลประกอบการวางแผน ไม่ใช่ข้อมูลเดินเรือ**

| ตาราง | หน้าที่ |
|---|---|
| `data_sources` | ทะเบียนแหล่งข้อมูล — URL, ผู้เผยแพร่, สัญญาอนุญาต, วันที่ดึงข้อมูล |
| `bathymetry_contours` | เส้นชั้นความลึก (MULTILINESTRING SRID 4326) พร้อม vertical datum |
| `fishing_spots` | หมายตกปลา (POINT SRID 4326) แยกสาธารณะ / ส่วนตัว |
| `spot_depth_profiles` | ความลึกที่คำนวณได้รอบหมายภายในรัศมีที่ประกาศไว้ |
| `gear_rules` | กติกาแนะนำอุปกรณ์ตามรูปแบบการตก × ช่วงความลึก |
| `trip_plans` | ทริปที่วางไว้ พร้อม `score_inputs` (JSON) ที่ใช้คำนวณคะแนน |
| `trip_gear_items` | เช็กลิสต์อุปกรณ์ของแต่ละทริป |
| `catch_logs` | บันทึกปลาที่ได้ ผูกกับทริป |

ติดตั้ง:

```bash
mysql -u USER -p --default-character-set=utf8mb4 DBNAME < data-model.sql
```

### ⚠️ ลำดับแกนพิกัด

MySQL ใช้ SRID 4326 แบบ **Latitude ก่อน Longitude** ตรงข้ามกับ PostGIS

```sql
POINT(6.87 101.25)   -- ถูก: อ่าวปัตตานี (lat 6.87, lon 101.25)
POINT(101.25 6.87)   -- ผิด: MySQL ปฏิเสธ เพราะ 101.25 เกินช่วงละติจูด
```

โชคดีที่ลองจิจูดของภาคใต้อยู่ราว 98-103 ซึ่งเกินช่วง `[-90, 90]` ของละติจูด
ถ้าใครเขียนสลับ MySQL จะ error ทันที ไม่ใช่คำนวณผิดเงียบ ๆ — CI มีขั้นตอนตรวจข้อนี้ไว้

ขั้นตอนนำเข้าชั้นข้อมูลความลึกอยู่ใน [BATHYMETRY_IMPORT.md](BATHYMETRY_IMPORT.md)

---

## แหล่งข้อมูลภายนอก

ทุกแหล่งใช้ได้โดยไม่ต้องมีคีย์หรือสมัครสมาชิก ซึ่งเป็นเงื่อนไขที่ตั้งไว้ตั้งแต่ต้น

| ข้อมูล | แหล่ง | สัญญาอนุญาต |
|---|---|---|
| ลม ฝน ความกดอากาศ พระอาทิตย์ขึ้น-ตก | Open-Meteo Forecast | CC BY 4.0 |
| คลื่น อุณหภูมิผิวน้ำ ระดับน้ำ | Open-Meteo Marine | CC BY 4.0 |
| คลอโรฟิลล์-เอ | MODIS Aqua ผ่าน NOAA ERDDAP | public domain |
| เส้นความลึก | ETOPO ผ่าน NOAA ERDDAP | public domain |
| ชายฝั่ง เขตแดน | Natural Earth 1:10m | public domain |
| ปะการังเทียม | กรมประมง ผ่าน data.go.th | Open Data Common |
| หมาย (ซากเรือ กองหิน) | OpenStreetMap | ODbL 1.0 |
| ชื่อจังหวัด/อำเภอ | GeoThai | MIT |

Solunar และข้างขึ้นข้างแรมคำนวณเองในระบบตาม Meeus *Astronomical Algorithms* ไม่ได้ดึงจากที่ไหน

## Roadmap

- [x] Backend API แทนค่า hard-code ในหน้าเว็บ
- [x] ต่อข้อมูลลม คลื่น ฝน ความกดอากาศ อุณหภูมิน้ำ
- [x] คำนวณ Solunar (Major/Minor Time, ข้างขึ้นข้างแรม) จากตำแหน่งจริง
- [x] สูตรคะแนน Fishing Score ที่อธิบายที่มาได้ ([docs/fishing-score.md](docs/fishing-score.md))
- [x] แผนที่เลือกหมาย พร้อมเส้นความลึก เขตแดน ปะการังเทียม
- [x] เลือกวันที่ล่วงหน้าได้ 7 วัน
- [ ] ต่อข้อมูลน้ำขึ้นน้ำลงจริง (กรมอุทกศาสตร์) — ตอนนี้ใช้แบบจำลองซึ่งอ้างอิง MSL คนละ datum กับตารางน้ำทางการ
- [ ] นำเข้าชั้นความลึก DMR ตาม `BATHYMETRY_IMPORT.md` แล้วเติม `spot_depth_profiles`
- [ ] เก็บ `score_inputs` ทุกทริป แล้วปรับน้ำหนักคะแนนจากสถิติการจับจริง
- [ ] ฟอร์มบันทึกปลาจริง (ชนิด น้ำหนัก เวลา รูป) แทน toast
- [ ] ระบบผู้ใช้ + หมายส่วนตัว
- [ ] PWA / offline สำหรับใช้งานริมทะเลที่สัญญาณอ่อน

## ข้อควรระวัง

ข้อมูลความลึกในโปรเจคนี้ใช้เพื่อ **วางแผนการตกปลาเท่านั้น** ห้ามใช้เพื่อการเดินเรือหรือการตัดสินใจด้านความปลอดภัยทางทะเล
ก่อนออกทริปให้ตรวจสอบแผนที่เดินเรือฉบับทางการ ประกาศเตือนภัย และพื้นที่ห้ามทำการประมงทุกครั้ง
