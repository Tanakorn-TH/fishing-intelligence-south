<?php
declare(strict_types=1);

/**
 * ทดสอบ /api/places.php ผ่าน HTTP จริง
 * รันด้วย:  php -S 127.0.0.1:8098 -t .
 *           API_BASE=http://127.0.0.1:8098 php tests/places-test.php
 *
 * ชุดในระบบไม่ต้องต่อเน็ต ส่วนการเสริมด้วย geocoder ต้องต่อ
 * ข้อทดสอบจึงเขียนให้ผ่านได้แม้ geocoder ใช้ไม่ได้ ตราบใดที่ชุดในระบบยังทำงาน
 * เพราะนั่นคือพฤติกรรมที่ออกแบบไว้: geocoder ล้มแล้ว endpoint ต้องไม่ล้มตาม
 *
 * เรื่องที่ตรวจเข้มเป็นพิเศษคือ "พิกัดต้องอยู่ในภาคใต้จริง"
 * เพราะสัญญาห้ามแสดงพิกัดที่ประมาณเอง ถ้าชุดข้อมูลเพี้ยนต้องจับได้ที่นี่
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
    $body = @file_get_contents($url, false, stream_context_create(['http' => $options]));

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

function q(string $text): string
{
    return rawurlencode($text);
}

/** ขอบเขตภาคใต้ ต้องตรงกับค่าคงที่ใน api/places.php */
const LAT_MIN = 5.4;
const LAT_MAX = 11.5;
const LON_MIN = 97.0;
const LON_MAX = 102.5;

/**
 * 14 จังหวัดภาคใต้ครบทั้งภาค — ชุดข้อมูลต้องมีตัวจังหวัดให้เลือกทุกจังหวัด
 * ถ้าจังหวัดไหนหายไปโดยไม่ตั้งใจตอน regenerate ข้อทดสอบนี้จะจับได้ทันที
 */
const SOUTHERN_PROVINCES = [
    'ชุมพร', 'ระนอง', 'สุราษฎร์ธานี',
    'นครศรีธรรมราช', 'กระบี่', 'พังงา', 'ภูเก็ต', 'ตรัง', 'พัทลุง',
    'สงขลา', 'สตูล', 'ปัตตานี', 'ยะลา', 'นราธิวาส',
];

echo "ทดสอบกับ {$base}\n\n=== ไม่ส่งคำค้น: ต้องมีอะไรให้เลือกทันที ===\n";

// ไม่ส่ง limit มาเลย เพื่อทดสอบว่าโหมดเปิดดูรายการคืนของครบ ไม่ถูกตัดเหลือ 8 แบบโหมดค้นหา
$r = get($base . '/api/places.php');
check('ตอบ 200', $r['status'] === 200, "ได้ {$r['status']} body=" . substr($r['body'], 0, 160));
check('Content-Type เป็น application/json; charset=utf-8',
      stripos($r['content_type'], 'application/json') === 0 && stripos($r['content_type'], 'utf-8') !== false,
      $r['content_type']);

$rows = is_array($r['json']['data'] ?? null) ? $r['json']['data'] : [];
check('คืนรายการทั้งหมดมาให้เลือกโดยไม่ต้องพิมพ์', count($rows) >= 40, 'ได้ ' . count($rows));
check('ไม่ส่งพิกัดมา -> เรียงตามจังหวัด', ($r['json']['meta']['sorted_by'] ?? '') === 'province',
      var_export($r['json']['meta']['sorted_by'] ?? null, true));

// จังหวัดต้องติดไปทุกแถวเสมอ เพราะชื่ออำเภอซ้ำกันได้ข้ามจังหวัด
// ผู้ใช้ต้องอ่านออกว่ากำลังจะเลือกที่ไหน ไม่ใช่เดาเอง
$noProvince = array_filter($rows, static fn($x) => trim((string) ($x['province'] ?? '')) === '');
check('ทุกรายการระบุจังหวัดกำกับไว้', $noProvince === [],
      implode(', ', array_map(static fn($x) => (string) $x['name'], $noProvince)));

