<?php
declare(strict_types=1);

/**
 * GET /api/tides.php?lat=6.87&lon=101.25&date=2026-08-08
 * ระดับน้ำรายชั่วโมงและเวลาที่น้ำขึ้น/ลงเต็มที่ ตาม docs/api-contract.md
 *
 * ข้อมูลมาจาก Open-Meteo Marine API ตัวแปร sea_level_height_msl
 * ซึ่งเบื้องหลังเป็นแบบจำลอง MeteoFrance SMOC ความละเอียด 0.08 องศา (~8 กม.)
 *
 * ⚠️ เรื่องที่ต้องเข้าใจก่อนแก้ไฟล์นี้ (รายละเอียดเต็มอยู่ในสัญญา):
 * ค่าที่ได้อ้างอิง "ระดับน้ำทะเลปานกลาง" (MSL) ส่วนตารางน้ำของกรมอุทกศาสตร์
 * อ้างอิง "ระดับน้ำลงต่ำสุด" (chart datum) — เป็นคนละฐานกัน ตัวเลขจึงเทียบกันไม่ได้
 * สิ่งที่ใช้ได้จริงคือ "จังหวะ" และ "พิสัย" ไม่ใช่ความลึกสัมบูรณ์
 * ด้วยเหตุนี้ทุกคำตอบจึงต้องแนบ notice และ datum ไปด้วยเสมอ ห้ามตัดออกเพื่อความสวยงาม
 */

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/remote.php';
require_once __DIR__ . '/lib/cache.php';

const FIS_TIDES_TZ = 'Asia/Bangkok';

/** น้ำเปลี่ยนช้ากว่าอากาศ และแบบจำลองอัปเดตวันละครั้ง แคชนานกว่า weather ได้โดยไม่เสียความสด */
const FIS_TIDES_CACHE_TTL = 1800;

/**
 * ปลายทาง "รับ" ช่วงวันที่ได้ถึงราว 15 วัน แต่แบบจำลองระดับน้ำ "มีค่าจริง" สั้นกว่านั้นมาก
 * วัดจริงเมื่อ ส.ค. 2569 ได้ราว 8 วัน เลยจากนั้นตอบ 200 พร้อมค่า null ล้วน
 * จึงตั้งเพดานไว้ 7 วันแบบระมัดระวัง เพื่อให้คำขอที่ผ่านด่านนี้มีโอกาสได้ข้อมูลจริงสูง
 *
 * อย่าเพิ่มตัวเลขนี้เพราะเห็นว่าปลายทางไม่ฟ้อง error — มันไม่ฟ้อง แต่ส่งค่าว่างมาให้
 */
const FIS_TIDES_MAX_AHEAD_DAYS = 7;

/** ย้อนหลังได้ไกลมาก (ปลายทางมีตั้งแต่ ค.ศ. 1940) แต่จำกัดไว้เท่าที่การวางแผนตกปลาต้องใช้จริง */
const FIS_TIDES_MAX_BACK_DAYS = 365;

/**
 * ข้อความเตือนเรื่อง datum — เก็บเป็นค่าคงที่เพราะต้องตรงกันทั้งสัญญา ชุดทดสอบ และคำตอบจริง
 * ถ้าจะแก้ ต้องแก้ docs/api-contract.md ด้วย มิฉะนั้นชุดทดสอบจะจับได้
 */
const FIS_TIDES_NOTICE = 'ระดับน้ำอ้างอิงระดับน้ำทะเลปานกลาง (MSL) ไม่ใช่ระดับน้ำลงต่ำสุด (chart datum) '
    . 'ตัวเลขจึงเทียบกับตารางน้ำของกรมอุทกศาสตร์ไม่ได้ ใช้เพื่อดูจังหวะน้ำขึ้นน้ำลงเท่านั้น ห้ามใช้เพื่อการเดินเรือ';

