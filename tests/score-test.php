<?php
declare(strict_types=1);

/**
 * ทดสอบ /api/score.php ผ่าน HTTP จริง
 * รันด้วย:  php -S 127.0.0.1:8098 -t .
 *           API_BASE=http://127.0.0.1:8098 php tests/score-test.php
 *
 * คะแนนเป็นตัวเลขที่ "เราตั้งน้ำหนักเอง" จึงทดสอบว่ามันถูกต้องตามนิยามของตัวเองไม่ได้
 * ด้วยการเทียบกับความจริงภายนอก สิ่งที่ทดสอบได้และต้องทดสอบคือ:
 *
 *   1. เลขทุกตัวตรวจย้อนได้ — contribution รวมกันต้องเท่ากับ score จริง ๆ
 *   2. น้ำหนักตรงกับ docs/fishing-score.md — ถ้าใครแก้โค้ดโดยไม่แก้เอกสาร ต้องจับได้
 *   3. คะแนนตอบสนองต่อสภาพในทิศทางที่เอกสารบอกไว้
 *   4. ความปลอดภัยไม่ถูกกลบด้วยคะแนนสูง
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
    $body = @file_get_contents($url, false, $ctx);

    $status = 0;
    $contentType = '';
    foreach ((isset($http_response_header) ? $http_response_header : []) as $header) {
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
    ];
}

function get(string $url): array
{
    return request($url, 'GET');
}

function dayOffset(int $days): string
{
    return (new DateTimeImmutable('today', new DateTimeZone('Asia/Bangkok')))
        ->modify(($days >= 0 ? '+' : '') . $days . ' days')
        ->format('Y-m-d');
}

const PATTANI = 'lat=6.87&lon=101.25';
const ISO_TH = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+07:00$/';

/** งานทั้งหมดที่สัญญาไว้ใน docs/fishing-score.md */
const EXPECTED_STYLES = ['squid', 'bottom', 'jigging', 'popping', 'trolling', 'shore', 'lightgame', 'float'];

/** ปัจจัยร่วมทั้งหมดที่เอกสารระบุ */
const EXPECTED_FACTORS = [
    'water_movement', 'tidal_range', 'moon_darkness', 'solunar',
    'light_phase', 'wind_calm', 'wave_calm', 'dry',
];

/**
 * น้ำหนักตามตารางใน docs/fishing-score.md
 * ถ้าใครแก้น้ำหนักในโค้ดโดยไม่แก้เอกสาร (หรือกลับกัน) ข้อทดสอบนี้จะตก
 * ซึ่งเป็นสิ่งที่ต้องการ เพราะเอกสารคือ "ที่มา" ของตัวเลขตามกติกาในสัญญาหลัก
 */
const DOC_WEIGHTS = [
    'squid' => ['moon_darkness' => 0.30, 'wind_calm' => 0.25, 'water_movement' => 0.15,
                'wave_calm' => 0.15, 'light_phase' => 0.10, 'dry' => 0.05],
    'bottom' => ['water_movement' => 0.30, 'wave_calm' => 0.25, 'wind_calm' => 0.20,
                 'tidal_range' => 0.10, 'solunar' => 0.10, 'dry' => 0.05],
    'jigging' => ['water_movement' => 0.30, 'light_phase' => 0.20, 'wave_calm' => 0.20,
                  'wind_calm' => 0.15, 'tidal_range' => 0.10, 'solunar' => 0.05],
    'popping' => ['water_movement' => 0.30, 'tidal_range' => 0.20, 'light_phase' => 0.20,
                  'wind_calm' => 0.15, 'wave_calm' => 0.10, 'solunar' => 0.05],
    'trolling' => ['wave_calm' => 0.30, 'wind_calm' => 0.25, 'water_movement' => 0.15,
                   'light_phase' => 0.15, 'dry' => 0.10, 'solunar' => 0.05],
    'shore' => ['water_movement' => 0.30, 'light_phase' => 0.25, 'tidal_range' => 0.15,
                'dry' => 0.10, 'wind_calm' => 0.10, 'wave_calm' => 0.10],
    'lightgame' => ['wind_calm' => 0.30, 'water_movement' => 0.20, 'wave_calm' => 0.20,
                    'light_phase' => 0.15, 'solunar' => 0.10, 'dry' => 0.05],
    'float' => ['wind_calm' => 0.30, 'wave_calm' => 0.25, 'water_movement' => 0.15,
                'light_phase' => 0.15, 'solunar' => 0.10, 'dry' => 0.05],
];