// เรียงตามจังหวัดแปลว่ารายการของจังหวัดเดียวกันต้องอยู่ติดกัน ไม่กระจัดกระจาย
$provinceOrder = array_map(static fn($x) => (string) $x['province'], $rows);
$firstSeen = [];
$contiguous = true;
foreach ($provinceOrder as $i => $province) {
    if (!isset($firstSeen[$province])) {
        $firstSeen[$province] = $i;
    } elseif ($i > 0 && $provinceOrder[$i - 1] !== $province) {
        $contiguous = false;
    }
}
check('รายการของจังหวัดเดียวกันอยู่ติดกันเป็นกลุ่ม', $contiguous);

echo "\n--- ชุดข้อมูลครอบคลุมครบทุกจังหวัดในขอบเขต ---\n";

$all = get($base . '/api/places.php');
$names = array_map(static fn($x) => (string) $x['name'], $all['json']['data'] ?? []);
$missing = [];
foreach (SOUTHERN_PROVINCES as $province) {
    if (!in_array($province, $names, true)) {
        $missing[] = $province;
    }
}
check('มีครบทุกจังหวัดในขอบเขต', $missing === [], 'ขาด: ' . implode(', ', $missing));

// บั๊กที่เคยเกิด: เพดาน limit ต่ำกว่าขนาดชุดข้อมูล จังหวัดท้าย ๆ เลยหายไปเงียบ ๆ
// โหมดเปิดดูรายการต้องคืนครบเสมอ ไม่ว่าชุดข้อมูลจะโตขึ้นแค่ไหน
check('โหมดเปิดดูรายการคืนครบ ไม่ถูกเพดานตัด',
      count($all['json']['data'] ?? []) === (int) ($all['json']['meta']['count'] ?? -1)
          && count($all['json']['data'] ?? []) >= count(SOUTHERN_PROVINCES),
      'ได้ ' . count($all['json']['data'] ?? []) . ' รายการ');

echo "
--- ต้องเป็นที่ที่มีน้ำให้ตกปลาเท่านั้น ---
";

// แอปนี้แสดงสภาพทะเล อำเภอกลางแผ่นดินจึงไม่มีอะไรให้ดู และหลอกผู้ใช้ว่ามีข้อมูล
// ชุดข้อมูลถูกคัดด้วยระยะห่างจากชายฝั่งใน scripts/build-places.py
// ข้อนี้กันไม่ให้จุดกลางแผ่นดินหลุดกลับเข้ามาตอน regenerate ครั้งถัดไป
$inlandNames = ['ทุ่งสง', 'ฉวาง', 'นาบอน', 'ถ้ำพรรณรา', 'สะเดา', 'ห้วยยอด', 'รัษฎา', 'หาดใหญ่'];
$leaked = [];
foreach ($rows as $row) {
    if (in_array((string) $row['name'], $inlandNames, true)) {
        $leaked[] = (string) $row['name'];
    }
}
check('ไม่มีอำเภอกลางแผ่นดินหลุดเข้ามา', $leaked === [], 'หลุดมา: ' . implode(', ', $leaked));

// เกาะกลางทะเลเคยถูกตัดทิ้ง เพราะข้อมูลชายฝั่ง 10m ไม่มีเกาะเล็ก ๆ อยู่
// ระบบจึงวัดว่าเกาะเต่าห่างแผ่นดินใหญ่ 70 กม. แล้วคิดว่าเป็นจุดกลางแผ่นดิน
$islands = ['เกาะสมุย', 'เกาะพะงัน', 'เกาะเต่า', 'เกาะลันตา', 'เกาะพีพี'];
$missingIslands = [];
foreach ($islands as $island) {
    $found = false;
    foreach ($rows as $row) {
        if ((string) $row['name'] === $island) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $missingIslands[] = $island;
    }
}
check('เกาะกลางทะเลไม่ถูกตัดทิ้ง', $missingIslands === [], 'ขาด: ' . implode(', ', $missingIslands));

