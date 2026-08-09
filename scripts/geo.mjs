/**
 * geo.mjs — เครื่องมือเรขาคณิตที่สคริปต์สร้างข้อมูลแผนที่ใช้ร่วมกัน
 *
 * แยกออกมาเพราะ build-coastline.mjs กับ build-borders.mjs ต้องตัดกรอบและลดจุด
 * ด้วยกติกาเดียวกันเป๊ะ ถ้าต่างคนต่างเขียน สักวันชายฝั่งกับเส้นเขตแดน
 * จะถูกลดจุดคนละระดับแล้ววาดออกมาไม่แนบกัน ซึ่งบนแผนที่จะเห็นเป็นช่องว่างทันที
 *
 * รูปหลายเหลี่ยมกับเส้นต้องใช้วิธีตัดคนละแบบ ดูเหตุผลที่แต่ละฟังก์ชัน
 */

/** ตัวเลขความละเอียดที่ทั้งโปรเจคใช้ร่วมกัน หน่วยองศา — 0.004 องศา ≈ 400 เมตร
    ละเอียดกว่านี้ไม่มีประโยชน์เพราะข้อมูลต้นทาง 1:10m เองก็หยาบกว่านั้น */
export const TOLERANCE = 0.004;

/** กรอบภาคใต้ เผื่อขอบไว้ให้เลื่อนแผนที่ได้นิดหน่อยโดยไม่เจอขอบขาว */
export const BBOX = { west: 96.0, east: 103.5, south: 4.5, north: 12.5 };

/* รับแค่จุดเดียว ไม่มีพารามิเตอร์กรอบ เจตนา:
   เคยเขียนเป็น inBox(point, box = BBOX) แล้วเรียกด้วย ring.some(inBox)
   ซึ่ง Array.some ส่ง index มาเป็นอาร์กิวเมนต์ที่สอง ค่า box จึงกลายเป็นตัวเลข
   เทียบ box.west ได้ undefined ทุกจุดตกหมด ผลลัพธ์เหลือศูนย์รูปโดยไม่มี error
   ทั้งโปรเจคใช้กรอบเดียวอยู่แล้ว จึงตัดพารามิเตอร์ทิ้งไปเลยแทนที่จะระวังเอาเอง */
export function inBox([lon, lat]) {
  return lon >= BBOX.west && lon <= BBOX.east && lat >= BBOX.south && lat <= BBOX.north;
}

function cutX([x1, y1], [x2, y2], x) {
  const t = (x - x1) / (x2 - x1);
  return [x, y1 + t * (y2 - y1)];
}

function cutY([x1, y1], [x2, y2], y) {
  const t = (y - y1) / (y2 - y1);
  return [x1 + t * (x2 - x1), y];
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
export function clipPolygonToBox(ring) {
  const box = BBOX;
  const edges = [
    { keep: ([lon]) => lon >= box.west, cut: (a, b) => cutX(a, b, box.west) },
    { keep: ([lon]) => lon <= box.east, cut: (a, b) => cutX(a, b, box.east) },
    { keep: ([, lat]) => lat >= box.south, cut: (a, b) => cutY(a, b, box.south) },
    { keep: ([, lat]) => lat <= box.north, cut: (a, b) => cutY(a, b, box.north) },
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

/**
 * ตัดเส้น (ไม่ใช่รูปปิด) ให้เหลือเฉพาะช่วงที่อยู่ในกรอบ
 *
 * ห้ามใช้ Sutherland-Hodgman กับเส้น เพราะวิธีนั้นถือว่าจุดสุดท้ายต่อกลับไปจุดแรก
 * เส้นเขตแดนที่เข้าออกกรอบสองครั้งจะถูกเชื่อมปลายทั้งสองเข้าหากัน
 * กลายเป็นเส้นตรงพาดกลางทะเลที่ไม่มีอยู่จริง
 *
 * จึงตัดทีละท่อนแทน แล้วคืนเป็นหลายเส้นย่อย — เส้นเดียวอาจแตกเป็นหลายท่อนได้
 */
export function clipLineToBox(line) {
  const box = BBOX;
  const pieces = [];
  let current = [];

  const push = () => {
    if (current.length >= 2) pieces.push(current);
    current = [];
  };

  for (let i = 0; i < line.length - 1; i++) {
    const a = line[i];
    const b = line[i + 1];
    const segment = clipSegment(a, b, box);

    if (!segment) {
      push();
      continue;
    }

    const [start, end] = segment;
    if (!current.length) {
      current.push(start);
    } else {
      const last = current[current.length - 1];
      // ท่อนก่อนหน้าจบตรงขอบแล้วท่อนนี้เริ่มที่อื่น แปลว่าเส้นออกนอกกรอบไปแล้วกลับเข้ามา
      // ต้องขึ้นเส้นใหม่ ไม่ใช่ลากต่อ ไม่งั้นจะได้เส้นลัดที่ไม่มีในความจริง
      if (Math.abs(last[0] - start[0]) > 1e-9 || Math.abs(last[1] - start[1]) > 1e-9) {
        push();
        current.push(start);
      }
    }
    current.push(end);
  }
  push();

  return pieces;
}

/** ตัดส่วนของเส้นตรงหนึ่งท่อนด้วยวิธี Liang-Barsky คืน null ถ้าอยู่นอกกรอบทั้งท่อน */
function clipSegment([x1, y1], [x2, y2], box) {
  const dx = x2 - x1;
  const dy = y2 - y1;
  let t0 = 0;
  let t1 = 1;

  const clip = (p, q) => {
    if (p === 0) return q >= 0; // ขนานกับขอบนี้ อยู่ในกรอบก็ต่อเมื่อ q ไม่ติดลบ
    const r = q / p;
    if (p < 0) {
      if (r > t1) return false;
      if (r > t0) t0 = r;
    } else {
      if (r < t0) return false;
      if (r < t1) t1 = r;
    }
    return true;
  };

  if (!clip(-dx, x1 - box.west)) return null;
  if (!clip(dx, box.east - x1)) return null;
  if (!clip(-dy, y1 - box.south)) return null;
  if (!clip(dy, box.north - y1)) return null;

  return [
    [x1 + t0 * dx, y1 + t0 * dy],
    [x1 + t1 * dx, y1 + t1 * dy],
  ];
}

/** ลดจำนวนจุดแบบ Douglas-Peucker อย่างง่าย โดยวัดระยะตั้งฉากจากคอร์ด */
export function simplify(points, tolerance = TOLERANCE) {
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
