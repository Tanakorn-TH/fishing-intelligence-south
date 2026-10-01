<?php
declare(strict_types=1);

/**
 * ทดสอบ /api/tides.php ผ่าน HTTP จริง
 * รันด้วย:  php -S 127.0.0.1:8098 -t .
 *           API_BASE=http://127.0.0.1:8098 php tests/tides-test.php
 *
 * ชุดนี้ต้องต่ออินเทอร์เน็ตออกไป Open-Meteo ได้ แต่ไม่ต้องใช้ฐานข้อมูล
 *
 * แนวคิดของชุดทดสอบนี้: เราไม่มีตารางน้ำทางการมาตรึงค่าเทียบแบบที่ solunar ทำได้
 * (ตารางของกรมอุทกศาสตร์ใช้ datum คนละฐาน จึงเอาตัวเลขมาเทียบตรง ๆ ไม่ได้)
 * จึงตรวจสองอย่างแทน:
 *   1. โครงสร้างและกติกาที่สัญญากำหนด ซึ่งตรวจได้เด็ดขาด
 *   2. ความสมเหตุสมผลเชิงฟิสิกส์ของน้ำ ซึ่งถ้าแบบจำลองเพี้ยนจะจับได้
 *      เช่น ระดับน้ำ 15 วันต้องเต้นตามคาบของดวงจันทร์ และพิสัยต้องผันแปรตามรอบดาราศาสตร์
 *      (เหตุที่ไม่ตรวจ "น้ำเกิดกว้างกว่าน้ำตาย" ตรง ๆ อยู่ในหัวข้อนั้นด้านล่าง)
 */

$base = getenv('API_BASE');
if (!is_string($base) || $base === '') {
    $base = 'http://127.0.0.1:8098';
}

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ผ่าน    {$label}\n";
    } else {
        $failed++;
        echo "  ไม่ผ่าน {$label}" . ($detail === '' ? '' : " — {$detail}") . "\n";
    }
}

function request(string $url, string $method = 'GET'): array
{
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 25];
    if ($method === 'POST') {
        $options['header'] = "Content-Type: application/x-www-form-urlencoded\r\n";
        $options['content'] = '';
    }
    $ctx = stream_context_create(['http' => $options]);

    $started = microtime(true);
    $body = @file_get_contents($url, false, $ctx);
    $elapsed = microtime(true) - $started;

    $status = 0;
    $contentType = '';
    $headers = isset($http_response_header) ? $http_response_header : [];
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
            $status = (int) $m[1];
        }
        if (stripos($header, 'Content-Type:') === 0) {
            $contentType = trim(substr($header, 13));
        }
    }

    return [
        'status' => $status,
        'body' => $body === false ? '' : $body,
        'json' => json_decode($body === false ? '' : $body, true),
        'content_type' => $contentType,
        'seconds' => $elapsed,
    ];
}

function get(string $url): array
{
    return request($url, 'GET');
}

/** วันที่แบบ YYYY-MM-DD เลื่อนจากวันนี้ตามเวลาไทย */
function dayOffset(int $days): string
{
    $tz = new DateTimeZone('Asia/Bangkok');
    return (new DateTimeImmutable('today', $tz))
        ->modify(($days >= 0 ? '+' : '') . $days . ' days')
        ->format('Y-m-d');
}

const PATTANI = 'lat=6.87&lon=101.25';
const ISO_TH = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+07:00$/';

echo "ทดสอบกับ {$base}\n\n=== โครงสร้างคำตอบ (ปัตตานี วันนี้) ===\n";

$r = get($base . '/api/tides.php?' . PATTANI);
check('ตอบ 200', $r['status'] === 200, "ได้ {$r['status']} body=" . substr($r['body'], 0, 200));
check('เป็น JSON ที่ parse ได้', is_array($r['json']), substr($r['body'], 0, 160));
check('Content-Type เป็น application/json; charset=utf-8',
      stripos($r['content_type'], 'application/json') === 0 && stripos($r['content_type'], 'utf-8') !== false,
      $r['content_type']);
check('ตอบกลับภายใน 15 วินาที', $r['seconds'] < 15.0, sprintf('ใช้เวลา %.1f วินาที', $r['seconds']));

$d = isset($r['json']['data']) && is_array($r['json']['data']) ? $r['json']['data'] : [];
$meta = isset($r['json']['meta']) && is_array($r['json']['meta']) ? $r['json']['meta'] : [];

check('มีคีย์ครบตามสัญญา',
      isset($d['date'], $d['datum'], $d['extremes'], $d['series'], $d['notice'])
          && array_key_exists('current', $d),
      implode(',', array_keys($d)));