// ยะลาไม่ติดทะเลแต่ตกลงกันว่าเก็บไว้ เพราะมีแหล่งน้ำจืดที่คนไปตกจริง
$yalaRows = array_filter($rows, static fn($x) => mb_strpos((string) $x['province'], 'ยะลา') !== false);
check('ยะลายังอยู่ครบแม้ไม่ติดทะเล (ข้อยกเว้นที่ตั้งใจ)', count($yalaRows) >= 3,
      'ได้ ' . count($yalaRows) . ' จุด');

// ทุกจังหวัดต้องมีตัวจังหวัดให้เลือก ไม่งั้นคนจังหวัดนั้นหาที่ของตัวเองไม่เจอ
// ตัวเมืองบางจังหวัดอยู่ลึกเข้าไป เช่นตรัง 20 กม. ถ้าคัดด้วยระยะทางล้วนจะหายไปทั้งจังหวัด
$seats = array_filter($rows, static fn($x) => ($x['kind'] ?? '') === 'province');
check('มีตัวจังหวัดครบทุกจังหวัด', count($seats) === count(SOUTHERN_PROVINCES),
      'ได้ ' . count($seats) . ' จาก ' . count(SOUTHERN_PROVINCES));


echo "\n--- ฝั่งทะเล: อันดามัน หรือ อ่าวไทย ---\n";

// สองฝั่งมีคลื่นลมและฤดูมรสุมคนละแบบ ถ้าไม่บอกฝั่ง ผู้ใช้อาจเลือกจุดผิดฝั่งโดยไม่รู้ตัว
$coasts = array_map(static fn($x) => (string) ($x['coast'] ?? ''), $rows);
check('ทุกรายการมีฟิลด์ coast', count(array_filter($rows, static fn($x) => array_key_exists('coast', $x))) === count($rows));
check('ทุกรายการมี coast_label ภาษาไทย',
      count(array_filter($rows, static fn($x) => preg_match('/\p{Thai}/u', (string) ($x['coast_label'] ?? '')) === 1)) === count($rows));
check('coast เป็นค่าที่กำหนดไว้เท่านั้น',
      array_diff(array_unique($coasts), ['andaman', 'gulf', 'lake', 'inland', '']) === [],
      implode(',', array_unique($coasts)));
check('มีทั้งฝั่งอันดามันและฝั่งอ่าวไทยในชุดข้อมูล',
      in_array('andaman', $coasts, true) && in_array('gulf', $coasts, true));

/** ดึงรายการแรกที่ชื่อตรงกับที่ค้น */
function firstNamed(string $base, string $name): array
{
    $x = get($base . '/api/places.php?q=' . q($name) . '&limit=5');
    foreach ($x['json']['data'] ?? [] as $row) {
        if (($row['name'] ?? '') === $name) {
            return $row;
        }
    }
    return [];
}

// จังหวัดฝั่งอันดามันต้องไม่ถูกป้ายว่าอ่าวไทย และกลับกัน
$phuket = firstNamed($base, 'ภูเก็ต');
check('ภูเก็ตอยู่ฝั่งอันดามัน', ($phuket['coast'] ?? '') === 'andaman',
      var_export($phuket['coast'] ?? null, true));
$pattani = firstNamed($base, 'ปัตตานี');
check('ปัตตานีอยู่ฝั่งอ่าวไทย', ($pattani['coast'] ?? '') === 'gulf',
      var_export($pattani['coast'] ?? null, true));

// สองกรณีที่ป้ายว่า "อันดามัน/อ่าวไทย" เฉย ๆ จะไม่ตรงความจริง
$yala = firstNamed($base, 'ยะลา');
check('ยะลาระบุว่าไม่ติดทะเล ไม่ถูกป้ายว่าติดฝั่งใดฝั่งหนึ่ง',
      ($yala['coast'] ?? '') === 'inland', var_export($yala['coast'] ?? null, true));
