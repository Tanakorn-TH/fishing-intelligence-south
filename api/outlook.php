<?php
declare(strict_types=1);

/**
 * GET /api/outlook.php?lat=6.87&lon=101.25&days=8
 *
 * คะแนนรวมของหลายวันติดกัน สำหรับปฏิทินวางแผนทริป
 * ใช้สูตรและน้ำหนักชุดเดียวกับ /api/score.php (lib/scoring.php)
 * ถ้าต่างคนต่างคิด สักวันปฏิทินกับการ์ดคะแนนจะไม่ตรงกันของวันเดียวกัน
 *
 * ⚠️ ทำไมได้แค่ไม่กี่วัน: คะแนนต้องใช้ข้อมูลระดับน้ำ ซึ่งแบบจำลองพยากรณ์ได้ราว 7 วัน
 * ปฏิทินรายเดือนจึงเติมคะแนนจริงได้แค่ช่วงสั้น ๆ ที่เหลือต้องบอกตรง ๆ ว่ายังไม่รู้
 * ห้ามเติมตัวเลขให้เต็มเดือนเพื่อความสวยงาม — คนเอาไปวางแผนออกทะเลจริง
 *
 * แต่ละวันต้องดึงข้อมูลน้ำของตัวเอง การขอหลายวันจึงช้าตามจำนวนวัน
 * เพดานจึงตั้งไว้เตี้ยโดยตั้งใจ ไม่ใช่ข้อจำกัดทางเทคนิคที่ลืมแก้
 */

require_once __DIR__ . '/lib/scoring.php';

/** ขอได้สูงสุดกี่วันต่อหนึ่งคำขอ — เท่ากับระยะที่แบบจำลองน้ำพยากรณ์ได้พอดี */
const FIS_OUTLOOK_MAX_DAYS = 8;
const FIS_OUTLOOK_DEFAULT_DAYS = 8;

fis_handle(function (): void {
    fis_require_get();

    $lat = fis_conditions_coord('lat', -90.0, 90.0);
    $lon = fis_conditions_coord('lon', -180.0, 180.0);

    $days = fis_outlook_days();
    $tz = new DateTimeZone(FIS_SCORE_TZ);
    $start = new DateTimeImmutable('today', $tz);

    $rows = [];
    $failures = 0;

    for ($i = 0; $i < $days; $i++) {
        $day = $start->modify("+{$i} days");
        $date = $day->format('Y-m-d');

        try {
            $rows[] = fis_outlook_day($lat, $lon, $date, $tz);
        } catch (FisTidesNoDataException $e) {
            // จุดนี้หรือวันนี้ไม่มีข้อมูลน้ำ — บอกตรง ๆ ว่าคิดคะแนนไม่ได้
            $rows[] = ['date' => $date, 'score' => null, 'label' => null,
                       'best_style' => null, 'reason' => 'ไม่มีข้อมูลระดับน้ำของวันนี้'];
        } catch (FisRemoteException $e) {
            $failures++;
            error_log('[fishing-api/outlook] ' . $e->getMessage());
            $rows[] = ['date' => $date, 'score' => null, 'label' => null,
                       'best_style' => null, 'reason' => 'ดึงข้อมูลไม่สำเร็จ'];
        }
    }

    // ล้มทุกวัน = ปลายทางมีปัญหาจริง ไม่ใช่แค่บางวันไม่มีข้อมูล
    if ($failures > 0 && $failures === count($rows)) {
        fis_fail(
            'ขณะนี้ดึงข้อมูลสภาพอากาศหรือระดับน้ำไม่ได้ จึงยังคิดคะแนนล่วงหน้าให้ไม่ได้',
            502,
            'upstream_unavailable'
        );
        return;
    }

    $scored = array_values(array_filter($rows, static fn(array $r): bool => $r['score'] !== null));
    usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

    fis_json([
        'data' => [
            'from' => $rows[0]['date'] ?? null,
            'to' => $rows[count($rows) - 1]['date'] ?? null,
            'days' => $rows,
            // วันที่ดีที่สุดในช่วงที่คิดได้ ไม่ใช่ในเดือน — frontend ต้องไม่ไปเขียนว่า "ดีที่สุดของเดือน"
            'best_day' => $scored === [] ? null : $scored[0]['date'],
            'notice' => FIS_SCORE_NOTICE,
        ],
        'meta' => [
            'source' => 'คำนวณในระบบจากข้อมูล Open-Meteo และการคำนวณดาราศาสตร์ของเรา',
            'formula_version' => FIS_SCORE_FORMULA_VERSION,
            'formula_doc' => 'docs/fishing-score.md',
            'max_days' => FIS_OUTLOOK_MAX_DAYS,
            'horizon_note' => 'คิดคะแนนล่วงหน้าได้ไม่เกิน ' . FIS_OUTLOOK_MAX_DAYS
                            . ' วัน เพราะแบบจำลองระดับน้ำพยากรณ์ได้เท่านี้',
            'fetched_at' => (new DateTimeImmutable('now', $tz))->format('c'),
        ],
    ]);
});

