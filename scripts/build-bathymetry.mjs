/**
 * สร้าง map/depth-south.json — เส้นความลึกภาคใต้สำหรับวาดบนแผนที่เลือกหมาย
 *
 *   node scripts/build-bathymetry.mjs
 *   node scripts/build-bathymetry.mjs --refresh   ดึงกริดใหม่จาก ERDDAP
 *
 * ⚠️ อ่านก่อนใช้ผลลัพธ์จากไฟล์นี้
 *
 * ความละเอียดของกริดต้นทางคือ 1 ลิปดา ราว 1.85 กิโลเมตร
 * แปลว่าเส้นที่ได้บอกได้แค่ "แถบนี้ราว ๆ ลึกเท่านี้" ไม่ได้บอกว่าขอบน้ำลึกอยู่ตรงไหนเป๊ะ
 * และไม่มีหินโสโครก ไม่มีร่องน้ำ ไม่มีสิ่งกีดขวางใด ๆ ทั้งสิ้น
 *
 * เส้นเหล่านี้จึงต้องวาดเป็น "เส้นประ" เสมอ ซึ่งเป็นสัญลักษณ์มาตรฐานของ IHO
 * ที่หมายถึงข้อมูลความเชื่อมั่นต่ำ (ZOC C) — คนที่อ่านแผนที่เดินเรือเป็นจะเข้าใจทันที
 * ห้ามเปลี่ยนเป็นเส้นทึบ เพราะเส้นทึบแปลว่า "สำรวจมาแล้ว" ซึ่งไม่จริงสำหรับข้อมูลชุดนี้
 *
 * ห้ามใส่ตัวเลขความลึกกระจายทั่วแผนที่ (soundings) เด็ดขาด
 * นั่นคือองค์ประกอบที่ทำให้อ่านเป็นแผนที่เดินเรือมากที่สุด และอันตรายที่สุด
 *
 * ที่มาข้อมูล: ETOPO ผ่าน NOAA ERDDAP (public domain)
 */
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = dirname(HERE);
const RAW = join(HERE, 'bathy-south.csv');
const OUT = join(REPO, 'map', 'depth-south.json');

const SOURCE = 'https://upwell.pfeg.noaa.gov/erddap/griddap/etopo180.csv';
const BBOX = { west: 96.0, east: 103.5, south: 4.5, north: 12.5 };

/**
 * ระดับความลึกที่วาด หน่วยเมตร
 * เลือกให้ครอบคลุมทั้งอ่าวไทย (ตื้น ส่วนใหญ่ไม่เกิน 80 ม.)
 * และฝั่งอันดามันที่ลาดลงไหล่ทวีปเร็วกว่า
 * ไม่วาดตื้นกว่า 10 ม. เพราะที่ความละเอียด 1.85 กม. เส้นจะเป็นขยะล้วน
 */
const LEVELS = [10, 20, 30, 50, 100, 200];

/** ลดจุดบนเส้น หน่วยองศา — 0.01 องศา ราว 1 กม. ละเอียดกว่านี้ไม่มีความหมาย */
const TOLERANCE = 0.01;

/** เส้นที่สั้นกว่านี้เป็นเศษเล็กเศษน้อย ตัดทิ้งเพื่อไม่ให้แผนที่รก */
const MIN_SEGMENT_POINTS = 6;

async function fetchGrid() {
  const url = `${SOURCE}?altitude%5B(${BBOX.south}):(${BBOX.north})%5D%5B(${BBOX.west}):(${BBOX.east})%5D`;
  process.stdout.write('ดึงกริดความลึกจาก ERDDAP ...\n');
  const response = await fetch(url);
  if (!response.ok) throw new Error(`ERDDAP ตอบ HTTP ${response.status}`);
  const text = await response.text();
  writeFileSync(RAW, text, 'utf8');
  return text;
}

/** อ่าน CSV เป็นตาราง depth[row][col] โดย depth เป็นบวกเมื่ออยู่ใต้น้ำ */
function parseGrid(csv) {
  const lines = csv.split('\n');
  const lats = new Set();
  const lons = new Set();
  const values = new Map();

  // สองบรรทัดแรกเป็นหัวตารางกับหน่วย
  for (let i = 2; i < lines.length; i++) {
    const line = lines[i];
    if (!line) continue;
    const [latText, lonText, altText] = line.split(',');
    if (altText === undefined) continue;
    const lat = Number(latText);
    const lon = Number(lonText);
    const alt = Number(altText);
    if (!Number.isFinite(lat) || !Number.isFinite(lon) || !Number.isFinite(alt)) continue;
    lats.add(lat);
    lons.add(lon);
    // altitude เป็นบวกบนบก ลบใต้น้ำ — เราสนใจความลึกจึงกลับเครื่องหมาย
    values.set(`${lat},${lon}`, -alt);
  }

  const latList = [...lats].sort((a, b) => a - b);
  const lonList = [...lons].sort((a, b) => a - b);
  const grid = latList.map((lat) => lonList.map((lon) => values.get(`${lat},${lon}`) ?? NaN));

  return { lats: latList, lons: lonList, grid };
}

/**
 * ดึงเส้นชั้นความลึกด้วย marching squares
 *
 * เดินทีละสี่เหลี่ยมของกริด ดูว่าด้านไหนถูกระดับที่ต้องการตัดผ่าน
 * แล้วต่อจุดตัดเป็นเส้นสั้น ๆ จากนั้นค่อยเอาเส้นสั้นมาต่อกันเป็นเส้นยาว
 *
 * ใช้วิธีนี้เพราะตรงไปตรงมาและตรวจสอบได้ ไม่ต้องพึ่ง library ภายนอก
 * ซึ่งสำคัญเพราะสคริปต์นี้เป็นส่วนหนึ่งของขั้นตอน build ที่ต้องรันซ้ำได้ในอนาคต
 */
