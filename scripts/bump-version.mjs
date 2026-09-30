/**
 * ขยับเลขเวอร์ชันทุกที่ในคำสั่งเดียว
 *
 *   node scripts/bump-version.mjs            0.9.9 -> 0.9.10  (แก้บั๊ก ปรับดีไซน์)
 *   node scripts/bump-version.mjs minor      0.9.9 -> 0.10.0  (ความสามารถใหม่ เปลี่ยนสคีมา)
 *   node scripts/bump-version.mjs 1.0.0      ตั้งเลขเอง
 *
 * ทำไมต้องมี: เซิร์ฟเวอร์ตั้งแคชไฟล์สแตติกไว้ 10 ปี เลขเวอร์ชันจึงต้องขยับทุกครั้งที่
 * app.js map.js หรือ css เปลี่ยน ไม่งั้นคนที่เคยเข้าเว็บจะไม่เห็นของใหม่เลย
 * เลขไปโผล่ 7 จุดใน app.js กับ index.html แก้มือแล้วตกหล่นง่าย — check-version.mjs จับได้
 * แต่จับได้ตอนท้าย สคริปต์นี้ทำให้ไม่ต้องไปถึงตรงนั้น
 *
 * ops/deploy.sh เรียกสคริปต์นี้ให้เองเมื่อเห็นว่าไฟล์เปลี่ยนแต่เลขยังเท่ากับบนเว็บ
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO = dirname(dirname(fileURLToPath(import.meta.url)));
const appPath = join(REPO, 'app.js');
const indexPath = join(REPO, 'index.html');

const appJs = readFileSync(appPath, 'utf8');
const declared = appJs.match(/const APP_VERSION = '(\d+)\.(\d+)\.(\d+)'/);
if (!declared) {
  console.error('ไม่พบ APP_VERSION รูปแบบ x.y.z ใน app.js');
  process.exit(1);
}
const [, major, minor, patch] = declared.map(Number);
const current = `${major}.${minor}.${patch}`;

const arg = process.argv[2] || 'patch';
let next;
if (arg === 'patch') next = `${major}.${minor}.${patch + 1}`;
else if (arg === 'minor') next = `${major}.${minor + 1}.0`;
else if (/^\d+\.\d+\.\d+$/.test(arg)) next = arg;
else {
  console.error(`ไม่รู้จัก "${arg}" — ใช้ patch, minor หรือเลขแบบ 1.2.3`);
  process.exit(2);
}
if (next === current) {
  console.error(`เวอร์ชันเป็น ${current} อยู่แล้ว`);
  process.exit(2);
}

// อ่าน index.html ก่อนเขียนอะไรลงดิสก์ ถ้าหาจุดไม่เจอจะได้ไม่แก้ค้างไว้ครึ่งเดียว
const indexHtml = readFileSync(indexPath, 'utf8');
const assetTag = `?v=${current}"`;
const fallbackTag = `id="appVersion">v${current}<`;
const assetCount = indexHtml.split(assetTag).length - 1;
if (assetCount === 0 || !indexHtml.includes(fallbackTag)) {
  console.error(`index.html ไม่ได้ใช้เลข ${current} ตรงกับ app.js — รัน node scripts/check-version.mjs ดูก่อน`);
  process.exit(1);
}

writeFileSync(appPath, appJs.replace(declared[0], `const APP_VERSION = '${next}'`));
writeFileSync(
  indexPath,
  indexHtml.split(assetTag).join(`?v=${next}"`).split(fallbackTag).join(`id="appVersion">v${next}<`),
);

console.log(`${current} -> ${next}  (app.js 1 จุด · index.html ${assetCount + 1} จุด)`);