/**
 * "ปลายทางตอบปกติ แต่ไม่มีค่าระดับน้ำให้" — คนละเรื่องกับ "ปลายทางล้ม"
 *
 * เกิดได้สองกรณี และทั้งสองกรณีไม่ใช่ความผิดพลาดของระบบ:
 *   1. พิกัดอยู่นอกพื้นที่ที่แบบจำลองครอบคลุม (กลางแผ่นดิน ทะเลสาบ ขั้วโลก)
 *   2. วันที่ขอเลยระยะที่แบบจำลองพยากรณ์ได้ (ปลายทางตอบ 200 พร้อม null ล้วน ไม่ได้ฟ้อง error)
 *
 * ถ้าปล่อยให้กลายเป็น FisRemoteException จะได้ 502 ซึ่งบอกผู้ใช้ว่า "ระบบภายนอกล้ม"
 * ทั้งที่ความจริงคือ "จุดนี้/วันนี้ไม่มีข้อมูล" — คนละเรื่อง และทำให้ไล่ปัญหาผิดทาง
 */
class FisTidesNoDataException extends RuntimeException
{
}

fis_handle(function (): void {
    fis_require_get();

    $lat = fis_tides_coord('lat', -90.0, 90.0);
    $lon = fis_tides_coord('lon', -180.0, 180.0);

    // ปัดพิกัดเหลือทศนิยม 2 ตำแหน่งเหมือน weather.php — แบบจำลองหยาบกว่านี้มาก
    // การปัดจึงไม่ทำให้เสียความแม่น แต่ทำให้คำขอจากหมายเดียวกันใช้แคชช่องเดียวกัน
    $lat = round($lat, 2);
    $lon = round($lon, 2);

    $tz = new DateTimeZone(FIS_TIDES_TZ);
    $date = fis_tides_date($tz);

    $cacheKey = sprintf('tides:%.2f:%.2f:%s', $lat, $lon, $date);
    $payload = fis_cache_get($cacheKey, FIS_TIDES_CACHE_TTL);
    $cached = $payload !== null;

    if (!$cached) {
        try {
            $raw = fis_remote_get_json(fis_tides_url($lat, $lon, $date), 8);
            $payload = fis_tides_build($raw, $date, $tz);
        } catch (FisTidesNoDataException $e) {
            fis_tides_fail_no_data($date, $tz);
            return;
        } catch (FisRemoteException $e) {
            // ปลายทางบอก "ไม่มีข้อมูลของจุดนี้" ผ่าน HTTP 400 ก็มี (เช่นขั้วโลกใต้)
            // ซึ่งเป็นเรื่องเดียวกับกรณีค่า null ล้วน ไม่ใช่ปลายทางล้ม จึงต้องตอบให้เหมือนกัน
            if (fis_tides_looks_like_no_data($e->getMessage())) {
                fis_tides_fail_no_data($date, $tz);
                return;
            }
            error_log('[fishing-api/tides] ' . $e->getMessage());
            fis_fail(
                'ขณะนี้ดึงข้อมูลระดับน้ำจากแหล่งข้อมูลภายนอกไม่ได้ (Open-Meteo ไม่ตอบหรือตอบช้าเกินไป) กรุณาลองใหม่อีกครั้งในอีกสักครู่',
                502,
                'upstream_unavailable'
            );
            return; // ไปไม่ถึง เขียนไว้ให้เครื่องมือวิเคราะห์โค้ดเห็นว่าจบแล้ว
        }

        fis_cache_put($cacheKey, $payload);
    }

    fis_json([
        'data' => $payload['data'],
        'meta' => [
            'source' => 'Open-Meteo Marine API',
            'source_url' => 'https://open-meteo.com/en/docs/marine-weather-api',
            'license' => 'CC BY 4.0',
            'model' => 'MeteoFrance SMOC ความละเอียด 0.08° (~8 กม.) รายชั่วโมง',
            'datum' => 'mean_sea_level',
            'accuracy' => 'เวลาน้ำขึ้น-ลงเต็มที่ประมาณจากข้อมูลรายชั่วโมงด้วยการหาจุดยอดแบบพาราโบลา '
                        . 'คลาดเคลื่อนได้ราว 5-15 นาที ค่าที่คืนจึงปัดเป็น 5 นาที',
            'fetched_at' => $payload['fetched_at'],
            'cached' => $cached,
        ],
    ]);
});

