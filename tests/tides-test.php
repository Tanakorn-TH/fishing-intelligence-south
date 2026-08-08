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
 *      เช่น น้ำเกิดต้องมาใกล้เดือนดับ/เดือนเพ็ญ และช่วงน้ำต้องกว้างกว่าช่วงน้ำตายชัดเจน
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

echo "\n=== ความสมเหตุสมผลเชิงฟิสิกส์: น้ำเกิด-น้ำตายต้องตามดวงจันทร์ ===\n";

/**
 * ดึงพิสัยน้ำ (สูงสุด-ต่ำสุด) ของวันหนึ่ง จาก series
 * พิสัยเป็นค่าที่ไม่ขึ้นกับ datum จึงเป็นตัวเดียวที่เอามาตรวจเชิงฟิสิกส์ได้อย่างตรงไปตรงมา
 */
function rangeFor(string $base, string $date): ?float
{
    $x = get($base . '/api/tides.php?' . PATTANI . '&date=' . $date);
    $series = $x['json']['data']['series'] ?? null;
    if (!is_array($series) || $series === []) {
        return null;
    }
    $heights = array_map(static fn($p) => (float) $p['height_m'], $series);
    return max($heights) - min($heights);
}

/** เปอร์เซ็นต์สว่างของดวงจันทร์วันนั้น จาก endpoint ของเราเอง */
function illumFor(string $base, string $date): ?int
{
    $x = get($base . '/api/solunar.php?' . PATTANI . '&date=' . $date);
    $v = $x['json']['data']['moon']['illumination_pct'] ?? null;
    return is_int($v) ? $v : null;
}

// ต้องใช้ช่วงยาวราวสองสัปดาห์จึงจะเจอทั้งวันน้ำเกิดและวันน้ำตาย (รอบน้ำเกิด->น้ำตาย ~7.4 วัน)
// พยากรณ์ล่วงหน้าได้แค่ 7 วัน จึงสแกนย้อนหลังควบไปด้วย — ข้อมูลอดีตย้อนได้ถึง 365 วัน
$springRanges = [];
$neapRanges = [];
$scanned = 0;
for ($i = -7; $i <= 7; $i++) {
    $date = dayOffset($i);
    $illum = illumFor($base, $date);
    $range = rangeFor($base, $date);
    if ($illum === null || $range === null) {
        continue;
    }
    $scanned++;
    // ใกล้เดือนดับ (0%) หรือเดือนเพ็ญ (100%) = แรงดึงดูดเสริมกัน -> น้ำเกิด
    if ($illum <= 12 || $illum >= 88) {
        $springRanges[] = $range;
    }
    // ใกล้ครึ่งดวง = แรงดึงดูดหักล้างกัน -> น้ำตาย
    if ($illum >= 38 && $illum <= 62) {
        $neapRanges[] = $range;
    }
}

check('สแกนได้อย่างน้อย 12 วันเพื่อใช้ตรวจ', $scanned >= 12, "สแกนได้ {$scanned} วัน");
check('ช่วง 15 วันครอบคลุมทั้งวันน้ำเกิดและวันน้ำตาย',
      $springRanges !== [] && $neapRanges !== [],
      'น้ำเกิด ' . count($springRanges) . ' วัน · น้ำตาย ' . count($neapRanges) . ' วัน');

if ($springRanges !== [] && $neapRanges !== []) {
    $avgSpring = array_sum($springRanges) / count($springRanges);
    $avgNeap = array_sum($neapRanges) / count($neapRanges);
    check('พิสัยน้ำวันน้ำเกิดกว้างกว่าวันน้ำตาย (ฟิสิกส์ของน้ำเกิดน้ำตาย)',
          $avgSpring > $avgNeap,
          sprintf('น้ำเกิดเฉลี่ย %.2f ม. · น้ำตายเฉลี่ย %.2f ม.', $avgSpring, $avgNeap));
    // ถ้าต่างกันน้อยกว่า 10% แปลว่าแทบไม่ตอบสนองต่อดวงจันทร์ ซึ่งผิดธรรมชาติของน้ำจริง
    check('ความต่างมากพอที่จะไม่ใช่ความบังเอิญ (อย่างน้อย 10%)',
          $avgNeap > 0 && ($avgSpring / $avgNeap) >= 1.10,
          sprintf('อัตราส่วน %.2f เท่า', $avgNeap > 0 ? $avgSpring / $avgNeap : 0));
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