check('date เป็นวันนี้ตามเวลาไทย', ($d['date'] ?? '') === dayOffset(0),
      'ได้ ' . ($d['date'] ?? 'ไม่มี') . ' คาด ' . dayOffset(0));

echo "\n--- datum และคำเตือน: ห้ามหายไปเด็ดขาด ---\n";

check('datum = mean_sea_level', ($d['datum'] ?? '') === 'mean_sea_level', var_export($d['datum'] ?? null, true));
check('meta.datum = mean_sea_level', ($meta['datum'] ?? '') === 'mean_sea_level', var_export($meta['datum'] ?? null, true));
$notice = (string) ($d['notice'] ?? '');
check('notice บอกว่าอ้างอิง MSL', mb_strpos($notice, 'MSL') !== false, $notice);
check('notice เตือนว่าเทียบตารางกรมอุทกศาสตร์ไม่ได้',
      mb_strpos($notice, 'กรมอุทกศาสตร์') !== false, $notice);
check('notice ห้ามใช้เพื่อการเดินเรือ', mb_strpos($notice, 'เดินเรือ') !== false, $notice);

echo "\n--- series ---\n";

$series = isset($d['series']) && is_array($d['series']) ? $d['series'] : [];
check('series คืน 24 จุด', count($series) === 24, 'ได้ ' . count($series));

$seriesShapeOk = true;
$seriesIsoOk = true;
$badIso = '';
foreach ($series as $point) {
    if (!is_array($point) || !array_key_exists('time', $point) || !array_key_exists('height_m', $point)) {
        $seriesShapeOk = false;
        break;
    }
    if (preg_match(ISO_TH, (string) $point['time']) !== 1) {
        $seriesIsoOk = false;
        $badIso = (string) $point['time'];
        break;
    }
}
check('ทุกจุดใน series มีคีย์ time และ height_m', $seriesShapeOk);
check('ทุกเวลาใน series เป็น ISO 8601 พร้อม +07:00', $seriesIsoOk, $badIso);

if ($series !== []) {
    check('series เริ่มที่ 00:00 ของวันที่ขอ',
          strpos((string) $series[0]['time'], $d['date'] . 'T00:00:00') === 0,
          (string) $series[0]['time']);
    check('series จบที่ 23:00 ของวันที่ขอ',
          strpos((string) $series[count($series) - 1]['time'], $d['date'] . 'T23:00:00') === 0,
          (string) $series[count($series) - 1]['time']);

    $stepOk = true;
    for ($i = 1; $i < count($series); $i++) {
        if (strtotime((string) $series[$i]['time']) - strtotime((string) $series[$i - 1]['time']) !== 3600) {
            $stepOk = false;
            break;
        }
    }
    check('จุดใน series ห่างกันชั่วโมงละหนึ่งชั่วโมงเรียงต่อกัน', $stepOk);

    $heightsNumeric = true;
    foreach ($series as $point) {
        if (!is_int($point['height_m']) && !is_float($point['height_m'])) {
            $heightsNumeric = false;
            break;
        }
    }
    check('height_m เป็นตัวเลขทุกจุด (ไม่มีค่าที่แต่งขึ้นหรือ null ปน)', $heightsNumeric);
}

echo "\n--- extremes ---\n";

$extremes = isset($d['extremes']) && is_array($d['extremes']) ? $d['extremes'] : [];
check('มีจุดน้ำขึ้น/น้ำลงอย่างน้อย 2 จุดในหนึ่งวัน', count($extremes) >= 2, 'ได้ ' . count($extremes));
// อ่าวไทยแถบนี้เป็นน้ำผสม วันหนึ่งมีจุดยอดได้ราว 2-4 จุด มากกว่านี้แปลว่าจับสัญญาณรบกวนมาด้วย
check('จำนวนจุดยอดไม่เกิน 4 ต่อวัน', count($extremes) <= 4, 'ได้ ' . count($extremes));

$typesOk = true;
$isoOk = true;
$inDayOk = true;
foreach ($extremes as $e) {
    if (!in_array($e['type'] ?? null, ['high', 'low'], true)) {
        $typesOk = false;
    }
    if (preg_match(ISO_TH, (string) ($e['time'] ?? '')) !== 1) {
        $isoOk = false;
    }
    if (strpos((string) ($e['time'] ?? ''), (string) $d['date']) !== 0) {
        $inDayOk = false;
    }
}
check('type เป็น high หรือ low เท่านั้น', $typesOk);
check('เวลาของจุดยอดเป็น ISO 8601 พร้อม +07:00', $isoOk);
check('จุดยอดทุกจุดอยู่ในวันที่ขอ ไม่ล้นไปวันอื่น', $inDayOk);

