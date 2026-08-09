<?php
declare(strict_types=1);

/**
 * conditions.php — แหล่งกลางของ "สภาพหน้างาน" ทั้งสามชุด: อากาศ ระดับน้ำ และดวงจันทร์
 *
 * ทำไมต้องมีไฟล์นี้: /api/score.php ต้องใช้ข้อมูลทั้งสามชุดพร้อมกันเพื่อคิดคะแนน
 * ถ้าปล่อยให้ score.php ยิง HTTP กลับมาหา endpoint ของตัวเอง จะช้าเป็นสองเท่า
 * และพังทันทีถ้าเซิร์ฟเวอร์เรียกตัวเองไม่ได้ (เจอบ่อยบน shared hosting ที่ปิด loopback)
 * ถ้าปล่อยให้ score.php เขียนโค้ดดึงข้อมูลเอง ก็จะมีตรรกะซ้ำสองที่แล้ววันหนึ่งจะไม่ตรงกัน
 *
 * ทางออกคือย้ายส่วน "ดึงและประกอบข้อมูล" มาไว้ที่นี่ที่เดียว
 * แล้วให้ endpoint แต่ละตัวเหลือหน้าที่แค่ตรวจพารามิเตอร์กับจัดรูปคำตอบ
 *
 * ชุดทดสอบเดิมของ weather และ tides (76 + 117 ข้อ) เป็นตาข่ายกันการย้ายครั้งนี้ทำอะไรพัง
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/remote.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/astro.php';

const FIS_WEATHER_TZ = 'Asia/Bangkok';
const FIS_WEATHER_CACHE_TTL = 900;

/* เลขรุ่นของกุญแจแคช — เพิ่มทีละหนึ่งทุกครั้งที่รูปร่างของสิ่งที่เก็บเปลี่ยน
   ทำไมต้องมี: ตอนเพิ่มคลอโรฟิลล์ลง payload สิ่งที่เก็บไว้ก่อนหน้าไม่มีคีย์นั้น
   แต่ยังอยู่ในแคชและถูกเสิร์ฟต่อไปจนหมดอายุ ทำให้ดูเหมือนฟีเจอร์ไม่ทำงานทั้งที่ทำงานดี
   หนักกว่านั้นคือคลอโรฟิลล์ที่เก็บค่า null ไว้ก่อนจะมีธงบอกว่า "ล้มเหลว"
   ถูกอ่านเป็นคำตอบจริงว่าไม่มีข้อมูล แล้วค้างอยู่สิบสองชั่วโมง
   การขยับเลขรุ่นทำให้ของเก่าถูกมองข้ามทันที แทนที่จะต้องนั่งรอให้หมดอายุ */
const FIS_CACHE_VERSION = 2;
const FIS_WEATHER_HOURS = 24;

/* ── คลอโรฟิลล์-เอ จากดาวเทียม ──────────────────────────────────────────
   ตัวชี้แหล่งอาหาร แพลงก์ตอนพืชมากแปลว่ามีฐานอาหาร ปลาเหยื่อตาม ปลาล่าตามอีกที
   ตรงกว่าอุณหภูมิเพราะอุณหภูมิเป็นตัวแทนทางอ้อม ส่วนคลอโรฟิลล์คือของจริงที่วัดได้

   ⚠️ ทำไมใช้ชุดรายเดือน ไม่ใช่รายวันหรือ 8 วัน:
   วัดหมายจริง 20 จุดในเดือน ส.ค. 2569 ชุด 8 วันมีข้อมูลแค่ 4/20 หมาย (20%)
   ขยายกรอบเป็น 18 กม. แล้วยังได้แค่ 9/20 (45%) ชุมพร ตรัง พังงา ว่างทั้งจังหวัด
   เพราะมรสุมตะวันตกเฉียงใต้เมฆปกคลุมแทบตลอด ดาวเทียมมองผ่านเมฆไม่ได้
   ส่วนชุดรายเดือนได้ครบ 20/20 หมาย เพราะสะสมภาพทั้งเดือนจนหาช่องฟ้าเปิดเจอ

   ชุด gap-filled ที่เติมช่องว่างให้แล้วมีอยู่จริงแต่เข้าไม่ได้ (403)
   และชุด VIIRS ทุกตัวค้างอยู่เดือน มิ.ย. 2569 จึงเหลือ MODIS รายเดือนตัวเดียว */
const FIS_CHL_DATASET = 'erdMH1chlamday_R2022NRT';
const FIS_CHL_BASE = 'https://coastwatch.pfeg.noaa.gov/erddap/griddap';

/* กรอบที่ดึงรอบจุด หน่วยองศา — 0.04 องศา ~ 4.4 กม. ต่อด้าน รวมเป็นกรอบราว 9 กม.
   กว้างพอให้เฉลี่ยข้ามเซลล์ 4 กม. ได้หลายเซลล์ แต่ยังเป็น "แถวนี้" อยู่ */
const FIS_CHL_BOX_DEG = 0.04;

/* แคชนานกว่าลมและน้ำมาก เพราะข้อมูลเป็นค่าเฉลี่ยรายเดือนที่ออกเดือนละครั้ง
   ดึงถี่กว่านี้คือรบกวนเซิร์ฟเวอร์สาธารณะโดยไม่ได้ค่าใหม่อะไรเลย */
const FIS_CHL_CACHE_TTL = 43200;

/* แคชกรณี "ดึงไม่ได้" สั้นกว่ากรณีที่ได้คำตอบมาก
   เพราะสองอย่างนี้คนละเรื่อง: "เมฆบังทั้งเดือน" จะไม่เปลี่ยนภายในวันนี้แน่ ๆ
   แต่ "ต่อปลายทางไม่ติด" เป็นเรื่องชั่วคราวที่หายเองได้ในไม่กี่นาที
   ถ้าจำความล้มเหลวไว้ 12 ชั่วโมงเท่ากัน เน็ตสะดุดครั้งเดียวจะทำให้ค่าหายไปทั้งวัน
   สั้นกว่านี้ก็ไม่ดี เพราะถ้าปลายทางล่มยาวจะกลายเป็นยิงซ้ำทุกคำขอแล้วหน้าเว็บช้า */
const FIS_CHL_FAIL_TTL = 900;

