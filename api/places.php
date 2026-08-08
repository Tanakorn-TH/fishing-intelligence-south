<?php
declare(strict_types=1);

/**
 * GET /api/places.php?q=หาดใหญ่          ค้นหาสถานที่สำหรับเลือกจุดดูสภาพอากาศ
 * GET /api/places.php?lat=6.87&lon=101.25  หาสถานที่ใกล้พิกัดที่สุด (ใช้กับ GPS)
 *
 * ทำไมต้องมี endpoint นี้: ผู้ใช้ต้องเลือกได้ว่าจะดูสภาพอากาศของจุดไหนในภาคใต้
 * และเมื่อขอตำแหน่งจาก GPS มาได้ ต้องแปลงพิกัดดิบเป็นชื่อที่คนอ่านรู้เรื่อง
 *
 * ⚠️ สถานที่ในนี้เป็น "จุดอ้างอิงสำหรับดูสภาพอากาศ" ไม่ใช่หมายตกปลา
 * หมายจริงอยู่ในตาราง fishing_spots ซึ่งต้องได้พิกัดจากผู้ดูแลเท่านั้น
 * ห้ามเอาสองอย่างนี้ไปปนกันในหน้าเว็บโดยไม่บอกผู้ใช้ว่าอันไหนเป็นอันไหน
 *
 * แหล่งข้อมูลสองชั้น:
 *   1. ชุดในระบบ (api/lib/places-data.php) — อำเภอทั้งหมดของจังหวัดที่รองรับ
 *      รายชื่อมาจาก GeoThai (MIT) พิกัดมาจาก Open-Meteo Geocoding
 *      ตอบเร็ว ค้นบางส่วนของคำภาษาไทยได้ และค้นด้วยชื่ออังกฤษได้
 *   2. Open-Meteo Geocoding สด ๆ — เสริมเมื่อชุดในระบบไม่พอ
 *      จำเป็นเพราะชุดในระบบมีเฉพาะระดับอำเภอ ไม่มีชื่อสถานที่ย่อย
 *      แต่ค้นบางส่วนของคำภาษาไทยได้ไม่ดี จึงใช้เป็นตัวเสริม ไม่ใช่ตัวหลัก
 *      ผลจากชั้นนี้ถูกจัดลำดับไว้ท้ายกลุ่มเสมอ
 */

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/remote.php';
require_once __DIR__ . '/lib/cache.php';
require_once __DIR__ . '/lib/places-data.php';

/** ขอบเขตภาคใต้โดยประมาณ ใช้กันไม่ให้ผลจาก geocoder หลุดไปภาคอื่นหรือประเทศอื่น */
const FIS_PLACES_LAT_MIN = 5.4;
const FIS_PLACES_LAT_MAX = 11.5;
const FIS_PLACES_LON_MIN = 97.0;
const FIS_PLACES_LON_MAX = 102.5;

// เพดานสูงพอให้ frontend ขอรายการทั้งหมดมาแสดงในครั้งเดียว
// ชุดข้อมูลอยู่ในไฟล์ ไม่ได้แตะฐานข้อมูลหรือเน็ต การคืนทั้งหมดจึงถูกมาก
//
// ⚠️ เคยตั้งไว้ 100 ตอนที่มีข้อมูล 54 จุด พอชุดข้อมูลโตเป็น 113 จุด
// จังหวัดท้าย ๆ ถูกตัดหายไปเงียบ ๆ โดยไม่มีอะไรฟ้อง ผู้ใช้จังหวัดนั้นจะเลือกที่ของตัวเองไม่ได้
// เพดานนี้จึงต้องสูงกว่าขนาดชุดข้อมูลเสมอ และโหมดเปิดดูรายการไม่ตัดเลย (ดู fis_places_respond)
const FIS_PLACES_MAX_LIMIT = 300;
const FIS_PLACES_DEFAULT_LIMIT = 8;

