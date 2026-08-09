/**
 * สร้าง map/borders-south.json — เส้นเขตแดนระหว่างประเทศและเส้นแบ่งจังหวัด
 *
 *   node scripts/build-borders.mjs
 *
 * ทำไมต้องมีเส้นพวกนี้บนแผนที่ตกปลา:
 * คนออกเรือแถบนราธิวาส-สตูลอยู่ห่างน่านน้ำมาเลเซียไม่กี่สิบกิโล
 * แผนที่ที่ไม่บอกว่าเส้นแบ่งอยู่ไหนเลยคือแผนที่ที่ปล่อยให้คนเดาเอง
 * ส่วนเส้นจังหวัดช่วยให้เทียบตำแหน่งกับที่ตัวเองรู้จักได้เร็วขึ้นมาก
 *
 * ⚠️ เส้นเขตแดนชุดนี้มาจาก Natural Earth 1:10m (คลาดเคลื่อนได้ระดับกิโลเมตร)
 * ใช้ดูภาพรวมเท่านั้น ห้ามใช้ตัดสินว่าอยู่ในน่านน้ำประเทศไหน
 * และเส้นในทะเลไม่ใช่เขตแดนทางทะเล — Natural Earth ให้เฉพาะเขตแดนบนบก
 *
 * ที่มาข้อมูล: Natural Earth 1:10m (public domain)
 */
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { BBOX, TOLERANCE, clipLineToBox, simplify } from './geo.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = dirname(HERE);
const OUT = join(REPO, 'map', 'borders-south.json');

const BASE = 'https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson';
const SOURCES = {
  country: `${BASE}/ne_10m_admin_0_boundary_lines_land.geojson`,
  province: `${BASE}/ne_10m_admin_1_states_provinces_lines.geojson`,
};

/* เส้นจังหวัดของทั้งโลกมี 21 MB แต่เราสนใจแค่ไทย จึงกรองก่อนตัดกรอบ
   ไม่งั้นเส้นรัฐของมาเลเซียกับเมียนมาจะโผล่มาปนโดยไม่ได้ตั้งใจ

   ใช้รหัส ISO ไม่ใช่ชื่อประเทศ เพราะชื่อเป็นข้อความสำหรับแสดงผลซึ่งเปลี่ยนได้ทุกรุ่น
   ส่วนรหัสสามตัวเป็นมาตรฐานที่ Natural Earth ผูกไว้กับ ISO 3166 */
const THAILAND_A3 = 'THA';

async function fetchJson(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error(`ดึง ${url} ไม่สำเร็จ: HTTP ${response.status}`);
  return response.json();
}

/** แปลง feature เส้นหนึ่งอัน (LineString หรือ MultiLineString) ให้เป็นเส้นย่อยที่อยู่ในกรอบ */
function linesInBox(feature) {
  const geom = feature.geometry;
  if (!geom) return [];
  const lines = geom.type === 'LineString' ? [geom.coordinates] : geom.coordinates;

  return lines
    // ตัดก่อนแล้วค่อยลดจุด ลำดับเดียวกับ build-coastline.mjs
    // ถ้าลดจุดก่อน จะเสียรายละเอียดในจอไปกับการเกลี่ยเส้นที่อยู่นอกจอ
    .flatMap((line) => clipLineToBox(line))
    .map((line) => simplify(line, TOLERANCE))
    .filter((line) => line.length >= 2);
}

const features = [];
const counts = {};

for (const [kind, url] of Object.entries(SOURCES)) {
  const collection = await fetchJson(url);

  const wanted = collection.features.filter((feature) => {
    if (kind !== 'province') return true;
    return (feature.properties || {}).ADM0_A3 === THAILAND_A3;
  });

  let lineCount = 0;
  for (const feature of wanted) {
    for (const line of linesInBox(feature)) {
      features.push({
        type: 'Feature',
        properties: { kind },
        geometry: { type: 'LineString', coordinates: line },
      });
      lineCount++;
    }
  }
  counts[kind] = lineCount;
}

if (!counts.country) throw new Error('ไม่พบเส้นเขตแดนระหว่างประเทศในกรอบภาคใต้ — ตรวจแหล่งข้อมูล');
if (!counts.province) throw new Error('ไม่พบเส้นแบ่งจังหวัดของไทยในกรอบภาคใต้ — ตรวจชื่อคีย์ประเทศ');

const out = {
  type: 'FeatureCollection',
  // ที่มาติดไปกับข้อมูลเสมอ เป็นกติกาเดียวกับ endpoint อื่นในโปรเจค
  metadata: {
    source: 'Natural Earth 1:10m admin boundary lines',
    source_urls: SOURCES,
    license: 'public domain',
    bbox: [BBOX.west, BBOX.south, BBOX.east, BBOX.north],
    simplify_tolerance_deg: TOLERANCE,
    note: 'เส้นเขตแดนบนบกสำหรับใช้อ้างอิงสายตาบนแผนที่ ไม่ใช่เขตแดนทางทะเล '
      + 'และห้ามใช้ตัดสินว่าอยู่ในน่านน้ำประเทศใด',
  },
  features,
};

mkdirSync(dirname(OUT), { recursive: true });
writeFileSync(OUT, JSON.stringify(out), 'utf8');

const points = features.reduce((n, f) => n + f.geometry.coordinates.length, 0);
const kb = (Buffer.byteLength(JSON.stringify(out), 'utf8') / 1024).toFixed(1);
console.log(`wrote ${OUT}`);
console.log(`  เขตแดนประเทศ ${counts.country} เส้น, เขตจังหวัด ${counts.province} เส้น`);
console.log(`  รวม ${points} จุด, ${kb} KB`);
