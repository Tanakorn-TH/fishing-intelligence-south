<?php
declare(strict_types=1);

/**
 * ทดสอบ API ผ่าน HTTP จริง ใช้กับข้อมูลใน tests/fixtures.sql
 * รันด้วย: API_BASE=http://127.0.0.1:8080 php tests/api-test.php
 */

$base = getenv('API_BASE');
if (!is_string($base) || $base === '') {
    $base = 'http://127.0.0.1:8080';
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
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
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
        'method' => 'POST', 'ignore_errors' => true, 'timeout' => 10,
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

echo "ทดสอบกับ {$base}\n\n=== /api/spots.php ===\n";

$r = get($base . '/api/spots.php');
check('ตอบ 200', $r['status'] === 200, "ได้ {$r['status']}");
check('เป็น JSON ที่ parse ได้', is_array($r['json']), substr($r['body'], 0, 120));

$spots = isset($r['json']['data']) ? $r['json']['data'] : [];
check('คืนเฉพาะหมายสาธารณะ 1 รายการ', count($spots) === 1, 'ได้ ' . count($spots) . ' รายการ');

if (count($spots) === 1) {
    $s = $spots[0];
    check('ชื่อหมายถูกต้อง (ภาษาไทยไม่เพี้ยน)', $s['name'] === 'หมายทดสอบ', $s['name']);
    // จุดสำคัญที่สุดของชุดทดสอบนี้ ถ้าอ่านแกนสลับ ค่าจะกลับกัน
    check('ละติจูด = 6.87 (ไม่ใช่ 101.25)', abs($s['coordinates']['lat'] - 6.87) < 0.0001,
          'ได้ ' . var_export($s['coordinates']['lat'], true));
    check('ลองจิจูด = 101.25 (ไม่ใช่ 6.87)', abs($s['coordinates']['lon'] - 101.25) < 0.0001,
          'ได้ ' . var_export($s['coordinates']['lon'], true));
    check('ความลึกทั่วไป = 4.5', abs($s['depth']['typical_m'] - 4.5) < 0.0001);
    check('มีคำเตือนห้ามใช้เดินเรือติดมากับความลึก',
          isset($s['depth']['notice']) && strpos($s['depth']['notice'], 'ห้ามใช้เพื่อการเดินเรือ') !== false);
}

$r = post($base . '/api/spots.php');
check('POST ถูกปฏิเสธด้วย 405', $r['status'] === 405, "ได้ {$r['status']}");

echo "\n=== /api/gear.php ===\n";

$r = get($base . '/api/gear.php?style=shore&depth=4.5');
check('ตอบ 200', $r['status'] === 200, "ได้ {$r['status']}");
check('ความลึก 4.5 m แบบ shore เข้าเงื่อนไข 1 กติกา',
      isset($r['json']['count']) && $r['json']['count'] === 1,
      'ได้ ' . var_export(isset($r['json']['count']) ? $r['json']['count'] : null, true));
if (!empty($r['json']['data'])) {
    check('ได้ช่วง 0-5 m ตามที่ควร',
          $r['json']['data'][0]['depth_range_m']['min'] == 0.0 && $r['json']['data'][0]['depth_range_m']['max'] == 5.0);
    check('มี safety_note ติดมาด้วย', $r['json']['data'][0]['safety_note'] !== '');
}

$r = get($base . '/api/gear.php?style=boat&depth=20');
check('ความลึก 20 m แบบ boat เข้าเงื่อนไข 1 กติกา',
      isset($r['json']['count']) && $r['json']['count'] === 1);

$r = get($base . '/api/gear.php?style=shore&depth=900');
check('ความลึก 900 m แบบ shore ไม่เข้ากติกาใดเลย',
      isset($r['json']['count']) && $r['json']['count'] === 0);

echo "\n=== ตรวจการรับค่าที่ไม่ถูกต้อง ===\n";
foreach ([
    'ไม่ส่ง style' => '/api/gear.php?depth=5',
    'ไม่ส่ง depth' => '/api/gear.php?style=shore',
    'depth ไม่ใช่ตัวเลข' => '/api/gear.php?style=shore&depth=abc',
    'depth ติดลบ' => '/api/gear.php?style=shore&depth=-1',
    'depth เกิน 1000' => '/api/gear.php?style=shore&depth=5000',
] as $label => $path) {
    $r = get($base . $path);
    check("{$label} -> 400", $r['status'] === 400, "ได้ {$r['status']}");
}

$r = get($base . "/api/gear.php?style=" . rawurlencode("shore' OR '1'='1") . "&depth=5");
check('พยายาม SQL injection แล้วไม่คืนข้อมูล',
      $r['status'] === 200 && isset($r['json']['count']) && $r['json']['count'] === 0,
      "status {$r['status']}");

echo "\n=== /api/health.php ===\n";
$r = get($base . '/api/health.php');
check('ตอบ 200', $r['status'] === 200, "ได้ {$r['status']} body=" . substr($r['body'], 0, 200));
check('รายงาน ok = true', isset($r['json']['ok']) && $r['json']['ok'] === true);
check('เห็น pdo_mysql', !empty($r['json']['checks']['pdo_mysql']));
check('พบตารางครบ 8 ตาราง', isset($r['json']['checks']['tables_found']) && $r['json']['checks']['tables_found'] === 8);
// health endpoint ต้องไม่รั่วค่าเชื่อมต่อออกมา ต่อให้ผู้เรียกเป็นใครก็ตาม
$leaks = [];
foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
    $value = getenv($key);
    if (is_string($value) && $value !== '' && strpos($r['body'], $value) !== false) {
        $leaks[] = $key;
    }
}
check('ไม่รั่วชื่อฐานข้อมูล ชื่อผู้ใช้ หรือรหัสผ่าน', $leaks === [], 'พบ ' . implode(', ', $leaks));

echo "\nผ่าน {$passed} ข้อ ไม่ผ่าน {$failed} ข้อ\n";
exit($failed === 0 ? 0 : 1);
