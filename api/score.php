<?php
declare(strict_types=1);

/**
 * GET /api/score.php?lat=6.87&lon=101.25&date=2026-08-08
 *
 * Fishing Score — คะแนนความน่าออกทะเลของวันนั้น แยกตามประเภทงานตกปลา
 *
 * ⚠️ อ่าน docs/fishing-score.md ก่อนแก้ไฟล์นี้
 * น้ำหนักทุกตัวในไฟล์นี้ต้องตรงกับตารางในเอกสารนั้น ชุดทดสอบเทียบไว้แล้ว
 *
 * สิ่งที่คะแนนนี้เป็น: เครื่องมือช่วยตัดสินใจว่าวันนี้น่าออกไหมและเหมาะกับงานแบบไหน
 * สิ่งที่คะแนนนี้ไม่ใช่: คำพยากรณ์ว่าจะได้ปลา และไม่ได้มาจากสถิติการจับปลาจริง
 * น้ำหนักทั้งหมด "เราเลือกเอง" จากการอ่านเอกสารและประสบการณ์ ไม่ได้ fit จากข้อมูล
 * ด้วยเหตุนี้ทุกคำตอบจึงแนบ notice และ breakdown รายปัจจัย เพื่อให้ตรวจสอบย้อนได้ทุกตัวเลข
 */

require_once __DIR__ . '/lib/conditions.php';

const FIS_SCORE_TZ = 'Asia/Bangkok';

/** เปลี่ยนเลขนี้ทุกครั้งที่น้ำหนักหรือสูตรเปลี่ยน และต้องแก้ docs/fishing-score.md ด้วย */
const FIS_SCORE_FORMULA_VERSION = '1.0';

const FIS_SCORE_NOTICE = 'คะแนนนี้เป็นเครื่องมือช่วยวางแผน ไม่ใช่คำพยากรณ์ว่าจะได้ปลา '
    . 'น้ำหนักของแต่ละปัจจัยทีมเราเลือกเอง ยังไม่ได้ปรับจากสถิติการจับปลาจริง '
    . 'ดูที่มาทั้งหมดได้ใน docs/fishing-score.md';

/**
 * เกณฑ์ความปลอดภัย อ้างอิง small craft advisory และมาตรา Beaufort
 * แยกจากคะแนนโดยเจตนา — คะแนนสูงต้องไม่กลบคำเตือนว่าทะเลอันตราย
 */
const FIS_SCORE_WIND_CAUTION = 20.0;
const FIS_SCORE_WIND_DANGER = 40.0;
const FIS_SCORE_WAVE_CAUTION = 1.0;
const FIS_SCORE_WAVE_DANGER = 2.0;

/**
 * นิยามงานตกปลาและน้ำหนัก — ต้องตรงกับตารางใน docs/fishing-score.md
 * น้ำหนักของแต่ละงานรวมกันได้ 1.00 เสมอ (ชุดทดสอบบังคับไว้)
 *
 * @return array<string, array{name_th:string, tagline:string, weights:array<string,float>}>
 */
