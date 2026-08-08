<?php
declare(strict_types=1);

/**
 * GET /api/weather.php?lat=6.87&lon=101.25
 * สภาพอากาศทะเลปัจจุบันและรายชั่วโมง 24 ชั่วโมงข้างหน้า ตาม docs/api-contract.md
 *
 * ข้อมูลมาจาก Open-Meteo สองบริการ:
 *   - Forecast API  → อุณหภูมิ ลม ความกดอากาศ โอกาสฝน (ขาดไม่ได้)
 *   - Marine API    → ความสูงคลื่น (มีเฉพาะพิกัดที่อยู่ในโมเดลคลื่น จึงถือเป็นของแถม)
 *
 * ความสูงคลื่นเป็น null ได้ตามสัญญา เพราะคนเอาข้อมูลนี้ไปตัดสินใจออกทะเลจริง
 * การเดาค่าให้ดูสวยจึงอันตรายกว่าการบอกตรง ๆ ว่าไม่มีข้อมูล
 */

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/remote.php';
require_once __DIR__ . '/lib/cache.php';

const FIS_WEATHER_TZ = 'Asia/Bangkok';

/** 15 นาทีตามสัญญา — พยากรณ์ของ Open-Meteo อัปเดตเป็นรายชั่วโมงอยู่แล้ว ถี่กว่านี้ไม่ได้อะไรเพิ่ม */
const FIS_WEATHER_CACHE_TTL = 900;

const FIS_WEATHER_HOURS = 24;

fis_handle(function (): void {
    fis_require_get();

    $lat = fis_weather_coord('lat', -90.0, 90.0);
    $lon = fis_weather_coord('lon', -180.0, 180.0);

    // ปัดพิกัดเหลือทศนิยม 2 ตำแหน่ง (~1 กม.) ก่อนใช้งาน
    // โมเดลพยากรณ์มีความละเอียดระดับสิบกิโลเมตร หมายที่ห่างกันไม่กี่ร้อยเมตรจึงได้ค่าชุดเดียวกันอยู่ดี
    // แต่การปัดทำให้คำขอจากคนหลายคนที่หมายเดียวกันตกลงในแคชช่องเดียวกัน แทนที่จะยิงออกไปคนละครั้ง
    $lat = round($lat, 2);
    $lon = round($lon, 2);

    $cacheKey = sprintf('weather:%.2f:%.2f', $lat, $lon);
    $payload = fis_cache_get($cacheKey, FIS_WEATHER_CACHE_TTL);
    $cached = $payload !== null;

    if (!$cached) {
        try {
            $forecast = fis_remote_get_json(fis_weather_forecast_url($lat, $lon), 8);

            // คลื่นล้มได้โดยไม่ทำให้ทั้ง endpoint ล้ม จึงจับ exception แยกและให้เวลาน้อยกว่า
            // เพื่อไม่ให้ของแถมกินเวลาของคำขอทั้งก้อน
            $marine = null;
            try {
                $marine = fis_remote_get_json(fis_weather_marine_url($lat, $lon), 5);
            } catch (FisRemoteException $e) {
                error_log('[fishing-api/weather] marine ใช้ไม่ได้ จะคืนความสูงคลื่นเป็น null: ' . $e->getMessage());
            }

            $payload = fis_weather_build($forecast, $marine);
        } catch (FisRemoteException $e) {
            error_log('[fishing-api/weather] ' . $e->getMessage());
            fis_fail(
                'ขณะนี้ดึงข้อมูลสภาพอากาศจากแหล่งข้อมูลภายนอกไม่ได้ (Open-Meteo ไม่ตอบหรือตอบช้าเกินไป) กรุณาลองใหม่อีกครั้งในอีกสักครู่',
                502,
                'upstream_unavailable'
            );
            return; // ไปไม่ถึงบรรทัดนี้ เขียนไว้ให้เครื่องมือวิเคราะห์โค้ดเห็นว่าจบแล้ว
        }

        fis_cache_put($cacheKey, $payload);
    }

    fis_json([
        'data' => $payload['data'],
        'meta' => [
            'source' => 'Open-Meteo',
            'source_url' => 'https://open-meteo.com/',
            'license' => 'CC BY 4.0',
            // เวลาที่ "ดึงข้อมูลจริง" ไม่ใช่เวลาที่ตอบคำขอนี้ ผู้ใช้จะได้รู้ว่าของเก่าแค่ไหน
            'fetched_at' => $payload['fetched_at'],
            'cached' => $cached,
        ],
    ]);
});

/**
 * อ่านและตรวจพิกัดหนึ่งแกน
 * เข้มตั้งแต่ประตูหน้า เพราะค่าที่หลุดเข้าไปจะกลายเป็นส่วนหนึ่งของ URL ที่ยิงออกไปข้างนอก
 */
function fis_weather_coord(string $name, float $min, float $max): float
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
        fis_fail(
            sprintf('%s ต้องอยู่ระหว่าง %g ถึง %g', $name, $min, $max),
            400,
            'invalid_' . $name
        );
    }

    return $value;
}

function fis_weather_forecast_url(float $lat, float $lon): string
{
    return 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude' => sprintf('%.2f', $lat),
        'longitude' => sprintf('%.2f', $lon),
        'current' => 'temperature_2m,pressure_msl,wind_speed_10m,wind_direction_10m,weather_code',
        'hourly' => 'temperature_2m,precipitation_probability,pressure_msl,wind_speed_10m,wind_direction_10m,weather_code',
        'wind_speed_unit' => 'kmh',
        'timezone' => FIS_WEATHER_TZ,
        // 2 วันพอให้เหลือครบ 24 ชั่วโมงข้างหน้าแม้จะเรียกตอนเกือบเที่ยงคืน
        'forecast_days' => 2,
    ], '', '&', PHP_QUERY_RFC3986);
}

function fis_weather_marine_url(float $lat, float $lon): string
{
    return 'https://marine-api.open-meteo.com/v1/marine?' . http_build_query([
        'latitude' => sprintf('%.2f', $lat),
        'longitude' => sprintf('%.2f', $lon),
        'current' => 'wave_height',
        'hourly' => 'wave_height',
        'timezone' => FIS_WEATHER_TZ,
        'forecast_days' => 2,
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * ประกอบคำตอบตามสัญญา
 *
 * @param array<string, mixed> $forecast
 * @param array<string, mixed>|null $marine
 * @return array{data: array<string, mixed>, fetched_at: string}
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
            'weather_code' => fis_weather_int($forecast['hourly']['weather_code'][$i] ?? null),
        ];
    }

    $windDeg = fis_weather_int($current['wind_direction_10m'] ?? $forecast['hourly']['wind_direction_10m'][$startIndex] ?? null);

    // โอกาสฝนไม่มีในบล็อก current ของ Open-Meteo จึงต้องหยิบจากแถวรายชั่วโมงของชั่วโมงปัจจุบัน
    $precip = fis_weather_int($forecast['hourly']['precipitation_probability'][$startIndex] ?? null);

    $currentWave = fis_weather_float($marine['current']['wave_height'] ?? null);
    if ($currentWave === null) {
        $currentWave = $waves[$currentHourKey] ?? null;
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
                'precipitation_probability_pct' => $precip,
                'pressure_hpa' => fis_weather_float($current['pressure_msl'] ?? null),
            ],
            'hourly' => $hourly,
        ],
        'fetched_at' => (new DateTimeImmutable('now', $tz))->format('c'),
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

    $times = $marine['hourly']['time'] ?? null;
    $heights = $marine['hourly']['wave_height'] ?? null;
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
