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
| ฐานข้อมูล | PostgreSQL 16 + PostGIS (สคีมาเตรียมไว้แล้วใน `data-model.sql`) |

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
├── data-model.sql        # สคีมา PostgreSQL + PostGIS พร้อม seed ของ gear_rules
├── BATHYMETRY_IMPORT.md  # ขั้นตอนนำเข้าข้อมูลความลึกท้องทะเลจากกรมทรัพยากรธรณี
├── CONTRIBUTING.md       # แนวทางร่วมพัฒนา กติกา PR และสิ่งที่ CI ตรวจ
├── scripts/
│   └── check-dom-ids.mjs # ตรวจว่า id ที่ app.js เรียกหา มีจริงใน index.html
├── .htmlvalidate.json    # ชุดกฎตรวจ HTML
├── .github/workflows/    # GitHub Actions
└── README.md
```

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

`data-model.sql` ออกแบบให้ความลึกท้องทะเลเป็น **ข้อมูลประกอบการวางแผน ไม่ใช่ข้อมูลเดินเรือ**

| ตาราง | หน้าที่ |
|---|---|
| `data_sources` | ทะเบียนแหล่งข้อมูล — URL, ผู้เผยแพร่, สัญญาอนุญาต, วันที่ดึงข้อมูล |
| `bathymetry_contours` | เส้นชั้นความลึก (MultiLineString, EPSG:4326) พร้อม vertical datum |
| `fishing_spots` | หมายตกปลา (Point, EPSG:4326) แยกสาธารณะ / ส่วนตัว |
| `spot_depth_profiles` | ความลึกที่คำนวณได้รอบหมายภายในรัศมีที่ประกาศไว้ |
| `gear_rules` | กติกาแนะนำอุปกรณ์ตามรูปแบบการตก × ช่วงความลึก |
| `trip_plans` | ทริปที่วางไว้ พร้อม `score_inputs` (JSONB) ที่ใช้คำนวณคะแนน |
| `trip_gear_items` | เช็กลิสต์อุปกรณ์ของแต่ละทริป |
| `catch_logs` | บันทึกปลาที่ได้ ผูกกับทริป |

ติดตั้ง:

```bash
psql -d fishing -f data-model.sql
```

ขั้นตอนนำเข้าชั้นข้อมูลความลึกอยู่ใน [BATHYMETRY_IMPORT.md](BATHYMETRY_IMPORT.md)

---

## Roadmap

- [ ] Backend service + REST/GraphQL API แทนค่า hard-code ในหน้าเว็บ
- [ ] ต่อข้อมูลน้ำขึ้นน้ำลงจริง (กรมอุทกศาสตร์ / กรมเจ้าท่า)
- [ ] ต่อข้อมูลลม คลื่น ฝน ความกดอากาศ (เช่น Open-Meteo Marine)
- [ ] คำนวณ Solunar (Major/Minor Time, ข้างขึ้นข้างแรม) จากตำแหน่งจริง
- [ ] นำเข้าชั้นความลึก DMR ตาม `BATHYMETRY_IMPORT.md` แล้วเติม `spot_depth_profiles`
- [ ] สูตรคะแนน Fishing Score ที่อธิบายที่มาได้ พร้อมเก็บ `score_inputs`
- [ ] ฟอร์มบันทึกปลาจริง (ชนิด น้ำหนัก เวลา รูป) แทน toast
- [ ] ระบบผู้ใช้ + หมายส่วนตัว
- [ ] PWA / offline สำหรับใช้งานริมทะเลที่สัญญาณอ่อน

## ข้อควรระวัง

ข้อมูลความลึกในโปรเจคนี้ใช้เพื่อ **วางแผนการตกปลาเท่านั้น** ห้ามใช้เพื่อการเดินเรือหรือการตัดสินใจด้านความปลอดภัยทางทะเล
ก่อนออกทริปให้ตรวจสอบแผนที่เดินเรือฉบับทางการ ประกาศเตือนภัย และพื้นที่ห้ามทำการประมงทุกครั้ง