function fis_score_styles(): array
{
    return [
        'squid' => [
            'name_th' => 'ตกหมึก',
            'tagline' => 'อีจิ้ง งานกลางคืน',
            'weights' => [
                'moon_darkness' => 0.30,
                'wind_calm' => 0.25,
                'water_movement' => 0.15,
                'wave_calm' => 0.15,
                'light_phase' => 0.10,
                'dry' => 0.05,
            ],
        ],
        'bottom' => [
            'name_th' => 'งานหน้าดิน',
            'tagline' => 'เก๋า กะพง ปลากินหน้าดิน',
            'weights' => [
                'water_movement' => 0.30,
                'wave_calm' => 0.25,
                'wind_calm' => 0.20,
                'tidal_range' => 0.10,
                'solunar' => 0.10,
                'dry' => 0.05,
            ],
        ],
        'jigging' => [
            'name_th' => 'จิ๊กกิ้ง',
            'tagline' => 'โยกจิ๊กน้ำลึก',
            'weights' => [
                'water_movement' => 0.30,
                'light_phase' => 0.20,
                'wave_calm' => 0.20,
                'wind_calm' => 0.15,
                'tidal_range' => 0.10,
                'solunar' => 0.05,
            ],
        ],
        'popping' => [
            'name_th' => 'ป๊อปปิ้ง',
            'tagline' => 'GT ผิวน้ำ',
            'weights' => [
                'water_movement' => 0.30,
                'tidal_range' => 0.20,
                'light_phase' => 0.20,
                'wind_calm' => 0.15,
                'wave_calm' => 0.10,
                'solunar' => 0.05,
            ],
        ],
        'trolling' => [
            'name_th' => 'ทรอลลิ่ง',
            'tagline' => 'ลากเหยื่อหาปลาผิวน้ำ',
            'weights' => [
                'wave_calm' => 0.30,
                'wind_calm' => 0.25,
                'water_movement' => 0.15,
                'light_phase' => 0.15,
                'dry' => 0.10,
                'solunar' => 0.05,
            ],
        ],
        'shore' => [
            'name_th' => 'ตกชายฝั่ง',
            'tagline' => 'หน้าหาด เซิฟ ไม่ต้องใช้เรือ',
            'weights' => [
                'water_movement' => 0.30,
                'light_phase' => 0.25,
                'tidal_range' => 0.15,
                'dry' => 0.10,
                'wind_calm' => 0.10,
                'wave_calm' => 0.10,
            ],
        ],
        'lightgame' => [
            'name_th' => 'ไลท์เกม',
            'tagline' => 'ไลท์จิ๊ก ไลท์ร็อค อุปกรณ์เบา',
            'weights' => [
                'wind_calm' => 0.30,
                'water_movement' => 0.20,
                'wave_calm' => 0.20,
                'light_phase' => 0.15,
                'solunar' => 0.10,
                'dry' => 0.05,
            ],
        ],
        'float' => [
            'name_th' => 'ชิงหลิว',
            'tagline' => 'ทุ่นลอย สปิ๋ว',
            'weights' => [
                'wind_calm' => 0.30,
                'wave_calm' => 0.25,
                'water_movement' => 0.15,
                'light_phase' => 0.15,
                'solunar' => 0.10,
                'dry' => 0.05,
            ],
        ],
    ];
}

/** ชื่อไทยของปัจจัย ใช้แสดงใน breakdown ให้ผู้ใช้อ่านรู้เรื่องโดยไม่ต้องเปิดเอกสาร */
function fis_score_factor_labels(): array
{
    return [
        'water_movement' => 'แรงการไหลของน้ำ',
        'tidal_range' => 'น้ำเกิด-น้ำตาย',
        'moon_darkness' => 'ความมืดของคืน',
        'solunar' => 'ช่วง Solunar',
        'light_phase' => 'ช่วงแสง',
        'wind_calm' => 'ลมสงบ',
        'wave_calm' => 'คลื่นสงบ',
        'dry' => 'โอกาสไม่มีฝน',
    ];
}