function fis_outlook_days(): int
{
    $raw = isset($_GET['days']) ? trim((string) $_GET['days']) : '';
    if ($raw === '') {
        return FIS_OUTLOOK_DEFAULT_DAYS;
    }
    if (!ctype_digit($raw)) {
        fis_fail('days ต้องเป็นจำนวนเต็ม', 400, 'invalid_days');
    }
    $value = (int) $raw;
    if ($value < 1 || $value > FIS_OUTLOOK_MAX_DAYS) {
        fis_fail('days ต้องอยู่ระหว่าง 1 ถึง ' . FIS_OUTLOOK_MAX_DAYS, 400, 'invalid_days');
    }
    return $value;
}

/**
 * คะแนนรวมของวันเดียว — คืนเฉพาะที่ปฏิทินต้องใช้
 * ไม่ส่ง breakdown เต็มมาด้วยเพราะแปดวันรวมกันจะกลายเป็นก้อนใหญ่
 * คนที่อยากเห็นที่มาแบบละเอียดกดเข้าไปดูวันนั้นผ่าน /api/score.php ได้
 *
 * @return array<string, mixed>
 * @throws FisRemoteException|FisTidesNoDataException
 */
function fis_outlook_day(float $lat, float $lon, string $date, DateTimeZone $tz): array
{
    // ปฏิทินมองไปข้างหน้าหลายวัน จึงต้องขอพยากรณ์เต็มช่วงที่มี
    // ไม่งั้นทุกวันในปฏิทินจะได้ลมและคลื่นชุดเดียวกันหมดคือของวันนี้
    $weather = fis_weather_payload($lat, $lon, FIS_WEATHER_MAX_DAYS);
    $tides = fis_tides_payload($lat, $lon, $date);

    $dayStart = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $date . ' 00:00:00',
        new DateTimeZone(FIS_SOLUNAR_TZ)
    );
    $solunar = fis_solunar_data($lat, $lon, $date, $dayStart);

    // ประเมินที่เที่ยงวันเสมอ เพื่อให้ทุกวันเทียบกันได้บนฐานเดียวกัน
    // ถ้าวันนี้ใช้ "ตอนนี้" แต่วันอื่นใช้เที่ยงวัน ปฏิทินจะเทียบกันไม่ได้
    $at = new DateTimeImmutable($date . ' 12:00:00', $tz);

    $factors = fis_score_factors($weather, $tides, $solunar, $at, $tz);
    $styles = fis_score_all_styles($factors);
    $overall = fis_score_overall($styles);
    $safety = fis_score_safety(fis_weather_conditions_at($weather, $at));

    return [
        'date' => $date,
        'score' => $overall['score'],
        'label' => $overall['label'],
        'best_style' => $styles === [] ? null : $styles[0]['key'],
        'best_style_name' => $styles === [] ? null : $styles[0]['name_th'],
        // ความปลอดภัยติดไปด้วยเพราะปฏิทินคือที่ที่คนเลือกวันออกเรือ
        // เห็นคะแนนสูงแล้วจองวันโดยไม่รู้ว่าลมแรงคือความผิดพลาดที่แพง
        'safety' => $safety['level'],
    ];
}