echo "ทดสอบกับ {$base}\n\n=== โครงสร้างคำตอบ ===\n";

$r = get($base . '/api/score.php?' . PATTANI);
check('ตอบ 200', $r['status'] === 200, "ได้ {$r['status']} body=" . substr($r['body'], 0, 200));
check('เป็น JSON ที่ parse ได้', is_array($r['json']), substr($r['body'], 0, 160));
check('Content-Type เป็น application/json; charset=utf-8',
      stripos($r['content_type'], 'application/json') === 0 && stripos($r['content_type'], 'utf-8') !== false,
      $r['content_type']);

$d = isset($r['json']['data']) && is_array($r['json']['data']) ? $r['json']['data'] : [];
$meta = isset($r['json']['meta']) && is_array($r['json']['meta']) ? $r['json']['meta'] : [];

check('มีคีย์ครบตามสัญญา',
      isset($d['date'], $d['overall'], $d['styles'], $d['safety'], $d['factors'], $d['notice'])
          && array_key_exists('best_style', $d),
      implode(',', array_keys($d)));
check('date เป็นวันนี้ตามเวลาไทย', ($d['date'] ?? '') === dayOffset(0), 'ได้ ' . ($d['date'] ?? 'ไม่มี'));
check('evaluated_at เป็น ISO 8601 พร้อม +07:00',
      preg_match(ISO_TH, (string) ($d['evaluated_at'] ?? '')) === 1, (string) ($d['evaluated_at'] ?? ''));
check('วันนี้ประเมินจากเวลาปัจจุบัน (scope = now)', ($d['evaluated_scope'] ?? '') === 'now',
      var_export($d['evaluated_scope'] ?? null, true));

echo "\n--- คำเตือนว่าคะแนนนี้คืออะไร: ห้ามหายไป ---\n";

$notice = (string) ($d['notice'] ?? '');
check('notice บอกว่าไม่ใช่คำพยากรณ์', mb_strpos($notice, 'ไม่ใช่คำพยากรณ์') !== false, $notice);
check('notice บอกว่าน้ำหนักทีมเลือกเอง', mb_strpos($notice, 'เลือกเอง') !== false, $notice);
check('notice ชี้ไปที่เอกสารที่มา', mb_strpos($notice, 'fishing-score.md') !== false, $notice);
check('meta.formula_version มีค่า', isset($meta['formula_version']) && $meta['formula_version'] !== '',
      var_export($meta['formula_version'] ?? null, true));
check('meta.formula_doc ชี้ไปที่เอกสาร', ($meta['formula_doc'] ?? '') === 'docs/fishing-score.md',
      var_export($meta['formula_doc'] ?? null, true));
check('meta.method บอกตรง ๆ ว่าไม่ได้ปรับจากสถิติการจับปลา',
      isset($meta['method']) && mb_strpos((string) $meta['method'], 'ไม่ได้ปรับจากสถิติ') !== false,
      var_export($meta['method'] ?? null, true));

echo "\n--- ปัจจัยร่วม ---\n";

$factors = isset($d['factors']) && is_array($d['factors']) ? $d['factors'] : [];
foreach (EXPECTED_FACTORS as $name) {
    check("มีปัจจัย {$name}", isset($factors[$name]));
}