/** ชื่อสถานที่ไม่เปลี่ยนบ่อย แคชได้ยาว ลดภาระปลายทางเวลาผู้ใช้พิมพ์ทีละตัวอักษร */
const FIS_PLACES_CACHE_TTL = 86400;

fis_handle(function (): void {
    fis_require_get();

    $limit = fis_places_limit();

    // พิกัดอ้างอิงเป็นตัวเลือก ส่งมาได้ทั้งคู่หรือไม่ส่งเลย
    // ถ้าส่งมา ผลลัพธ์จะถูกเรียงตามระยะทางจากจุดนั้นและติดระยะทางไปด้วย
    // ใช้ได้ทั้งกับการเปิดดูรายการทั้งหมดและการค้นหา เพราะคนเลือกหมายคิดจาก "ใกล้ฉันแค่ไหน" เป็นหลัก
    $hasLat = isset($_GET['lat']) && trim((string) $_GET['lat']) !== '';
    $hasLon = isset($_GET['lon']) && trim((string) $_GET['lon']) !== '';
    if ($hasLat !== $hasLon) {
        fis_fail('ถ้าจะอ้างอิงพิกัด ต้องส่งทั้ง lat และ lon', 400, 'missing_coordinate');
    }

    $origin = null;
    if ($hasLat) {
        $origin = [
            'lat' => fis_places_coord('lat', -90.0, 90.0),
            'lon' => fis_places_coord('lon', -180.0, 180.0),
        ];
    }

    fis_places_respond(fis_places_clean_query($_GET['q'] ?? ''), $origin, $limit);
});

/**
 * ทำความสะอาดคำค้นตั้งแต่ประตูหน้า
 *
 * ค่านี้ถูกเอาไปสองที่: ประกอบ URL ที่ยิงออกไปหา geocoder และสะท้อนกลับใน meta.query
 * ชื่อสถานที่ไม่มีทางมีวงเล็บมุมหรืออักขระควบคุมอยู่ จึงตัดทิ้งได้โดยไม่เสียความสามารถในการค้น
 *
 * คำตอบเป็น application/json พร้อม nosniff อยู่แล้ว markup ที่สะท้อนกลับจึงรันไม่ได้
 * แต่การไม่สะท้อน markup ดิบออกไปตั้งแต่แรกคือสุขอนามัยที่ถูกกว่า
 * และเพดานความยาวกันไม่ให้มีคนส่งคำค้นยาวเป็นกิโลไบต์ไปให้ปลายทางแทนเรา
 */
function fis_places_clean_query($raw): string
{
    if (!is_string($raw)) {
        return '';
    }

    // ตัดอักขระควบคุมและวงเล็บมุมออก ที่เหลือปล่อยผ่านเพื่อไม่ให้กระทบภาษาไทย
    $clean = preg_replace('/[\x00-\x1F\x7F<>]/u', '', $raw);
    if (!is_string($clean)) {
        return '';
    }

    $clean = trim($clean);
    return mb_substr($clean, 0, 60);
}

function fis_places_limit(): int
{
    $raw = isset($_GET['limit']) ? trim((string) $_GET['limit']) : '';
    if ($raw === '') {
        return FIS_PLACES_DEFAULT_LIMIT;
    }
    if (!ctype_digit($raw)) {
        fis_fail('limit ต้องเป็นจำนวนเต็ม', 400, 'invalid_limit');
    }
    $value = (int) $raw;
    if ($value < 1 || $value > FIS_PLACES_MAX_LIMIT) {
        fis_fail('limit ต้องอยู่ระหว่าง 1 ถึง ' . FIS_PLACES_MAX_LIMIT, 400, 'invalid_limit');
    }
    return $value;
}

function fis_places_coord(string $name, float $min, float $max): float
{
    $raw = trim((string) $_GET[$name]);
    if (!is_numeric($raw)) {
        fis_fail("{$name} ต้องเป็นตัวเลข", 400, 'invalid_' . $name);
    }
    $value = (float) $raw;
    if (!is_finite($value) || $value < $min || $value > $max) {
        fis_fail(sprintf('%s ต้องอยู่ระหว่าง %g ถึง %g', $name, $min, $max), 400, 'invalid_' . $name);
    }
    return $value;
}