fis_handle(function (): void {
    fis_require_get();

    $lat = fis_conditions_coord('lat', -90.0, 90.0);
    $lon = fis_conditions_coord('lon', -180.0, 180.0);

    $tz = new DateTimeZone(FIS_SCORE_TZ);
    // ใช้ตัวตรวจวันที่ชุดเดียวกับ tides เพราะคะแนนต้องพึ่งข้อมูลน้ำ
    // ขอบเขตจึงถูกจำกัดด้วยระยะพยากรณ์ของแบบจำลองน้ำอยู่ดี
    $date = fis_tides_date($tz);

    try {
        $weather = fis_weather_payload($lat, $lon);
        $tides = fis_tides_payload($lat, $lon, $date);
    } catch (FisTidesNoDataException $e) {
        fis_tides_fail_no_data($date, $tz);
        return;
    } catch (FisRemoteException $e) {
        if (fis_tides_looks_like_no_data($e->getMessage())) {
            fis_tides_fail_no_data($date, $tz);
            return;
        }
        error_log('[fishing-api/score] ' . $e->getMessage());
        fis_fail(
            'ขณะนี้ดึงข้อมูลสภาพอากาศหรือระดับน้ำไม่ได้ จึงยังคิดคะแนนให้ไม่ได้ กรุณาลองใหม่อีกครั้งในอีกสักครู่',
            502,
            'upstream_unavailable'
        );
        return;
    }

    $dayStart = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $date . ' 00:00:00',
        new DateTimeZone(FIS_SOLUNAR_TZ)
    );
    $solunar = fis_solunar_data($lat, $lon, $date, $dayStart);

    // เวลาที่ใช้ประเมิน: ถ้าเป็นวันนี้ใช้ตอนนี้ ถ้าเป็นวันอื่นใช้เที่ยงวัน
    // เพราะ "ตอนนี้" ของวันอื่นไม่มีความหมาย และเที่ยงวันเป็นตัวแทนกลาง ๆ ของทั้งวัน
    $now = new DateTimeImmutable('now', $tz);
    $isToday = $now->format('Y-m-d') === $date;
    $at = $isToday ? $now : new DateTimeImmutable($date . ' 12:00:00', $tz);

    $factors = fis_score_factors($weather, $tides, $solunar, $at, $tz);
    $styles = fis_score_all_styles($factors);
    $overall = fis_score_overall($styles);
    $safety = fis_score_safety($weather['data']['current'] ?? []);

    fis_json([
        'data' => [
            'date' => $date,
            'evaluated_at' => $at->format('Y-m-d\TH:i:sP'),
            'evaluated_scope' => $isToday ? 'now' : 'midday',
            'overall' => $overall,
            'best_style' => $styles === [] ? null : $styles[0]['key'],
            'styles' => $styles,
            'safety' => $safety,
            'factors' => $factors,
            'notice' => FIS_SCORE_NOTICE,
        ],
        'meta' => [
            'source' => 'คำนวณในระบบจากข้อมูล Open-Meteo และการคำนวณดาราศาสตร์ของเรา',
            'formula_version' => FIS_SCORE_FORMULA_VERSION,
            'formula_doc' => 'docs/fishing-score.md',
            'method' => 'ถ่วงน้ำหนักปัจจัยที่ทีมเลือกเอง ไม่ได้ปรับจากสถิติการจับปลาจริง',
            'overall_method' => 'ค่าเฉลี่ยของ 3 งานที่ได้คะแนนสูงสุดในวันนั้น',
            'fetched_at' => (new DateTimeImmutable('now', $tz))->format('c'),
            'cached' => ($weather['cached'] ?? false) && ($tides['cached'] ?? false),
        ],
    ]);
});

/**
 * คำนวณค่าปัจจัยร่วมทั้งหมด ทุกตัวอยู่ในช่วง 0.0-1.0
 *
 * @return array<string, array{value:float, note:string}>
 */
function fis_score_factors(array $weather, array $tides, array $solunar, DateTimeImmutable $at, DateTimeZone $tz): array
{
    $current = $weather['data']['current'] ?? [];
    $series = $tides['data']['series'] ?? [];

    return [
        'water_movement' => fis_score_water_movement($series, $at),
        'tidal_range' => fis_score_tidal_range($series),
        'moon_darkness' => fis_score_moon_darkness($solunar),
        'solunar' => fis_score_solunar($solunar, $at),
        'light_phase' => fis_score_light_phase($weather, $at, $tz),
        'wind_calm' => fis_score_wind_calm($current),
        'wave_calm' => fis_score_wave_calm($current),
        'dry' => fis_score_dry($current),
    ];
}

