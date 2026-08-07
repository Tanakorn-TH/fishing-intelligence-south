/* ตรวจว่า id ทุกตัวที่ app.js เรียกหา มีอยู่จริงใน index.html
   บั๊กแบบนี้ทำให้สคริปต์ตายเงียบ ๆ ตอนโหลดหน้า มองไม่เห็นถ้าไม่เปิด devtools */
import { readFileSync } from 'node:fs';

const html = readFileSync('index.html', 'utf8');
const js = readFileSync('app.js', 'utf8');

const definedIds = new Set([...html.matchAll(/\bid="([^"]+)"/g)].map((m) => m[1]));
const usedIds = [...js.matchAll(/getElementById\(\s*['"]([^'"]+)['"]\s*\)/g)].map((m) => m[1]);

const missing = [...new Set(usedIds)].filter((id) => !definedIds.has(id));

if (missing.length > 0) {
  console.error('app.js เรียกหา id ที่ไม่มีใน index.html:');
  missing.forEach((id) => console.error(`  - ${id}`));
  process.exit(1);
}

console.log(`ตรวจแล้ว ${new Set(usedIds).size} id จาก app.js มีครบใน index.html`);