$sorted = true;
$alternating = true;
for ($i = 1; $i < count($extremes); $i++) {
    if (strtotime((string) $extremes[$i]['time']) < strtotime((string) $extremes[$i - 1]['time'])) {
        $sorted = false;
    }
    if (($extremes[$i]['type'] ?? '') === ($extremes[$i - 1]['type'] ?? '')) {
        $alternating = false;
    }
}
check('จุดยอดเรียงตามเวลา', $sorted);
check('ชนิดสลับ high/low เสมอ (ไม่มี high ติด high)', $alternating,
      implode(',', array_map(static fn($e) => (string) ($e['type'] ?? '?'), $extremes)));

// เวลาที่คืนต้องปัดเป็น 5 นาที ตามที่สัญญาบอกว่าไม่อ้างความแม่นระดับนาที
$roundedOk = true;
foreach ($extremes as $e) {
    $minute = (int) substr((string) $e['time'], 14, 2);
    if ($minute % 5 !== 0) {
        $roundedOk = false;
    }
}
check('เวลาจุดยอดปัดเป็น 5 นาที', $roundedOk);

// จุดสูงสุดต้องสูงกว่าจุดต่ำสุดจริง ๆ ไม่ใช่ป้ายกำกับสลับกัน
$highs = array_values(array_filter($extremes, static fn($e) => ($e['type'] ?? '') === 'high'));
$lows = array_values(array_filter($extremes, static fn($e) => ($e['type'] ?? '') === 'low'));
if ($highs !== [] && $lows !== []) {
    $minHigh = min(array_map(static fn($e) => (float) $e['height_m'], $highs));
    $maxLow = max(array_map(static fn($e) => (float) $e['height_m'], $lows));
    check('น้ำขึ้นเต็มที่ทุกจุดสูงกว่าน้ำลงเต็มที่ทุกจุด', $minHigh > $maxLow,
          "high ต่ำสุด {$minHigh} vs low สูงสุด {$maxLow}");
}

// จุดยอดต้องสอดคล้องกับ series ไม่ใช่ตัวเลขที่มาจากคนละชุด
if ($series !== [] && $extremes !== []) {
    $seriesHeights = array_map(static fn($p) => (float) $p['height_m'], $series);
    $lo = min($seriesHeights) - 0.15;
    $hi = max($seriesHeights) + 0.15;
    $withinRange = true;
    foreach ($extremes as $e) {
        $h = (float) $e['height_m'];
        if ($h < $lo || $h > $hi) {
            $withinRange = false;
        }
    }
    check('ความสูงของจุดยอดอยู่ในพิสัยเดียวกับ series', $withinRange);
}

echo "\n--- current ---\n";

$current = $d['current'] ?? null;
check('วันนี้ต้องมี current (ไม่ใช่ null)', is_array($current), var_export($current, true));
if (is_array($current)) {
    check('current มีคีย์ time / height_m / trend',
          array_key_exists('time', $current) && array_key_exists('height_m', $current)
              && array_key_exists('trend', $current));
    check('current.time เป็น ISO 8601 พร้อม +07:00',
          preg_match(ISO_TH, (string) $current['time']) === 1, (string) $current['time']);
    check('current.time เป็นชั่วโมงเต็ม', substr((string) $current['time'], 14, 5) === '00:00',
          (string) $current['time']);
    check('trend เป็น rising / falling / null',
          in_array($current['trend'], ['rising', 'falling', null], true),
          var_export($current['trend'], true));
}

echo "\n=== วันอื่นที่ไม่ใช่วันนี้ ต้องไม่เดาว่า \"ตอนนี้\" คือเมื่อไหร่ ===\n";

$other = get($base . '/api/tides.php?' . PATTANI . '&date=' . dayOffset(3));
check('ตอบ 200', $other['status'] === 200, "ได้ {$other['status']}");
check('current เป็น null เมื่อไม่ใช่วันนี้',
      array_key_exists('current', $other['json']['data'] ?? []) && $other['json']['data']['current'] === null,
      var_export($other['json']['data']['current'] ?? 'ไม่มีคีย์', true));
check('แต่ยังมี extremes ให้ใช้วางแผนล่วงหน้าได้',
      is_array($other['json']['data']['extremes'] ?? null) && count($other['json']['data']['extremes']) >= 1);
check('date สะท้อนวันที่ขอ', ($other['json']['data']['date'] ?? '') === dayOffset(3));

echo "\n=== ความสมเหตุสมผลเชิงฟิสิกส์: ระดับน้ำต้องเต้นตามดวงจันทร์ ===\n";

/**
 * ความสูงรายชั่วโมงครบ 24 จุดของวันหนึ่ง จาก series — null ถ้าดึงไม่ได้หรือไม่ครบ
 *
 * @return list<float>|null
 */