$phatthalung = firstNamed($base, 'พัทลุง');
check('พัทลุงระบุว่าเป็นทะเลสาบสงขลา ไม่ใช่ทะเลเปิด',
      ($phatthalung['coast'] ?? '') === 'lake', var_export($phatthalung['coast'] ?? null, true));

echo "\n--- โครงสร้างของแต่ละรายการ ---\n";

$first = $rows[0] ?? [];
foreach (['id', 'name', 'name_en', 'province', 'lat', 'lon', 'kind', 'coast', 'coast_label', 'source'] as $field) {
    check("มีคีย์ {$field}", array_key_exists($field, $first), implode(',', array_keys($first)));
}
check('name เป็นภาษาไทย',
      isset($first['name']) && preg_match('/\p{Thai}/u', (string) $first['name']) === 1,
      var_export($first['name'] ?? null, true));

echo "\n=== พิกัดทุกจุดต้องอยู่ในภาคใต้จริง ===\n";

// ข้อนี้สำคัญที่สุดของชุดนี้ — สัญญาห้ามแสดงพิกัดที่ประมาณเอง
// ถ้าชุดข้อมูลมีพิกัดหลุดออกนอกภาค แปลว่ามีค่าที่เชื่อไม่ได้ปนอยู่
$outOfBounds = [];
$badNumbers = [];
foreach ($rows as $row) {
    $lat = $row['lat'] ?? null;
    $lon = $row['lon'] ?? null;
    if (!is_numeric($lat) || !is_numeric($lon)) {
        $badNumbers[] = (string) ($row['name'] ?? '?');
        continue;
    }
    if ($lat < LAT_MIN || $lat > LAT_MAX || $lon < LON_MIN || $lon > LON_MAX) {
        $outOfBounds[] = sprintf('%s (%.3f,%.3f)', $row['name'], $lat, $lon);
    }
}
check('lat/lon เป็นตัวเลขทุกรายการ', $badNumbers === [], implode(', ', $badNumbers));
check('ทุกพิกัดอยู่ในกรอบภาคใต้', $outOfBounds === [], implode(' · ', $outOfBounds));

// พิกัดซ้ำกันแปลว่าชุดข้อมูลมีรายการซ้ำซ้อน ผู้ใช้จะเห็นชื่อซ้ำในช่องค้นหา
$coords = array_map(static fn($x) => sprintf('%.3f,%.3f', $x['lat'], $x['lon']), $rows);
check('ไม่มีพิกัดซ้ำกันในผลลัพธ์เดียว', count($coords) === count(array_unique($coords)));

echo "\n=== ค้นหาบางส่วนของคำภาษาไทย ===\n";

// เหตุผลที่ต้องมีชุดข้อมูลในระบบ: geocoder ภายนอกค้นแบบนี้ไม่เจอ
$partial = get($base . '/api/places.php?q=' . q('ปัต'));
check('ค้น "ปัต" -> 200', $partial['status'] === 200, "ได้ {$partial['status']}");
$partialRows = $partial['json']['data'] ?? [];
check('ค้น "ปัต" เจออย่างน้อย 1 รายการ', count($partialRows) >= 1, 'ได้ ' . count($partialRows));
check('ผลลัพธ์แรกคือ "ปัตตานี" (ตรงเป๊ะต้องมาก่อน)',
      ($partialRows[0]['name'] ?? '') === 'ปัตตานี',
      var_export($partialRows[0]['name'] ?? null, true));

$full = get($base . '/api/places.php?q=' . q('หาดใหญ่'));
check('ค้นชื่อเต็ม "หาดใหญ่" เจอ',
      ($full['json']['data'][0]['name'] ?? '') === 'หาดใหญ่',
      var_export($full['json']['data'][0]['name'] ?? null, true));