$rangeOk = true;
$noteOk = true;
$badFactor = '';
foreach ($factors as $name => $factor) {
    $value = $factor['value'] ?? null;
    if (!is_numeric($value) || $value < 0.0 || $value > 1.0) {
        $rangeOk = false;
        $badFactor = $name . '=' . var_export($value, true);
    }
    if (!isset($factor['note']) || $factor['note'] === '') {
        $noteOk = false;
        $badFactor = $name;
    }
}
check('ค่าปัจจัยทุกตัวอยู่ในช่วง 0.0-1.0', $rangeOk, $badFactor);
check('ทุกปัจจัยมี note อธิบายที่มาเป็นภาษาไทย', $noteOk, $badFactor);

echo "\n--- งานตกปลา ---\n";

$styles = isset($d['styles']) && is_array($d['styles']) ? $d['styles'] : [];
check('คืนครบทั้ง 8 งาน', count($styles) === count(EXPECTED_STYLES), 'ได้ ' . count($styles));

$keys = array_map(static fn($s) => (string) ($s['key'] ?? ''), $styles);
sort($keys);
$expected = EXPECTED_STYLES;
sort($expected);
check('รายชื่องานตรงกับเอกสาร', $keys === $expected, implode(',', $keys));

$hasThai = true;
$scoreRangeOk = true;
$hasLabel = true;
foreach ($styles as $style) {
    if (!isset($style['name_th']) || preg_match('/\p{Thai}/u', (string) $style['name_th']) !== 1) {
        $hasThai = false;
    }
    $score = $style['score'] ?? null;
    if (!is_int($score) || $score < 0 || $score > 100) {
        $scoreRangeOk = false;
    }
    if (!isset($style['label']) || $style['label'] === '') {
        $hasLabel = false;
    }
}
check('ทุกงานมีชื่อภาษาไทย', $hasThai);
check('คะแนนทุกงานเป็นจำนวนเต็ม 0-100', $scoreRangeOk);
check('ทุกงานมีป้ายกำกับ', $hasLabel);

$sortedDesc = true;
for ($i = 1; $i < count($styles); $i++) {
    if ((int) $styles[$i]['score'] > (int) $styles[$i - 1]['score']) {
        $sortedDesc = false;
    }
}
check('งานเรียงจากคะแนนมากไปน้อย', $sortedDesc,
      implode(',', array_map(static fn($s) => (string) $s['score'], $styles)));
check('best_style ตรงกับงานที่คะแนนสูงสุด',
      ($d['best_style'] ?? '') === ($styles[0]['key'] ?? ''),
      var_export($d['best_style'] ?? null, true) . ' vs ' . var_export($styles[0]['key'] ?? null, true));

echo "\n=== เลขทุกตัวต้องตรวจย้อนได้ ===\n";

// นี่คือข้อทดสอบที่สำคัญที่สุดของชุดนี้
// ถ้า contribution รวมแล้วไม่เท่า score แปลว่า breakdown ที่โชว์ให้ผู้ใช้ดูไม่ใช่ที่มาของคะแนนจริง
$sumMatches = true;
$weightsSumOne = true;
$weightsMatchDoc = true;
$badStyle = '';
foreach ($styles as $style) {
    $key = (string) $style['key'];
    $breakdown = is_array($style['breakdown'] ?? null) ? $style['breakdown'] : [];

    $sum = 0.0;
    $weightSum = 0.0;
    $weights = [];
    foreach ($breakdown as $row) {
        $sum += (float) ($row['contribution'] ?? 0);
        $weightSum += (float) ($row['weight'] ?? 0);
        $weights[(string) $row['factor']] = round((float) $row['weight'], 4);
    }

    if (abs($sum - (float) $style['score']) > 0.75) {
        $sumMatches = false;
        $badStyle = sprintf('%s: รวม %.2f แต่ score %d', $key, $sum, $style['score']);
    }
    if (abs($weightSum - 1.0) > 0.0001) {
        $weightsSumOne = false;
        $badStyle = sprintf('%s: น้ำหนักรวม %.4f', $key, $weightSum);
    }
    $doc = DOC_WEIGHTS[$key] ?? [];
    ksort($doc);
    ksort($weights);
    if ($weights !== $doc) {
        $weightsMatchDoc = false;
        $badStyle = $key . ': ' . json_encode($weights, JSON_UNESCAPED_UNICODE)
                  . ' vs เอกสาร ' . json_encode($doc, JSON_UNESCAPED_UNICODE);
    }
}
check('contribution ของทุกปัจจัยรวมกันแล้วเท่ากับคะแนนของงานนั้น', $sumMatches, $badStyle);
check('น้ำหนักของแต่ละงานรวมกันได้ 1.00 พอดี', $weightsSumOne, $badStyle);
check('น้ำหนักในโค้ดตรงกับตารางใน docs/fishing-score.md ทุกตัว', $weightsMatchDoc, $badStyle);