function heightsFor(string $base, string $date): ?array
{
    $x = get($base . '/api/tides.php?' . PATTANI . '&date=' . $date);
    $series = $x['json']['data']['series'] ?? null;
    if (!is_array($series) || count($series) !== 24) {
        return null;
    }
    return array_map(static fn($p) => (float) $p['height_m'], $series);
}

/**
 * ดึงพิสัยน้ำ (สูงสุด-ต่ำสุด) ของวันหนึ่ง จาก series
 * พิสัยเป็นค่าที่ไม่ขึ้นกับ datum จึงเป็นตัวเดียวที่เอามาตรวจเชิงฟิสิกส์ได้อย่างตรงไปตรงมา
 */
function rangeFor(string $base, string $date): ?float
{
    $heights = heightsFor($base, $date);
    return $heights === null ? null : max($heights) - min($heights);
}

/**
 * ความเร็วเชิงมุมของแรงไทด์หลัก (องศาต่อชั่วโมง) ค่ามาตรฐานที่ใช้กันทั่วไปในงานวิเคราะห์น้ำ
 * 15 วันพอแยก M2 กับ S2 (ต้อง 14.8 วัน) และ K1 กับ O1 (ต้อง 13.7 วัน) ออกจากกันได้
 */
const TIDAL_SPEEDS = [
    'M2' => 28.9841042, // ครึ่งวัน ตามดวงจันทร์ คาบ 12.42 ชม.
    'S2' => 30.0,       // ครึ่งวัน ตามดวงอาทิตย์ คาบ 12.00 ชม.
    'N2' => 28.4397295, // ครึ่งวัน จากดวงจันทร์ใกล้-ไกลโลก
    'K1' => 15.0410686, // รายวัน ดวงจันทร์และดวงอาทิตย์
    'O1' => 13.9430356, // รายวัน ดวงจันทร์
];

/**
 * ฟิตระดับน้ำรายชั่วโมงด้วยคลื่นไซน์ที่คาบใน TIDAL_SPEEDS + ค่าเฉลี่ย + แนวโน้มเส้นตรง
 * (แนวโน้มไว้กินน้ำยกตัวช้า ๆ ช่วงมรสุม ซึ่งไม่ใช่ไทด์) ด้วย least squares แบบ normal equations
 * ตัวแปรมีแค่ 12 ตัว Gaussian elimination ธรรมดาก็พอ
 *
 * @param array<int, float> $hourly ชั่วโมงที่ (นับจากจุดเริ่มเดียวกัน) => ความสูง
 * @return array{r2: float, amp: array<string, float>} amp = แอมพลิจูดของแต่ละคาบ (เมตร)
 */
function tidalFit(array $hourly): array
{
    $hours = array_keys($hourly);
    $mid = (min($hours) + max($hours)) / 2;

    $rows = [];
    foreach ($hourly as $hour => $height) {
        $row = [1.0, ($hour - $mid) / 24.0];
        foreach (TIDAL_SPEEDS as $speed) {
            $angle = deg2rad($speed * $hour);
            $row[] = cos($angle);
            $row[] = sin($angle);
        }
        $rows[$hour] = $row;
    }

    $n = 2 + 2 * count(TIDAL_SPEEDS);
    $a = array_fill(0, $n, array_fill(0, $n, 0.0));
    $b = array_fill(0, $n, 0.0);
    foreach ($rows as $hour => $row) {
        for ($r = 0; $r < $n; $r++) {
            $b[$r] += $row[$r] * $hourly[$hour];
            for ($c = 0; $c < $n; $c++) {
                $a[$r][$c] += $row[$r] * $row[$c];
            }
        }
    }

    $failed = ['r2' => 0.0, 'amp' => array_fill_keys(array_keys(TIDAL_SPEEDS), 0.0)];
    for ($p = 0; $p < $n; $p++) {
        $pivot = $p;
        for ($r = $p + 1; $r < $n; $r++) {
            if (abs($a[$r][$p]) > abs($a[$pivot][$p])) {
                $pivot = $r;
            }
        }
        if (abs($a[$pivot][$p]) < 1e-9) {
            return $failed; // ข้อมูลน้อยเกินจะแยกคาบได้
        }
        [$a[$p], $a[$pivot]] = [$a[$pivot], $a[$p]];
        [$b[$p], $b[$pivot]] = [$b[$pivot], $b[$p]];
        for ($r = $p + 1; $r < $n; $r++) {
            $f = $a[$r][$p] / $a[$p][$p];
            for ($c = $p; $c < $n; $c++) {
                $a[$r][$c] -= $f * $a[$p][$c];
            }
            $b[$r] -= $f * $b[$p];
        }
    }
    $coef = array_fill(0, $n, 0.0);
    for ($r = $n - 1; $r >= 0; $r--) {
        $sum = $b[$r];
        for ($c = $r + 1; $c < $n; $c++) {
            $sum -= $a[$r][$c] * $coef[$c];
        }
        $coef[$r] = $sum / $a[$r][$r];
    }

    $mean = array_sum($hourly) / count($hourly);
    $ssTotal = 0.0;
    $ssResidual = 0.0;
    foreach ($rows as $hour => $row) {
        $fitted = 0.0;
        foreach ($row as $c => $v) {
            $fitted += $v * $coef[$c];
        }
        $ssTotal += ($hourly[$hour] - $mean) ** 2;
        $ssResidual += ($hourly[$hour] - $fitted) ** 2;
    }
    if ($ssTotal <= 0.0) {
        return $failed; // เส้นตรงแบน ไม่มีอะไรให้อธิบาย
    }

    $amp = [];
    $k = 2;
    foreach (array_keys(TIDAL_SPEEDS) as $name) {
        $amp[$name] = hypot($coef[$k], $coef[$k + 1]);
        $k += 2;
    }
    return ['r2' => 1.0 - $ssResidual / $ssTotal, 'amp' => $amp];
}