/**
 * แรงการไหลของน้ำ = |Δh/Δt| ของชั่วโมงที่สนใจ เทียบกับอัตราสูงสุดของวันนั้น
 * ปัจจัยที่มีหลักฐานหนุนดีที่สุด — ปลานักล่าตั้งซุ่มรอเหยื่อที่น้ำพัดมา ช่วงน้ำนิ่งมักแย่ที่สุด
 */
function fis_score_water_movement(array $series, DateTimeImmutable $at): array
{
    $count = count($series);
    if ($count < 2) {
        return ['value' => 0.5, 'note' => 'ไม่มีข้อมูลระดับน้ำพอจะคิดอัตราการไหล ใช้ค่ากลางแทน'];
    }

    // อัตราการเปลี่ยนระหว่างชั่วโมง i กับ i+1 หน่วยเมตรต่อชั่วโมง
    $rates = [];
    for ($i = 0; $i < $count - 1; $i++) {
        $a = $series[$i]['height_m'] ?? null;
        $b = $series[$i + 1]['height_m'] ?? null;
        if (!is_numeric($a) || !is_numeric($b)) {
            continue;
        }
        $rates[$i] = abs((float) $b - (float) $a);
    }
    if ($rates === []) {
        return ['value' => 0.5, 'note' => 'ไม่มีข้อมูลระดับน้ำพอจะคิดอัตราการไหล ใช้ค่ากลางแทน'];
    }

    $peak = max($rates);
    if ($peak <= 0.0) {
        return ['value' => 0.0, 'note' => 'ระดับน้ำแทบไม่เปลี่ยนตลอดวัน'];
    }

    // หาแถวของชั่วโมงที่กำลังประเมิน
    $hourKey = $at->format('Y-m-d\TH');
    $index = null;
    foreach ($series as $i => $point) {
        if (strpos((string) $point['time'], $hourKey) === 0) {
            $index = $i;
            break;
        }
    }
    if ($index === null || !isset($rates[$index])) {
        // ชั่วโมงนั้นอยู่นอกชุด (เช่นชั่วโมงสุดท้ายของวัน) ใช้ค่าเฉลี่ยทั้งวันแทน
        $avg = array_sum($rates) / count($rates);
        return [
            'value' => fis_score_clamp($avg / $peak),
            'note' => sprintf('ใช้อัตราเฉลี่ยทั้งวัน %.2f ม./ชม.', $avg),
        ];
    }

    $rate = $rates[$index];
    $value = fis_score_clamp($rate / $peak);

    $word = $value >= 0.66 ? 'น้ำเดินแรง' : ($value >= 0.33 ? 'น้ำเดินปานกลาง' : 'น้ำค่อนข้างนิ่ง');
    return [
        'value' => $value,
        'note' => sprintf('%s %.2f ม./ชม. (แรงสุดของวันนี้ %.2f)', $word, $rate, $peak),
    ];
}

/** น้ำเกิดหรือน้ำตาย — เทียบพิสัยของวันกับช่วง 0.2-1.2 ม. ที่พบจริงในอ่าวไทย */
function fis_score_tidal_range(array $series): array
{
    $heights = [];
    foreach ($series as $point) {
        if (is_numeric($point['height_m'] ?? null)) {
            $heights[] = (float) $point['height_m'];
        }
    }
    if ($heights === []) {
        return ['value' => 0.5, 'note' => 'ไม่มีข้อมูลระดับน้ำ ใช้ค่ากลางแทน'];
    }

    $range = max($heights) - min($heights);
    $value = fis_score_clamp(($range - 0.2) / 1.0);

    $word = $value >= 0.66 ? 'น้ำเกิด' : ($value >= 0.33 ? 'ปานกลาง' : 'น้ำตาย');
    return ['value' => $value, 'note' => sprintf('%s พิสัย %.2f ม.', $word, $range)];
}