// contribution ต้องเท่ากับ weight x value x 100 จริง ๆ ไม่ใช่เลขที่ใส่มาลอย ๆ
$contribConsistent = true;
$badContrib = '';
foreach ($styles as $style) {
    foreach (($style['breakdown'] ?? []) as $row) {
        $expectedContrib = (float) $row['weight'] * (float) $row['value'] * 100.0;
        if (abs($expectedContrib - (float) $row['contribution']) > 0.2) {
            $contribConsistent = false;
            $badContrib = sprintf('%s/%s: %.2f vs %.2f', $style['key'], $row['factor'],
                                  $expectedContrib, (float) $row['contribution']);
        }
    }
}
check('contribution = น้ำหนัก × ค่าปัจจัย × 100 ทุกแถว', $contribConsistent, $badContrib);

// ค่าปัจจัยใน breakdown ต้องเป็นค่าเดียวกับใน data.factors ไม่ใช่คนละชุด
$factorsConsistent = true;
$badMatch = '';
foreach ($styles as $style) {
    foreach (($style['breakdown'] ?? []) as $row) {
        $shared = $factors[(string) $row['factor']]['value'] ?? null;
        if ($shared === null || abs((float) $shared - (float) $row['value']) > 0.002) {
            $factorsConsistent = false;
            $badMatch = sprintf('%s/%s', $style['key'], $row['factor']);
        }
    }
}
check('ค่าปัจจัยใน breakdown ตรงกับ data.factors', $factorsConsistent, $badMatch);

$breakdownSorted = true;
foreach ($styles as $style) {
    $rows = $style['breakdown'] ?? [];
    for ($i = 1; $i < count($rows); $i++) {
        if ((float) $rows[$i]['contribution'] > (float) $rows[$i - 1]['contribution'] + 0.001) {
            $breakdownSorted = false;
        }
    }
}
check('breakdown เรียงตามแต้มที่ได้จริง ผู้ใช้เห็นตัวชี้ขาดก่อน', $breakdownSorted);

$hasFactorNotes = true;
foreach ($styles as $style) {
    foreach (($style['breakdown'] ?? []) as $row) {
        if (!isset($row['label']) || preg_match('/\p{Thai}/u', (string) $row['label']) !== 1) {
            $hasFactorNotes = false;
        }
    }
}
check('ทุกแถวใน breakdown มีชื่อปัจจัยภาษาไทย', $hasFactorNotes);

echo "\n=== คะแนนรวม ===\n";

$overall = isset($d['overall']) && is_array($d['overall']) ? $d['overall'] : [];
check('overall.score เป็นจำนวนเต็ม 0-100',
      is_int($overall['score'] ?? null) && $overall['score'] >= 0 && $overall['score'] <= 100,
      var_export($overall['score'] ?? null, true));
check('overall มีป้ายกำกับภาษาไทย',
      isset($overall['label']) && preg_match('/\p{Thai}/u', (string) $overall['label']) === 1,
      var_export($overall['label'] ?? null, true));
check('overall.from_styles บอกว่าคิดจากงานไหนบ้าง',
      is_array($overall['from_styles'] ?? null) && count($overall['from_styles']) === 3,
      json_encode($overall['from_styles'] ?? null, JSON_UNESCAPED_UNICODE));