/* ถ้าดึงไม่สำเร็จ ยอมเสิร์ฟค่าเดิมที่เคยได้ นานได้ถึงเจ็ดวัน
   ERDDAP เป็นเซิร์ฟเวอร์สาธารณะที่ตอบ 503 เป็นระยะ วัดจากโปรดักชันแล้ว
   ล้มราวครึ่งหนึ่งของคำขอในช่วงที่มันหนัก การ retry ทันทีจะทำให้หน้าเว็บช้าขึ้นสองเท่า
   แต่ค่านี้เป็นค่าเฉลี่ยรายเดือน ของเมื่อวานกับของวันนี้คือตัวเลขเดียวกัน
   เสิร์ฟของเดิมไปก่อนจึงดีกว่าทั้งการรอและการบอกว่าไม่มีข้อมูล
   ติดธง is_stale ไปด้วยเสมอ เพื่อไม่ให้ใครเข้าใจว่าเพิ่งดึงมาสด ๆ */
const FIS_CHL_STALE_TTL = 604800;

const FIS_TIDES_TZ = 'Asia/Bangkok';
const FIS_TIDES_CACHE_TTL = 1800;
const FIS_TIDES_MAX_AHEAD_DAYS = 7;
const FIS_TIDES_MAX_BACK_DAYS = 365;

/* จำนวนวันพยากรณ์ที่หน้าเว็บใช้แสดงผล กับเพดานที่ยอมให้ขอได้
   เพดานผูกกับ FIS_TIDES_MAX_AHEAD_DAYS เพราะคะแนนต้องมีทั้งลมและน้ำถึงจะคิดได้
   ขอลมล่วงหน้าไกลกว่าที่มีข้อมูลน้ำจึงไม่มีประโยชน์ (+1 เผื่อวันปลายที่ต้องใช้ถึงเที่ยงคืน)

   ต้องประกาศหลัง FIS_TIDES_MAX_AHEAD_DAYS — const ที่คำนวณจาก const อื่น
   ต้องเห็นตัวที่อ้างถึงก่อน ไม่งั้น PHP จะตาย "Undefined constant" ตั้งแต่โหลดไฟล์ */
/* ระยะสุ่มรอบจุดที่สนใจ หน่วยกิโลเมตร
   กริดของแบบจำลองละเอียด 1/12 องศา (~9.3 กม.) และมันสแนปพิกัดที่ขอเข้าช่องกริด
   ถ้าสุ่มใกล้กว่าหนึ่งช่อง จุดสองจุดจะตกช่องเดียวกันแล้วได้ความชันเป็นศูนย์ปลอม ๆ
   12 กม. ทำให้เหนือกับใต้ห่างกัน 24 กม. คือราว 2.6 ช่อง ซึ่งแยกออกจากกันแน่นอน */
const FIS_FRONT_SAMPLE_KM = 12.0;

/* ความชันที่ถือว่าเป็นแนวน้ำเต็มระดับ หน่วยองศาต่อกิโลเมตร
   0.06 มาจากงานวิจัยในทะเลอาหรับที่พบว่าความชันระดับนี้ให้โอกาสจับได้สูงราว 80%
   และอยู่ในช่วง 0.05-0.5 ที่วรรณกรรมใช้นิยาม "แนวน้ำ"

   ⚠️ วัดน่านน้ำภาคใต้ 30 หมายในเดือน ส.ค. 2569 ได้ 0.005-0.029 องศา/กม.
   คือ "ไม่มีหมายไหนถึงเกณฑ์แนวน้ำเลย" ซึ่งเป็นความจริงที่ต้องบอกผู้ใช้
   ห้ามปรับสเกลให้อิงค่าที่วัดได้เองเพื่อให้ตัวเลขดูดี เพราะนั่นคือการเปลี่ยน
   "ไม่มีแนวน้ำ" ให้กลายเป็น "แนวน้ำเต็มสิบ" โดยที่ทะเลไม่ได้เปลี่ยนอะไรเลย */
const FIS_FRONT_FULL_GRADIENT = 0.06;

/* ระยะห่างจริงขั้นต่ำระหว่างสองจุดตรงข้ามที่ยอมให้คิดความชัน
   ถ้าน้อยกว่านี้แปลว่าทั้งคู่ตกช่องกริดเดียวกันหรือใกล้กันเกินไป
   หารด้วยระยะสั้น ๆ จะขยายทั้งความชันจริงและความคลาดเคลื่อนจากการปัดทศนิยม */
const FIS_FRONT_MIN_BASELINE_KM = 5.0;

const FIS_WEATHER_PANEL_DAYS = 2;
const FIS_WEATHER_MAX_DAYS = FIS_TIDES_MAX_AHEAD_DAYS + 1;

const FIS_SOLUNAR_TZ = '+07:00';

const FIS_TIDES_NOTICE = 'ระดับน้ำอ้างอิงระดับน้ำทะเลปานกลาง (MSL) ไม่ใช่ระดับน้ำลงต่ำสุด (chart datum) '
    . 'ตัวเลขจึงเทียบกับตารางน้ำของกรมอุทกศาสตร์ไม่ได้ ใช้เพื่อดูจังหวะน้ำขึ้นน้ำลงเท่านั้น ห้ามใช้เพื่อการเดินเรือ';

/**
 * "ปลายทางตอบปกติ แต่ไม่มีค่าระดับน้ำให้" — คนละเรื่องกับ "ปลายทางล้ม"
 *
 * เกิดได้สองกรณี และทั้งสองไม่ใช่ความผิดพลาดของระบบ:
 *   1. พิกัดอยู่นอกพื้นที่ที่แบบจำลองครอบคลุม (กลางแผ่นดิน แหล่งน้ำปิด ขั้วโลก)
 *   2. วันที่ขอเลยระยะที่แบบจำลองพยากรณ์ได้ (ปลายทางตอบ 200 พร้อม null ล้วน ไม่ได้ฟ้อง error)
 *
 * ถ้าปล่อยให้กลายเป็น FisRemoteException จะได้ 502 ซึ่งบอกผู้ใช้ว่า "ระบบภายนอกล้ม"
 * ทั้งที่ความจริงคือ "จุดนี้/วันนี้ไม่มีข้อมูล" — คนละเรื่อง และทำให้ไล่ปัญหาผิดทาง
 */
class FisTidesNoDataException extends RuntimeException
{
}

// ---------------------------------------------------------------------------
// ตัวช่วยที่ใช้ร่วมกันทุกชุด
// ---------------------------------------------------------------------------

/**
 * อ่านและตรวจพิกัดหนึ่งแกน
 * เข้มตั้งแต่ประตูหน้า เพราะค่าที่หลุดเข้าไปจะกลายเป็นส่วนหนึ่งของ URL ที่ยิงออกไปข้างนอก
 */
function fis_conditions_coord(string $name, float $min, float $max): float
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

