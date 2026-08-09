/**
 * สร้าง map/coastline-south.json — เส้นชายฝั่งภาคใต้สำหรับวาดแผนที่
 *
 *   node scripts/build-coastline.mjs
 *
 * ต้องมี topojson-client ก่อน:  npm install topojson-client@3
 * (เป็น dependency ของขั้นตอน build เท่านั้น หน้าเว็บไม่ได้ใช้ จึงไม่ต้องมี node_modules บนเซิร์ฟเวอร์)
 *
 * ทำไมต้องตัดเอง: ไฟล์ทั้งโลกที่ความละเอียด 10m ใหญ่ราว 3 MB
 * ถ้าส่งทั้งก้อนให้ผู้ใช้ที่เปิดจากมือถือกลางทะเลคือความโหดร้าย
 * ตัดเหลือเฉพาะกรอบภาคใต้แล้วลดจุดที่ละเอียดเกินจำเป็น เหลือไม่กี่สิบกิโลไบต์
 *
 * ทำไมไม่ใช้ tile จากเซิร์ฟเวอร์ภายนอก: นโยบายของ OpenStreetMap ระบุว่า
 * ใช้หนักต้องขออนุญาต และตัดการเข้าถึงได้โดยไม่แจ้งล่วงหน้า
 * เว็บที่คนเปิดดูก่อนออกทะเลไม่ควรพึ่งบริการที่หายไปเมื่อไหร่ก็ได้
 * วาดเองจากไฟล์ในเครื่องจึงพึ่งตัวเองได้ 100% และได้หน้าตาตามที่ออกแบบไว้พอดี
 *
 * ที่มาข้อมูล: Natural Earth 1:10m ผ่าน world-atlas (public domain)
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as topojson from 'topojson-client';
import { BBOX, TOLERANCE, inBox, clipPolygonToBox, simplify } from './geo.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = dirname(HERE);
const OUT = join(REPO, 'map', 'coastline-south.json');

const SOURCE = 'https://cdn.jsdelivr.net/npm/world-atlas@2/land-10m.json';

/** วงแหวนที่แตะกรอบภาคใต้เท่านั้นที่เก็บไว้ ที่เหลือทิ้ง */
function ringTouchesBox(ring) {
  return ring.some(inBox);
}

const raw = await fetch(SOURCE).then((r) => {
  if (!r.ok) throw new Error(`ดึง ${SOURCE} ไม่สำเร็จ: HTTP ${r.status}`);
  return r.json();
});

const land = topojson.feature(raw, raw.objects.land);

const polygons = [];
for (const feature of land.features) {
  const geom = feature.geometry;
  const parts = geom.type === 'Polygon' ? [geom.coordinates] : geom.coordinates;

  for (const rings of parts) {
    const kept = rings
      .filter(ringTouchesBox)
      // ตัดให้เหลือเฉพาะในกรอบก่อน แล้วค่อยลดจุด ลำดับนี้สำคัญ
      // ถ้าลดจุดก่อนตัด จะเสียรายละเอียดชายฝั่งไปกับการเกลี่ยเส้นที่อยู่นอกจอ
      .map(clipPolygonToBox)
      .filter((ring) => ring.length >= 4)
      .map((ring) => simplify(ring, TOLERANCE))
      // วงแหวนที่เหลือไม่ถึงสามจุดวาดเป็นรูปปิดไม่ได้
      .filter((ring) => ring.length >= 4);

    if (kept.length) polygons.push(kept);
  }
}

const out = {
  type: 'FeatureCollection',
  // ที่มาติดไปกับข้อมูลเสมอ เป็นกติกาเดียวกับ endpoint อื่นในโปรเจค
  metadata: {
    source: 'Natural Earth 1:10m land via world-atlas',
    source_url: SOURCE,
    license: 'public domain',
    bbox: [BBOX.west, BBOX.south, BBOX.east, BBOX.north],
    simplify_tolerance_deg: TOLERANCE,
    note: 'เส้นชายฝั่งสำหรับใช้เป็นฉากหลังของแผนที่เลือกหมาย ไม่ใช่แผนที่เดินเรือ',
  },
  features: polygons.map((coordinates) => ({
    type: 'Feature',
    properties: {},
    geometry: { type: 'Polygon', coordinates },
  })),
};

mkdirSync(dirname(OUT), { recursive: true });
writeFileSync(OUT, JSON.stringify(out), 'utf8');

const points = polygons.flat().reduce((n, ring) => n + ring.length, 0);
const kb = (Buffer.byteLength(JSON.stringify(out), 'utf8') / 1024).toFixed(1);
console.log(`wrote ${OUT}`);
console.log(`  ${out.features.length} polygons, ${points} points, ${kb} KB`);
