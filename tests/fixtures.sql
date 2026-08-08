-- ข้อมูลสมมติสำหรับทดสอบ API ใน CI เท่านั้น ห้ามนำไปใส่ฐานข้อมูลจริง
-- พิกัดในไฟล์นี้เป็นค่าปัดหยาบ ไม่ได้มาจากการสำรวจ ใช้เพื่อยืนยันว่าโค้ดอ่านแกนถูกด้านเท่านั้น
-- จำไว้: MySQL SRID 4326 เขียน POINT(lat lon)

INSERT INTO data_sources (name, publisher, source_url, license)
VALUES ('ชุดทดสอบ CI', 'ไม่ใช่ข้อมูลจริง', 'https://example.invalid/ci-fixture', 'ไม่มี');

INSERT INTO fishing_spots (name, province, fishing_style, geom, is_public)
VALUES ('หมายทดสอบ', 'ปัตตานี', 'shore', ST_GeomFromText('POINT(6.87 101.25)', 4326), TRUE);

INSERT INTO spot_depth_profiles (spot_id, min_depth_m, max_depth_m, typical_depth_m, vertical_datum, source_id)
VALUES (1, 2.0, 8.0, 4.5, 'ทดสอบ', 1);

-- หมายที่ไม่เปิดสาธารณะ ต้องไม่โผล่ใน /api/spots.php
INSERT INTO fishing_spots (name, province, fishing_style, geom, is_public)
VALUES ('หมายส่วนตัว', 'สงขลา', 'boat', ST_GeomFromText('POINT(7.20 100.60)', 4326), FALSE);
