/* กันไวยากรณ์ media query แบบ Media Queries Level 4 (range syntax)
   เช่น @media (width < 40rem) หรือ @media (400px <= width <= 700px)

   ทำไมต้องกัน: Safari ก่อน 16.4 ไม่รู้จักไวยากรณ์นี้ แล้ว **ทิ้งทั้งบล็อกเงียบ ๆ**
   ไม่มี error ไม่มีอะไรเตือน กฎข้างในหายไปทั้งก้อน

   ite-avm เจอของจริงบน PWA ของ iPhone: แถบล่างไม่ย้ายลงก้นจอ และระยะกันท้ายหน้า
   ค้างที่ 0 ผลคือปุ่มหน้าถัดไปกดไม่ถึง กว่าจะรู้ว่าสาเหตุคือไวยากรณ์ media query
   ก็เสียเวลาไปมาก

   เขียนซ้อนสองชั้นแทน:
     @media not all and (min-width: 40rem) {
       @media (orientation: portrait) { … }
     }

   ตอนเพิ่มตัวตรวจนี้ โปรเจคยังไม่มีจุดไหนผิดเลย มันจึงมีไว้กันของใหม่หลุดเข้ามา
   ไม่ใช่ไว้แก้ของเก่า */
import { readFileSync, readdirSync } from 'node:fs';

/* ใช้ readdirSync ไม่ใช่ globSync — globSync เพิ่งมีใน Node 22
   แต่ CI รัน Node 20 ตัวสคริปต์จึงพังตอนนำเข้าโมดูลก่อนจะได้ทำงานอะไรเลย
   เครื่องนักพัฒนามักใหม่กว่า CI จึงไม่เห็นปัญหานี้ตอนรันในเครื่อง */
const files = readdirSync('.').filter((name) => name.endsWith('.css')).sort();
const problems = [];

for (const file of files) {
  const css = readFileSync(file, 'utf8');
  // ดูเฉพาะส่วนหัวของ @media คือตั้งแต่ @media จนถึงปีกกาเปิด
  for (const match of css.matchAll(/@media[^{]*/g)) {
    const prelude = match[0];
    if (!/[<>]/.test(prelude)) continue;
    const line = css.slice(0, match.index).split('\n').length;
    problems.push({ file, line, prelude: prelude.trim().replace(/\s+/g, ' ') });
  }
}

if (problems.length > 0) {
  console.error('พบ media query แบบ range syntax ซึ่ง Safari ก่อน 16.4 จะทิ้งทั้งบล็อก:');
  for (const p of problems) {
    console.error(`  ${p.file}:${p.line}  ${p.prelude}`);
  }
  console.error('\nเขียนซ้อนสองชั้นแทน เช่น');
  console.error('  @media not all and (min-width: 40rem) { @media (orientation: portrait) { … } }');
  process.exit(1);
}

console.log(`ตรวจ media query ใน ${files.length} ไฟล์ CSS แล้ว ไม่พบ range syntax`);
