/* เก็บสีที่คำนวณได้จริงของทุกอิลิเมนต์ ไว้เทียบก่อน-หลังตอนแก้ CSS
   ใช้ตอนรีแฟกเตอร์ที่ "ห้ามให้หน้าตาเปลี่ยน" เช่นตอนยุบสีดิบเป็นตัวแปร
   ไม่ได้อยู่ใน CI เพราะต้องมีไฟล์อ้างอิงที่จะเปลี่ยนทุกครั้งที่ดีไซน์เปลี่ยนโดยตั้งใจ

   ต้องมี playwright: npm install --no-save playwright && npx playwright install chromium

   ⚠️ บางสีมาจากข้อมูลสด ไม่ได้มาจาก CSS — เช่นจุดสีคลอโรฟิลล์ที่ app.js
   เลือกเฉดตามค่าที่ API คืนมา ถ้าเก็บ before กับ after ห่างกันหลายนาที
   ค่าอาจเปลี่ยนเองแล้วอ่านเป็น "รีแฟกเตอร์ทำพัง" ทั้งที่ไม่ใช่
   วิธีตัดตัวแปรนี้: สลับไฟล์ CSS สองเวอร์ชันแล้วเก็บติดกันในรอบเดียว */
import { chromium } from 'playwright';
import { writeFileSync } from 'node:fs';

const BASE = process.argv[2];
const OUT = process.argv[3];
if (!BASE || !OUT) {
  console.error('ใช้: node snapshot-colors.mjs <base-url> <ไฟล์ผลลัพธ์>');
  process.exit(1);
}

const PROPS = [
  'color', 'backgroundColor', 'backgroundImage',
  'borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor',
  'outlineColor', 'textDecorationColor', 'caretColor', 'fill', 'stroke',
  'boxShadow', 'textShadow',
];

const SCREENS = [
  { label: '390', width: 390, height: 844, isMobile: true },
  { label: '820', width: 820, height: 1180, isMobile: false },
  { label: '1280', width: 1280, height: 900, isMobile: false },
];

const grab = (props) => {
  const rows = [];
  const walk = (root, path) => {
    const list = root.querySelectorAll('*');
    list.forEach((el, i) => {
      const s = getComputedStyle(el);
      const rec = {};
      for (const p of props) rec[p] = s[p];
      // เก็บ ::before / ::after ด้วย เพราะดีไซน์นี้ใช้มันวาดขอบและแสง
      for (const pseudo of ['::before', '::after']) {
        const ps = getComputedStyle(el, pseudo);
        if (ps.content && ps.content !== 'none') {
          for (const p of props) rec[pseudo + p] = ps[p];
        }
      }
      const tag = el.tagName.toLowerCase();
      const cls = String(el.className || '').trim().split(/\s+/).filter(Boolean).sort().join('.');
      rows.push({ key: `${path}${i}:${tag}${cls ? '.' + cls : ''}`, ...rec });
    });
  };
  walk(document, '');
  return rows;
};

const browser = await chromium.launch();
const snapshot = {};

for (const screen of SCREENS) {
  const context = await browser.newContext({
    viewport: { width: screen.width, height: screen.height },
    hasTouch: true, isMobile: screen.isMobile,
  });
  const page = await context.newPage();
  await page.goto(BASE, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(2500);
  // เปิดกล่องโต้ตอบด้วย เพื่อให้สีข้างในถูกเก็บ ไม่งั้นรีแฟกเตอร์พังตรงนั้นแล้วไม่รู้
  await page.evaluate(() => {
    const dlg = document.getElementById('planner');
    if (dlg && typeof dlg.showModal === 'function' && !dlg.open) {
      try { dlg.showModal(); } catch { /* ไม่เป็นไร */ }
    }
  });
  await page.waitForTimeout(600);
  snapshot[screen.label] = await page.evaluate(grab, PROPS);
  await context.close();
}

await browser.close();
writeFileSync(OUT, JSON.stringify(snapshot, null, 0), 'utf8');
const total = Object.values(snapshot).reduce((n, rows) => n + rows.length, 0);
console.log(`เก็บสีของ ${total} อิลิเมนต์ (${SCREENS.length} ช่วงจอ) ลง ${OUT}`);