// ทำไมไม่ตรวจ "พิสัยวันน้ำเกิดกว้างกว่าวันน้ำตาย" ตรง ๆ แบบที่ชุดนี้เคยทำ:
// ชุดเดิมแบ่งวันตามเปอร์เซ็นต์สว่างของดวงจันทร์ (≤12% หรือ ≥88% = น้ำเกิด, 38-62% = น้ำตาย)
// แล้วบังคับให้พิสัยเฉลี่ยต่างกันอย่างน้อย 10% — ผ่านเมื่อ 9 ส.ค. 2569 (1.57 เท่า)
// แต่ล้มเมื่อ 30 ก.ย. 2569 (1.06 เท่า) ทั้งที่โค้ดและข้อมูลไม่ได้ผิด
// เพราะพิสัยรายวันที่จุดนี้ถูกดันด้วยสามรอบที่แรงพอ ๆ กัน ไม่ใช่รอบเดียว (แอมพลิจูดจากการฟิตข้อมูลทั้งปี)
//   - ข้างขึ้นข้างแรม (S2 ~0.04 ม.) รอบ 14.8 วัน — น้ำเกิดน้ำตายตามตำรา
//   - ดวงจันทร์ใกล้-ไกลโลก (N2 ~0.05 ม. แรงกว่า S2 เสียอีก) รอบ 27.6 วัน
//   - declination ของดวงจันทร์ (K1+O1 ~0.14 ม.) รอบ 13.7 วัน และที่จุดนี้มาช้ากว่า declination ราว 3 วัน
// ในหน้าต่าง 15 วันสามรอบนี้หักล้างกันได้ ช่วง 23 ก.ย.-7 ต.ค. 2569 วันน้ำเกิดตรงกับช่วงที่ส่วนรายวัน
// เกือบเป็นศูนย์ ส่วนวันน้ำตายตรงกับช่วงที่ส่วนรายวันแรงสุด พิสัยเลยออกมาแทบเท่ากัน
// ไล่เกณฑ์เดิมกับข้อมูล Open-Meteo จริงทุกหน้าต่าง 15 วัน ตั้งแต่ 2 ต.ค. 2568 ถึง 8 ต.ค. 2569
// ล้ม 93 จาก 358 หน้าต่าง (26%) และ 38 หน้าต่างในนั้นวันน้ำตายกว้างกว่าวันน้ำเกิดเสียอีก
// แบ่งวันตาม declination แทนยิ่งแย่ (ล้ม 290 หน้าต่าง) เพราะส่วนรายวันมาช้ากว่า declination
// และ form factor (K1+O1)/(M2+S2) ของจุดนี้ ≈ 0.66 คือน้ำผสมค่อนไปทางน้ำคู่ ไม่ใช่น้ำเดี่ยว
//
// จึงตรวจสามข้อข้างล่างแทน ซึ่งจริงทุกหน้าต่างตลอดปีนั้น และข้อมูลที่พังแบบต่าง ๆ ไม่ผ่าน
// ("จริง" = ค่าที่แย่ที่สุดตลอดปี · ที่เหลือ = ข้อมูลปลอมที่จำลองขึ้นมาลองเกณฑ์)
//   1. R² ≥ 0.40   จริง 0.53 (มรสุม พ.ย. 2568) · สุ่มล้วน 0.03 · เอาวันจริงมาสลับลำดับ 0.24
//   2. M2 ใหญ่กว่าทุกคาบอื่น   จริง M2 ≥ 1.19 เท่าของตัวรองลงมา (K1)
//      · วงจรกลางวัน-กลางคืนตามดวงอาทิตย์ (เช่นได้อุณหภูมิน้ำมาแทน) K1 ใหญ่สุด M2 แทบเป็นศูนย์
//      · ทุกวันได้ข้อมูลวันเดียวกันซ้ำ (แคชไม่แยกวันที่) เป็นคาบ 24 ชม. พอดี ไม่มี M2 เลย
//      ปีที่วัดอยู่ใกล้ major lunar standstill ซึ่ง K1 แรงสุดและ M2 อ่อนสุดในรอบ 18.6 ปี
//      ช่องว่าง 19% นี้จึงเป็นกรณีแย่ที่สุดแล้ว ปีอื่นจะห่างกว่านี้
//   3. พิสัยกว้างสุด ≥ 1.15 × แคบสุด   จริง 1.24 · M2 ล้วนแอมพลิจูดคงที่ 1.00 · ข้อมูลวันเดียวกันซ้ำ 1.00
// พยากรณ์ล่วงหน้าได้แค่ 7 วัน จึงสแกนย้อนหลังควบไปด้วย — ข้อมูลอดีตย้อนได้ถึง 365 วัน
$hourly = [];
$dailyRanges = [];
for ($i = -7; $i <= 7; $i++) {
    $heights = heightsFor($base, dayOffset($i));
    if ($heights === null) {
        continue;
    }
    foreach ($heights as $h => $value) {
        $hourly[($i + 7) * 24 + $h] = $value;
    }
    $dailyRanges[] = max($heights) - min($heights);
}
$scanned = count($dailyRanges);

