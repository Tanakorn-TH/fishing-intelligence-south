/**
 * ตรวจว่าเลขเวอร์ชันตรงกันทุกที่ที่มันปรากฏ
 *
 *   node scripts/check-version.mjs
 *
 * ทำไมต้องมี: เซิร์ฟเวอร์ตั้ง Cache-Control ของไฟล์สแตติกไว้ 10 ปี
 * เบราว์เซอร์ของคนที่เคยเข้าเว็บจึงไม่ยอมโหลด app.js หรือ css ใหม่เลย
 * ทางแก้คือติด ?v=<เวอร์ชัน> ท้าย URL เพื่อให้กลายเป็นที่อยู่ใหม่ที่แคชไม่รู้จัก
 *
 * ผลข้างเคียงคือเลขเวอร์ชันไปโผล่หลายที่ ถ้าอัปเดตไม่ครบ
 * ไฟล์ที่ตกหล่นจะยังเสิร์ฟของเก่าให้ผู้ใช้เดิมต่อไปโดยไม่มีอะไรฟ้อง
 * ซึ่งเป็นบั๊กที่เงียบที่สุดแบบหนึ่ง — เทสต์ผ่านหมด เครื่องเราเห็นของใหม่ แต่ผู้ใช้ไม่เห็น
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO = dirname(dirname(fileURLToPath(import.meta.url)));
const appJs = readFileSync(join(REPO, 'app.js'), 'utf8');
const indexHtml = readFileSync(join(REPO, 'index.html'), 'utf8');

const problems = [];

const declared = appJs.match(/const APP_VERSION = '([^']+)'/);
if (!declared) {
  console.error('ไม่พบ APP_VERSION ใน app.js');
  process.exit(1);
}
const version = declared[1];

// ค่าสำรองที่แสดงตอน JS ยังไม่ทำงาน
const fallback = indexHtml.match(/id="appVersion">v([\d.]+)</);
if (!fallback) {
  problems.push('ไม่พบค่าสำรองเวอร์ชันใน index.html');
} else if (fallback[1] !== version) {
  problems.push(`index.html แสดง v${fallback[1]} แต่ APP_VERSION คือ ${version}`);
}

// ทุกไฟล์สแตติกที่เปลี่ยนตาม release ต้องติดเวอร์ชันปัจจุบัน
const VERSIONED = ['styles.css', 'design.css', 'fonts.css', 'app.js', 'map.js'];
for (const asset of VERSIONED) {
  const escaped = asset.replace('.', '\\.');
  const tag = new RegExp(`(?:href|src)="${escaped}(\\?v=([^"]*))?"`);
  const found = indexHtml.match(tag);

  if (!found) {
    problems.push(`index.html ไม่ได้อ้างถึง ${asset} เลย`);
  } else if (!found[1]) {
    problems.push(`${asset} ไม่ได้ติด ?v= — ผู้ใช้เดิมจะยังได้ไฟล์เก่าจากแคช`);
  } else if (found[2] !== version) {
    problems.push(`${asset} ติด ?v=${found[2]} แต่เวอร์ชันปัจจุบันคือ ${version}`);
  }
}

if (problems.length) {
  console.error(`เวอร์ชันไม่ตรงกัน (APP_VERSION = ${version}):`);
  for (const problem of problems) console.error(`  - ${problem}`);
  console.error('\nแก้ให้ตรงกันทุกที่ ไม่งั้นผู้ใช้เดิมจะยังเห็นเว็บเวอร์ชันเก่า');
  process.exit(1);
}

console.log(`เวอร์ชัน ${version} ตรงกันครบ ${VERSIONED.length + 1} จุด`);