/** ตัดคำนำหน้า "จังหวัด" ออกเพื่อให้ผู้ใช้พิมพ์ "สงขลา" แล้วเจอ "จังหวัดสงขลา" ด้วย */
function fis_places_plain(string $text): string
{
    $text = trim($text);
    if (mb_strpos($text, 'จังหวัด') === 0) {
        $text = mb_substr($text, mb_strlen('จังหวัด'));
    }
    return $text;
}

/**
 * ตอบรายการสถานที่
 *
 * ลำดับของผลลัพธ์:
 *   - มีพิกัดอ้างอิง -> เรียงตามระยะทางจากใกล้ไปไกล เพราะคนเลือกหมายคิดจาก "ใกล้ฉันแค่ไหน"
 *   - ไม่มีพิกัด     -> เรียงตามจังหวัด เพื่อให้ผลออกมาเหมือนเดิมทุกครั้ง ไม่สุ่มไปมา
 *
 * จังหวัดติดไปกับทุกแถวเสมอไม่ว่าจะเรียงแบบไหน เพราะชื่ออำเภอซ้ำกันได้ข้ามจังหวัด
 * ผู้ใช้ต้องอ่านออกว่ากำลังจะเลือก "ปะทิว ชุมพร" ไม่ใช่ปะทิวที่อื่น
 *
 * @param array{lat:float, lon:float}|null $origin
 */
function fis_places_respond(string $query, ?array $origin, int $limit): void
{
    $usedRemote = false;

    if ($query === '') {
        $results = [];
        foreach (fis_places_sorted() as $place) {
            $results[] = fis_places_row($place, 'ในระบบ');
        }
        // เปิดดูรายการทั้งหมด ไม่ใช่ค้นหา — คืนครบทุกจุด ไม่ตัด
        // "ขอดูทั้งหมด" แล้วได้ไม่ครบคือการโกหกผู้ใช้ และเป็นบั๊กที่ไม่มีอะไรฟ้องเลย
        if (!isset($_GET['limit'])) {
            $limit = count($results);
        }
    } else {
        $results = fis_places_search_local($query);

        // ชุดในระบบไม่พอ ค่อยออกไปถาม geocoder
        // ล้มได้โดยไม่ทำให้ทั้ง endpoint ล้ม เพราะผลจากชุดในระบบยังใช้ได้อยู่
        if (count($results) < $limit && mb_strlen($query) >= 2) {
            try {
                $remote = fis_places_search_remote($query);
                $usedRemote = true;
                $results = fis_places_merge($results, $remote);
            } catch (FisRemoteException $e) {
                error_log('[fishing-api/places] geocoder ใช้ไม่ได้ จะคืนเฉพาะชุดในระบบ: ' . $e->getMessage());
            }
        }
    }

    if ($origin !== null) {
        foreach ($results as $i => $row) {
            $results[$i]['distance_km'] = round(
                fis_places_distance($origin['lat'], $origin['lon'], $row['lat'], $row['lon']),
                1
            );
        }

        // เรียงตามความตรงของชื่อก่อน แล้วค่อยใช้ระยะทางตัดสินในกลุ่มเดียวกัน
        //
        // ถ้าเรียงด้วยระยะทางล้วน ผลที่ตรงเป๊ะจะถูกดันลงไปใต้ผลที่บังเอิญอยู่ใกล้กว่า
        // เช่นค้น "krabi" แล้วได้ "ท่าอากาศยานนานาชาติกระบี่" ขึ้นก่อน "กระบี่"
        // คนพิมพ์ชื่อมาเต็ม ๆ ย่อมอยากได้สิ่งที่ชื่อตรงก่อน ไม่ใช่สิ่งที่ใกล้กว่า
        //
        // ตอนเปิดดูรายการ (ไม่ได้ค้น) ทุกแถวมี rank เท่ากัน ผลจึงเป็นการเรียงตามระยะทางล้วน
        usort($results, static function (array $a, array $b): int {
            $byRank = ($a['_rank'] ?? 9) <=> ($b['_rank'] ?? 9);
            return $byRank !== 0 ? $byRank : ($a['distance_km'] <=> $b['distance_km']);
        });
    }

    // _rank เป็นของใช้ภายใน ไม่ใช่ส่วนหนึ่งของสัญญา จึงไม่ส่งออกไปให้ frontend
    foreach ($results as $i => $row) {
        unset($results[$i]['_rank']);
    }

    $results = array_slice($results, 0, $limit);

    $meta = [
        'query' => $query,
        'count' => count($results),
        'sorted_by' => $origin === null ? 'province' : 'distance',
        'source' => $usedRemote
            ? 'ชุดข้อมูลในระบบ + Open-Meteo Geocoding'
            : 'ชุดข้อมูลในระบบ',
        'source_url' => 'https://open-meteo.com/en/docs/geocoding-api',
        'license' => 'CC BY 4.0',
        'coverage' => '14 จังหวัดภาคใต้ ทั้งฝั่งอ่าวไทยและอันดามัน',
        'notice' => 'รายการนี้เป็นจุดอ้างอิงสำหรับดูสภาพอากาศ ไม่ใช่หมายตกปลา',
    ];

    if ($origin !== null) {
        $inRegion = $origin['lat'] >= FIS_PLACES_LAT_MIN && $origin['lat'] <= FIS_PLACES_LAT_MAX
            && $origin['lon'] >= FIS_PLACES_LON_MIN && $origin['lon'] <= FIS_PLACES_LON_MAX;

        $meta['from'] = $origin;
        // บอกตรง ๆ ว่าอยู่นอกพื้นที่ที่ชุดข้อมูลครอบคลุม จะได้ไม่งงว่าทำไมที่ใกล้สุดอยู่ไกลจัง
        $meta['in_region'] = $inRegion;
        if (!$inRegion) {
            $meta['notice'] = 'พิกัดที่ส่งมาอยู่นอกภาคใต้ รายการที่คืนจึงเป็นจุดที่ใกล้ที่สุดเท่าที่มีในระบบ';
        }
    }

    fis_json(['data' => $results, 'meta' => $meta]);
}