// เอกสารระบุว่า overall = ค่าเฉลี่ยของ 3 งานที่คะแนนสูงสุด
if (count($styles) >= 3) {
    $expectedOverall = (int) round(((int) $styles[0]['score'] + (int) $styles[1]['score'] + (int) $styles[2]['score']) / 3);
    check('overall = ค่าเฉลี่ยของ 3 งานที่คะแนนสูงสุด ตามที่เอกสารระบุ',
          (int) ($overall['score'] ?? -1) === $expectedOverall,
          'ได้ ' . var_export($overall['score'] ?? null, true) . ' คาด ' . $expectedOverall);

    $topKeys = [(string) $styles[0]['key'], (string) $styles[1]['key'], (string) $styles[2]['key']];
    check('from_styles ตรงกับ 3 งานแรกจริง ๆ',
          ($overall['from_styles'] ?? []) === $topKeys,
          json_encode($overall['from_styles'] ?? null) . ' vs ' . json_encode($topKeys));
}

// ป้ายกำกับต้องตรงกับช่วงคะแนนที่เอกสารกำหนด
function labelFor(int $score): string
{
    if ($score >= 80) return 'ดีมาก';
    if ($score >= 65) return 'ดี';
    if ($score >= 50) return 'พอใช้';
    return 'ไม่เด่น';
}
$labelsOk = true;
$badLabel = '';
foreach ($styles as $style) {
    if ((string) $style['label'] !== labelFor((int) $style['score'])) {
        $labelsOk = false;
        $badLabel = $style['key'] . ' ' . $style['score'] . ' -> ' . $style['label'];
    }
}
check('ป้ายกำกับของทุกงานตรงกับช่วงคะแนนในเอกสาร', $labelsOk, $badLabel);
check('ป้ายกำกับของคะแนนรวมก็ตรงเช่นกัน',
      (string) ($overall['label'] ?? '') === labelFor((int) ($overall['score'] ?? 0)));

echo "\n=== ความปลอดภัย: ต้องไม่ถูกกลบด้วยคะแนนสูง ===\n";

$safety = isset($d['safety']) && is_array($d['safety']) ? $d['safety'] : [];
check('safety แยกออกมาเป็นก้อนของตัวเอง ไม่ปนอยู่ในคะแนน',
      isset($safety['level'], $safety['label'], $safety['reasons']));
check('safety.level เป็นค่าที่กำหนดไว้',
      in_array($safety['level'] ?? null, ['good', 'caution', 'dangerous'], true),
      var_export($safety['level'] ?? null, true));
check('safety.label เป็นภาษาไทย',
      preg_match('/\p{Thai}/u', (string) ($safety['label'] ?? '')) === 1,
      var_export($safety['label'] ?? null, true));
check('safety.reasons มีเหตุผลอย่างน้อยหนึ่งข้อ',
      is_array($safety['reasons'] ?? null) && count($safety['reasons']) >= 1);
check('safety.basis บอกว่าเทียบกับเกณฑ์อะไร และไม่ใช่ประกาศทางการ',
      isset($safety['basis']) && mb_strpos((string) $safety['basis'], 'ไม่ใช่ประกาศเตือนทางการ') !== false,
      var_export($safety['basis'] ?? null, true));

// safety ต้องสอดคล้องกับลมและคลื่นที่รายงานจริง ไม่ใช่ค่าที่ตั้งไว้ตายตัว
$w = get($base . '/api/weather.php?' . PATTANI);
$wind = $w['json']['data']['current']['wind_speed_kmh'] ?? null;
$wave = $w['json']['data']['current']['wave_height_m'] ?? null;
if (is_numeric($wind)) {
    $expectedLevel = 'good';
    if ($wind >= 40.0) {
        $expectedLevel = 'dangerous';
    } elseif ($wind >= 20.0) {
        $expectedLevel = 'caution';
    }
    if (is_numeric($wave)) {
        if ($wave >= 2.0) {
            $expectedLevel = 'dangerous';
        } elseif ($wave >= 1.0 && $expectedLevel !== 'dangerous') {
            $expectedLevel = 'caution';
        }
    }
    check('ระดับความปลอดภัยตรงกับลม/คลื่นที่รายงานจริง',
          ($safety['level'] ?? '') === $expectedLevel,
          sprintf('ลม %.1f คลื่น %s -> ได้ %s คาด %s', (float) $wind,
                  is_numeric($wave) ? sprintf('%.2f', (float) $wave) : 'ไม่มี',
                  (string) ($safety['level'] ?? ''), $expectedLevel));
}