check('สแกนได้อย่างน้อย 12 วันเพื่อใช้ตรวจ', $scanned >= 12, "สแกนได้ {$scanned} วัน");

if ($scanned >= 12) {
    $fit = tidalFit($hourly);
    check('ระดับน้ำ 15 วันเป็นน้ำขึ้นน้ำลงจริง (คาบดาราศาสตร์อธิบายได้อย่างน้อย 40%)',
          $fit['r2'] >= 0.40,
          sprintf('R² %.2f', $fit['r2']));
    $others = $fit['amp'];
    unset($others['M2']);
    check('น้ำครึ่งวันตามดวงจันทร์ (M2 คาบ 12.42 ชม.) เป็นจังหวะหลัก ใหญ่กว่าทุกคาบอื่นที่ฟิต',
          $fit['amp']['M2'] > max($others),
          implode(' · ', array_map(static fn(string $name, float $amp): string => sprintf('%s %.3f', $name, $amp),
                                   array_keys($fit['amp']), $fit['amp'])) . ' ม.');
    $narrowest = min($dailyRanges);
    $widest = max($dailyRanges);
    check('พิสัยรายวันผันแปรตามรอบดาราศาสตร์ (วันกว้างสุดกว้างกว่าวันแคบสุดอย่างน้อย 15%)',
          $narrowest > 0 && $widest >= 1.15 * $narrowest,
          sprintf('แคบสุด %.2f ม. · กว้างสุด %.2f ม.', $narrowest, $widest));
}

echo "\n=== พิสัยน้ำอยู่ในระดับที่เป็นไปได้จริงของอ่าวไทย ===\n";

$todayRange = rangeFor($base, dayOffset(0));
check('พิสัยน้ำมากกว่า 0 (น้ำต้องขยับ ไม่ใช่เส้นตรง)', $todayRange !== null && $todayRange > 0.05,
      var_export($todayRange, true));
// อ่าวไทยพิสัยน้ำเล็ก ไม่ถึงระดับ 5-10 เมตรแบบอ่าวฟันดี ถ้าเกินนี้แปลว่าหน่วยผิดหรือแหล่งข้อมูลเปลี่ยน
check('พิสัยน้ำไม่เกิน 5 เมตร (สมเหตุสมผลกับอ่าวไทย)', $todayRange !== null && $todayRange < 5.0,
      var_export($todayRange, true));

echo "\n=== แคช ===\n";

$first = get($base . '/api/tides.php?' . PATTANI . '&date=' . dayOffset(1));
$second = get($base . '/api/tides.php?' . PATTANI . '&date=' . dayOffset(1));
check('เรียกซ้ำแล้วยังตอบ 200', $second['status'] === 200, "ได้ {$second['status']}");
check('เรียกซ้ำต้องมาจากแคช (meta.cached = true)',
      ($second['json']['meta']['cached'] ?? null) === true,
      var_export($second['json']['meta']['cached'] ?? null, true));
check('คำตอบจากแคชคงค่า fetched_at เดิมไว้ ไม่ใช่เวลาปัจจุบัน',
      ($first['json']['meta']['fetched_at'] ?? 'a') === ($second['json']['meta']['fetched_at'] ?? 'b'));
check('คำตอบจากแคชตอบเร็วกว่า 2 วินาที', $second['seconds'] < 2.0,
      sprintf('ใช้เวลา %.2f วินาที', $second['seconds']));