/**
 * เรียงชุดข้อมูลตามจังหวัด แล้วให้ตัวจังหวัดเองมาก่อนอำเภอในจังหวัดนั้น
 *
 * เรื่องการเรียงภาษาไทย: ใช้ strcmp บน UTF-8 ซึ่งเท่ากับเรียงตามรหัสอักขระ
 * พยัญชนะไทย ก-ฮ อยู่ในช่วง U+0E01-U+0E2E เรียงตามลำดับพยัญชนะพอดี ผลจึงใกล้เคียงพจนานุกรม
 * ข้อจำกัดที่รู้ตัว: สระหน้า (เ แ โ ใ ไ) มีรหัสสูงกว่าพยัญชนะ ชื่อที่ขึ้นต้นด้วยสระหน้า
 * จึงไปกองท้ายแทนที่จะแทรกตามเสียง — ยอมรับได้เพราะรายการถูกจัดกลุ่มตามจังหวัดอยู่แล้ว
 * ถ้าต้องการเรียงแบบไทยจริงต้องใช้ ext-intl ซึ่งโฮสต์ปลายทางอาจไม่มี
 *
 * @return list<array{name:string, province:string, lat:float, lon:float, kind:string}>
 */
function fis_places_sorted(): array
{
    $places = fis_places_dataset();

    usort($places, static function (array $a, array $b): int {
        $byProvince = strcmp(fis_places_plain($a['province']), fis_places_plain($b['province']));
        if ($byProvince !== 0) {
            return $byProvince;
        }
        // ในจังหวัดเดียวกัน ตัวจังหวัดขึ้นก่อน แล้วค่อยอำเภอ
        $rank = static fn(array $p): int => $p['kind'] === 'province' ? 0 : 1;
        $byKind = $rank($a) <=> $rank($b);
        if ($byKind !== 0) {
            return $byKind;
        }
        return strcmp($a['name'], $b['name']);
    });

    return $places;
}

