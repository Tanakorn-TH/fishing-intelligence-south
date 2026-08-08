-- คลื่นดี — Fishing Intelligence South
-- MySQL 8.0.13 ขึ้นไป (ต้องการ SRID บนคอลัมน์ geometry, CHECK constraint และ generated column)
-- เก็บความลึกท้องทะเลเป็นข้อมูลวางแผน ไม่ใช่ข้อมูลเดินเรือ
--
-- ⚠️ ลำดับแกนของ SRID 4326 บน MySQL คือ LATITUDE ก่อน LONGITUDE
--    เขียน POINT(lat lon) เช่น อ่าวปัตตานี = POINT(6.87 101.25)
--    ถ้าเขียนสลับแบบ PostGIS เป็น POINT(101.25 6.87) MySQL จะปฏิเสธทันที
--    เพราะลองจิจูดของภาคใต้เกินช่วง [-90, 90] ของละติจูด
--
-- ประวัติ: เวอร์ชัน PostgreSQL + PostGIS ของไฟล์นี้อยู่ใน git ที่ commit bf5ffa0

SET NAMES utf8mb4;

CREATE TABLE data_sources (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  publisher VARCHAR(255) NOT NULL,
  source_url VARCHAR(512) NOT NULL,
  license VARCHAR(255) NULL,
  retrieved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes TEXT NULL,
  UNIQUE KEY uq_data_sources_source_url (source_url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- MySQL สร้าง UNIQUE บนคอลัมน์ geometry โดยตรงไม่ได้
-- จึงเก็บ SHA-256 ของรูปทรงไว้ในคอลัมน์ generated แล้วใส่ UNIQUE บนคอลัมน์นั้นแทน
CREATE TABLE bathymetry_contours (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
  depth_m DECIMAL(7,2) NOT NULL,
  vertical_datum VARCHAR(100) NOT NULL,
  scale_denominator INT UNSIGNED NULL,
  surveyed_at DATE NULL,
  geom MULTILINESTRING NOT NULL SRID 4326,
  geom_sha256 BINARY(32) AS (UNHEX(SHA2(ST_AsBinary(geom), 256))) STORED NOT NULL,
  CONSTRAINT chk_bathymetry_depth CHECK (depth_m >= 0),
  CONSTRAINT fk_bathymetry_source FOREIGN KEY (source_id) REFERENCES data_sources (id),
  UNIQUE KEY uq_bathymetry_shape (source_id, depth_m, geom_sha256),
  SPATIAL INDEX sx_bathymetry_geom (geom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE fishing_spots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  province VARCHAR(100) NOT NULL,
  fishing_style VARCHAR(50) NOT NULL,
  geom POINT NOT NULL SRID 4326,
  is_public BOOLEAN NOT NULL DEFAULT FALSE,
  UNIQUE KEY uq_fishing_spots_name_province (name, province),
  SPATIAL INDEX sx_fishing_spots_geom (geom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ค่าที่คำนวณได้ ต้องรีเฟรชใหม่ทุกครั้งที่นำเข้าเส้นชั้นความลึกหรือค่าหยั่งน้ำใกล้หมาย
CREATE TABLE spot_depth_profiles (
  spot_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  min_depth_m DECIMAL(7,2) NULL,
  max_depth_m DECIMAL(7,2) NULL,
  typical_depth_m DECIMAL(7,2) NULL,
  sample_radius_m INT NOT NULL DEFAULT 1000,
  vertical_datum VARCHAR(100) NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  measured_at DATE NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_spot_depth_radius CHECK (sample_radius_m BETWEEN 50 AND 10000),
  CONSTRAINT fk_spot_depth_spot FOREIGN KEY (spot_id) REFERENCES fishing_spots (id) ON DELETE CASCADE,
  CONSTRAINT fk_spot_depth_source FOREIGN KEY (source_id) REFERENCES data_sources (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE gear_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fishing_style VARCHAR(50) NOT NULL,
  min_depth_m DECIMAL(7,2) NOT NULL,
  max_depth_m DECIMAL(7,2) NOT NULL,
  rod VARCHAR(255) NOT NULL,
  reel VARCHAR(255) NOT NULL,
  line_and_leader VARCHAR(255) NOT NULL,
  lure_or_rig VARCHAR(255) NOT NULL,
  safety_note VARCHAR(500) NOT NULL,
  CONSTRAINT chk_gear_min_depth CHECK (min_depth_m >= 0),
  CONSTRAINT chk_gear_depth_order CHECK (max_depth_m >= min_depth_m),
  CONSTRAINT chk_gear_max_depth CHECK (max_depth_m <= 1000),
  UNIQUE KEY uq_gear_rules_range (fishing_style, min_depth_m, max_depth_m)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE trip_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  spot_id BIGINT UNSIGNED NOT NULL,
  trip_date DATE NOT NULL,
  fishing_score SMALLINT NULL,
  score_inputs JSON NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'planned',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_trip_score CHECK (fishing_score BETWEEN 0 AND 100),
  CONSTRAINT chk_trip_status CHECK (status IN ('planned', 'completed', 'cancelled')),
  CONSTRAINT fk_trip_spot FOREIGN KEY (spot_id) REFERENCES fishing_spots (id),
  UNIQUE KEY uq_trip_spot_date (spot_id, trip_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE trip_gear_items (
  trip_id BIGINT UNSIGNED NOT NULL,
  item_name VARCHAR(190) NOT NULL,
  gear_rule_id BIGINT UNSIGNED NULL,
  is_packed BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (trip_id, item_name),
  CONSTRAINT fk_gear_item_trip FOREIGN KEY (trip_id) REFERENCES trip_plans (id) ON DELETE CASCADE,
  CONSTRAINT fk_gear_item_rule FOREIGN KEY (gear_rule_id) REFERENCES gear_rules (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ทุกบันทึกปลาต้องอยู่ในทริป ผู้ใช้จึงต้องสร้าง trip_plans ก่อนเสมอ
CREATE TABLE catch_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  trip_id BIGINT UNSIGNED NOT NULL,
  species VARCHAR(190) NOT NULL,
  weight_kg DECIMAL(6,2) NULL,
  caught_at TIMESTAMP NULL,
  notes TEXT NULL,
  CONSTRAINT fk_catch_trip FOREIGN KEY (trip_id) REFERENCES trip_plans (id) ON DELETE CASCADE,
  KEY ix_catch_logs_trip (trip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW spot_planning_summary AS
SELECT s.id, s.name, s.province, s.fishing_style,
       p.typical_depth_m, p.min_depth_m, p.max_depth_m,
       p.sample_radius_m, p.vertical_datum,
       ds.name AS depth_source
FROM fishing_spots s
LEFT JOIN spot_depth_profiles p ON p.spot_id = s.id
LEFT JOIN data_sources ds ON ds.id = p.source_id;

-- Seed เฉพาะกติกาแนะนำอุปกรณ์ ห้าม seed ค่าความลึกจนกว่าจะนำเข้ารูปทรงจากแหล่งทางการแล้ว
INSERT INTO gear_rules (fishing_style, min_depth_m, max_depth_m, rod, reel, line_and_leader, lure_or_rig, safety_note) VALUES
('shore', 0, 5, 'คัน 6-10 lb', 'รอก 2000-3000', 'PE 0.8-1.2 + leader 16-20 lb', 'เหยื่อปลอมผิวน้ำ / จิ๊ก 7-15 g', 'ตรวจคลื่น กระแสน้ำ และพื้นที่ห้ามตกก่อนออกทริป'),
('shore', 5, 15, 'คัน 8-15 lb', 'รอก 3000-4000', 'PE 1.0-1.5 + leader 20-30 lb', 'จิ๊ก 15-30 g / หน้าดินเบา', 'ตรวจคลื่น กระแสน้ำ และพื้นที่ห้ามตกก่อนออกทริป'),
('boat', 10, 35, 'คัน 15-30 lb', 'รอก 5000 หรือ overhead', 'PE 2-3 + leader 30-50 lb', 'จิ๊ก 40-100 g / หน้าดิน', 'ความลึกไม่ใช่ข้อมูลเดินเรือ ใช้แผนที่เดินเรือและอุปกรณ์ความปลอดภัยที่ถูกต้อง'),
('boat', 35, 100, 'คัน 30-50 lb', 'overhead', 'PE 3-4 + leader 50-80 lb', 'จิ๊ก 100-200 g / หน้าดินหนัก', 'ความลึกไม่ใช่ข้อมูลเดินเรือ ใช้แผนที่เดินเรือและอุปกรณ์ความปลอดภัยที่ถูกต้อง');

-- ค้นเส้นชั้นความลึกรอบหมายภายในรัศมีที่กำหนด
-- MySQL ใช้ SPATIAL INDEX ได้เฉพาะกับฟังก์ชันตระกูล MBR เท่านั้น
-- จึงต้องกรองหยาบด้วย MBRIntersects ก่อน แล้วค่อยวัดระยะจริงด้วย ST_Distance_Sphere
-- ถ้าเรียก ST_Distance_Sphere ตรง ๆ อย่างเดียว MySQL จะสแกนทั้งตาราง
--
--   SET @spot = (SELECT geom FROM fishing_spots WHERE id = ?);
--   SET @radius_m = 1000;
--   SET @box = ST_Buffer(@spot, @radius_m / 111320);  -- แปลงเป็นองศาโดยประมาณ ใช้เป็นกรอบหยาบเท่านั้น
--   SELECT c.depth_m, ST_Distance_Sphere(c.geom, @spot) AS distance_m
--     FROM bathymetry_contours c
--    WHERE MBRIntersects(c.geom, @box)
--      AND ST_Distance_Sphere(c.geom, @spot) <= @radius_m
--    ORDER BY distance_m;

-- ดึงอุปกรณ์ที่เหมาะกับทริปที่วางไว้ หลังจากมี spot_depth_profiles แล้ว
--   SELECT g.* FROM trip_plans t
--     JOIN fishing_spots s ON s.id = t.spot_id
--     JOIN spot_depth_profiles p ON p.spot_id = s.id
--     JOIN gear_rules g ON g.fishing_style = s.fishing_style
--    WHERE t.id = ? AND p.typical_depth_m BETWEEN g.min_depth_m AND g.max_depth_m;