/** ความมืดของคืน — ใช้กับงานกลางคืนที่พึ่งไฟล่อ คืนเดือนมืดหมึกเข้าไฟดีกว่า */
function fis_score_moon_darkness(array $solunar): array
{
    $illum = $solunar['moon']['illumination_pct'] ?? null;
    if (!is_numeric($illum)) {
        return ['value' => 0.5, 'note' => 'ไม่มีค่าความสว่างของดวงจันทร์ ใช้ค่ากลางแทน'];
    }

    $value = fis_score_clamp(1.0 - ((float) $illum / 100.0));
    $phase = (string) ($solunar['moon']['phase_name_th'] ?? '');
    return [
        'value' => $value,
        'note' => trim($phase . sprintf(' แสงจันทร์ %d%%', (int) $illum)),
    ];
}

/**
 * อยู่ในช่วง major/minor ไหม
 * ไม่ให้ 0 เมื่ออยู่นอกช่วง เพราะไม่มีหลักฐานว่านอกช่วงแล้วปลาไม่กิน
 * ให้เป็นแค่แต้มต่อเล็กน้อยตามทฤษฎีที่ยังพิสูจน์ไม่ได้
 */
function fis_score_solunar(array $solunar, DateTimeImmutable $at): array
{
    $stamp = $at->getTimestamp();

    foreach (($solunar['major_periods'] ?? []) as $period) {
        if (fis_score_within($period, $stamp)) {
            return ['value' => 1.0, 'note' => 'อยู่ในช่วง Major'];
        }
    }
    foreach (($solunar['minor_periods'] ?? []) as $period) {
        if (fis_score_within($period, $stamp)) {
            return ['value' => 0.6, 'note' => 'อยู่ในช่วง Minor'];
        }
    }
    return ['value' => 0.25, 'note' => 'ไม่อยู่ในช่วง Major หรือ Minor'];
}

function fis_score_within(array $period, int $stamp): bool
{
    $start = strtotime((string) ($period['start'] ?? ''));
    $end = strtotime((string) ($period['end'] ?? ''));
    return $start !== false && $end !== false && $stamp >= $start && $stamp <= $end;
}

/**
 * ช่วงแสง — เช้ามืด/พลบค่ำดีที่สุด ปลานักล่าออกหากินช่วงแสงน้อยมากที่สุด
 * ใช้ sunrise/sunset จริงของวันนั้นที่ขอมาพร้อมข้อมูลอากาศ
 */
function fis_score_light_phase(array $weather, DateTimeImmutable $at, DateTimeZone $tz): array
{
    $sunrises = $weather['sun']['sunrise'] ?? [];
    $sunsets = $weather['sun']['sunset'] ?? [];

    $date = $at->format('Y-m-d');
    $sunrise = null;
    $sunset = null;
    foreach ($sunrises as $value) {
        if (is_string($value) && strpos($value, $date) === 0) {
            $sunrise = strtotime($value);
        }
    }
    foreach ($sunsets as $value) {
        if (is_string($value) && strpos($value, $date) === 0) {
            $sunset = strtotime($value);
        }
    }

    if ($sunrise === false || $sunset === false || $sunrise === null || $sunset === null) {
        // ไม่มี sunrise/sunset ของวันนั้น (เช่นดูล่วงหน้าเกินที่ forecast ให้มา)
        // ประมาณด้วยเวลาโดยทั่วไปของภาคใต้ แล้วบอกตรง ๆ ว่าเป็นค่าประมาณ
        $hour = (int) $at->format('G');
        $value = ($hour >= 5 && $hour <= 7) || ($hour >= 17 && $hour <= 19) ? 1.0
            : (($hour >= 20 || $hour <= 4) ? 0.7 : 0.5);
        return ['value' => $value, 'note' => 'ไม่มีเวลาพระอาทิตย์ขึ้น-ตกของวันนี้ ใช้ช่วงเวลาโดยประมาณ'];
    }

    $stamp = $at->getTimestamp();
    $window = 3600; // ±1 ชั่วโมงรอบพระอาทิตย์ขึ้นและตก

    if (abs($stamp - $sunrise) <= $window) {
        return ['value' => 1.0, 'note' => 'ช่วงเช้ามืด'];
    }
    if (abs($stamp - $sunset) <= $window) {
        return ['value' => 1.0, 'note' => 'ช่วงพลบค่ำ'];
    }
    if ($stamp < $sunrise || $stamp > $sunset) {
        return ['value' => 0.7, 'note' => 'กลางคืน'];
    }
    return ['value' => 0.5, 'note' => 'กลางวัน'];
}

