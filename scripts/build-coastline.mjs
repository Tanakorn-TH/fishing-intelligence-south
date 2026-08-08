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

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = dirname(HERE);
const OUT = join(REPO, 'map', 'coastline-south.json');

const SOURCE = 'https://cdn.jsdelivr.net/npm/world-atlas@2/land-10m.json';

/* กรอบภาคใต้ เผื่อขอบไว้ให้เลื่อนแผนที่ได้นิดหน่อยโดยไม่เจอขอบขาว */
const BBOX = { west: 96.0, east: 103.5, south: 4.5, north: 12.5 };

/* ความละเอียดที่เก็บไว้ หน่วยองศา — 0.004 องศา ≈ 400 เมตร
   ละเอียดกว่านี้ไม่มีประโยชน์เพราะข้อมูลต้นทางเองก็หยาบกว่านั้น
   และไฟล์จะใหญ่ขึ้นโดยผู้ใช้ไม่ได้อะไรเพิ่ม */
const TOLERANCE = 0.004;

function inBox([lon, lat]) {
  return lon >= BBOX.west && lon <= BBOX.east && lat >= BBOX.south && lat <= BBOX.north;
}

/**
 * ตัดรูปหลายเหลี่ยมให้เหลือเฉพาะส่วนในกรอบ ด้วยวิธี Sutherland-Hodgman
 *
 * จำเป็นมาก ไม่ใช่ของแถม: วงแหวนที่ "แตะ" กรอบภาคใต้คือแผ่นดินยูเรเชียทั้งผืน
 * ถ้าเก็บทั้งวงจะได้ไฟล์ระดับเมกะไบต์ทั้งที่ผู้ใช้เห็นแค่มุมเล็ก ๆ ของมัน
 * ตัดก่อนแล้วค่อยลดจุด เหลือแค่ชายฝั่งที่อยู่ในจอจริง
 *
 * ใช้ได้เพราะกรอบเป็นสี่เหลี่ยมนูน ซึ่งเป็นเงื่อนไขของวิธีนี้
 */
function clipToBox(ring) {
  const edges = [
    { keep: ([lon]) => lon >= BBOX.west, cut: (a, b) => cutX(a, b, BBOX.west) },
    { keep: ([lon]) => lon <= BBOX.east, cut: (a, b) => cutX(a, b, BBOX.east) },
    { keep: ([, lat]) => lat >= BBOX.south, cut: (a, b) => cutY(a, b, BBOX.south) },
    { keep: ([, lat]) => lat <= BBOX.north, cut: (a, b) => cutY(a, b, BBOX.north) },
  ];

  let output = ring;
  for (const edge of edges) {
    const input = output;
    output = [];
    for (let i = 0; i < input.length; i++) {
      const current = input[i];
      const previous = input[(i + input.length - 1) % input.length];
      const currentIn = edge.keep(current);
      const previousIn = edge.keep(previous);

      if (currentIn) {
        if (!previousIn) output.push(edge.cut(previous, current));
        output.push(current);
      } else if (previousIn) {
        output.push(edge.cut(previous, current));
      }
    }
    if (!output.length) return [];
  }
  return output;
}

function cutX([x1, y1], [x2, y2], x) {
  const t = (x - x1) / (x2 - x1);
  return [x, y1 + t * (y2 - y1)];
}

function cutY([x1, y1], [x2, y2], y) {
  const t = (y - y1) / (y2 - y1);
  return [x1 + t * (x2 - x1), y];
}

/** ลดจำนวนจุดแบบ Douglas-Peucker อย่างง่าย โดยวัดระยะตั้งฉากจากคอร์ด */
function simplify(points, tolerance) {
  if (points.length < 3) return points;

  const sqTol = tolerance * tolerance;
  const keep = new Array(points.length).fill(false);
  keep[0] = keep[points.length - 1] = true;

  const stack = [[0, points.length - 1]];
  while (stack.length) {
    const [first, last] = stack.pop();
    let maxSq = 0;
    let index = 0;

    const [x1, y1] = points[first];
    const [x2, y2] = points[last];
    const dx = x2 - x1;
    const dy = y2 - y1;
    const len = dx * dx + dy * dy;

    for (let i = first + 1; i < last; i++) {
      const [px, py] = points[i];
      let t = len === 0 ? 0 : ((px - x1) * dx + (py - y1) * dy) / len;
      t = Math.max(0, Math.min(1, t));
      const ex = x1 + t * dx - px;
      const ey = y1 + t * dy - py;
      const sq = ex * ex + ey * ey;
      if (sq > maxSq) {
        maxSq = sq;
        index = i;
      }
    }

    if (maxSq > sqTol && index > 0) {
      keep[index] = true;
      stack.push([first, index], [index, last]);
    }
  }

  return points.filter((_, i) => keep[i]);
}

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
      .map(clipToBox)
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