echo "\n=== คะแนนตอบสนองต่อสภาพจริง ไม่ใช่ค่าคงที่ ===\n";

// จุดที่สภาพต่างกันมากต้องได้คะแนนต่างกัน ถ้าเท่ากันเป๊ะแปลว่าไม่ได้คิดจากข้อมูลจริง
$other = get($base . '/api/score.php?lat=7.9&lon=98.35'); // ภูเก็ต ฝั่งอันดามัน
check('พิกัดอื่นยังตอบ 200', $other['status'] === 200, "ได้ {$other['status']}");
if ($other['status'] === 200) {
    $otherFactors = $other['json']['data']['factors'] ?? [];
    $differs = false;
    foreach (EXPECTED_FACTORS as $name) {
        $a = $factors[$name]['value'] ?? null;
        $b = $otherFactors[$name]['value'] ?? null;
        if ($a !== null && $b !== null && abs((float) $a - (float) $b) > 0.001) {
            $differs = true;
        }
    }
    check('คนละพิกัดได้ค่าปัจจัยต่างกัน (คิดจากข้อมูลจริง)', $differs);
}

// วันอื่นประเมินที่เที่ยงวัน ไม่ใช่ "ตอนนี้"
$future = get($base . '/api/score.php?' . PATTANI . '&date=' . dayOffset(2));
check('วันอื่นยังตอบ 200', $future['status'] === 200, "ได้ {$future['status']}");
check('วันอื่นประเมินที่เที่ยงวัน (scope = midday)',
      ($future['json']['data']['evaluated_scope'] ?? '') === 'midday',
      var_export($future['json']['data']['evaluated_scope'] ?? null, true));
check('วันอื่น evaluated_at เป็นเที่ยงวันจริง',
      strpos((string) ($future['json']['data']['evaluated_at'] ?? ''), 'T12:00:00') !== false,
      (string) ($future['json']['data']['evaluated_at'] ?? ''));

echo "\n=== ทิศทางของปัจจัยตรงกับที่เอกสารอธิบาย ===\n";

// ตกหมึกให้น้ำหนัก moon_darkness สูงสุด (0.30) — คืนเดือนมืดต้องดันคะแนนหมึกขึ้น
// ตรวจโดยหาวันที่จันทร์สว่างต่างกันมากในช่วงที่ขอข้อมูลได้ แล้วเทียบคะแนนหมึก
$darkest = null;
$brightest = null;
for ($i = 0; $i <= 7; $i++) {
    $date = dayOffset($i);
    $x = get($base . '/api/score.php?' . PATTANI . '&date=' . $date);
    if ($x['status'] !== 200) {
        continue;
    }
    $dark = $x['json']['data']['factors']['moon_darkness']['value'] ?? null;
    $squid = null;
    foreach ($x['json']['data']['styles'] ?? [] as $s) {
        if (($s['key'] ?? '') === 'squid') {
            $squid = ['dark' => (float) $dark, 'score' => (int) $s['score'], 'date' => $date];
        }
    }
    if ($squid === null || $dark === null) {
        continue;
    }
    if ($darkest === null || $squid['dark'] > $darkest['dark']) {
        $darkest = $squid;
    }
    if ($brightest === null || $squid['dark'] < $brightest['dark']) {
        $brightest = $squid;
    }
}

