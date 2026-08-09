-- db/seed-fishing-spots.sql — สร้างโดย scripts/build-spots.py ห้ามแก้ด้วยมือ
--
-- เติมตาราง fishing_spots ด้วยหมายที่มีพิกัดเผยแพร่จริงจาก OpenStreetMap
-- ทุกแถวมี osm_type/osm_id อยู่ในคอมเมนต์ ตรวจย้อนกลับได้ทีละจุด
--
-- ที่มา: OpenStreetMap contributors, ODbL 1.0 (https://www.openstreetmap.org/copyright)
-- fishing_style ตั้งเป็น bottom เพราะซากเรือ กองหิน และแนวปะการังคืองานหน้าดินทั้งหมด
-- ถ้าหมายไหนเหมาะกับงานอื่นให้ผู้ดูแลแก้ทีหลัง อย่าเดาแทนคนที่เคยไปจริง
--
-- MySQL เก็บ SRID 4326 แบบละติจูดก่อน จึงต้องใส่ ST_GeomFromText('POINT(lat lon)')
-- ไม่ใช่ลำดับ lon lat อย่างที่คนคุ้น PostGIS คาด

INSERT INTO fishing_spots (name, province, fishing_style, geom, is_public) VALUES
  ('Hin Daeng', 'กระบี่', 'bottom', ST_GeomFromText('POINT(7.367574 98.875754)', 4326), TRUE)  -- reef · node/2463501831,
  ('Hin Muang', 'กระบี่', 'bottom', ST_GeomFromText('POINT(7.367387 98.87002)', 4326), TRUE)  -- reef · node/2463501832,
  ('หินบิดา', 'กระบี่', 'bottom', ST_GeomFromText('POINT(7.636639 98.820822)', 4326), TRUE)  -- reef · node/2463491455,
  ('Hin Jom', 'พังงา', 'bottom', ST_GeomFromText('POINT(7.807392 98.627376)', 4326), TRUE)  -- reef · node/270985343,
  ('Ko Dok Mai', 'พังงา', 'bottom', ST_GeomFromText('POINT(7.796424 98.530281)', 4326), TRUE)  -- reef · node/2470089673,
  ('MS King Cruiser', 'พังงา', 'bottom', ST_GeomFromText('POINT(7.801344 98.642907)', 4326), TRUE)  -- wreck · node/340378903,
  ('Na Yak Reef snorkeling and diving place', 'พังงา', 'bottom', ST_GeomFromText('POINT(8.573914 98.210542)', 4326), TRUE)  -- reef · node/10314145199,
  ('Snorkeling Point', 'พังงา', 'bottom', ST_GeomFromText('POINT(7.906393 98.550284)', 4326), TRUE)  -- reef · node/10558561429,
  ('หินกลาง', 'พังงา', 'bottom', ST_GeomFromText('POINT(7.79198 98.779384)', 4326), TRUE)  -- reef · way/477779773,
  ('ซากเรือ', 'ภูเก็ต', 'bottom', ST_GeomFromText('POINT(7.866393 98.400625)', 4326), TRUE)  -- wreck · node/12378118591,
  ('แนวปะการังเกาะบุโหลนดอน', 'สตูล', 'bottom', ST_GeomFromText('POINT(6.855256 99.594513)', 4326), TRUE)  -- reef · way/680087060,
  ('แนวประการัง', 'สุราษฎร์ธานี', 'bottom', ST_GeomFromText('POINT(9.797931 99.9783)', 4326), TRUE)  -- reef · way/776102325
ON DUPLICATE KEY UPDATE geom = VALUES(geom), is_public = VALUES(is_public);