function contourSegments({ lats, lons, grid }, level) {
  const segments = [];

  // จุดตัดบนด้านหนึ่งของสี่เหลี่ยม หาโดยการประมาณเชิงเส้นระหว่างสองมุม
  const cut = (v1, v2, p1, p2) => {
    const t = (level - v1) / (v2 - v1);
    return [p1[0] + t * (p2[0] - p1[0]), p1[1] + t * (p2[1] - p1[1])];
  };

  for (let r = 0; r < lats.length - 1; r++) {
    for (let c = 0; c < lons.length - 1; c++) {
      const v = [grid[r][c], grid[r][c + 1], grid[r + 1][c + 1], grid[r + 1][c]];
      if (v.some((x) => !Number.isFinite(x))) continue;

      const p = [
        [lons[c], lats[r]],
        [lons[c + 1], lats[r]],
        [lons[c + 1], lats[r + 1]],
        [lons[c], lats[r + 1]],
      ];

      const crossings = [];
      for (let e = 0; e < 4; e++) {
        const a = v[e];
        const b = v[(e + 1) % 4];
        if ((a >= level) !== (b >= level)) {
          crossings.push(cut(a, b, p[e], p[(e + 1) % 4]));
        }
      }

      // สองจุดตัด = เส้นเดียวผ่านช่องนี้ · สี่จุด = อานม้า ข้ามไปเพื่อไม่ให้ต่อเส้นผิด
      if (crossings.length === 2) segments.push(crossings);
    }
  }

  return segments;
}

/** ต่อเส้นสั้นที่ปลายชนกันให้เป็นเส้นยาว */
function stitch(segments) {
  const key = (pt) => `${pt[0].toFixed(5)},${pt[1].toFixed(5)}`;
  const heads = new Map();

  for (const [a, b] of segments) {
    if (!heads.has(key(a))) heads.set(key(a), []);
    if (!heads.has(key(b))) heads.set(key(b), []);
    heads.get(key(a)).push([a, b]);
    heads.get(key(b)).push([b, a]);
  }

  const used = new Set();
  const lines = [];

  for (const segment of segments) {
    const id = key(segment[0]) + '|' + key(segment[1]);
    if (used.has(id)) continue;

    const line = [segment[0], segment[1]];
    used.add(id);
    used.add(key(segment[1]) + '|' + key(segment[0]));

    // เดินต่อไปทางปลายเส้นจนกว่าจะไม่มีอะไรต่อ
    for (let guard = 0; guard < 100000; guard++) {
      const tail = line[line.length - 1];
      const next = (heads.get(key(tail)) || []).find(
        ([from, to]) => !used.has(key(from) + '|' + key(to))
      );
      if (!next) break;
      used.add(key(next[0]) + '|' + key(next[1]));
      used.add(key(next[1]) + '|' + key(next[0]));
      line.push(next[1]);
    }

    lines.push(line);
  }

  return lines;
}

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
      if (sq > maxSq) { maxSq = sq; index = i; }
    }

    if (maxSq > sqTol && index > 0) {
      keep[index] = true;
      stack.push([first, index], [index, last]);
    }
  }

  return points.filter((_, i) => keep[i]);
}

const csv = process.argv.includes('--refresh') || !existsSync(RAW)
  ? await fetchGrid()
  : (console.log(`ใช้ ${RAW} ที่ดึงไว้แล้ว (ใส่ --refresh เพื่อดึงใหม่)`), readFileSync(RAW, 'utf8'));

const parsed = parseGrid(csv);
console.log(`กริด ${parsed.lats.length} x ${parsed.lons.length} จุด`);

const features = [];
for (const level of LEVELS) {
  const raw = stitch(contourSegments(parsed, level));
  const lines = raw
    .map((line) => simplify(line, TOLERANCE).map(([x, y]) => [Number(x.toFixed(4)), Number(y.toFixed(4))]))
    .filter((line) => line.length >= MIN_SEGMENT_POINTS);

  const points = lines.reduce((n, l) => n + l.length, 0);
  console.log(`  ${String(level).padStart(3)} ม.: ${String(lines.length).padStart(4)} เส้น ${String(points).padStart(6)} จุด`);

  if (lines.length) {
    features.push({
      type: 'Feature',
      properties: { depth_m: level },
      geometry: { type: 'MultiLineString', coordinates: lines },
    });
  }
}

const out = {
  type: 'FeatureCollection',
  metadata: {
    source: 'ETOPO global relief via NOAA ERDDAP (etopo180)',
    source_url: SOURCE,
    license: 'public domain',
    resolution: '1 arc-minute (~1.85 km)',
    levels_m: LEVELS,
    datum: 'ประมาณระดับน้ำทะเลปานกลาง',
    notice: 'เส้นความลึกโดยประมาณ ความละเอียดต่ำ ไม่มีหินโสโครกหรือร่องน้ำ '
          + 'ใช้ดูภาพรวมพื้นท้องทะเลเพื่อวางแผนตกปลาเท่านั้น ห้ามใช้เพื่อการเดินเรือ',
    render_hint: 'ต้องวาดเป็นเส้นประเสมอ ตามสัญลักษณ์ IHO ที่หมายถึงข้อมูลความเชื่อมั่นต่ำ',
  },
  features,
};

mkdirSync(dirname(OUT), { recursive: true });
writeFileSync(OUT, JSON.stringify(out), 'utf8');

const kb = (Buffer.byteLength(JSON.stringify(out), 'utf8') / 1024).toFixed(1);
console.log(`\nwrote ${OUT}`);
console.log(`  ${features.length} ระดับ, ${kb} KB`);