echo "\n=== meta ===\n";

check('meta.source บอกว่ามาจาก Open-Meteo',
      isset($meta['source']) && stripos((string) $meta['source'], 'open-meteo') !== false,
      var_export($meta['source'] ?? null, true));
check('มี meta.source_url', isset($meta['source_url']) && strpos((string) $meta['source_url'], 'http') === 0);
check('มี meta.license', isset($meta['license']) && $meta['license'] !== '');
check('meta.model บอกแบบจำลองที่ใช้',
      isset($meta['model']) && $meta['model'] !== '', var_export($meta['model'] ?? null, true));
check('meta.accuracy บอกความคลาดเคลื่อนตรง ๆ',
      isset($meta['accuracy']) && mb_strpos((string) $meta['accuracy'], 'นาที') !== false,
      var_export($meta['accuracy'] ?? null, true));
check('meta.fetched_at เป็น ISO 8601 พร้อม +07:00',
      isset($meta['fetched_at']) && preg_match(ISO_TH, (string) $meta['fetched_at']) === 1,
      var_export($meta['fetched_at'] ?? null, true));

echo "\n=== ขอบเขตวันที่ที่รับได้ ===\n";

$far = get($base . '/api/tides.php?' . PATTANI . '&date=' . dayOffset(60));
check('ล่วงหน้าเกินขอบเขต -> 400 (ไม่ใช่ 502 ที่ชี้นิ้วผิดที่)', $far['status'] === 400, "ได้ {$far['status']}");
check('บอกรหัส date_out_of_range', ($far['json']['error']['code'] ?? '') === 'date_out_of_range',
      substr($far['body'], 0, 140));
check('ข้อความบอกว่าขอได้กี่วัน',
      mb_strpos((string) ($far['json']['error']['message'] ?? ''), 'วัน') !== false,
      (string) ($far['json']['error']['message'] ?? ''));

$old = get($base . '/api/tides.php?' . PATTANI . '&date=' . dayOffset(-400));
check('ย้อนหลังเกินขอบเขต -> 400', $old['status'] === 400, "ได้ {$old['status']}");

// เพดานตั้งไว้ 7 วันเพราะแบบจำลองมีค่าจริงราว 8 วัน ไม่ใช่ 15 วันตามที่ปลายทางยอมรับช่วงวันที่
$edge = get($base . '/api/tides.php?' . PATTANI . '&date=' . dayOffset(7));
check('ล่วงหน้า 7 วันพอดี ยังต้องผ่าน (ได้ข้อมูลจริง ไม่ใช่ค่าว่าง)',
      $edge['status'] === 200, "ได้ {$edge['status']}");
check('ข้อมูลล่วงหน้า 7 วันมีค่าจริงครบ 24 จุด',
      count($edge['json']['data']['series'] ?? []) === 24,
      'ได้ ' . count($edge['json']['data']['series'] ?? []));

$beyond = get($base . '/api/tides.php?' . PATTANI . '&date=' . dayOffset(8));
check('ล่วงหน้า 8 วันถูกปฏิเสธที่ด่านหน้า ไม่ปล่อยให้ไปเจอค่าว่างปลายทาง',
      $beyond['status'] === 400, "ได้ {$beyond['status']}");

$past = get($base . '/api/tides.php?' . PATTANI . '&date=' . dayOffset(-7));
check('ย้อนหลัง 7 วัน ยังดูได้ (ไว้ทบทวนทริปที่ผ่านมา)', $past['status'] === 200, "ได้ {$past['status']}");

echo "\n=== จุดที่แบบจำลองไม่ครอบคลุม ต้องบอกตรง ๆ ไม่ใช่โทษว่าปลายทางล้ม ===\n";

// เชียงใหม่ กลางแผ่นดิน — ปลายทางตอบ 200 พร้อมค่า null ล้วน
$inland = get($base . '/api/tides.php?lat=18.79&lon=98.98');
check('กลางแผ่นดิน -> 400 ไม่ใช่ 502', $inland['status'] === 400, "ได้ {$inland['status']}");
check('กลางแผ่นดิน -> รหัส no_tide_data',
      ($inland['json']['error']['code'] ?? '') === 'no_tide_data', substr($inland['body'], 0, 140));
check('กลางแผ่นดิน -> ข้อความอธิบายว่าครอบคลุมเฉพาะทะเล',
      mb_strpos((string) ($inland['json']['error']['message'] ?? ''), 'ทะเล') !== false,
      (string) ($inland['json']['error']['message'] ?? ''));
check('กลางแผ่นดิน -> ไม่ได้แต่งค่าระดับน้ำขึ้นมาให้',
      !isset($inland['json']['data']), substr($inland['body'], 0, 140));