/** ลมสงบแค่ไหน — 1.0 ที่ลม 0 ลดเป็นเส้นตรงจนเป็น 0.0 ที่ 40 กม./ชม. */
function fis_score_wind_calm(array $current): array
{
    $wind = $current['wind_speed_kmh'] ?? null;
    if (!is_numeric($wind)) {
        return ['value' => 0.5, 'note' => 'ไม่มีข้อมูลลม ใช้ค่ากลางแทน'];
    }

    $value = fis_score_clamp(1.0 - ((float) $wind / FIS_SCORE_WIND_DANGER));
    return ['value' => $value, 'note' => sprintf('ลม %.1f กม./ชม.', (float) $wind)];
}

/**
 * คลื่นสงบแค่ไหน — 1.0 ที่คลื่น 0 ม. ลดเป็นเส้นตรงจนเป็น 0.0 ที่ 2.5 ม.
 * จุดที่ไม่มีข้อมูลคลื่นใช้ค่ากลาง 0.5 และบอกไว้ใน note ตรง ๆ ไม่แกล้งทำเป็นว่าคลื่นสงบ
 */
function fis_score_wave_calm(array $current): array
{
    $wave = $current['wave_height_m'] ?? null;
    if (!is_numeric($wave)) {
        return ['value' => 0.5, 'note' => 'จุดนี้ไม่มีข้อมูลคลื่น ใช้ค่ากลางแทน'];
    }

    $value = fis_score_clamp(1.0 - ((float) $wave / 2.5));
    return ['value' => $value, 'note' => sprintf('คลื่น %.2f ม.', (float) $wave)];
}

function fis_score_dry(array $current): array
{
    $rain = $current['precipitation_probability_pct'] ?? null;
    if (!is_numeric($rain)) {
        return ['value' => 0.5, 'note' => 'ไม่มีข้อมูลโอกาสฝน ใช้ค่ากลางแทน'];
    }

    return [
        'value' => fis_score_clamp(1.0 - ((float) $rain / 100.0)),
        'note' => sprintf('โอกาสฝน %d%%', (int) $rain),
    ];
}

function fis_score_clamp(float $value): float
{
    return max(0.0, min(1.0, $value));
}

/**
 * คิดคะแนนทุกงาน แล้วเรียงจากมากไปน้อย
 *
 * @param array<string, array{value:float, note:string}> $factors
 * @return list<array<string, mixed>>
 */