/**
 * @return list<array<string, mixed>>
 */
function fis_places_search_local(string $query): array
{
    $needle = fis_places_plain($query);
    if ($needle === '') {
        return [];
    }

    // ค้นได้ทั้งไทยและอังกฤษ เพราะคนพิมพ์บนแป้นอังกฤษอยู่แล้วมีเยอะ
    // และชื่ออังกฤษมาจากรายชื่อทางการ (GeoThai) จึงสะกดตรงกับที่คนเห็นบนป้ายจริง
    $lowerNeedle = mb_strtolower($needle);

    $exact = [];
    $prefix = [];
    $contains = [];

    foreach (fis_places_dataset() as $place) {
        $name = $place['name'];
        $nameEn = mb_strtolower((string) ($place['name_en'] ?? ''));
        $province = fis_places_plain($place['province']);

        if ($name === $needle || ($nameEn !== '' && $nameEn === $lowerNeedle)) {
            $exact[] = fis_places_row($place, 'ในระบบ') + ['_rank' => 0];
        } elseif (mb_strpos($name, $needle) === 0
            || ($nameEn !== '' && mb_strpos($nameEn, $lowerNeedle) === 0)) {
            $prefix[] = fis_places_row($place, 'ในระบบ') + ['_rank' => 1];
        } elseif (mb_strpos($name, $needle) !== false
            || mb_strpos($province, $needle) !== false
            || ($nameEn !== '' && mb_strpos($nameEn, $lowerNeedle) !== false)) {
            $contains[] = fis_places_row($place, 'ในระบบ') + ['_rank' => 2];
        }
    }

    // ตรงเป๊ะ -> ขึ้นต้นด้วย -> มีคำนั้นอยู่ข้างใน
    // เรียงแบบนี้เพราะคนพิมพ์ค้นหามักคาดหวังให้สิ่งที่ตรงที่สุดอยู่บนสุด
    return array_merge($exact, $prefix, $contains);
}

/**
 * @return list<array<string, mixed>>
 * @throws FisRemoteException
 */
function fis_places_search_remote(string $query): array
{
    $cacheKey = 'places:' . mb_strtolower($query);
    $cached = fis_cache_get($cacheKey, FIS_PLACES_CACHE_TTL);
    if ($cached !== null) {
        return $cached['rows'] ?? [];
    }

    $url = 'https://geocoding-api.open-meteo.com/v1/search?' . http_build_query([
        'name' => $query,
        'count' => 10,
        'language' => 'th',
        'format' => 'json',
    ], '', '&', PHP_QUERY_RFC3986);

    $data = fis_remote_get_json($url, 5);

    $rows = [];
    foreach (($data['results'] ?? []) as $result) {
        if (!is_array($result)) {
            continue;
        }
        $lat = $result['latitude'] ?? null;
        $lon = $result['longitude'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)) {
            continue;
        }
        // กันผลที่หลุดออกนอกภาคใต้ ผู้ใช้เลือกไปก็ไม่มีความหมายกับแอปนี้
        if (($result['country_code'] ?? '') !== 'TH'
            || $lat < FIS_PLACES_LAT_MIN || $lat > FIS_PLACES_LAT_MAX
            || $lon < FIS_PLACES_LON_MIN || $lon > FIS_PLACES_LON_MAX) {
            continue;
        }

        $rows[] = fis_places_row([
            'name' => (string) ($result['name'] ?? ''),
            'province' => (string) ($result['admin1'] ?? ''),
            'lat' => (float) $lat,
            'lon' => (float) $lon,
            'kind' => 'geocoded',
        ], 'Open-Meteo') + ['_rank' => 3];
    }

    fis_cache_put($cacheKey, ['rows' => $rows]);
    return $rows;
}

