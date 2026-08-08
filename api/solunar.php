<?php
declare(strict_types=1);

/**
 * GET /api/solunar.php?lat=6.87&lon=101.25&date=2026-08-08
 *
 * ข้างขึ้นข้างแรมและช่วงเวลาตามทฤษฎี Solunar — คำนวณในระบบทั้งหมด ไม่เรียกบริการภายนอก
 * สูตรดาราศาสตร์อยู่ใน api/lib/astro.php (อ้างอิง Meeus, Astronomical Algorithms ฉบับที่ 2)
 * การประกอบข้อมูลอยู่ใน api/lib/conditions.php เพราะ /api/score.php ใช้ชุดเดียวกัน
 *
 * นิยามช่วงเวลาตาม docs/api-contract.md:
 *   major = ช่วงรอบการผ่านเมริเดียนบนและล่าง ช่วงละ 2 ชั่วโมง (เหตุการณ์อยู่กึ่งกลาง ±1 ชม.)
 *   minor = ช่วงรอบจันทร์ขึ้นและจันทร์ตก ช่วงละ 1 ชั่วโมง (เหตุการณ์อยู่กึ่งกลาง ±30 นาที)
 *
 * หมายเหตุ: ทฤษฎี Solunar (John Alden Knight, 1926) เป็นแนวคิดเชิงประสบการณ์
 * ไม่ใช่ผลลัพธ์ที่พิสูจน์ทางสถิติ ตัวเลขที่คืนคือ "เวลาทางดาราศาสตร์ที่แม่นยำ"
 * ของเหตุการณ์ที่ทฤษฎีนี้อ้างถึง ไม่ใช่คำรับประกันว่าปลาจะกิน
 */

require_once __DIR__ . '/lib/conditions.php';

fis_handle(function (): void {
    fis_require_get();

    $lat = fis_conditions_coord('lat', -90.0, 90.0);
    $lon = fis_conditions_coord('lon', -180.0, 180.0);

    // ----- ตรวจ date (ไม่ส่งมา = วันนี้ตามเวลาไทย) -----
    $tz = new DateTimeZone(FIS_SOLUNAR_TZ);
    $dateRaw = isset($_GET['date']) ? trim((string) $_GET['date']) : '';
    if ($dateRaw === '') {
        $dateRaw = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) !== 1) {
        fis_fail('date ต้องอยู่ในรูปแบบ YYYY-MM-DD', 400, 'invalid_date');
    }
    // createFromFormat ยอมรับวันที่เกินจริง เช่น 2026-02-30 แล้วเลื่อนเป็น 2026-03-02
    // จึงต้องเทียบสตริงกลับเพื่อจับวันที่ที่ไม่มีอยู่จริงบนปฏิทิน
    $dayStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateRaw . ' 00:00:00', $tz);
    if ($dayStart === false || $dayStart->format('Y-m-d') !== $dateRaw) {
        fis_fail('date ไม่ใช่วันที่ที่มีอยู่จริงบนปฏิทิน', 400, 'invalid_date');
    }

    fis_json([
        'data' => fis_solunar_data($lat, $lon, $dateRaw, $dayStart),
        'meta' => [
            // ไม่มี source_url เพราะไม่ได้ดึงจากที่ไหน — ที่มาคือสูตรที่ระบุใน method
            'source'     => 'คำนวณในระบบ',
            'license'    => 'สูตรสาธารณะ อ้างอิง Meeus, Astronomical Algorithms (2nd ed.)',
            'method'     => 'Meeus บทที่ 47 (ตำแหน่งดวงจันทร์ ELP-2000/82 ตัดทอน), '
                          . 'บทที่ 48 (ส่วนสว่าง), บทที่ 49 (เวลาเดือนดับ), '
                          . 'บทที่ 15 (เกณฑ์มุมเงยขึ้น-ตก h0 = 0.7275·π − 34 ลิปดา)',
            'accuracy'   => 'เวลาจันทร์ขึ้น-ตก-ผ่านเมริเดียน คลาดเคลื่อนไม่เกินประมาณ 1 นาที '
                          . 'ค่าที่คืนจึงปัดเป็นนาที',
            'fetched_at' => (new DateTimeImmutable('now', new DateTimeZone(FIS_SOLUNAR_TZ)))
                                ->format('Y-m-d\TH:i:sP'),
            'cached'     => false,
        ],
    ]);
});