if ($darkest !== null && $brightest !== null && ($darkest['dark'] - $brightest['dark']) > 0.1) {
    check('คืนที่มืดกว่าให้คะแนนตกหมึกสูงกว่า (moon_darkness ถ่วง 0.30)',
          $darkest['score'] > $brightest['score'],
          sprintf('มืด %.2f -> %d (%s) · สว่าง %.2f -> %d (%s)',
                  $darkest['dark'], $darkest['score'], $darkest['date'],
                  $brightest['dark'], $brightest['score'], $brightest['date']));
} else {
    check('ช่วง 7 วันมีความสว่างของดวงจันทร์ต่างกันพอจะตรวจทิศทางได้',
          false,
          'ความสว่างในช่วงนี้ต่างกันน้อยเกินไป');
}

// ตกชายฝั่งถ่วงลม/คลื่นน้อยกว่างานที่ต้องใช้เรือ เพราะไม่ต้องขึ้นเรือ
$shoreW = 0.0;
$trollW = 0.0;
foreach ($styles as $style) {
    foreach (($style['breakdown'] ?? []) as $row) {
        if (in_array($row['factor'], ['wind_calm', 'wave_calm'], true)) {
            if ($style['key'] === 'shore') {
                $shoreW += (float) $row['weight'];
            }
            if ($style['key'] === 'trolling') {
                $trollW += (float) $row['weight'];
            }
        }
    }
}
check('งานชายฝั่งถ่วงลม+คลื่นน้อยกว่างานที่ต้องใช้เรือ',
      $shoreW < $trollW, sprintf('ชายฝั่ง %.2f vs ทรอลลิ่ง %.2f', $shoreW, $trollW));

echo "\n=== ตรวจการรับค่าที่ไม่ถูกต้อง ===\n";

foreach ([
    'ไม่ส่ง lat' => '/api/score.php?lon=101.25',
    'ไม่ส่ง lon' => '/api/score.php?lat=6.87',
    'ไม่ส่งอะไรเลย' => '/api/score.php',
    'lat เกินช่วง' => '/api/score.php?lat=91&lon=101.25',
    'lon เกินช่วง' => '/api/score.php?lat=6.87&lon=181',
    'lat ไม่ใช่ตัวเลข' => '/api/score.php?lat=abc&lon=101.25',
    'date ผิดรูปแบบ' => '/api/score.php?' . PATTANI . '&date=08-08-2026',
    'date ไม่มีจริง' => '/api/score.php?' . PATTANI . '&date=2026-02-30',
    'ล่วงหน้าเกินขอบเขต' => '/api/score.php?' . PATTANI . '&date=' . dayOffset(60),
] as $label => $path) {
    $bad = get($base . $path);
    check("{$label} -> 400", $bad['status'] === 400, "ได้ {$bad['status']}");
    $message = (string) ($bad['json']['error']['message'] ?? '');
    check("{$label} -> ข้อความภาษาไทย ไม่มี path หลุด",
          preg_match('/\p{Thai}/u', $message) === 1
              && strpos($message, '/') === false && stripos($message, '.php') === false,
          substr($bad['body'], 0, 120));
}

echo "\n=== จุดที่ไม่มีข้อมูลน้ำ ===\n";

$inland = get($base . '/api/score.php?lat=18.79&lon=98.98');
check('กลางแผ่นดิน -> 400 no_tide_data ไม่ใช่ 502',
      $inland['status'] === 400 && ($inland['json']['error']['code'] ?? '') === 'no_tide_data',
      "ได้ {$inland['status']} " . substr($inland['body'], 0, 120));
check('กลางแผ่นดิน -> ไม่แต่งคะแนนขึ้นมาให้', !isset($inland['json']['data']));

echo "\n=== เมธอดที่ไม่รองรับ ===\n";

$post = request($base . '/api/score.php?' . PATTANI, 'POST');
check('POST ถูกปฏิเสธด้วย 405', $post['status'] === 405, "ได้ {$post['status']}");
check('405 คืน error.code = method_not_allowed',
      ($post['json']['error']['code'] ?? '') === 'method_not_allowed', substr($post['body'], 0, 120));

echo "\nผ่าน {$passed} ข้อ ไม่ผ่าน {$failed} ข้อ\n";
exit($failed === 0 ? 0 : 1);
