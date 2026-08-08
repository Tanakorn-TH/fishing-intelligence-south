<?php
declare(strict_types=1);

/**
 * ทดสอบ /api/solunar.php ผ่าน HTTP จริง
 * รันด้วย:  php -S 127.0.0.1:8098 -t .
 *           API_BASE=http://127.0.0.1:8098 php tests/solunar-test.php
 *
 * endpoint นี้ไม่ใช้ฐานข้อมูลและไม่เรียกบริการภายนอก จึงไม่ต้องโหลด fixtures ก่อน
 *
 * ค่าที่ตรึงไว้ในไฟล์นี้มาจาก U.S. Naval Observatory, Astronomical Applications API
 * (https://aa.usno.navy.mil/api/rstt/oneday) ที่พิกัดปัตตานี lat 6.87 lon 101.25 tz=7
 * ถ้าแก้สูตรในอนาคตแล้วข้อทดสอบเหล่านี้ตก แปลว่าสูตรเพี้ยน ไม่ใช่ข้อทดสอบเพี้ยน
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

function get(string $url): array
{
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
    $body = file_get_contents($url, false, $ctx);
    $status = 0;
    $headers = isset($http_response_header) ? $http_response_header : [];
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
            $status = (int) $m[1];
        }
    }
    return ['status' => $status, 'body' => $body === false ? '' : $body,
            'json' => json_decode($body === false ? '' : $body, true)];
}

function post(string $url): array
{
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'ignore_errors' => true, 'timeout' => 20,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => '',
    ]]);
    $body = file_get_contents($url, false, $ctx);
    $status = 0;
    $headers = isset($http_response_header) ? $http_response_header : [];
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
            $status = (int) $m[1];
        }
    }
    return ['status' => $status, 'body' => $body === false ? '' : $body];
}

/** ดึงเฉพาะ HH:MM จากสตริง ISO 8601 เพื่อเทียบกับตาราง USNO ที่เป็นเวลาท้องถิ่น */
function hhmm(?string $iso): string
{
    return $iso === null ? '--' : substr($iso, 11, 5);
}

/** ความยาวช่วงเวลาเป็นวินาที */
function span(array $period): int
{
    return strtotime($period['end']) - strtotime($period['start']);
}

const PATTANI = 'lat=6.87&lon=101.25';

echo "ทดสอบกับ {$base}\n\n=== โครงสร้างคำตอบ ===\n";

$r = get($base . '/api/solunar.php?' . PATTANI . '&date=2026-08-08');
check('ตอบ 200', $r['status'] === 200, "ได้ {$r['status']} body=" . substr($r['body'], 0, 200));
check('เป็น JSON ที่ parse ได้', is_array($r['json']), substr($r['body'], 0, 160));

$d = isset($r['json']['data']) ? $r['json']['data'] : [];
check('มีคีย์ครบตามสัญญา',
      isset($d['date'], $d['moon'], $d['major_periods'], $d['minor_periods'])
          && array_key_exists('moonrise', $d) && array_key_exists('moonset', $d),
      implode(',', array_keys($d)));
check('date สะท้อนค่าที่ขอ', isset($d['date']) && $d['date'] === '2026-08-08');
check('moon มี phase_name_th / illumination_pct / age_days',
      isset($d['moon']['phase_name_th'], $d['moon']['illumination_pct'], $d['moon']['age_days']));
check('illumination_pct เป็นจำนวนเต็ม', isset($d['moon']['illumination_pct'])
      && is_int($d['moon']['illumination_pct']));

$meta = isset($r['json']['meta']) ? $r['json']['meta'] : [];
check('meta.source = คำนวณในระบบ', isset($meta['source']) && $meta['source'] === 'คำนวณในระบบ');
check('meta.cached = false', isset($meta['cached']) && $meta['cached'] === false);
check('meta.method บอกที่มาของสูตร',
      isset($meta['method']) && strpos($meta['method'], 'Meeus') !== false);