// ค้นด้วยชื่อจังหวัดต้องได้อำเภอในจังหวัดนั้นด้วย
// ชื่ออังกฤษมาจากรายชื่อทางการ (GeoThai) คนที่พิมพ์บนแป้นอังกฤษต้องค้นเจอด้วย
$english = get($base . '/api/places.php?q=' . q('phuket') . '&limit=5');
check('ค้นด้วยชื่ออังกฤษ "phuket" เจอ', count($english['json']['data'] ?? []) >= 1,
      'ได้ ' . count($english['json']['data'] ?? []));
check('ผลค้นอังกฤษตัวแรกเป็นภูเก็ต',
      ($english['json']['data'][0]['name'] ?? '') === 'ภูเก็ต',
      var_export($english['json']['data'][0]['name'] ?? null, true));
check('รายการในระบบมีชื่ออังกฤษกำกับ',
      trim((string) ($english['json']['data'][0]['name_en'] ?? '')) !== '',
      var_export($english['json']['data'][0]['name_en'] ?? null, true));

$byProvince = get($base . '/api/places.php?q=' . q('สงขลา') . '&limit=20');
$provinceRows = $byProvince['json']['data'] ?? [];
$inSongkhla = array_filter($provinceRows,
    static fn($x) => mb_strpos((string) ($x['province'] ?? ''), 'สงขลา') !== false);
check('ค้นชื่อจังหวัดแล้วได้อำเภอในจังหวัดนั้นมาด้วย', count($inSongkhla) >= 2,
      'ได้ ' . count($inSongkhla) . ' รายการในสงขลา');

// พิมพ์คำที่ไม่มีอยู่จริงต้องไม่พัง และต้องไม่แต่งผลลัพธ์ขึ้นมา
$nonsense = get($base . '/api/places.php?q=' . q('ฃฃฃไม่มีที่นี่ฃฃฃ'));
check('คำที่ไม่มีอยู่จริง -> ยังตอบ 200', $nonsense['status'] === 200, "ได้ {$nonsense['status']}");
check('คำที่ไม่มีอยู่จริง -> คืนรายการว่าง ไม่แต่งผลขึ้นมา',
      ($nonsense['json']['data'] ?? null) === [],
      json_encode($nonsense['json']['data'] ?? null, JSON_UNESCAPED_UNICODE));

echo "\n--- limit ---\n";

$limited = get($base . '/api/places.php?q=' . q('เกาะ') . '&limit=3');
check('limit=3 คืนไม่เกิน 3 รายการ', count($limited['json']['data'] ?? []) <= 3,
      'ได้ ' . count($limited['json']['data'] ?? []));
check('meta.count ตรงกับจำนวนที่คืนจริง',
      ($limited['json']['meta']['count'] ?? -1) === count($limited['json']['data'] ?? []));

echo "\n=== โหมด GPS: หาสถานที่ใกล้พิกัด ===\n";

// กลางอ่าวปัตตานี — จุดที่คนออกเรือจริง
$near = get($base . '/api/places.php?lat=6.95&lon=101.30&limit=5');
check('ตอบ 200', $near['status'] === 200, "ได้ {$near['status']}");
$nearRows = $near['json']['data'] ?? [];
check('คืนรายการมาให้', count($nearRows) >= 1, 'ได้ ' . count($nearRows));
check('ทุกรายการมี distance_km',
      $nearRows !== [] && count(array_filter($nearRows, static fn($x) => isset($x['distance_km']))) === count($nearRows));

$distances = array_map(static fn($x) => (float) $x['distance_km'], $nearRows);
$sorted = $distances;
sort($sorted);
check('เรียงจากใกล้ไปไกล', $distances === $sorted, implode(', ', $distances));
check('ที่ใกล้ที่สุดอยู่ในปัตตานี (พิกัดทดสอบอยู่กลางอ่าวปัตตานี)',
      mb_strpos((string) ($nearRows[0]['province'] ?? ''), 'ปัตตานี') !== false,
      var_export($nearRows[0] ?? null, true));