function fis_score_all_styles(array $factors): array
{
    $labels = fis_score_factor_labels();
    $result = [];

    foreach (fis_score_styles() as $key => $style) {
        $breakdown = [];
        $total = 0.0;

        foreach ($style['weights'] as $factor => $weight) {
            $value = $factors[$factor]['value'] ?? 0.5;
            $contribution = $weight * $value * 100.0;
            $total += $contribution;

            $breakdown[] = [
                'factor' => $factor,
                'label' => $labels[$factor] ?? $factor,
                'weight' => $weight,
                'value' => round($value, 3),
                // ให้ frontend แสดงได้เลยว่าปัจจัยนี้ดันคะแนนขึ้นมากี่แต้ม
                'contribution' => round($contribution, 1),
                'note' => $factors[$factor]['note'] ?? '',
            ];
        }

        // เรียงปัจจัยตามแต้มที่ได้จริง ผู้ใช้จะได้เห็นทันทีว่าอะไรเป็นตัวชี้ขาดของวันนี้
        usort($breakdown, static fn(array $a, array $b): int => $b['contribution'] <=> $a['contribution']);

        $score = (int) round($total);
        $result[] = [
            'key' => $key,
            'name_th' => $style['name_th'],
            'tagline' => $style['tagline'],
            'score' => $score,
            'label' => fis_score_label($score),
            'breakdown' => $breakdown,
        ];
    }

    usort($result, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    return $result;
}

/**
 * คะแนนรวม = ค่าเฉลี่ยของ 3 งานที่ได้คะแนนสูงสุด
 * เหตุผลอยู่ใน docs/fishing-score.md — วันที่ดีคือวันที่มีงานให้เลือกทำหลายอย่าง
 *
 * @param list<array<string, mixed>> $styles เรียงจากมากไปน้อยมาแล้ว
 */
function fis_score_overall(array $styles): array
{
    if ($styles === []) {
        return ['score' => 0, 'label' => fis_score_label(0), 'from_styles' => []];
    }

    $top = array_slice($styles, 0, 3);
    $sum = 0;
    $keys = [];
    foreach ($top as $style) {
        $sum += (int) $style['score'];
        $keys[] = (string) $style['key'];
    }

    $score = (int) round($sum / count($top));
    return [
        'score' => $score,
        'label' => fis_score_label($score),
        'from_styles' => $keys,
    ];
}

function fis_score_label(int $score): string
{
    if ($score >= 80) return 'ดีมาก';
    if ($score >= 65) return 'ดี';
    if ($score >= 50) return 'พอใช้';
    return 'ไม่เด่น';
}

/**
 * ประเมินความปลอดภัย แยกจากคะแนนโดยเจตนา
 * คะแนน 70 กับ "ลมแรงเกินไปสำหรับเรือเล็ก" เป็นคนละคำถาม และคำถามหลังสำคัญกว่า
 */
function fis_score_safety(array $current): array
{
    $wind = is_numeric($current['wind_speed_kmh'] ?? null) ? (float) $current['wind_speed_kmh'] : null;
    $wave = is_numeric($current['wave_height_m'] ?? null) ? (float) $current['wave_height_m'] : null;

    $level = 'good';
    $reasons = [];

    if ($wind !== null) {
        if ($wind >= FIS_SCORE_WIND_DANGER) {
            $level = 'dangerous';
            $reasons[] = sprintf('ลมแรง %.0f กม./ชม. เกินเกณฑ์เรือเล็ก', $wind);
        } elseif ($wind >= FIS_SCORE_WIND_CAUTION) {
            $level = 'caution';
            $reasons[] = sprintf('ลม %.0f กม./ชม. ต้องระวัง', $wind);
        }
    } else {
        $reasons[] = 'ไม่มีข้อมูลลม ประเมินความปลอดภัยได้ไม่ครบ';
    }

    if ($wave !== null) {
        if ($wave >= FIS_SCORE_WAVE_DANGER) {
            $level = 'dangerous';
            $reasons[] = sprintf('คลื่นสูง %.1f ม. อันตรายสำหรับเรือเล็ก', $wave);
        } elseif ($wave >= FIS_SCORE_WAVE_CAUTION && $level !== 'dangerous') {
            $level = 'caution';
            $reasons[] = sprintf('คลื่น %.1f ม. ต้องระวัง', $wave);
        }
    } else {
        $reasons[] = 'จุดนี้ไม่มีข้อมูลคลื่น ประเมินความปลอดภัยได้ไม่ครบ';
    }

    $labels = ['good' => 'ออกได้', 'caution' => 'ต้องระวัง', 'dangerous' => 'ไม่ควรออก'];

    return [
        'level' => $level,
        'label' => $labels[$level],
        'reasons' => $reasons === [] ? ['ลมและคลื่นอยู่ในเกณฑ์ปกติ'] : $reasons,
        'basis' => 'เทียบเกณฑ์ small craft advisory และมาตรา Beaufort — '
                 . 'เป็นการประเมินคร่าว ๆ ไม่ใช่ประกาศเตือนทางการ ตรวจประกาศกรมอุตุนิยมวิทยาก่อนออกเสมอ',
    ];
}