/** ค่าที่ปลายทางไม่มีจะเป็น null ห้ามแปลงเป็น 0 เพราะ 0 องศาหรือคลื่น 0 เมตรคือข้อมูลคนละความหมาย */
function fis_weather_float($value): ?float
{
    if (is_int($value) || is_float($value)) {
        return is_finite((float) $value) ? (float) $value : null;
    }
    if (is_string($value) && is_numeric($value)) {
        return (float) $value;
    }
    return null;
}

function fis_weather_int($value): ?int
{
    $number = fis_weather_float($value);
    return $number === null ? null : (int) round($number);
}

/** แปลงเวลาท้องถิ่นแบบไม่มี offset ให้เป็น ISO 8601 พร้อม +07:00 ตามที่สัญญากำหนด */
function fis_weather_iso(?string $localTime, DateTimeZone $tz): ?string
{
    if (!is_string($localTime) || $localTime === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($localTime, $tz))->format('c');
    } catch (Exception $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// สภาพอากาศ — Open-Meteo Forecast API + Marine API
// ---------------------------------------------------------------------------

/**
 * ดึงและประกอบข้อมูลอากาศ พร้อมแคช
 *
 * @return array{data: array<string, mixed>, fetched_at: string, cached: bool}
 * @throws FisRemoteException เมื่อแหล่งข้อมูลล้มจริง ๆ
 */
function fis_weather_payload(float $lat, float $lon, int $days = FIS_WEATHER_PANEL_DAYS): array
{
    $lat = round($lat, 2);
    $lon = round($lon, 2);
    $days = max(1, min(FIS_WEATHER_MAX_DAYS, $days));

    // จำนวนวันอยู่ในกุญแจแคชด้วย ไม่งั้นคำขอของหน้าเว็บที่ขอ 2 วัน
    // จะไปคืนให้ตัวคิดคะแนนที่ต้องการ 8 วัน แล้วชั่วโมงที่ต้องใช้จะหายไปเงียบ ๆ
    $cacheKey = sprintf('weather:v%d:%.2f:%.2f:%dd', FIS_CACHE_VERSION, $lat, $lon, $days);
    $payload = fis_cache_get($cacheKey, FIS_WEATHER_CACHE_TTL);
    if ($payload !== null) {
        $payload['cached'] = true;
        return $payload;
    }

    $forecast = fis_remote_get_json(fis_weather_forecast_url($lat, $lon, $days), 8);

    // คลื่นล้มได้โดยไม่ทำให้ทั้งชุดล้ม จึงจับ exception แยกและให้เวลาน้อยกว่า
    // เพื่อไม่ให้ของแถมกินเวลาของคำขอทั้งก้อน
    $marine = null;
    try {
        $marine = fis_remote_get_json(fis_weather_marine_url($lat, $lon, $days), 5);
    } catch (FisRemoteException $e) {
        error_log('[fishing-api/weather] marine ใช้ไม่ได้ จะคืนความสูงคลื่นเป็น null: ' . $e->getMessage());
    }

    $payload = fis_weather_build($forecast, $marine);

    /* คลอโรฟิลล์มาจาก ERDDAP ซึ่งเป็นคนละปลายทางกับ Open-Meteo และช้ากว่า
       ใส่ทีหลังเพื่อให้ล้มแยกกันได้ ถ้า ERDDAP ล่ม สภาพอากาศต้องยังใช้ได้ตามปกติ */
    $payload['data']['chlorophyll'] = fis_chlorophyll_payload($lat, $lon);

    fis_cache_put($cacheKey, $payload);

    $payload['cached'] = false;
    return $payload;
}

function fis_weather_forecast_url(float $lat, float $lon, int $days): string
{
    return 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude' => sprintf('%.2f', $lat),
        'longitude' => sprintf('%.2f', $lon),
        'current' => 'temperature_2m,pressure_msl,wind_speed_10m,wind_direction_10m,weather_code',
        'hourly' => 'temperature_2m,precipitation_probability,pressure_msl,wind_speed_10m,wind_direction_10m,weather_code',
        // sunrise/sunset ใช้คิดช่วงแสงใน score.php — ขอมาพร้อมกันเลยจะได้ไม่ต้องยิงเพิ่มอีกรอบ
        'daily' => 'sunrise,sunset',
        'wind_speed_unit' => 'kmh',
        'timezone' => FIS_WEATHER_TZ,
        // 2 วันพอให้เหลือครบ 24 ชั่วโมงข้างหน้าแม้จะเรียกตอนเกือบเที่ยงคืน
        // แต่การคิดคะแนนล่วงหน้าต้องขอมากกว่านั้น จึงให้ผู้เรียกกำหนดเอง
        'forecast_days' => $days,
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * คลอโรฟิลล์-เอ เฉลี่ยรอบจุดที่สนใจ จากดาวเทียม MODIS ผ่าน NOAA ERDDAP
 *
 * คืน null เมื่อดึงไม่ได้หรือไม่มีเซลล์ไหนมีค่า ซึ่งเกิดได้จริงถ้าเมฆบังทั้งเดือน
 * ห้ามเดาค่าแทน — "ไม่มีข้อมูล" เป็นคำตอบที่ถูกต้องกว่าตัวเลขที่แต่งขึ้น
 *
 * @return array<string, mixed>|null
 */
function fis_chlorophyll_payload(float $lat, float $lon): ?array
{
    $lat = round($lat, 2);
    $lon = round($lon, 2);

    $cacheKey = sprintf('chl:v%d:%.2f:%.2f', FIS_CACHE_VERSION, $lat, $lon);

    // คำตอบที่ได้จริง (รวมถึง "เมฆบังทั้งเดือน") เก็บยาว เพราะข้อมูลออกเดือนละครั้ง
    $cached = fis_cache_get($cacheKey, FIS_CHL_CACHE_TTL);
    if ($cached !== null && ($cached['failed'] ?? false) !== true) {
        return $cached['value'] ?? null;
    }

    // ส่วนความล้มเหลว ยอมจำไว้แค่ช่วงสั้น ๆ แล้วลองใหม่
    $recent = fis_cache_get($cacheKey, FIS_CHL_FAIL_TTL);
    if ($recent !== null && ($recent['failed'] ?? false) === true) {
        return fis_chlorophyll_stale($cacheKey);
    }

    try {
        $csv = fis_remote_get_text(fis_chlorophyll_url($lat, $lon), 8);
    } catch (FisRemoteException $e) {
        // ของเสริม ล้มได้โดยไม่ทำให้สภาพอากาศทั้งก้อนล้มตาม
        error_log('[fishing-api/chlorophyll] ดึงไม่ได้: ' . $e->getMessage());
        $stale = fis_chlorophyll_stale($cacheKey);
        fis_cache_put($cacheKey, ['value' => $stale, 'failed' => true]);
        return $stale;
    }

    $parsed = fis_chlorophyll_parse($csv);
    fis_cache_put($cacheKey, ['value' => $parsed, 'failed' => false]);

    return $parsed;
}

/**
 * ค่าคลอโรฟิลล์เดิมที่เคยดึงได้ ใช้ตอนปลายทางล้ม
 * คืน null ถ้าไม่เคยมีค่าเลย — ห้ามแต่งตัวเลขขึ้นมาแทน
 *
 * @return array<string, mixed>|null
 */
function fis_chlorophyll_stale(string $cacheKey): ?array
{
    $old = fis_cache_get($cacheKey, FIS_CHL_STALE_TTL);
    $value = $old['value'] ?? null;
    if (!is_array($value)) {
        return null;
    }

    $value['is_stale'] = true;
    return $value;
}

function fis_chlorophyll_url(float $lat, float $lon): string
{
    $box = FIS_CHL_BOX_DEG;

    /* (last) ให้ ERDDAP เลือกเวลาล่าสุดที่มีเอง ดีกว่าเราคำนวณวันที่ส่งไป
       เพราะรอบการออกข้อมูลไม่ตายตัว ถ้าเดาวันผิดจะได้ 404 ทั้งที่ข้อมูลมี */
    $query = sprintf(
        'chlorophyll[(last)][(%.4f):(%.4f)][(%.4f):(%.4f)]',
        $lat - $box,
        $lat + $box,
        $lon - $box,
        $lon + $box
    );

    return FIS_CHL_BASE . '/' . FIS_CHL_DATASET . '.csv?' . rawurlencode($query);
}

/**
 * แยก CSV ของ ERDDAP — สองบรรทัดแรกเป็นชื่อคอลัมน์กับหน่วย ที่เหลือเป็นข้อมูล
 * เซลล์ที่เมฆบังจะเป็นค่าว่างหรือ NaN ต้องข้ามไป ไม่ใช่นับเป็นศูนย์
 *
 * @return array<string, mixed>|null
 */
function fis_chlorophyll_parse(string $csv): ?array
{
    $lines = preg_split('/
?
/', trim($csv));
    if ($lines === false || count($lines) < 3) {
        return null;
    }

    $values = [];
    $observed = null;
    foreach (array_slice($lines, 2) as $line) {
        $cells = str_getcsv($line);
        if (count($cells) < 4) {
            continue;
        }
        $raw = trim((string) $cells[3]);
        if ($raw === '' || strcasecmp($raw, 'NaN') === 0 || !is_numeric($raw)) {
            continue;
        }
        $values[] = (float) $raw;
        $observed = $observed ?? trim((string) $cells[0]);
    }

    if ($values === []) {
        return null;
    }

    sort($values);
    $count = count($values);
    $middle = (int) floor($count / 2);

    return [
        // ใช้มัธยฐาน ไม่ใช่ค่าเฉลี่ย เพราะเซลล์ติดชายฝั่งมักมีค่าสูงผิดปกติ
        // จากตะกอนและน้ำจืด ซึ่งดาวเทียมนับรวมเป็นคลอโรฟิลล์ไปด้วย
        'value_mg_m3' => round($count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2, 2),
        'min_mg_m3' => round($values[0], 2),
        'max_mg_m3' => round($values[$count - 1], 2),
        'cells_used' => $count,
        'observed_month' => $observed === null ? null : substr($observed, 0, 7),
        'source' => 'MODIS Aqua ผ่าน NOAA ERDDAP (' . FIS_CHL_DATASET . ')',
        'is_stale' => false,
    ];
}

/**
 * พิกัดที่ขอจาก Marine API — จุดที่สนใจ แล้วตามด้วยสี่ทิศรอบ ๆ
 *
 * สี่จุดรอบใช้หาความชันของอุณหภูมิ (แนวน้ำ) ซึ่งเป็นสิ่งที่งานวิจัยบอกว่าดึงปลา
 * ไม่ใช่ตัวอุณหภูมิเอง — อุณหภูมิที่จุดเดียวบอกได้แค่ว่าน้ำอุ่นหรือเย็น
 * ส่วนแนวน้ำคือ "อุณหภูมิต่างกันกี่องศาต่อกิโลเมตร" ต้องมีหลายจุดถึงจะรู้
 *
 * Marine API รับหลายพิกัดในคำขอเดียวได้ การเพิ่มสี่จุดจึงไม่ได้เพิ่มจำนวนคำขอเลย
 * ตอบกลับมาเป็นอาร์เรย์เรียงตามลำดับที่ขอ จุดที่ตกบนบกจะได้ค่า null ทั้งชุด
 * โดยที่คำขอทั้งก้อนไม่ล้ม (ทดสอบแล้ว)
 *
 * @return list<array{float, float}> [กลาง, เหนือ, ตะวันออก, ใต้, ตะวันตก]
 */
function fis_front_sample_points(float $lat, float $lon): array
{
    $points = [[$lat, $lon]];
    foreach ([0.0, 90.0, 180.0, 270.0] as $bearing) {
        $points[] = fis_front_offset($lat, $lon, FIS_FRONT_SAMPLE_KM, $bearing);
    }
    return $points;
}

/** เลื่อนพิกัดไปตามทิศและระยะทาง โดยประมาณโลกเป็นทรงกลม พอสำหรับระยะสิบกว่ากิโล */
function fis_front_offset(float $lat, float $lon, float $km, float $bearingDeg): array
{
    $earthRadiusKm = 6371.0;
    $bearing = deg2rad($bearingDeg);

    $dLat = ($km * cos($bearing)) / $earthRadiusKm * 180.0 / M_PI;
    $dLon = ($km * sin($bearing)) / ($earthRadiusKm * cos(deg2rad($lat))) * 180.0 / M_PI;

    return [$lat + $dLat, $lon + $dLon];
}

function fis_weather_marine_url(float $lat, float $lon, int $days): string
{
    $points = fis_front_sample_points($lat, $lon);

    return 'https://marine-api.open-meteo.com/v1/marine?' . http_build_query([
        'latitude' => implode(',', array_map(static fn (array $p): string => sprintf('%.4f', $p[0]), $points)),
        'longitude' => implode(',', array_map(static fn (array $p): string => sprintf('%.4f', $p[1]), $points)),
        'current' => 'wave_height,sea_surface_temperature',
        'hourly' => 'wave_height,sea_surface_temperature',
        'timezone' => FIS_WEATHER_TZ,
        'forecast_days' => $days,
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * ประกอบคำตอบสภาพอากาศตามสัญญา
 *
 * @param array<string, mixed> $forecast
 * @param array<string, mixed>|null $marine
 * @return array{data: array<string, mixed>, fetched_at: string, sun: array<string, mixed>}
 * @throws FisRemoteException เมื่อรูปร่างข้อมูลจากปลายทางไม่ใช่อย่างที่ตกลงกันไว้
 */
function fis_weather_build(array $forecast, ?array $marine): array
{
    $tz = new DateTimeZone(FIS_WEATHER_TZ);

    $times = $forecast['hourly']['time'] ?? null;
    if (!is_array($times) || $times === []) {
        throw new FisRemoteException('Forecast API ไม่ได้ส่ง hourly.time กลับมา');
    }

    $current = is_array($forecast['current'] ?? null) ? $forecast['current'] : [];

    // Open-Meteo คืนเวลาท้องถิ่นแบบไม่มี offset (เช่น 2026-08-08T16:30) และเป็นช่วง 15 นาที
    // ตัดให้เหลือต้นชั่วโมงเพื่อใช้จับคู่กับแถวใน hourly ซึ่งเป็นรายชั่วโมงเต็ม
    $nowLocal = is_string($current['time'] ?? null) ? $current['time'] : (new DateTimeImmutable('now', $tz))->format('Y-m-d\TH:i');
    $currentHourKey = substr($nowLocal, 0, 13) . ':00';

    $startIndex = array_search($currentHourKey, $times, true);
    if (!is_int($startIndex)) {
        // เผื่อปลายทางเปลี่ยนรูปแบบเวลา ให้ไล่หาแถวแรกที่ยังไม่ผ่านไป ดีกว่าตอบข้อมูลของเมื่อวาน
        $startIndex = 0;
        foreach ($times as $i => $t) {
            if (is_string($t) && strcmp($t, $currentHourKey) >= 0) {
                $startIndex = (int) $i;
                break;
            }
        }
    }

    $waves = fis_weather_wave_map($marine);
    $seaTemps = fis_weather_sea_map($marine);

    $hourly = [];
    $count = count($times);
    for ($i = $startIndex; $i < $count && count($hourly) < FIS_WEATHER_HOURS; $i++) {
        $iso = fis_weather_iso(is_string($times[$i]) ? $times[$i] : null, $tz);
        if ($iso === null) {
            continue;
        }
        $hourly[] = [
            'time' => $iso,
            'temperature_c' => fis_weather_float($forecast['hourly']['temperature_2m'][$i] ?? null),
            'wind_speed_kmh' => fis_weather_float($forecast['hourly']['wind_speed_10m'][$i] ?? null),
            'wave_height_m' => $waves[$times[$i]] ?? null,
            'sea_temperature_c' => $seaTemps[$times[$i]] ?? null,
            'weather_code' => fis_weather_int($forecast['hourly']['weather_code'][$i] ?? null),
        ];
    }

    /* ตารางพยากรณ์รายชั่วโมงแบบเต็มช่วง ไม่ตัดที่ FIS_WEATHER_HOURS
       ใช้ตอนคิดคะแนนของวันอื่นที่ไม่ใช่วันนี้ ซึ่งต้องหยิบลมและคลื่นของชั่วโมงนั้นจริง ๆ
       เก็บแยกจาก data.hourly เพราะสัญญาของ /api/weather.php ระบุไว้แค่ 24 แถว
       ถ้าไปยัดรวมกัน ผู้ใช้ endpoint เดิมจะได้ payload ที่ใหญ่ขึ้นโดยไม่ได้ขอ */
    $byHour = [];
    for ($i = 0; $i < $count; $i++) {
        $key = is_string($times[$i]) ? substr($times[$i], 0, 13) : null;
        if ($key === null) {
            continue;
        }
        $byHour[$key] = [
            'wind_speed_kmh' => fis_weather_float($forecast['hourly']['wind_speed_10m'][$i] ?? null),
            'wind_direction_deg' => fis_weather_int($forecast['hourly']['wind_direction_10m'][$i] ?? null),
            'wave_height_m' => $waves[$times[$i]] ?? null,
            'sea_temperature_c' => $seaTemps[$times[$i]] ?? null,
            'precipitation_probability_pct' => fis_weather_int($forecast['hourly']['precipitation_probability'][$i] ?? null),
            'temperature_c' => fis_weather_float($forecast['hourly']['temperature_2m'][$i] ?? null),
            'pressure_hpa' => fis_weather_float($forecast['hourly']['pressure_msl'][$i] ?? null),
        ];
    }

    $windDeg = fis_weather_int($current['wind_direction_10m'] ?? $forecast['hourly']['wind_direction_10m'][$startIndex] ?? null);

    // โอกาสฝนไม่มีในบล็อก current ของ Open-Meteo จึงต้องหยิบจากแถวรายชั่วโมงของชั่วโมงปัจจุบัน
    $precip = fis_weather_int($forecast['hourly']['precipitation_probability'][$startIndex] ?? null);

    $currentWave = fis_weather_float($marine[0]['current']['wave_height'] ?? null);
    if ($currentWave === null) {
        $currentWave = $waves[$currentHourKey] ?? null;
    }

    $currentSea = fis_weather_float($marine[0]['current']['sea_surface_temperature'] ?? null);
    if ($currentSea === null) {
        $currentSea = $seaTemps[$currentHourKey] ?? null;
    }

    return [
        'data' => [
            'current' => [
                'observed_at' => fis_weather_iso($nowLocal, $tz),
                'temperature_c' => fis_weather_float($current['temperature_2m'] ?? null),
                'wind_speed_kmh' => fis_weather_float($current['wind_speed_10m'] ?? null),
                'wind_direction_deg' => $windDeg,
                'wind_direction_label' => fis_weather_direction_label($windDeg),
                'wave_height_m' => $currentWave,
                'sea_temperature_c' => $currentSea,
                'precipitation_probability_pct' => $precip,
                'pressure_hpa' => fis_weather_float($current['pressure_msl'] ?? null),
            ],
            'hourly' => $hourly,
            // สรุปรายวันของอุณหภูมิน้ำ ครอบคลุมเท่าที่ขอพยากรณ์มา
            'sea_temperature_daily' => fis_weather_sea_daily($seaTemps),
            // แนวน้ำ ณ ชั่วโมงปัจจุบัน — null ได้ถ้าจุดรอบ ๆ ตกบนบกทั้งสองแกน
            'sea_front' => fis_weather_front($marine, $currentHourKey),
        ],
        'by_hour' => $byHour,
        /* เก็บผลดิบของ Marine API ไว้ เพราะการคิดแนวน้ำของ "ชั่วโมงอื่น"
           ต้องย้อนไปอ่านอุณหภูมิของทั้งสี่จุดรอบ ๆ ที่ชั่วโมงนั้น
           ไม่ยัดลง data เพราะสัญญาของ /api/weather.php ไม่ได้ระบุไว้
           และก้อนนี้ใหญ่เกินกว่าจะส่งให้เบราว์เซอร์โดยไม่มีใครใช้ */
        'marine_raw' => $marine,
        // เก็บ sunrise/sunset แยกไว้ให้ score.php ใช้ ไม่ยัดลง data เพราะสัญญาของ weather ไม่ได้ระบุไว้
        'sun' => [
            'sunrise' => is_array($forecast['daily']['sunrise'] ?? null) ? $forecast['daily']['sunrise'] : [],
            'sunset' => is_array($forecast['daily']['sunset'] ?? null) ? $forecast['daily']['sunset'] : [],
        ],
        'fetched_at' => (new DateTimeImmutable('now', $tz))->format('c'),
    ];
}

/**
 * สภาพอากาศ ณ เวลาที่ระบุ ในรูปแบบเดียวกับบล็อก current
 *
 * ทำไมต้องมี: ตัวคิดคะแนนเคยอ่าน data.current เสมอ แปลว่าคะแนนของวันศุกร์หน้า
 * ใช้ลมและคลื่นของ "ตอนนี้" ซึ่งไม่เกี่ยวกันเลย ตอนที่ยังไม่มีตัวเลือกวันที่
 * ความผิดนี้ไม่มีใครเห็น แต่พอเลือกวันได้เมื่อไหร่มันจะกลายเป็นการโกหกทันที
 *
 * ถ้าไม่มีแถวของชั่วโมงนั้น (เช่นขอไกลเกินกว่าที่พยากรณ์ให้มา) จะคืน current
 * พร้อมธง estimated เพื่อให้ผู้เรียกบอกผู้ใช้ได้ว่าค่านี้ไม่ใช่ของวันนั้นจริง ๆ
 *
 * @param array<string, mixed> $payload ผลจาก fis_weather_payload
 * @return array<string, mixed>
 */
function fis_weather_conditions_at(array $payload, DateTimeImmutable $at): array
{
    $current = $payload['data']['current'] ?? [];
    $byHour = $payload['by_hour'] ?? [];

    $key = $at->format('Y-m-d\TH');

    // ชั่วโมงปัจจุบันใช้บล็อก current เสมอ เพราะเป็นค่าที่ปลายทางวิเคราะห์ล่าสุดจริง ๆ
    // ส่วนแถวรายชั่วโมงเป็นค่าพยากรณ์ของชั่วโมงนั้น ซึ่งใกล้กันแต่ไม่เท่ากัน
    // ถ้าเปลี่ยนมาใช้แถวพยากรณ์กับวันนี้ด้วย คะแนนของวันนี้จะขยับโดยไม่มีเหตุผลให้ผู้ใช้
    $observed = is_string($current['observed_at'] ?? null) ? substr($current['observed_at'], 0, 13) : null;
    if ($observed !== null && $observed === $key) {
        return $current + ['estimated' => false];
    }

    if (!isset($byHour[$key])) {
        return $current + ['estimated' => true];
    }

    $row = $byHour[$key];
    return [
        'observed_at' => $at->format('Y-m-d\TH:00:00P'),
        'temperature_c' => $row['temperature_c'],
        'wind_speed_kmh' => $row['wind_speed_kmh'],
        'wind_direction_deg' => $row['wind_direction_deg'],
        'wind_direction_label' => fis_weather_direction_label($row['wind_direction_deg']),
        'wave_height_m' => $row['wave_height_m'],
        'sea_temperature_c' => $row['sea_temperature_c'],
        'precipitation_probability_pct' => $row['precipitation_probability_pct'],
        'pressure_hpa' => $row['pressure_hpa'],
        'estimated' => false,
    ];
}

/**
 * จับคู่เวลา -> ความสูงคลื่น
 * ใช้เวลาเป็นกุญแจแทนตำแหน่งในอาร์เรย์ เพราะสองบริการอาจเริ่มนับคนละชั่วโมงกัน
 * ถ้าจับคู่ด้วย index แล้วเหลื่อมกันแม้ชั่วโมงเดียว ผู้ใช้จะเห็นคลื่นของเวลาอื่น
 *
 * @param array<string, mixed>|null $marine
 * @return array<string, float|null>
 */
function fis_weather_wave_map(?array $marine): array
{
    if ($marine === null) {
        return [];
    }

    $centre = $marine[0] ?? null;
    $times = $centre['hourly']['time'] ?? null;
    $heights = $centre['hourly']['wave_height'] ?? null;
    if (!is_array($times) || !is_array($heights)) {
        return [];
    }

    $map = [];
    foreach ($times as $i => $t) {
        if (is_string($t)) {
            $map[$t] = fis_weather_float($heights[$i] ?? null);
        }
    }
    return $map;
}

/**
 * จับคู่เวลา -> อุณหภูมิผิวน้ำทะเล
 * ใช้กติกาเดียวกับ fis_weather_wave_map คือจับด้วยเวลา ไม่ใช่ตำแหน่งในอาร์เรย์
 *
 * @param array<string, mixed>|null $marine
 * @return array<string, float|null>
 */
function fis_weather_sea_map(?array $marine): array
{
    if ($marine === null) {
        return [];
    }

    $centre = $marine[0] ?? null;
    $times = $centre['hourly']['time'] ?? null;
    $temps = $centre['hourly']['sea_surface_temperature'] ?? null;
    if (!is_array($times) || !is_array($temps)) {
        return [];
    }

    $map = [];
    foreach ($times as $i => $t) {
        if (is_string($t)) {
            $map[$t] = fis_weather_float($temps[$i] ?? null);
        }
    }
    return $map;
}

/**
 * สรุปอุณหภูมิน้ำรายวันจากค่ารายชั่วโมง
 *
 * ทำไมต้องสรุปรายวัน: วัดจริงแล้วอุณหภูมิน้ำแกว่งในวันเดียวราว 0.4 องศา
 * และต่างกันแค่ 0.01 องศาระหว่างวันแรกกับวันที่แปด (ปัตตานี ส.ค. 2569)
 * กราฟรายชั่วโมงจึงเป็นเส้นแบนที่ไม่บอกอะไร ค่าต่ำสุด-สูงสุด-เฉลี่ยของแต่ละวัน
 * บอกได้ตรงกว่าว่า "นิ่ง" จริง ๆ และจะเห็นความต่างชัดตอนเปลี่ยนฤดูมรสุม
 *
 * @param array<string, float|null> $seaMap
 * @return list<array<string, mixed>>
 */
function fis_weather_sea_daily(array $seaMap): array
{
    $byDate = [];
    foreach ($seaMap as $time => $value) {
        if ($value === null) {
            continue;
        }
        $date = substr((string) $time, 0, 10);
        $byDate[$date][] = $value;
    }
    ksort($byDate);

    $daily = [];
    foreach ($byDate as $date => $values) {
        $daily[] = [
            'date' => $date,
            'min_c' => round(min($values), 1),
            'max_c' => round(max($values), 1),
            'mean_c' => round(array_sum($values) / count($values), 1),
        ];
    }
    return $daily;
}

/**
 * ความชันของอุณหภูมิผิวน้ำรอบจุดที่สนใจ — "แนวน้ำ"
 *
 * ใช้ผลต่างกลาง (central difference) จากสี่ทิศ:
 *   dT/dx = (ตะวันออก - ตะวันตก) / ระยะระหว่างสองจุดนั้น
 *   dT/dy = (เหนือ - ใต้) / ระยะระหว่างสองจุดนั้น
 * ขนาดความชัน = รากที่สองของผลบวกกำลังสอง และทิศคือด้านที่น้ำอุ่นกว่า
 *
 * ⚠️ ต้องคิดระยะจากพิกัดที่ API ตอบกลับมา ไม่ใช่พิกัดที่เราขอ
 * เพราะแบบจำลองสแนปพิกัดเข้าช่องกริด 1/12 องศา จุดที่ขอห่างกัน 24 กม.
 * อาจกลายเป็นห่างจริง 18 หรือ 28 กม. ถ้าหารด้วยระยะที่ขอ ตัวเลขจะเพี้ยนได้ถึง 20%
 *
 * แกนไหนใช้ไม่ได้ (จุดตกบนบก หรือสองจุดตกช่องเดียวกัน) จะข้ามแกนนั้น
 * แล้วคิดจากแกนที่เหลือ ถ้าใช้ไม่ได้ทั้งคู่จะคืน null ไม่ใช่เดาค่าให้
 *
 * @param list<array<string, mixed>>|null $marine ผลจาก Marine API แบบหลายพิกัด
 * @return array<string, mixed>|null
 */
function fis_weather_front(?array $marine, string $hourKey): ?array
{
    if ($marine === null || count($marine) < 5) {
        return null;
    }

    // ลำดับตรงกับ fis_front_sample_points: กลาง เหนือ ตะวันออก ใต้ ตะวันตก
    [$centre, $north, $east, $south, $west] = $marine;

    $axisNs = fis_front_axis($north, $south, $centre, $hourKey);
    $axisEw = fis_front_axis($east, $west, $centre, $hourKey);

    if ($axisNs === null && $axisEw === null) {
        return null;
    }

    $dTdy = $axisNs['slope'] ?? 0.0;
    $dTdx = $axisEw['slope'] ?? 0.0;

    $gradient = sqrt($dTdx * $dTdx + $dTdy * $dTdy);

    // ทิศที่น้ำอุ่นขึ้น นับตามเข็มจากทิศเหนือ ซึ่งเป็นวิธีอ่านทิศบนเรือ
    $bearing = $gradient > 0.0
        ? (int) round(fmod(rad2deg(atan2($dTdx, $dTdy)) + 360.0, 360.0))
        : null;

    // ระยะที่ใช้จริงต่างจากระยะที่ขอ เพราะแบบจำลองสแนปพิกัดเข้าช่องกริด
    // ส่งออกไปด้วยเพื่อให้ตรวจย้อนได้ว่าตัวเลขนี้มาจากช่วงกี่กิโลเมตร
    $baselines = array_values(array_filter([
        $axisNs['km'] ?? null,
        $axisEw['km'] ?? null,
    ], static fn ($v): bool => $v !== null));

    return [
        'gradient_c_per_km' => round($gradient, 4),
        'warmer_toward_deg' => $bearing,
        'warmer_toward_label' => fis_weather_direction_label($bearing),
        'axes_used' => ($axisNs !== null ? 1 : 0) + ($axisEw !== null ? 1 : 0),
        'requested_km' => FIS_FRONT_SAMPLE_KM,
        'baseline_km' => $baselines === [] ? null : round(array_sum($baselines) / count($baselines), 1),
    ];
}

/**
 * ความชันตามแกนหนึ่ง
 *
 * ปกติใช้ผลต่างกลางจากจุดตรงข้ามสองจุด แต่หมายที่อยู่ชิดฝั่งจะมีจุดหนึ่งตกบนบก
 * ซึ่งเป็นเรื่องปกติมาก ไม่ใช่กรณียกเว้น ถ้าทิ้งทั้งแกนไปเลยจะเหลือข้อมูลแกนเดียว
 * จึงถอยไปใช้ผลต่างระหว่างจุดกลางกับด้านที่ยังมีข้อมูล ซึ่งยังเป็นความชันจริง
 * เพียงแต่วัดจากช่วงที่สั้นกว่า
 *
 * คืน null เมื่อไม่มีข้อมูลทั้งสองด้าน หรือสองจุดใกล้กันเกินกว่าจะหารได้อย่างมีความหมาย
 *
 * @return array{slope: float, km: float}|null
 */
function fis_front_axis(?array $plus, ?array $minus, ?array $centre, string $hourKey): ?array
{
    $a = fis_front_temp_at($plus, $hourKey);
    $b = fis_front_temp_at($minus, $hourKey);

    if ($a !== null && $b !== null) {
        return fis_front_slope($plus, $minus, $a, $b);
    }

    $middle = fis_front_temp_at($centre, $hourKey);
    if ($middle === null) {
        return null;
    }

    if ($a !== null) {
        return fis_front_slope($plus, $centre, $a, $middle);
    }
    if ($b !== null) {
        return fis_front_slope($centre, $minus, $middle, $b);
    }

    return null;
}

/**
 * ความชันระหว่างสองจุด โดยคิดระยะจากพิกัดที่ API ตอบกลับมาจริง
 *
 * @return array{slope: float, km: float}|null
 */
function fis_front_slope(?array $from, ?array $to, float $tempFrom, float $tempTo): ?array
{
    $latA = isset($from['latitude']) ? (float) $from['latitude'] : null;
    $lonA = isset($from['longitude']) ? (float) $from['longitude'] : null;
    $latB = isset($to['latitude']) ? (float) $to['latitude'] : null;
    $lonB = isset($to['longitude']) ? (float) $to['longitude'] : null;
    if ($latA === null || $lonA === null || $latB === null || $lonB === null) {
        return null;
    }

    $km = fis_front_distance_km($latA, $lonA, $latB, $lonB);
    if ($km < FIS_FRONT_MIN_BASELINE_KM) {
        return null;
    }

    return ['slope' => ($tempFrom - $tempTo) / $km, 'km' => $km];
}

/** อุณหภูมิของจุดหนึ่งที่ชั่วโมงที่ระบุ ถ้าไม่มีแถวนั้นจะถอยไปใช้ค่าปัจจุบัน */
function fis_front_temp_at(?array $point, string $hourKey): ?float
{
    if ($point === null) {
        return null;
    }

    $times = $point['hourly']['time'] ?? null;
    $temps = $point['hourly']['sea_surface_temperature'] ?? null;
    if (is_array($times) && is_array($temps)) {
        foreach ($times as $i => $t) {
            if (is_string($t) && strncmp($t, $hourKey, 13) === 0) {
                return fis_weather_float($temps[$i] ?? null);
            }
        }
    }

    return fis_weather_float($point['current']['sea_surface_temperature'] ?? null);
}

/** ระยะทางระหว่างสองพิกัดด้วยสูตร haversine หน่วยกิโลเมตร */
function fis_front_distance_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusKm = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $earthRadiusKm * asin(min(1.0, sqrt($a)));
}

/** ทิศลมภาษาไทย 8 ทิศ แบ่งช่องละ 45 องศา โดยให้ 0 องศาอยู่กึ่งกลางช่อง "เหนือ" */
function fis_weather_direction_label(?int $degrees): ?string
{
    if ($degrees === null) {
        return null;
    }

    $labels = [
        'เหนือ',
        'ตะวันออกเฉียงเหนือ',
        'ตะวันออก',
        'ตะวันออกเฉียงใต้',
        'ใต้',
        'ตะวันตกเฉียงใต้',
        'ตะวันตก',
        'ตะวันตกเฉียงเหนือ',
    ];

    $normalized = (($degrees % 360) + 360) % 360;
    $index = (int) floor(($normalized + 22.5) / 45) % 8;

    return $labels[$index];
}

// ---------------------------------------------------------------------------
// ระดับน้ำ — Open-Meteo Marine API (sea_level_height_msl)
// ---------------------------------------------------------------------------

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

/**
 * ดึงและประกอบข้อมูลระดับน้ำ พร้อมแคช
 *
 * @return array{data: array<string, mixed>, fetched_at: string, cached: bool}
 * @throws FisRemoteException|FisTidesNoDataException
 */
function fis_tides_payload(float $lat, float $lon, string $date): array
{
    $lat = round($lat, 2);
    $lon = round($lon, 2);

    $tz = new DateTimeZone(FIS_TIDES_TZ);

    $cacheKey = sprintf('tides:%.2f:%.2f:%s', $lat, $lon, $date);
    $payload = fis_cache_get($cacheKey, FIS_TIDES_CACHE_TTL);
    if ($payload !== null) {
        $payload['cached'] = true;
        return $payload;
    }

    $raw = fis_remote_get_json(fis_tides_url($lat, $lon, $date), 8);
    $payload = fis_tides_build($raw, $date, $tz);
    fis_cache_put($cacheKey, $payload);

    $payload['cached'] = false;
    return $payload;
}

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
 * ประกอบคำตอบระดับน้ำตามสัญญา
 *
 * @param array<string, mixed> $raw
 * @return array{data: array<string, mixed>, fetched_at: string}
 * @throws FisRemoteException|FisTidesNoDataException
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

    return [
        'data' => [
            'date' => $date,
            'datum' => 'mean_sea_level',
            'current' => fis_tides_current($all, $date, $tz),
            'extremes' => fis_tides_extremes($all, $date, $tz),
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

// ---------------------------------------------------------------------------
// ดวงจันทร์และ Solunar — คำนวณในระบบทั้งหมด (ดู astro.php)
// ---------------------------------------------------------------------------

/**
 * ปัดเวลาเป็นนาทีที่ใกล้ที่สุดแล้วจัดรูปแบบ ISO 8601
 *
 * ทำไมปัดเป็นนาที: แบบจำลองมีความคลาดเคลื่อนระดับสิบวินาทีขึ้นไปอยู่แล้ว
 * การคืนวินาทีที่ไม่ใช่ศูนย์จะทำให้ผู้ใช้เข้าใจผิดว่าแม่นกว่าความเป็นจริง
 */
function fis_solunar_iso(float $unix): string
{
    $rounded = (int) round($unix / 60.0) * 60;
    return (new DateTimeImmutable('@' . $rounded))
        ->setTimezone(new DateTimeZone(FIS_SOLUNAR_TZ))
        ->format('Y-m-d\TH:i:sP');
}

/** สร้างช่วงเวลาที่มีเหตุการณ์อยู่กึ่งกลาง กว้างรวม $lengthMinutes นาที */
function fis_solunar_period(float $centerUnix, int $lengthMinutes): array
{
    $half = $lengthMinutes * 30.0; // ครึ่งหนึ่งของความยาว หน่วยวินาที
    return [
        'start' => fis_solunar_iso($centerUnix - $half),
        'end' => fis_solunar_iso($centerUnix + $half),
    ];
}

/**
 * ประกอบข้อมูล solunar ของวันหนึ่ง
 *
 * @return array<string, mixed> โครงตรงกับ data ของ /api/solunar.php
 */
function fis_solunar_data(float $lat, float $lon, string $date, DateTimeImmutable $dayStart): array
{
    $startUnix = (float) $dayStart->getTimestamp();
    $endUnix = $startUnix + 86400.0;

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

    return [
        'date' => $date,
        'moon' => [
            'phase_name_th' => $phase['phase_name_th'],
            'illumination_pct' => (int) round($phase['illumination'] * 100.0),
            'age_days' => round($phase['age_days'], 1),
        ],
        'major_periods' => $major,
        'minor_periods' => $minor,
        // null เมื่อวันนั้นไม่มีเหตุการณ์จริง ๆ เกิดขึ้นได้เพราะรอบจันทร์ขึ้นห่างกัน ~24 ชม. 50 นาที
        'moonrise' => $events['moonrise'] === null ? null : fis_solunar_iso($events['moonrise']),
        'moonset' => $events['moonset'] === null ? null : fis_solunar_iso($events['moonset']),
    ];
}