// จากกลางอ่าวถึงฝั่งไม่ควรเกินหลักสิบกิโลเมตร ถ้าเกินแปลว่าสูตรระยะทางผิด
check('ระยะทางสมเหตุสมผล (ไม่เกิน 60 กม.)',
      isset($distances[0]) && $distances[0] > 0 && $distances[0] < 60.0,
      'ได้ ' . ($distances[0] ?? 'ไม่มี') . ' กม.');
check('meta.in_region = true เมื่ออยู่ในภาคใต้',
      ($near['json']['meta']['in_region'] ?? null) === true);

check('meta.sorted_by = distance เมื่อส่งพิกัดมา',
      ($near['json']['meta']['sorted_by'] ?? '') === 'distance',
      var_export($near['json']['meta']['sorted_by'] ?? null, true));
check('ยังระบุจังหวัดกำกับทุกแถวแม้เรียงตามระยะทาง',
      $nearRows !== [] && count(array_filter($nearRows,
          static fn($x) => trim((string) ($x['province'] ?? '')) !== '')) === count($nearRows));

echo "\n--- ค้นหาพร้อมพิกัด: ต้องเรียงตามระยะทางด้วย ---\n";

// คนพิมพ์ค้นหาก็ยังอยากรู้ว่าอันไหนใกล้ตัวที่สุด ไม่ใช่แค่ตอนเปิดดูทั้งรายการ
$searchNear = get($base . '/api/places.php?q=' . q('เกาะ') . '&lat=6.95&lon=101.30&limit=10');
check('ค้นหาพร้อมพิกัด -> 200', $searchNear['status'] === 200, "ได้ {$searchNear['status']}");
$searchRows = $searchNear['json']['data'] ?? [];
check('ผลค้นหามี distance_km ติดมาด้วย',
      $searchRows !== [] && count(array_filter($searchRows, static fn($x) => isset($x['distance_km']))) === count($searchRows));
$searchDistances = array_map(static fn($x) => (float) ($x['distance_km'] ?? 0), $searchRows);
$expectedOrder = $searchDistances;
sort($expectedOrder);
check('ผลค้นหาเรียงจากใกล้ไปไกล', $searchDistances === $expectedOrder,
      implode(', ', $searchDistances));
check('meta.sorted_by = distance ในโหมดค้นหาด้วย',
      ($searchNear['json']['meta']['sorted_by'] ?? '') === 'distance');

echo "\n--- GPS นอกภาคใต้: ต้องบอกตรง ๆ ไม่ใช่แกล้งทำเป็นว่าอยู่ในพื้นที่ ---\n";

$far = get($base . '/api/places.php?lat=13.75&lon=100.50&limit=2'); // กรุงเทพ
check('ยังตอบ 200 ไม่พัง', $far['status'] === 200, "ได้ {$far['status']}");
check('meta.in_region = false', ($far['json']['meta']['in_region'] ?? null) === false);
check('notice บอกว่าอยู่นอกภาคใต้',
      mb_strpos((string) ($far['json']['meta']['notice'] ?? ''), 'นอกภาคใต้') !== false,
      (string) ($far['json']['meta']['notice'] ?? ''));
check('ระยะทางสะท้อนความจริงว่าไกล (เกิน 200 กม.)',
      (float) ($far['json']['data'][0]['distance_km'] ?? 0) > 200.0,
      var_export($far['json']['data'][0]['distance_km'] ?? null, true));

echo "\n=== meta และการบอกที่มา ===\n";

$meta = $r['json']['meta'] ?? [];
check('meta.source บอกว่ามาจากไหน', isset($meta['source']) && $meta['source'] !== '',
      var_export($meta['source'] ?? null, true));