/**
 * รวมสองชุดโดยไม่ให้สถานที่เดียวกันโผล่ซ้ำ
 * เทียบด้วยพิกัดปัดทศนิยม 2 ตำแหน่ง (~1 กม.) เพราะสองแหล่งมักให้พิกัดต่างกันเล็กน้อย
 * ถ้าเทียบด้วยชื่อจะพลาด เพราะ geocoder สะกดไม่เหมือนกันเสมอไป
 *
 * @param list<array<string, mixed>> $primary
 * @param list<array<string, mixed>> $extra
 * @return list<array<string, mixed>>
 */
function fis_places_merge(array $primary, array $extra): array
{
    $seen = [];
    foreach ($primary as $row) {
        $seen[sprintf('%.2f:%.2f', $row['lat'], $row['lon'])] = true;
    }

    foreach ($extra as $row) {
        $key = sprintf('%.2f:%.2f', $row['lat'], $row['lon']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $primary[] = $row;
    }

    return $primary;
}

/**
 * หาฝั่งทะเลจากชื่อจังหวัด — ใช้กับผลจาก geocoder ซึ่งไม่มีข้อมูลฝั่งติดมา
 * เทียบกับชุดข้อมูลของเราเองที่รู้ฝั่งอยู่แล้ว ไม่ได้เดาจากพิกัด
 * จังหวัดไหนไม่มีในชุดข้อมูลจะได้ค่าว่าง แล้วแสดงเป็น "ไม่ระบุฝั่ง" ตรง ๆ
 */
function fis_places_coast_from_province(string $province): string
{
    static $byProvince = null;
    if ($byProvince === null) {
        $byProvince = [];
        foreach (fis_places_dataset() as $place) {
            $key = fis_places_plain($place['province']);
            if ($key !== '' && ($place['coast'] ?? '') !== '') {
                $byProvince[$key] = $place['coast'];
            }
        }
    }

    return $byProvince[fis_places_plain($province)] ?? '';
}

/** ระยะทางวงกลมใหญ่ หน่วยกิโลเมตร (haversine) รัศมีโลกเฉลี่ย 6371 กม. */
function fis_places_distance(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $toRad = M_PI / 180.0;
    $dLat = ($lat2 - $lat1) * $toRad;
    $dLon = ($lon2 - $lon1) * $toRad;

    $a = sin($dLat / 2) ** 2
        + cos($lat1 * $toRad) * cos($lat2 * $toRad) * sin($dLon / 2) ** 2;

    return 6371.0 * 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));
}

/**
 * @param array{name:string, province:string, lat:float, lon:float, kind:string} $place
 * @return array<string, mixed>
 */
function fis_places_row(array $place, string $source): array
{
    // ฝั่งทะเลมีเฉพาะในชุดข้อมูลของเรา ผลจาก geocoder จะไม่มี จึงเติมให้จากจังหวัดที่ได้มา
    $coast = $place['coast'] ?? fis_places_coast_from_province((string) $place['province']);

    return [
        // id ประกอบจากพิกัด ใช้เป็นกุญแจฝั่ง frontend ได้โดยไม่ต้องมีตารางในฐานข้อมูล
        'id' => sprintf('%.4f,%.4f', $place['lat'], $place['lon']),
        'name' => $place['name'],
        // ชื่ออังกฤษมีเฉพาะชุดในระบบ ผลจาก geocoder จะเป็นค่าว่าง
        'name_en' => (string) ($place['name_en'] ?? ''),
        'province' => $place['province'],
        'lat' => round((float) $place['lat'], 4),
        'lon' => round((float) $place['lon'], 4),
        'kind' => $place['kind'],
        // อันดามันกับอ่าวไทยมีคลื่นลมและฤดูมรสุมคนละแบบ ต้องบอกให้ชัดก่อนคนเลือกจุดออกเรือ
        'coast' => $coast,
        'coast_label' => fis_places_coast_label($coast),
        'source' => $source,
    ];
}
