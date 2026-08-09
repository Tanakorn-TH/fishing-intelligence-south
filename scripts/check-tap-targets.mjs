/* วัดพื้นที่กดของทุกอย่างที่กดได้ หลายช่วงจอพร้อมกัน
   เกณฑ์ 44px ตาม docs/design.md §7.4 — ผู้ใช้ยืนอยู่บนเรือที่โคลง เล็งยาก

   ทำไมต้องเป็นสคริปต์ ไม่ใช่ตรวจด้วยตา:
   ไล่เก็บด้วยมือมาแล้วสามรอบ แต่ละรอบเจอของตกหล่นเพิ่มเพราะ
   - รอบแรกวัดบน static server ที่ไม่มี API ส่วนที่รอข้อมูลจึงไม่ถูกสร้าง ไม่มีให้วัด
   - รอบสองวัดแค่ 390px เมนูข้างที่โผล่เกิน 780px จึงไม่เคยถูกวัด
   ทั้งสองอย่างเป็นความผิดพลาดของ "ขอบเขตการวัด" ไม่ใช่ของเกณฑ์
   สคริปต์นี้จึงกำหนดขอบเขตไว้ตายตัวและรันทุก PR

   ต้องรันกับเซิร์ฟเวอร์ที่ API ทำงานจริง ไม่ใช่ static server
   ไม่งั้นจะพลาดแบบเดิมอีก

   ใช้: TAP_BASE=http://127.0.0.1:8080 node scripts/check-tap-targets.mjs
   ต้องมี playwright: npm install --no-save playwright && npx playwright install chromium
*/
import { chromium } from 'playwright';

const BASE = process.env.TAP_BASE || 'http://127.0.0.1:8080';
const MIN = 44;

/* ทดสอบทั้งสามช่วงโดยเปิดโหมดสัมผัสทุกช่วง
   เพราะเกณฑ์ผูกกับ pointer: coarse ไม่ใช่ความกว้างจอ — แท็บเล็ตและจอสัมผัส
   ขนาดใหญ่ก็ใช้นิ้วเหมือนกัน (docs/design.md §7.4) */
const SCREENS = [
  { label: 'มือถือ', width: 390, height: 844, isMobile: true },
  { label: 'แท็บเล็ต', width: 820, height: 1180, isMobile: false },
  { label: 'จอสัมผัสใหญ่', width: 1280, height: 900, isMobile: false },
];

const measure = (minSize) => {
  const SELECTOR = 'button, a, [role="button"], summary, input, select, textarea';
  const seen = [];
  document.querySelectorAll(SELECTOR).forEach((el) => {
    const rect = el.getBoundingClientRect();
    if (rect.width === 0 || rect.height === 0) return;          // ซ่อนอยู่
    const dialog = el.closest('dialog');
    if (dialog && !dialog.open) return;                          // อยู่ในกล่องที่ยังไม่เปิด
    if (el.disabled) return;                                     // กดไม่ได้อยู่แล้ว

    /* พื้นที่กดจริงอาจใหญ่กว่ากล่อง ถ้ามี ::after วางทับไว้ขยายให้
       (วิธีที่ design.css ใช้กับปุ่มที่โตเองไม่ได้เพราะอยู่ในแถวที่จัดระยะแล้ว) */
    let width = rect.width;
    let height = rect.height;
    for (const pseudo of ['::after', '::before']) {
      const style = getComputedStyle(el, pseudo);
      if (style.content === 'none' || style.position !== 'absolute') continue;
      width = Math.max(width, parseFloat(style.width) || 0);
      height = Math.max(height, parseFloat(style.height) || 0);
    }

    if (width >= minSize && height >= minSize) return;

    const name = String(el.className || '').trim().split(/\s+/)[0] || el.tagName.toLowerCase();
    const label = (el.getAttribute('aria-label') || el.textContent || '').replace(/\s+/g, ' ').trim();
    seen.push({ name, label: label.slice(0, 24), w: Math.round(width), h: Math.round(height) });
  });
  return seen;
};

const browser = await chromium.launch();
const failures = [];
let checked = 0;

for (const screen of SCREENS) {
  const context = await browser.newContext({
    viewport: { width: screen.width, height: screen.height },
    hasTouch: true,
    isMobile: screen.isMobile,
  });
  const page = await context.newPage();

  let response;
  try {
    response = await page.goto(BASE, { waitUntil: 'networkidle', timeout: 45000 });
  } catch (error) {
    console.error(`เปิด ${BASE} ไม่ได้ — ${error.message.split('\n')[0]}`);
    await browser.close();
    process.exit(1);
  }
  if (!response || !response.ok()) {
    console.error(`เปิด ${BASE} ได้สถานะ ${response ? response.status() : 'ไม่มีคำตอบ'}`);
    await browser.close();
    process.exit(1);
  }
  // รอให้ส่วนที่ต้องรอ API วาดเสร็จก่อน ไม่งั้นวัดไม่ครบเหมือนที่เคยพลาดมาแล้ว
  await page.waitForTimeout(2500);

  const coarse = await page.evaluate(() => matchMedia('(pointer: coarse)').matches);
  const total = await page.evaluate(() => document.querySelectorAll(
    'button, a, [role="button"], summary, input, select, textarea').length);
  const small = await page.evaluate(measure, MIN);
  checked += total;

  const head = `${screen.label} ${screen.width}px  (pointer: coarse = ${coarse ? 'ใช่' : 'ไม่ใช่'})`;
  if (small.length === 0) {
    console.log(`  ผ่าน    ${head} — ตรวจ ${total} จุด`);
  } else {
    console.log(`  ไม่ผ่าน ${head} — ${small.length} จุดเล็กเกินไป`);
    // ยุบรายการที่ซ้ำกัน เมนู 8 รายการที่พลาดเหมือนกันไม่ต้องพิมพ์แปดบรรทัด
    const grouped = new Map();
    for (const item of small) {
      const key = `${item.name} ${item.w}x${item.h}`;
      grouped.set(key, (grouped.get(key) || 0) + 1);
    }
    for (const [key, count] of grouped) {
      console.log(`            ${key}${count > 1 ? `  (${count} จุด)` : ''}`);
    }
    failures.push({ screen: head, small });
  }

  await context.close();
}

await browser.close();

if (failures.length > 0) {
  console.error(`\nพื้นที่กดต้องกว้างและสูงอย่างน้อย ${MIN}px เมื่ออยู่บนอุปกรณ์สัมผัส`);
  console.error('ขยายด้วยวิธีที่ไม่เปลี่ยนเลย์เอาต์ ดูตัวอย่างใน design.css หัวข้อ "พื้นที่กดบนเรือ":');
  console.error('  - อิลิเมนต์ inline        ใช้ padding แนวตั้ง + margin ติดลบ');
  console.error('  - อย่างอื่นในแถวที่จัดระยะแล้ว  ใช้ ::after วางทับตรงกลาง');
  process.exit(1);
}

console.log(`\nพื้นที่กดผ่านเกณฑ์ ${MIN}px ครบทุกช่วงจอ (ตรวจรวม ${checked} จุด)`);