/**
 * ปลายทางบอกว่า "จุดนี้ไม่มีข้อมูล" ผ่านข้อความใน body ตอนตอบ 4xx
 * ต้องดูจากข้อความเพราะไม่มีรหัสเฉพาะให้จับ — ถ้าปลายทางเปลี่ยนคำ ผลที่ได้คือกลับไปตอบ 502
 * ซึ่งยังปลอดภัย (แค่บอกสาเหตุหยาบกว่าเดิม) ไม่ใช่การแสดงข้อมูลผิดให้ผู้ใช้
 */
function fis_tides_looks_like_no_data(string $message): bool
{
    return stripos($message, 'No data is available') !== false;
}

/** ตอบกรณี "ไม่มีข้อมูล" ให้เหมือนกันทุกทาง ไม่ว่าปลายทางจะส่งสัญญาณมาแบบไหน */
function fis_tides_fail_no_data(string $date, DateTimeZone $tz): void
{
    $isFuture = $date > (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    fis_fail(
        $isFuture
            ? 'ยังไม่มีข้อมูลระดับน้ำของวันที่ขอ อาจเลยระยะที่แบบจำลองพยากรณ์ได้ '
                . 'หรือจุดนี้อยู่นอกพื้นที่ที่แบบจำลองครอบคลุม'
            : 'จุดนี้ไม่มีข้อมูลระดับน้ำ แบบจำลองครอบคลุมเฉพาะพื้นที่ทะเล '
                . 'พิกัดกลางแผ่นดินหรือแหล่งน้ำปิดจะไม่มีค่าให้',
        400,
        'no_tide_data'
    );
}

/** อ่านและตรวจพิกัดหนึ่งแกน — เข้มตั้งแต่ต้นทางเพราะค่านี้จะกลายเป็นส่วนหนึ่งของ URL ที่ยิงออกไป */
function fis_tides_coord(string $name, float $min, float $max): float
{
    $raw = isset($_GET[$name]) ? trim((string) $_GET[$name]) : '';

    if ($raw === '') {
        fis_fail("ต้องระบุพารามิเตอร์ {$name}", 400, 'missing_' . $name);
    }
    if (!is_numeric($raw)) {
        fis_fail("{$name} ต้องเป็นตัวเลข", 400, 'invalid_' . $name);
    }

    $value = (float) $raw;
    if (!is_finite($value) || $value < $min || $value > $max) {
        fis_fail(sprintf('%s ต้องอยู่ระหว่าง %g ถึง %g', $name, $min, $max), 400, 'invalid_' . $name);
    }

    return $value;
}

/**
 * อ่านและตรวจวันที่ คืนสตริง YYYY-MM-DD
 * ตรวจช่วงที่ขอได้ตั้งแต่ที่นี่ เพื่อให้ผู้ใช้ได้ 400 พร้อมคำอธิบาย
 * แทนที่จะปล่อยให้ปลายทางปฏิเสธแล้วเรากลายเป็น 502 ซึ่งชี้นิ้วผิดที่
 */
function fis_tides_date(DateTimeZone $tz): string
{
    $today = new DateTimeImmutable('today', $tz);

    $raw = isset($_GET['date']) ? trim((string) $_GET['date']) : '';
    if ($raw === '') {
        return $today->format('Y-m-d');
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
        fis_fail('date ต้องอยู่ในรูปแบบ YYYY-MM-DD', 400, 'invalid_date');
    }

    // createFromFormat ยอมรับวันที่เกินจริงแล้วเลื่อนให้เอง เช่น 2026-02-30 -> 2026-03-02
    // จึงต้องเทียบสตริงกลับเพื่อจับวันที่ที่ไม่มีอยู่จริงบนปฏิทิน
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw . ' 00:00:00', $tz);
    if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
        fis_fail('date ไม่ใช่วันที่ที่มีอยู่จริงบนปฏิทิน', 400, 'invalid_date');
    }

    $diffDays = (int) $today->diff($parsed)->format('%r%a');
    if ($diffDays > FIS_TIDES_MAX_AHEAD_DAYS) {
        fis_fail(
            'ขอข้อมูลระดับน้ำล่วงหน้าได้ไม่เกิน ' . FIS_TIDES_MAX_AHEAD_DAYS . ' วัน เพราะแบบจำลองพยากรณ์ได้เท่านี้',
            400,
            'date_out_of_range'
        );
    }
    if ($diffDays < -FIS_TIDES_MAX_BACK_DAYS) {
        fis_fail(
            'ขอข้อมูลระดับน้ำย้อนหลังได้ไม่เกิน ' . FIS_TIDES_MAX_BACK_DAYS . ' วัน',
            400,
            'date_out_of_range'
        );
    }

    return $raw;
}