check('meta.fetched_at เป็น ISO 8601 +07:00',
      isset($meta['fetched_at']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+07:00$/', $meta['fetched_at']) === 1,
      isset($meta['fetched_at']) ? $meta['fetched_at'] : 'ไม่มี');

// ทุก timestamp ต้องมี offset +07:00 ติดไปด้วย ไม่ใช่ UTC หรือเวลาเปล่า
$stamps = [];
foreach (['moonrise', 'moonset'] as $k) {
    if (isset($d[$k])) {
        $stamps[] = $d[$k];
    }
}
foreach (array_merge($d['major_periods'] ?? [], $d['minor_periods'] ?? []) as $p) {
    $stamps[] = $p['start'];
    $stamps[] = $p['end'];
}
$badStamps = array_filter($stamps, static function (string $s): bool {
    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+07:00$/', $s) !== 1;
});
check('ทุกเวลาเป็น ISO 8601 พร้อม offset +07:00', $badStamps === [] && $stamps !== [],
      implode(' ', $badStamps));

echo "\n=== ตรึงค่ากับตาราง USNO (ปัตตานี) ===\n";

// [วันที่, จันทร์ขึ้น, จันทร์ตก, %สว่าง]  '--' หมายถึงวันนั้นไม่มีเหตุการณ์นั้นจริง ๆ
$usno = [
    ['2026-08-06', '--',    '12:11', 49],
    ['2026-08-08', '01:15', '14:14', 27],
    ['2026-08-20', '12:13', '--',    51],
    ['2026-08-28', '18:33', '06:01', 100],
    ['2026-01-03', '18:11', '06:07', 100],
    ['2026-11-15', '10:49', '22:49', 29],
];
foreach ($usno as $row) {
    [$date, $rise, $set, $illum] = $row;
    $x = get($base . '/api/solunar.php?' . PATTANI . '&date=' . $date);
    $dd = isset($x['json']['data']) ? $x['json']['data'] : [];
    check("{$date} จันทร์ขึ้น = {$rise}",
          array_key_exists('moonrise', $dd) && hhmm($dd['moonrise']) === $rise,
          'ได้ ' . hhmm($dd['moonrise'] ?? null));
    check("{$date} จันทร์ตก = {$set}",
          array_key_exists('moonset', $dd) && hhmm($dd['moonset']) === $set,
          'ได้ ' . hhmm($dd['moonset'] ?? null));
    check("{$date} สว่าง {$illum}%",
          isset($dd['moon']['illumination_pct']) && abs($dd['moon']['illumination_pct'] - $illum) <= 1,
          'ได้ ' . var_export($dd['moon']['illumination_pct'] ?? null, true));
}

echo "\n=== วันที่ไม่มีจันทร์ขึ้นหรือจันทร์ตก ต้องเป็น null ไม่ใช่ค่าปลอม ===\n";

$x = get($base . '/api/solunar.php?' . PATTANI . '&date=2026-08-06');
$dd = $x['json']['data'];
check('2026-08-06 moonrise เป็น null จริง ๆ', $dd['moonrise'] === null,
      var_export($dd['moonrise'], true));
check('2026-08-06 ยังมี moonset ปกติ', is_string($dd['moonset']));
check('2026-08-06 มี minor period แค่ช่วงเดียว (เฉพาะจันทร์ตก)',
      count($dd['minor_periods']) === 1, 'ได้ ' . count($dd['minor_periods']));

$x = get($base . '/api/solunar.php?' . PATTANI . '&date=2026-08-20');
$dd = $x['json']['data'];
check('2026-08-20 moonset เป็น null จริง ๆ', $dd['moonset'] === null,
      var_export($dd['moonset'], true));
check('2026-08-20 ยังมี moonrise ปกติ', is_string($dd['moonrise']));

echo "\n=== เดือนเพ็ญ / เดือนดับ ที่ทราบคำตอบแน่ชัด ===\n";

// 2026-01-03 เป็นวันจันทร์เต็มดวง (USNO รายงาน 100%)
$x = get($base . '/api/solunar.php?' . PATTANI . '&date=2026-01-03');
$moon = $x['json']['data']['moon'];
check('วันจันทร์เต็มดวง สว่าง >= 99%', $moon['illumination_pct'] >= 99, 'ได้ ' . $moon['illumination_pct']);
check('วันจันทร์เต็มดวง อายุอยู่ราว 13-16 วัน',
      $moon['age_days'] >= 13.0 && $moon['age_days'] <= 16.0, 'ได้ ' . $moon['age_days']);

// 2026-08-12 เป็นวันเดือนดับ (จันทร์ดับเวลา ~17:37 น. ตามเวลาไทย)
$x = get($base . '/api/solunar.php?' . PATTANI . '&date=2026-08-12');
$moon = $x['json']['data']['moon'];
check('วันเดือนดับ สว่าง <= 1%', $moon['illumination_pct'] <= 1, 'ได้ ' . $moon['illumination_pct']);
check('วันเดือนดับ อายุใกล้ครบรอบ (>= 28 วัน)', $moon['age_days'] >= 28.0, 'ได้ ' . $moon['age_days']);
check('วันเดือนดับ ชื่อเป็น "แรม 15 ค่ำ"', $moon['phase_name_th'] === 'แรม 15 ค่ำ',
      $moon['phase_name_th']);

// วันถัดจากเดือนดับต้องกลับมาเป็น "ขึ้น 1 ค่ำ"
$x = get($base . '/api/solunar.php?' . PATTANI . '&date=2026-08-13');
$moon = $x['json']['data']['moon'];
check('วันหลังเดือนดับ ชื่อเป็น "ขึ้น 1 ค่ำ"', $moon['phase_name_th'] === 'ขึ้น 1 ค่ำ',
      $moon['phase_name_th']);
check('วันหลังเดือนดับ อายุรีเซ็ตกลับใกล้ 0', $moon['age_days'] < 1.5, 'ได้ ' . $moon['age_days']);

echo "\n=== ชื่อข้างขึ้นข้างแรมภาษาไทย ===\n";

$x = get($base . '/api/solunar.php?' . PATTANI . '&date=2026-08-27');
check('2026-08-27 เป็น "ขึ้น 15 ค่ำ"',
      $x['json']['data']['moon']['phase_name_th'] === 'ขึ้น 15 ค่ำ',
      $x['json']['data']['moon']['phase_name_th']);

// ไล่ทั้งเดือน ชื่อต้องอยู่ในรูปแบบที่ถูกต้องเสมอ และไม่มีค่าเกินขอบเขต
$namesOk = true;
$badName = '';
foreach (range(1, 30) as $day) {
    $x = get($base . '/api/solunar.php?' . PATTANI . '&date=' . sprintf('2026-09-%02d', $day));
    $name = $x['json']['data']['moon']['phase_name_th'] ?? '';
    if (preg_match('/^(ขึ้น|แรม) (1[0-5]|[1-9]) ค่ำ$/u', $name) !== 1) {
        $namesOk = false;
        $badName = sprintf('2026-09-%02d -> %s', $day, $name);
        break;
    }
}
check('ทุกวันในเดือนกันยายน 2026 ได้ชื่อในรูปแบบ "ขึ้น/แรม n ค่ำ" (n = 1-15)', $namesOk, $badName);

echo "\n=== ช่วง major / minor ===\n";

$x = get($base . '/api/solunar.php?' . PATTANI . '&date=2026-08-08');
$dd = $x['json']['data'];
check('2026-08-08 มี major 2 ช่วง (ผ่านเมริเดียนบนและล่าง)',
      count($dd['major_periods']) === 2, 'ได้ ' . count($dd['major_periods']));
check('2026-08-08 มี minor 2 ช่วง (จันทร์ขึ้นและจันทร์ตก)',
      count($dd['minor_periods']) === 2, 'ได้ ' . count($dd['minor_periods']));

$majorLen = array_map('span', $dd['major_periods']);
$minorLen = array_map('span', $dd['minor_periods']);
check('major ยาวช่วงละ 2 ชั่วโมงพอดี', $majorLen === array_fill(0, count($majorLen), 7200),
      implode(',', $majorLen));
check('minor ยาวช่วงละ 1 ชั่วโมงพอดี', $minorLen === array_fill(0, count($minorLen), 3600),
      implode(',', $minorLen));

// minor ต้องมีเหตุการณ์จันทร์ขึ้น/ตกอยู่กึ่งกลางพอดี
$riseTs = strtotime($dd['moonrise']);
$centered = false;
foreach ($dd['minor_periods'] as $p) {
    if (abs((strtotime($p['start']) + strtotime($p['end'])) / 2 - $riseTs) < 1) {
        $centered = true;
    }
}
check('มี minor period ที่จันทร์ขึ้นอยู่กึ่งกลางพอดี', $centered);

// major ต้องคร่อมเวลาผ่านเมริเดียนบน 07:44 ตาม USNO
$hasTransit = false;
foreach ($dd['major_periods'] as $p) {
    if (hhmm($p['start']) === '06:44' && hhmm($p['end']) === '08:44') {
        $hasTransit = true;
    }
}
check('major คร่อมเวลาผ่านเมริเดียนบน 07:44 (06:44-08:44)', $hasTransit,
      json_encode($dd['major_periods'], JSON_UNESCAPED_SLASHES));

check('major เรียงตามเวลาเริ่ม',
      $dd['major_periods'][0]['start'] <= $dd['major_periods'][1]['start']);

// วันที่ไม่มีการผ่านเมริเดียนล่าง ต้องเหลือ major ช่วงเดียว ไม่ใช่เติมค่าปลอม
$x = get($base . '/api/solunar.php?' . PATTANI . '&date=2026-08-12');
check('2026-08-12 มี major ช่วงเดียว (ไม่มีการผ่านเมริเดียนล่าง)',
      count($x['json']['data']['major_periods']) === 1,
      'ได้ ' . count($x['json']['data']['major_periods']));

echo "\n=== พิกัดมีผลต่อคำตอบจริง ===\n";

$a = get($base . '/api/solunar.php?lat=6.87&lon=101.25&date=2026-08-08');
$b = get($base . '/api/solunar.php?lat=6.87&lon=98.25&date=2026-08-08');
// ไปทางตะวันตก 3 องศา ดวงจันทร์ขึ้นช้าลงราว 3 / (15.04 - 0.55) ชั่วโมง = 12.4 นาที
check('ลองจิจูดต่างกัน 3 องศา ทำให้จันทร์ขึ้นต่างกันราว 12 นาที',
      abs(strtotime($b['json']['data']['moonrise']) - strtotime($a['json']['data']['moonrise']) - 744) < 180,
      $a['json']['data']['moonrise'] . ' vs ' . $b['json']['data']['moonrise']);

$c = get($base . '/api/solunar.php?lat=-33.87&lon=151.21&date=2026-08-08');
check('ซีกโลกใต้ (ซิดนีย์) ยังตอบ 200 และได้เวลาต่างออกไป',
      $c['status'] === 200 && $c['json']['data']['moonrise'] !== $a['json']['data']['moonrise']);

// ขั้วโลกเหนือกลางฤดูหนาว ดวงจันทร์อาจอยู่เหนือขอบฟ้าทั้งวัน -> ต้องคืน null ไม่ใช่พัง
$p = get($base . '/api/solunar.php?lat=89&lon=0&date=2026-08-08');
check('ละติจูด 89 องศา ไม่ทำให้ระบบพัง', $p['status'] === 200, "ได้ {$p['status']}");
check('ละติจูด 89 องศา คืน null ได้อย่างถูกต้อง',
      $p['status'] === 200 && array_key_exists('moonrise', $p['json']['data']));

echo "\n=== ไม่ส่ง date = วันนี้ ===\n";

$today = (new DateTimeImmutable('now', new DateTimeZone('+07:00')))->format('Y-m-d');
$x = get($base . '/api/solunar.php?' . PATTANI);
check('ไม่ส่ง date -> ตอบ 200', $x['status'] === 200, "ได้ {$x['status']}");
check('ไม่ส่ง date -> ใช้วันนี้ตามเวลาไทย',
      isset($x['json']['data']['date']) && $x['json']['data']['date'] === $today,
      'ได้ ' . ($x['json']['data']['date'] ?? 'ไม่มี') . ' คาด ' . $today);

echo "\n=== ตรวจการรับค่าที่ไม่ถูกต้อง ===\n";

foreach ([
    'ไม่ส่ง lat'          => '/api/solunar.php?lon=101.25',
    'ไม่ส่ง lon'          => '/api/solunar.php?lat=6.87',
    'lat ไม่ใช่ตัวเลข'     => '/api/solunar.php?lat=abc&lon=101.25',
    'lon ไม่ใช่ตัวเลข'     => '/api/solunar.php?lat=6.87&lon=xyz',
    'lat เกิน 90'         => '/api/solunar.php?lat=90.1&lon=101.25',
    'lat ต่ำกว่า -90'      => '/api/solunar.php?lat=-90.1&lon=101.25',
    'lon เกิน 180'        => '/api/solunar.php?lat=6.87&lon=180.5',
    'lon ต่ำกว่า -180'     => '/api/solunar.php?lat=6.87&lon=-180.5',
    'date ผิดรูปแบบ'       => '/api/solunar.php?' . PATTANI . '&date=08-08-2026',
    'date สั้นเกินไป'      => '/api/solunar.php?' . PATTANI . '&date=2026-8-8',
    'date เป็นข้อความ'     => '/api/solunar.php?' . PATTANI . '&date=today',
    'date ไม่มีจริง'       => '/api/solunar.php?' . PATTANI . '&date=2026-02-30',
    'date เดือน 13'       => '/api/solunar.php?' . PATTANI . '&date=2026-13-01',
] as $label => $path) {
    $x = get($base . $path);
    $msg = isset($x['json']['error']['message']) ? $x['json']['error']['message'] : '';
    check("{$label} -> 400", $x['status'] === 400, "ได้ {$x['status']}");
    check("{$label} -> ข้อความเป็นภาษาไทย", preg_match('/\p{Thai}/u', $msg) === 1, $msg);
}

// ค่าขอบเขตที่ถูกต้องพอดีต้องผ่าน ไม่ใช่ถูกปฏิเสธ
foreach ([
    'lat = 90 พอดี'   => '/api/solunar.php?lat=90&lon=0&date=2026-08-08',
    'lat = -90 พอดี'  => '/api/solunar.php?lat=-90&lon=0&date=2026-08-08',
    'lon = 180 พอดี'  => '/api/solunar.php?lat=0&lon=180&date=2026-08-08',
    'lon = -180 พอดี' => '/api/solunar.php?lat=0&lon=-180&date=2026-08-08',
] as $label => $path) {
    $x = get($base . $path);
    check("{$label} -> 200", $x['status'] === 200, "ได้ {$x['status']}");
}

echo "\n=== ความปลอดภัยและวิธีเรียก ===\n";

$x = post($base . '/api/solunar.php?' . PATTANI);
check('POST ถูกปฏิเสธด้วย 405', $x['status'] === 405, "ได้ {$x['status']}");

$x = get($base . '/api/solunar.php?lat=' . rawurlencode("6.87' OR '1'='1") . '&lon=101.25');
check('ค่า lat แปลกปลอมถูกปฏิเสธด้วย 400', $x['status'] === 400, "ได้ {$x['status']}");

// ข้อความ error ต้องไม่หลุด path ของเซิร์ฟเวอร์หรือข้อความ exception ดิบ
$x = get($base . '/api/solunar.php?' . PATTANI . '&date=2026-02-30');
check('ข้อความ error ไม่มี path หรือชื่อไฟล์หลุดออกมา',
      strpos($x['body'], '.php') === false && strpos($x['body'], '/lib/') === false
          && stripos($x['body'], 'Exception') === false,
      substr($x['body'], 0, 160));

echo "\nผ่าน {$passed} ข้อ ไม่ผ่าน {$failed} ข้อ\n";
exit($failed === 0 ? 0 : 1);