check('meta.license มี', isset($meta['license']) && $meta['license'] !== '');
check('meta.coverage บอกขอบเขตพื้นที่', isset($meta['coverage']) && mb_strpos((string) $meta['coverage'], 'ภาคใต้') !== false);
// ต้องแยกให้ชัดว่านี่ไม่ใช่หมายตกปลา ไม่งั้นผู้ใช้จะเข้าใจว่าเป็นพิกัดที่แนะนำให้ไปตก
check('meta.notice แยกให้ชัดว่าไม่ใช่หมายตกปลา',
      mb_strpos((string) ($meta['notice'] ?? ''), 'ไม่ใช่หมายตกปลา') !== false,
      (string) ($meta['notice'] ?? ''));

echo "\n=== ตรวจการรับค่าที่ไม่ถูกต้อง ===\n";

foreach ([
    'ส่ง lat มาอย่างเดียว' => '/api/places.php?lat=6.87',
    'ส่ง lon มาอย่างเดียว' => '/api/places.php?lon=101.25',
    'lat เกินช่วง' => '/api/places.php?lat=91&lon=101.25',
    'lon เกินช่วง' => '/api/places.php?lat=6.87&lon=181',
    'lat ไม่ใช่ตัวเลข' => '/api/places.php?lat=abc&lon=101.25',
    'limit = 0' => '/api/places.php?limit=0',
    'limit เกินเพดาน' => '/api/places.php?limit=999',
    'limit ไม่ใช่ตัวเลข' => '/api/places.php?limit=abc',
] as $label => $path) {
    $bad = get($base . $path);
    check("{$label} -> 400", $bad['status'] === 400, "ได้ {$bad['status']}");
    $message = (string) ($bad['json']['error']['message'] ?? '');
    check("{$label} -> ข้อความไทย ไม่มี path หลุด",
          preg_match('/\p{Thai}/u', $message) === 1
              && strpos($message, '/') === false && stripos($message, '.php') === false,
          substr($bad['body'], 0, 120));
}

echo "\n=== ความปลอดภัย ===\n";

// คำค้นถูกเอาไปประกอบ URL ที่ยิงออกไปข้างนอก จึงต้องไม่ทำให้ระบบพังหรือหลุดอะไรออกมา
$injection = get($base . '/api/places.php?q=' . q("' OR '1'='1"));
check('คำค้นแปลกปลอมไม่ทำให้ระบบพัง', $injection['status'] === 200, "ได้ {$injection['status']}");
check('คำค้นแปลกปลอมไม่คืนข้อมูลมั่ว', is_array($injection['json']['data'] ?? null));

$xss = get($base . '/api/places.php?q=' . q('<script>alert(1)</script>'));
check('แท็กสคริปต์ในคำค้นไม่สะท้อนกลับมาแบบดิบ',
      strpos($xss['body'], '<script>') === false, substr($xss['body'], 0, 120));
check('วงเล็บมุมถูกตัดออกจากคำค้นที่สะท้อนกลับ',
      strpos((string) ($xss['json']['meta']['query'] ?? ''), '<') === false
          && strpos((string) ($xss['json']['meta']['query'] ?? ''), '>') === false,
      var_export($xss['json']['meta']['query'] ?? null, true));

// คำค้นยาวเกินเหตุไม่ควรถูกส่งต่อไปให้ปลายทางเต็ม ๆ
$long = get($base . '/api/places.php?q=' . q(str_repeat('ก', 300)));
check('คำค้นยาวเกินไปถูกตัดให้สั้นลง ไม่ส่งต่อทั้งก้อน',
      $long['status'] === 200 && mb_strlen((string) ($long['json']['meta']['query'] ?? '')) <= 60,
      'ยาว ' . mb_strlen((string) ($long['json']['meta']['query'] ?? '')));

$post = request($base . '/api/places.php', 'POST');
check('POST ถูกปฏิเสธด้วย 405', $post['status'] === 405, "ได้ {$post['status']}");
check('405 คืน error.code = method_not_allowed',
      ($post['json']['error']['code'] ?? '') === 'method_not_allowed');

echo "\nผ่าน {$passed} ข้อ ไม่ผ่าน {$failed} ข้อ\n";
exit($failed === 0 ? 0 : 1);
