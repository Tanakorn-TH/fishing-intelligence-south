<?php
declare(strict_types=1);

/**
 * GET /api/solunar.php?lat=6.87&lon=101.25&date=2026-08-08
 *
 * ข้างขึ้นข้างแรมและช่วงเวลาตามทฤษฎี Solunar — คำนวณในระบบทั้งหมด ไม่เรียกบริการภายนอก
 * สูตรดาราศาสตร์อยู่ใน api/lib/astro.php (อ้างอิง Meeus, Astronomical Algorithms ฉบับที่ 2)
 *
 * นิยามช่วงเวลาตาม docs/api-contract.md:
 *   major = ช่วงรอบการผ่านเมริเดียนบนและล่าง ช่วงละ 2 ชั่วโมง (เหตุการณ์อยู่กึ่งกลาง ±1 ชม.)
 *   minor = ช่วงรอบจันทร์ขึ้นและจันทร์ตก ช่วงละ 1 ชั่วโมง (เหตุการณ์อยู่กึ่งกลาง ±30 นาที)
 *
 * หมายเหตุ: ทฤษฎี Solunar (John Alden Knight, 1926) เป็นแนวคิดเชิงประสบการณ์
 * ไม่ใช่ผลลัพธ์ที่พิสูจน์ทางสถิติ ตัวเลขที่คืนคือ "เวลาทางดาราศาสตร์ที่แม่นยำ"
 * ของเหตุการณ์ที่ทฤษฎีนี้อ้างถึง ไม่ใช่คำรับประกันว่าปลาจะกิน
 */

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/astro.php';

/** เขตเวลาของคำตอบ ตรึงที่ +07:00 ตามกติกาในสัญญา ประเทศไทยไม่มีการปรับเวลาตามฤดูกาล */
const FIS_SOLUNAR_TZ = '+07:00';

/**
 * ปัดเวลาเป็นนาทีที่ใกล้ที่สุดแล้วจัดรูปแบบ ISO 8601
 *
 * ทำไมปัดเป็นนาที: แบบจำลองมีความคลาดเคลื่อนระดับสิบวินาทีขึ้นไปอยู่แล้ว
 * การคืนวินาทีที่ไม่ใช่ศูนย์จะทำให้ผู้ใช้เข้าใจผิดว่าแม่นกว่าความเป็นจริง
 */
function fis_solunar_iso(float $unix): string
{
    $rounded = (int) round($unix / 60.0) * 60;
    $dt = (new DateTimeImmutable('@' . $rounded))
        ->setTimezone(new DateTimeZone(FIS_SOLUNAR_TZ));
    return $dt->format('Y-m-d\TH:i:sP');
}

/** สร้างช่วงเวลาที่มีเหตุการณ์อยู่กึ่งกลาง กว้างรวม $lengthMinutes นาที */
function fis_solunar_period(float $centerUnix, int $lengthMinutes): array
{
    $half = $lengthMinutes * 30.0; // ครึ่งหนึ่งของความยาว หน่วยวินาที
    return [
        'start' => fis_solunar_iso($centerUnix - $half),
        'end'   => fis_solunar_iso($centerUnix + $half),
    ];
}

fis_handle(function (): void {
    fis_require_get();

    // ----- ตรวจ lat -----
    $latRaw = isset($_GET['lat']) ? trim((string) $_GET['lat']) : '';
    if ($latRaw === '') {
        fis_fail('ต้องระบุพารามิเตอร์ lat เป็นละติจูด', 400, 'missing_lat');
    }
    if (!is_numeric($latRaw)) {
        fis_fail('lat ต้องเป็นตัวเลข', 400, 'invalid_lat');
    }
    $lat = (float) $latRaw;
    if ($lat < -90.0 || $lat > 90.0) {
        fis_fail('lat ต้องอยู่ระหว่าง -90 ถึง 90', 400, 'invalid_lat');
    }

    // ----- ตรวจ lon -----
    $lonRaw = isset($_GET['lon']) ? trim((string) $_GET['lon']) : '';
    if ($lonRaw === '') {
        fis_fail('ต้องระบุพารามิเตอร์ lon เป็นลองจิจูด', 400, 'missing_lon');
    }
    if (!is_numeric($lonRaw)) {
        fis_fail('lon ต้องเป็นตัวเลข', 400, 'invalid_lon');
    }
    $lon = (float) $lonRaw;
    if ($lon < -180.0 || $lon > 180.0) {
        fis_fail('lon ต้องอยู่ระหว่าง -180 ถึง 180', 400, 'invalid_lon');
    }

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

    $startUnix = (float) $dayStart->getTimestamp();
    $endUnix   = $startUnix + 86400.0;

    // ----- ดาราศาสตร์ -----
    $events = fis_astro_moon_events($startUnix, $endUnix, $lat, $lon);

    // ประเมินข้างขึ้นข้างแรม ณ เที่ยงวันท้องถิ่น เป็นตัวแทนของทั้งวัน
    // (ถ้าใช้เที่ยงคืน ค่าจะดูเหมือน "ของเมื่อวาน" สำหรับคนที่เปิดดูตอนกลางวัน)
    $phase = fis_astro_moon_phase($startUnix + 43200.0);

    // major = รอบการผ่านเมริเดียน ช่วงละ 2 ชั่วโมง
    $major = [];
    foreach (['transit', 'antitransit'] as $key) {
        if ($events[$key] !== null) {
            $major[] = fis_solunar_period($events[$key], 120);
        }
    }
    // minor = รอบจันทร์ขึ้นและจันทร์ตก ช่วงละ 1 ชั่วโมง
    $minor = [];
    foreach (['moonrise', 'moonset'] as $key) {
        if ($events[$key] !== null) {
            $minor[] = fis_solunar_period($events[$key], 60);
        }
    }
    // เรียงตามเวลาเริ่ม เพื่อให้ frontend แสดงไล่ตามลำดับได้เลยโดยไม่ต้องเรียงเอง
    usort($major, static fn(array $a, array $b): int => strcmp($a['start'], $b['start']));
    usort($minor, static fn(array $a, array $b): int => strcmp($a['start'], $b['start']));

    fis_json([
        'data' => [
            'date' => $dateRaw,
            'moon' => [
                'phase_name_th'    => $phase['phase_name_th'],
                'illumination_pct' => (int) round($phase['illumination'] * 100.0),
                'age_days'         => round($phase['age_days'], 1),
            ],
            'major_periods' => $major,
            'minor_periods' => $minor,
            // null เมื่อวันนั้นไม่มีเหตุการณ์จริง ๆ เกิดขึ้นได้เพราะรอบจันทร์ขึ้นห่างกัน ~24 ชม. 50 นาที
            'moonrise' => $events['moonrise'] === null ? null : fis_solunar_iso($events['moonrise']),
            'moonset'  => $events['moonset'] === null ? null : fis_solunar_iso($events['moonset']),
        ],
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
