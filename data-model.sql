-- PostgreSQL 16 + PostGIS. Keep bathymetry as planning data, never navigation data.
CREATE EXTENSION IF NOT EXISTS postgis;

CREATE TABLE data_sources (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name TEXT NOT NULL,
  publisher TEXT NOT NULL,
  source_url TEXT NOT NULL UNIQUE,
  license TEXT,
  retrieved_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  notes TEXT
);

CREATE TABLE bathymetry_contours (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  source_id BIGINT NOT NULL REFERENCES data_sources(id),
  depth_m NUMERIC(7,2) NOT NULL CHECK (depth_m >= 0),
  vertical_datum TEXT NOT NULL,
  scale_denominator INTEGER,
  surveyed_at DATE,
  geom geometry(MultiLineString, 4326) NOT NULL,
  UNIQUE (source_id, depth_m, geom)
);
CREATE INDEX bathymetry_contours_geom_idx ON bathymetry_contours USING GIST (geom);

CREATE TABLE fishing_spots (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name TEXT NOT NULL,
  province TEXT NOT NULL,
  fishing_style TEXT NOT NULL,
  geom geometry(Point, 4326) NOT NULL,
  is_public BOOLEAN NOT NULL DEFAULT false,
  UNIQUE (name, province)
);
CREATE INDEX fishing_spots_geom_idx ON fishing_spots USING GIST (geom);

-- A derived value, refreshed after importing contours or soundings near a spot.
CREATE TABLE spot_depth_profiles (
  spot_id BIGINT PRIMARY KEY REFERENCES fishing_spots(id) ON DELETE CASCADE,
  min_depth_m NUMERIC(7,2),
  max_depth_m NUMERIC(7,2),
  typical_depth_m NUMERIC(7,2),
  sample_radius_m INTEGER NOT NULL DEFAULT 1000 CHECK (sample_radius_m BETWEEN 50 AND 10000),
  vertical_datum TEXT NOT NULL,
  source_id BIGINT NOT NULL REFERENCES data_sources(id),
  measured_at DATE,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE gear_rules (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  fishing_style TEXT NOT NULL,
  min_depth_m NUMERIC(7,2) NOT NULL CHECK (min_depth_m >= 0),
  max_depth_m NUMERIC(7,2) NOT NULL CHECK (max_depth_m >= min_depth_m),
  rod TEXT NOT NULL,
  reel TEXT NOT NULL,
  line_and_leader TEXT NOT NULL,
  lure_or_rig TEXT NOT NULL,
  safety_note TEXT NOT NULL,
  CHECK (max_depth_m <= 1000)
);

CREATE TABLE trip_plans (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  spot_id BIGINT NOT NULL REFERENCES fishing_spots(id),
  trip_date DATE NOT NULL,
  fishing_score SMALLINT CHECK (fishing_score BETWEEN 0 AND 100),
  score_inputs JSONB NOT NULL DEFAULT '{}'::jsonb,
  status TEXT NOT NULL DEFAULT 'planned' CHECK (status IN ('planned','completed','cancelled')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (spot_id, trip_date)
);

CREATE TABLE trip_gear_items (
  trip_id BIGINT NOT NULL REFERENCES trip_plans(id) ON DELETE CASCADE,
  gear_rule_id BIGINT REFERENCES gear_rules(id),
  item_name TEXT NOT NULL,
  is_packed BOOLEAN NOT NULL DEFAULT false,
  PRIMARY KEY (trip_id, item_name)
);

CREATE TABLE catch_logs (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  trip_id BIGINT NOT NULL REFERENCES trip_plans(id) ON DELETE CASCADE,
  species TEXT NOT NULL,
  weight_kg NUMERIC(6,2),
  caught_at TIMESTAMPTZ,
  notes TEXT
);

CREATE VIEW spot_planning_summary AS
SELECT s.id, s.name, s.province, s.fishing_style, p.typical_depth_m, p.min_depth_m, p.max_depth_m,
       p.sample_radius_m, ds.name AS depth_source, p.vertical_datum
FROM fishing_spots s
LEFT JOIN spot_depth_profiles p ON p.spot_id = s.id
LEFT JOIN data_sources ds ON ds.id = p.source_id;

-- Seed only the recommendation logic. Do not seed depth values until official geometry is imported.
INSERT INTO gear_rules (fishing_style, min_depth_m, max_depth_m, rod, reel, line_and_leader, lure_or_rig, safety_note) VALUES
('shore', 0, 5, 'คัน 6-10 lb', 'รอก 2000-3000', 'PE 0.8-1.2 + leader 16-20 lb', 'เหยื่อปลอมผิวน้ำ / จิ๊ก 7-15 g', 'ตรวจคลื่น กระแสน้ำ และพื้นที่ห้ามตกก่อนออกทริป'),
('shore', 5, 15, 'คัน 8-15 lb', 'รอก 3000-4000', 'PE 1.0-1.5 + leader 20-30 lb', 'จิ๊ก 15-30 g / หน้าดินเบา', 'ตรวจคลื่น กระแสน้ำ และพื้นที่ห้ามตกก่อนออกทริป'),
('boat', 10, 35, 'คัน 15-30 lb', 'รอก 5000 หรือ overhead', 'PE 2-3 + leader 30-50 lb', 'จิ๊ก 40-100 g / หน้าดิน', 'ความลึกไม่ใช่ข้อมูลเดินเรือ ใช้แผนที่เดินเรือและอุปกรณ์ความปลอดภัยที่ถูกต้อง'),
('boat', 35, 100, 'คัน 30-50 lb', 'overhead', 'PE 3-4 + leader 50-80 lb', 'จิ๊ก 100-200 g / หน้าดินหนัก', 'ความลึกไม่ใช่ข้อมูลเดินเรือ ใช้แผนที่เดินเรือและอุปกรณ์ความปลอดภัยที่ถูกต้อง');

-- After importing a spot profile, fetch applicable equipment for a planned trip:
-- SELECT g.* FROM trip_plans t JOIN fishing_spots s ON s.id=t.spot_id
-- JOIN spot_depth_profiles p ON p.spot_id=s.id JOIN gear_rules g ON g.fishing_style=s.fishing_style
-- WHERE t.id = :trip_id AND p.typical_depth_m BETWEEN g.min_depth_m AND g.max_depth_m;