function fis_tides_url(float $lat, float $lon, string $date): string
{
    return 'https://marine-api.open-meteo.com/v1/marine?' . http_build_query([
        'latitude' => sprintf('%.2f', $lat),
        'longitude' => sprintf('%.2f', $lon),
        'hourly' => 'sea_level_height_msl',
        'timezone' => FIS_TIDES_TZ,
        // ขอเผื่อหน้าหลังอย่างละวัน เพื่อให้จุดยอดที่คร่อมเที่ยงคืนถูกตรวจเจอ
        // ถ้าขอแค่วันเดียว น้ำขึ้นเต็มที่ตอน 23:40 จะดูเหมือนเป็นขอบข้อมูล ไม่ใช่จุดยอด
        'start_date' => fis_tides_shift($date, -1),
        'end_date' => fis_tides_shift($date, 1),
    ], '', '&', PHP_QUERY_RFC3986);
}

/** เลื่อนวันที่แบบ YYYY-MM-DD ไปกี่วันก็ได้ (ใช้ UTC เพราะสนใจแค่การนับวัน ไม่เกี่ยวเขตเวลา) */
function fis_tides_shift(string $date, int $days): string
{
    $dt = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    return $dt->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/**
 * ประกอบคำตอบตามสัญญา
 *
 * @param array<string, mixed> $raw
 * @return array{data: array<string, mixed>, fetched_at: string}
 * @throws FisRemoteException เมื่อรูปร่างข้อมูลจากปลายทางไม่ใช่อย่างที่ตกลงกันไว้
 */
function fis_tides_build(array $raw, string $date, DateTimeZone $tz): array
{
    $times = $raw['hourly']['time'] ?? null;
    $heights = $raw['hourly']['sea_level_height_msl'] ?? null;
    if (!is_array($times) || !is_array($heights) || $times === []) {
        throw new FisRemoteException('Marine API ไม่ได้ส่ง hourly.sea_level_height_msl กลับมา');
    }

    // เก็บทุกจุดที่มีค่าจริงไว้เป็นชุดต่อเนื่อง (รวมวันก่อน-หลัง) สำหรับใช้หาจุดยอด
    $all = [];
    foreach ($times as $i => $t) {
        if (!is_string($t)) {
            continue;
        }
        $h = $heights[$i] ?? null;
        if (!is_int($h) && !is_float($h)) {
            continue;
        }
        $all[] = ['local' => $t, 'height' => (float) $h];
    }
    // ปลายทางส่งโครงมาครบแต่ค่าเป็น null ล้วน = ไม่มีข้อมูลของจุดนี้/ช่วงนี้ ไม่ใช่ปลายทางพัง
    if (count($all) < 3) {
        throw new FisTidesNoDataException('ไม่มีค่าระดับน้ำในช่วงที่ขอ (ได้ ' . count($all) . ' จุด)');
    }

    // series = เฉพาะ 24 ชั่วโมงของวันที่ขอ (ที่เผื่อมาใช้แค่ช่วยหาจุดยอดที่คร่อมเที่ยงคืน)
    $series = [];
    foreach ($all as $point) {
        if (strpos($point['local'], $date) === 0) {
            $series[] = [
                'time' => fis_tides_iso($point['local'], $tz),
                'height_m' => round($point['height'], 2),
            ];
        }
    }
    // มีข้อมูลของวันข้างเคียงที่ขอเผื่อไว้ แต่ไม่มีของวันที่ขอเอง — ยังเป็นกรณี "ไม่มีข้อมูล"
    if ($series === []) {
        throw new FisTidesNoDataException('ไม่มีข้อมูลของวันที่ขอ (' . $date . ')');
    }

    $extremes = fis_tides_extremes($all, $date, $tz);
    $current = fis_tides_current($all, $date, $tz);

    return [
        'data' => [
            'date' => $date,
            'datum' => 'mean_sea_level',
            'current' => $current,
            'extremes' => $extremes,
            'series' => $series,
            'notice' => FIS_TIDES_NOTICE,
        ],
        'fetched_at' => (new DateTimeImmutable('now', $tz))->format('c'),
    ];
}

/**
 * หาเวลาที่น้ำขึ้นเต็มที่และลงเต็มที่
 *
 * ต้นทางให้ค่าเป็นรายชั่วโมง จุดยอดจริงจึงมักอยู่ "ระหว่าง" สองชั่วโมง
 * ใช้การประมาณด้วยพาราโบลาผ่านสามจุดรอบจุดสูงสุด ซึ่งเป็นวิธีมาตรฐาน
 * และเหมาะกับเส้นโค้งน้ำที่ใกล้เคียงไซน์ในช่วงสั้น ๆ รอบจุดยอด
 *
 * ไม่ใช้ interpolation ที่ละเอียดกว่านี้เพราะข้อมูลต้นทางหยาบกว่าความละเอียดที่จะได้อยู่แล้ว
 * การอ้างความแม่นระดับนาทีจากข้อมูลรายชั่วโมงคือการหลอกตัวเอง
 *
 * @param list<array{local:string, height:float}> $all
 * @return list<array{type:string, time:string, height_m:float}>
 */
function fis_tides_extremes(array $all, string $date, DateTimeZone $tz): array
{
    $extremes = [];
    $count = count($all);

    for ($i = 1; $i < $count - 1; $i++) {
        $prev = $all[$i - 1]['height'];
        $cur = $all[$i]['height'];
        $next = $all[$i + 1]['height'];

        $isHigh = $cur > $prev && $cur >= $next;
        $isLow = $cur < $prev && $cur <= $next;
        if (!$isHigh && !$isLow) {
            continue;
        }

        // จุดยอดพาราโบลาที่ผ่าน (-1,prev) (0,cur) (1,next) อยู่ที่ offset นี้ หน่วยเป็นชั่วโมง
        $denom = $prev - 2.0 * $cur + $next;
        $offsetHours = 0.0;
        if (abs($denom) > 1e-9) {
            $offsetHours = 0.5 * ($prev - $next) / $denom;
            // จุดยอดต้องอยู่ในช่วงหนึ่งชั่วโมงรอบตัวมันเอง ถ้าเกินแปลว่าข้อมูลแปลก ไม่ต้องขยับ
            if ($offsetHours < -1.0 || $offsetHours > 1.0) {
                $offsetHours = 0.0;
            }
        }

        $base = fis_tides_local_to_immutable($all[$i]['local'], $tz);
        if ($base === null) {
            continue;
        }
        $peakUnix = $base->getTimestamp() + (int) round($offsetHours * 3600.0);

        // ปัดเป็น 5 นาที เพราะความแม่นจริงหยาบกว่านั้น การคืนรายนาทีทำให้ดูแม่นเกินจริง
        $peakUnix = (int) round($peakUnix / 300.0) * 300;
        $peak = (new DateTimeImmutable('@' . $peakUnix))->setTimezone($tz);

        // เอาเฉพาะจุดยอดที่ตกอยู่ในวันที่ขอจริง ๆ (ที่เผื่อมาใช้แค่ช่วยตรวจจับ)
        if ($peak->format('Y-m-d') !== $date) {
            continue;
        }

        // ความสูงที่จุดยอดจากพาราโบลาเดียวกัน — สม่ำเสมอกับเวลาที่คำนวณได้
        $peakHeight = $cur - 0.25 * ($prev - $next) * $offsetHours;

        $extremes[] = [
            'type' => $isHigh ? 'high' : 'low',
            'time' => $peak->format('Y-m-d\TH:i:sP'),
            'height_m' => round($peakHeight, 2),
        ];
    }

    // ที่ราบยาว (ค่าเท่ากันติดกัน) ทำให้ตรวจเจอจุดชนิดเดียวกันซ้อนกันได้
    // สัญญาบอกว่า type ต้องสลับเสมอ จึงยุบตัวที่ซ้ำชนิดกันให้เหลือตัวที่สุดโต่งกว่า
    $merged = [];
    foreach ($extremes as $item) {
        $last = $merged === [] ? null : $merged[count($merged) - 1];
        if ($last !== null && $last['type'] === $item['type']) {
            $keepNew = $item['type'] === 'high'
                ? $item['height_m'] > $last['height_m']
                : $item['height_m'] < $last['height_m'];
            if ($keepNew) {
                $merged[count($merged) - 1] = $item;
            }
            continue;
        }
        $merged[] = $item;
    }

    return $merged;
}

/**
 * ระดับน้ำ "ตอนนี้" — คืน null ถ้าวันที่ขอไม่ใช่วันนี้
 * เพราะคำว่า "ตอนนี้" ของวันอื่นไม่มีความหมาย การเดาให้มีค่าจะทำให้ผู้ใช้เข้าใจผิด
 *
 * @param list<array{local:string, height:float}> $all
 * @return array{time:string, height_m:float, trend:string|null}|null
 */
function fis_tides_current(array $all, string $date, DateTimeZone $tz): ?array
{
    $now = new DateTimeImmutable('now', $tz);
    if ($now->format('Y-m-d') !== $date) {
        return null;
    }

    $currentHour = $now->format('Y-m-d\TH:00');

    foreach ($all as $i => $point) {
        if ($point['local'] !== $currentHour) {
            continue;
        }

        // ทิศทางดูจากชั่วโมงถัดไป ถ้าไม่มีค่อยเทียบกับชั่วโมงก่อนหน้า
        $trend = null;
        if (isset($all[$i + 1])) {
            $delta = $all[$i + 1]['height'] - $point['height'];
            $trend = $delta > 0 ? 'rising' : ($delta < 0 ? 'falling' : null);
        } elseif (isset($all[$i - 1])) {
            $delta = $point['height'] - $all[$i - 1]['height'];
            $trend = $delta > 0 ? 'rising' : ($delta < 0 ? 'falling' : null);
        }

        return [
            'time' => fis_tides_iso($point['local'], $tz),
            'height_m' => round($point['height'], 2),
            'trend' => $trend,
        ];
    }

    return null;
}

/** แปลงเวลาท้องถิ่นแบบไม่มี offset ให้เป็น ISO 8601 พร้อม +07:00 ตามที่สัญญากำหนด */
function fis_tides_iso(string $localTime, DateTimeZone $tz): ?string
{
    $dt = fis_tides_local_to_immutable($localTime, $tz);
    return $dt === null ? null : $dt->format('Y-m-d\TH:i:sP');
}

function fis_tides_local_to_immutable(string $localTime, DateTimeZone $tz): ?DateTimeImmutable
{
    try {
        return new DateTimeImmutable($localTime, $tz);
    } catch (Exception $e) {
        return null;
    }
}