// ขั้วโลกเหนือ — นอกพื้นที่แบบจำลองเช่นกัน
$pole = get($base . '/api/tides.php?lat=90&lon=0');
check('ขั้วโลก -> 400 พร้อม no_tide_data ไม่ทำให้ระบบพัง',
      $pole['status'] === 400 && ($pole['json']['error']['code'] ?? '') === 'no_tide_data',
      "ได้ {$pole['status']} " . substr($pole['body'], 0, 120));

echo "\n=== ตรวจการรับค่าที่ไม่ถูกต้อง ===\n";

foreach ([
    'ไม่ส่ง lat' => '/api/tides.php?lon=101.25',
    'ไม่ส่ง lon' => '/api/tides.php?lat=6.87',
    'ไม่ส่งอะไรเลย' => '/api/tides.php',
    'lat เกินช่วง (91)' => '/api/tides.php?lat=91&lon=101.25',
    'lat ต่ำกว่าช่วง (-91)' => '/api/tides.php?lat=-91&lon=101.25',
    'lon เกินช่วง (181)' => '/api/tides.php?lat=6.87&lon=181',
    'lon ต่ำกว่าช่วง (-181)' => '/api/tides.php?lat=6.87&lon=-181',
    'lat ไม่ใช่ตัวเลข' => '/api/tides.php?lat=abc&lon=101.25',
    'lon เป็นสคริปต์' => '/api/tides.php?lat=6.87&lon=%3Cscript%3E',
    'lat ว่างเปล่า' => '/api/tides.php?lat=&lon=101.25',
    'date ผิดรูปแบบ' => '/api/tides.php?' . PATTANI . '&date=08-08-2026',
    'date เป็นข้อความ' => '/api/tides.php?' . PATTANI . '&date=today',
    'date ไม่มีจริง' => '/api/tides.php?' . PATTANI . '&date=2026-02-30',
    'date เดือน 13' => '/api/tides.php?' . PATTANI . '&date=2026-13-01',
    'SQL injection ใน lat' => '/api/tides.php?lat=' . rawurlencode("6.87' OR '1'='1") . '&lon=101.25',
] as $label => $path) {
    $bad = get($base . $path);
    check("{$label} -> 400", $bad['status'] === 400, "ได้ {$bad['status']}");
    $message = isset($bad['json']['error']['message']) ? (string) $bad['json']['error']['message'] : '';
    check("{$label} -> มี error.code และข้อความภาษาไทย",
          isset($bad['json']['error']['code']) && preg_match('/\p{Thai}/u', $message) === 1,
          substr($bad['body'], 0, 120));
    check("{$label} -> ไม่มี path หรือข้อความ exception หลุด",
          strpos($message, '/') === false && strpos($message, '\\') === false
              && stripos($message, 'exception') === false && stripos($message, '.php') === false,
          $message);
}

echo "\n=== ค่าขอบเขตที่ถูกต้องพอดีต้องไม่ถูกปฏิเสธเพราะรูปแบบ ===\n";

// พิกัดขอบเขตเหล่านี้ถูกต้องตามรูปแบบ จึงต้องผ่านด่านตรวจค่า
// ผลลัพธ์จะเป็น 200 (มีข้อมูล) หรือ 400 no_tide_data (นอกพื้นที่แบบจำลอง) ก็ได้
// แต่ต้องไม่ใช่ invalid_lat / invalid_lon ซึ่งแปลว่าเราปฏิเสธค่าที่ถูกต้อง
foreach ([
    'lat = 90 พอดี' => '/api/tides.php?lat=90&lon=0',
    'lat = -90 พอดี' => '/api/tides.php?lat=-90&lon=0',
    'lon = 180 พอดี' => '/api/tides.php?lat=0&lon=180',
    'lon = -180 พอดี' => '/api/tides.php?lat=0&lon=-180',
] as $label => $path) {
    $x = get($base . $path);
    $code = $x['json']['error']['code'] ?? '';
    check("{$label} -> ไม่ถูกปฏิเสธว่าพิกัดผิดรูปแบบ",
          $x['status'] === 200 || $code === 'no_tide_data',
          "ได้ {$x['status']} code={$code}");
}

echo "\n=== เมธอดที่ไม่รองรับ ===\n";

$r = request($base . '/api/tides.php?' . PATTANI, 'POST');
check('POST ถูกปฏิเสธด้วย 405', $r['status'] === 405, "ได้ {$r['status']}");
check('405 คืน error.code = method_not_allowed',
      ($r['json']['error']['code'] ?? '') === 'method_not_allowed', substr($r['body'], 0, 120));

echo "\nผ่าน {$passed} ข้อ ไม่ผ่าน {$failed} ข้อ\n";
exit($failed === 0 ? 0 : 1);
