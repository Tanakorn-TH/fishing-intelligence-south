<?php
declare(strict_types=1);

/**
 * ทดสอบ /api/weather.php ผ่าน HTTP จริง
 * รันด้วย: API_BASE=http://127.0.0.1:8099 php tests/weather-test.php
 *
 * ชุดนี้แยกจาก api-test.php เพราะไม่ต้องใช้ฐานข้อมูล แต่ต้องต่ออินเทอร์เน็ตออกไป Open-Meteo ได้
 */

$base = getenv('API_BASE');
if (!is_string($base) || $base === '') {
    $base = 'http://127.0.0.1:8099';
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
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 20];
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

echo "ทดสอบกับ {$base}\n\n=== /api/weather.php พิกัดถูกต้อง (ปัตตานี 6.87, 101.25) ===\n";

$r = get($base . '/api/weather.php?lat=6.87&lon=101.25');
check('ตอบ 200', $r['status'] === 200, "ได้ {$r['status']} body=" . substr($r['body'], 0, 200));
check('เป็น JSON ที่ parse ได้', is_array($r['json']), substr($r['body'], 0, 160));
check('Content-Type เป็น application/json; charset=utf-8',
      stripos($r['content_type'], 'application/json') === 0 && stripos($r['content_type'], 'utf-8') !== false,
      $r['content_type']);
// เพดาน timeout ของ endpoint คือ 8 วินาที บวกเวลาประกอบคำตอบแล้วต้องไม่เกินนี้
check('ตอบกลับภายใน 15 วินาที', $r['seconds'] < 15.0, sprintf('ใช้เวลา %.1f วินาที', $r['seconds']));

if ($r['status'] === 200 && is_array($r['json'])) {
    $data = isset($r['json']['data']) && is_array($r['json']['data']) ? $r['json']['data'] : [];
    $meta = isset($r['json']['meta']) && is_array($r['json']['meta']) ? $r['json']['meta'] : [];
    $current = isset($data['current']) && is_array($data['current']) ? $data['current'] : [];
    $hourly = isset($data['hourly']) && is_array($data['hourly']) ? $data['hourly'] : [];

    echo "\n--- โครงสร้างตามสัญญา: data.current ---\n";
    foreach ([
        'observed_at', 'temperature_c', 'wind_speed_kmh', 'wind_direction_deg',
        'wind_direction_label', 'wave_height_m', 'sea_temperature_c',
        'precipitation_probability_pct', 'pressure_hpa',
    ] as $field) {
        check("current มีคีย์ {$field}", array_key_exists($field, $current));
    }

    check('observed_at เป็น ISO 8601 พร้อม +07:00',
          isset($current['observed_at']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+07:00$/', (string) $current['observed_at']) === 1,
          var_export($current['observed_at'] ?? null, true));
    check('อุณหภูมิเป็นตัวเลขในช่วงที่เป็นไปได้ของภาคใต้',
          is_numeric($current['temperature_c'] ?? null) && $current['temperature_c'] > 0 && $current['temperature_c'] < 60,
          var_export($current['temperature_c'] ?? null, true));
    check('ความเร็วลมไม่ติดลบ',
          is_numeric($current['wind_speed_kmh'] ?? null) && $current['wind_speed_kmh'] >= 0,
          var_export($current['wind_speed_kmh'] ?? null, true));
    check('ทิศลมอยู่ในช่วง 0-360 องศา',
          is_int($current['wind_direction_deg'] ?? null) && $current['wind_direction_deg'] >= 0 && $current['wind_direction_deg'] <= 360,
          var_export($current['wind_direction_deg'] ?? null, true));

    $labels = ['เหนือ', 'ตะวันออกเฉียงเหนือ', 'ตะวันออก', 'ตะวันออกเฉียงใต้',
               'ใต้', 'ตะวันตกเฉียงใต้', 'ตะวันตก', 'ตะวันตกเฉียงเหนือ'];
    check('ทิศลมเป็นภาษาไทย 1 ใน 8 ทิศ',
          in_array($current['wind_direction_label'] ?? null, $labels, true),
          var_export($current['wind_direction_label'] ?? null, true));
    // ป้ายทิศต้องตรงกับองศาจริง ไม่ใช่แค่เป็นคำภาษาไทยเฉย ๆ
    if (is_int($current['wind_direction_deg'] ?? null)) {
        $expected = $labels[(int) floor(((($current['wind_direction_deg'] % 360) + 360) % 360 + 22.5) / 45) % 8];
        check('ป้ายทิศลมตรงกับองศาที่รายงาน', ($current['wind_direction_label'] ?? null) === $expected,
              'คาด ' . $expected . ' ได้ ' . var_export($current['wind_direction_label'] ?? null, true));
    }

    check('โอกาสฝนเป็น 0-100 หรือ null',
          !isset($current['precipitation_probability_pct'])
          || (is_int($current['precipitation_probability_pct'])
              && $current['precipitation_probability_pct'] >= 0
              && $current['precipitation_probability_pct'] <= 100),
          var_export($current['precipitation_probability_pct'] ?? null, true));
    check('ความกดอากาศอยู่ในช่วงที่สมเหตุสมผล',
          is_numeric($current['pressure_hpa'] ?? null) && $current['pressure_hpa'] > 800 && $current['pressure_hpa'] < 1200,
          var_export($current['pressure_hpa'] ?? null, true));
    // สัญญาอนุญาตให้เป็น null ได้ แต่ถ้ามีค่าต้องไม่ติดลบ
    check('ความสูงคลื่นเป็น null หรือตัวเลขไม่ติดลบ',
          $current['wave_height_m'] === null || (is_numeric($current['wave_height_m']) && $current['wave_height_m'] >= 0),
          var_export($current['wave_height_m'] ?? null, true));

    echo "\n--- โครงสร้างตามสัญญา: data.hourly ---\n";
    check('hourly คืน 24 ชั่วโมง', count($hourly) === 24, 'ได้ ' . count($hourly) . ' รายการ');
    if ($hourly !== []) {
        $first = $hourly[0];
        foreach (['time', 'temperature_c', 'wind_speed_kmh', 'wave_height_m',
                  'sea_temperature_c', 'weather_code'] as $field) {
            check("hourly[0] มีคีย์ {$field}", array_key_exists($field, $first));
        }
        check('hourly[0].time เป็น ISO 8601 พร้อม +07:00',
              preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+07:00$/', (string) ($first['time'] ?? '')) === 1,
              var_export($first['time'] ?? null, true));

        // ชั่วโมงแรกต้องเป็นชั่วโมงปัจจุบัน ไม่ใช่เที่ยงคืนของวันนี้
        $firstTs = strtotime((string) ($first['time'] ?? ''));
        check('hourly เริ่มที่ชั่วโมงปัจจุบัน ไม่ย้อนหลังเกิน 1 ชั่วโมง',
              $firstTs !== false && $firstTs > time() - 3700,
              var_export($first['time'] ?? null, true));

        $stepOk = true;
        for ($i = 1; $i < count($hourly); $i++) {
            $prev = strtotime((string) $hourly[$i - 1]['time']);
            $now = strtotime((string) $hourly[$i]['time']);
            if ($prev === false || $now === false || $now - $prev !== 3600) {
                $stepOk = false;
                break;
            }
        }
        check('เวลาใน hourly ห่างกันชั่วโมงละ 1 ชั่วโมงเรียงต่อกัน', $stepOk);

        $codesOk = true;
        $wavesOk = true;
        foreach ($hourly as $row) {
            if ($row['weather_code'] !== null && !is_int($row['weather_code'])) {
                $codesOk = false;
            }
            if ($row['wave_height_m'] !== null && !is_numeric($row['wave_height_m'])) {
                $wavesOk = false;
            }
        }
        check('weather_code เป็นจำนวนเต็มหรือ null ทุกแถว', $codesOk);
        check('wave_height_m เป็นตัวเลขหรือ null ทุกแถว (ไม่มีค่าที่แต่งขึ้น)', $wavesOk);
    }

    echo "\n--- meta ---\n";
    check('มี meta.source', isset($meta['source']) && $meta['source'] !== '', var_export($meta['source'] ?? null, true));
    check('meta.source บอกว่ามาจาก Open-Meteo',
          isset($meta['source']) && stripos((string) $meta['source'], 'open-meteo') !== false,
          var_export($meta['source'] ?? null, true));
    check('มี meta.source_url', isset($meta['source_url']) && strpos((string) $meta['source_url'], 'http') === 0);
    check('มี meta.license', isset($meta['license']) && $meta['license'] !== '');
    check('มี meta.fetched_at เป็น ISO 8601 พร้อม +07:00',
          isset($meta['fetched_at']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+07:00$/', (string) $meta['fetched_at']) === 1,
          var_export($meta['fetched_at'] ?? null, true));
    check('มี meta.cached เป็น boolean', isset($meta['cached']) && is_bool($meta['cached']));
}

echo "\n=== แคช ===\n";
$second = get($base . '/api/weather.php?lat=6.87&lon=101.25');
check('เรียกซ้ำแล้วยังตอบ 200', $second['status'] === 200, "ได้ {$second['status']}");
check('เรียกซ้ำภายใน 15 นาทีต้องมาจากแคช (meta.cached = true)',
      isset($second['json']['meta']['cached']) && $second['json']['meta']['cached'] === true,
      var_export($second['json']['meta']['cached'] ?? null, true));
check('คำตอบจากแคชคงค่า fetched_at เดิมไว้ ไม่ใช่เวลาปัจจุบัน',
      isset($r['json']['meta']['fetched_at'], $second['json']['meta']['fetched_at'])
      && $r['json']['meta']['fetched_at'] === $second['json']['meta']['fetched_at']);
check('คำตอบจากแคชตอบเร็วกว่า 2 วินาที', $second['seconds'] < 2.0, sprintf('ใช้เวลา %.2f วินาที', $second['seconds']));

echo "\n=== พิกัดกลางแผ่นดิน (ไม่มีข้อมูลคลื่น) ===\n";
$inland = get($base . '/api/weather.php?lat=18.79&lon=98.98');
check('ยังตอบ 200 ไม่ล้มทั้ง endpoint', $inland['status'] === 200, "ได้ {$inland['status']}");
check('ความสูงคลื่นเป็น null ไม่ใช่ค่าที่เดาขึ้นมา',
      array_key_exists('wave_height_m', $inland['json']['data']['current'] ?? [])
      && $inland['json']['data']['current']['wave_height_m'] === null,
      var_export($inland['json']['data']['current']['wave_height_m'] ?? 'ไม่มีคีย์', true));

echo "\n=== ตรวจการรับค่าที่ไม่ถูกต้อง ===\n";
foreach ([
    'ไม่ส่ง lat' => '/api/weather.php?lon=101.25',
    'ไม่ส่ง lon' => '/api/weather.php?lat=6.87',
    'ไม่ส่งอะไรเลย' => '/api/weather.php',
    'lat เกินช่วง (91)' => '/api/weather.php?lat=91&lon=101.25',
    'lat ต่ำกว่าช่วง (-91)' => '/api/weather.php?lat=-91&lon=101.25',
    'lon เกินช่วง (181)' => '/api/weather.php?lat=6.87&lon=181',
    'lon ต่ำกว่าช่วง (-181)' => '/api/weather.php?lat=6.87&lon=-181',
    'lat ไม่ใช่ตัวเลข' => '/api/weather.php?lat=abc&lon=101.25',
    'lon ไม่ใช่ตัวเลข' => '/api/weather.php?lat=6.87&lon=%3Cscript%3E',
    'lat ว่างเปล่า' => '/api/weather.php?lat=&lon=101.25',
] as $label => $path) {
    $bad = get($base . $path);
    check("{$label} -> 400", $bad['status'] === 400, "ได้ {$bad['status']}");
    $message = isset($bad['json']['error']['message']) ? (string) $bad['json']['error']['message'] : '';
    check("{$label} -> มี error.code และข้อความภาษาไทย",
          isset($bad['json']['error']['code']) && preg_match('/[ก-๙]/u', $message) === 1,
          substr($bad['body'], 0, 120));
    // ข้อความที่ส่งถึงผู้ใช้ต้องไม่มี path หรือร่องรอย exception ดิบ
    check("{$label} -> ไม่มี path หรือข้อความ exception หลุด",
          strpos($message, '/') === false && strpos($message, '\\') === false
          && stripos($message, 'exception') === false && stripos($message, '.php') === false,
          $message);
}

echo "\n=== เมธอดที่ไม่รองรับ ===\n";
$r = request($base . '/api/weather.php?lat=6.87&lon=101.25', 'POST');
check('POST ถูกปฏิเสธด้วย 405', $r['status'] === 405, "ได้ {$r['status']}");
check('405 คืน error.code = method_not_allowed',
      isset($r['json']['error']['code']) && $r['json']['error']['code'] === 'method_not_allowed',
      substr($r['body'], 0, 120));

echo "\n--- อุณหภูมิผิวน้ำทะเล ---\n";

/* น้ำทะเลแถบอันดามันและอ่าวไทยอยู่ราว 24-34 องศาตลอดปี
   ช่วงนี้กว้างพอจะไม่ล้มตามฤดู แต่แคบพอจะจับได้ถ้าปลายทางเปลี่ยนหน่วย
   หรือถ้าเราเผลอเอาอุณหภูมิอากาศมาใส่ช่องนี้ */
$seaNow = $current['sea_temperature_c'] ?? null;
check('อุณหภูมิน้ำเป็นตัวเลขหรือ null ตามสัญญา', $seaNow === null || is_numeric($seaNow),
      var_export($seaNow, true));
if (is_numeric($seaNow)) {
    check('อุณหภูมิน้ำอยู่ในช่วงที่เป็นไปได้ของทะเลไทย (24-34 องศา)',
          $seaNow >= 24.0 && $seaNow <= 34.0, var_export($seaNow, true));
}

$daily = $data['sea_temperature_daily'] ?? null;
check('มี data.sea_temperature_daily เป็นอาร์เรย์', is_array($daily), gettype($daily));

if (is_array($daily) && $daily !== []) {
    check('พยากรณ์อุณหภูมิน้ำครอบคลุมมากกว่าหนึ่งวัน', count($daily) > 1,
          'ได้ ' . count($daily) . ' วัน');

    $shape = true;
    $ordered = true;
    $sane = true;
    $previous = '';
    foreach ($daily as $row) {
        foreach (['date', 'min_c', 'max_c', 'mean_c'] as $field) {
            if (!array_key_exists($field, $row)) {
                $shape = false;
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['date'] ?? '')) !== 1) {
            $shape = false;
        }
        // วันต้องเรียงจากน้อยไปมาก ไม่งั้นแถบวันบนหน้าเว็บจะสลับกัน
        if ($previous !== '' && strcmp((string) $row['date'], $previous) <= 0) {
            $ordered = false;
        }
        $previous = (string) ($row['date'] ?? '');

        // ค่าต่ำสุดต้องไม่เกินค่าสูงสุด และค่าเฉลี่ยต้องอยู่ระหว่างสองค่านั้น
        if (is_numeric($row['min_c'] ?? null) && is_numeric($row['max_c'] ?? null)
            && is_numeric($row['mean_c'] ?? null)) {
            if ($row['min_c'] > $row['max_c']
                || $row['mean_c'] < $row['min_c'] - 0.05
                || $row['mean_c'] > $row['max_c'] + 0.05) {
                $sane = false;
            }
        }
    }
    check('ทุกแถวมีครบ date/min_c/max_c/mean_c และวันที่ถูกรูปแบบ', $shape);
    check('เรียงวันจากเก่าไปใหม่', $ordered);
    check('min <= mean <= max ทุกแถว', $sane);

    $firstDay = $daily[0]['date'] ?? '';
    check('วันแรกของพยากรณ์คือวันนี้', $firstDay === date('Y-m-d'),
          "ได้ {$firstDay} คาด " . date('Y-m-d'));
}

echo "\n--- แนวน้ำ (sea_front) ---\n";

$front = $data['sea_front'] ?? null;
check('มีคีย์ sea_front (เป็น object หรือ null)', array_key_exists('sea_front', $data));

if (is_array($front)) {
    foreach (['gradient_c_per_km', 'warmer_toward_deg', 'warmer_toward_label',
              'axes_used', 'requested_km', 'baseline_km'] as $field) {
        check("sea_front มีคีย์ {$field}", array_key_exists($field, $front));
    }

    check('ความชันไม่ติดลบ',
          is_numeric($front['gradient_c_per_km'] ?? null) && $front['gradient_c_per_km'] >= 0,
          var_export($front['gradient_c_per_km'] ?? null, true));

    /* ความชันเกิน 1 องศา/กม. ในทะเลชายฝั่งแปลว่าคำนวณผิด ไม่ใช่ทะเลแปลก
       ค่าที่วัดจริงในน่านน้ำเราอยู่ราว 0.004-0.06 */
    check('ความชันอยู่ในระดับที่เป็นไปได้ (ไม่เกิน 1 องศา/กม.)',
          !is_numeric($front['gradient_c_per_km'] ?? null) || $front['gradient_c_per_km'] <= 1.0,
          var_export($front['gradient_c_per_km'] ?? null, true));

    check('ใช้ข้อมูลอย่างน้อยหนึ่งแกน',
          is_int($front['axes_used'] ?? null) && $front['axes_used'] >= 1 && $front['axes_used'] <= 2,
          var_export($front['axes_used'] ?? null, true));

    /* ระยะที่ใช้จริงต้องผ่านเกณฑ์ขั้นต่ำ ไม่งั้นแปลว่าเผลอหารด้วยระยะสั้นเกินไป
       ซึ่งจะขยายทั้งความชันจริงและความคลาดเคลื่อนจากการปัดทศนิยม */
    check('ระยะที่ใช้คิดจริงไม่ต่ำกว่า 5 กม.',
          !is_numeric($front['baseline_km'] ?? null) || $front['baseline_km'] >= 5.0,
          var_export($front['baseline_km'] ?? null, true));

    if (is_int($front['warmer_toward_deg'] ?? null)) {
        check('ทิศน้ำอุ่นอยู่ในช่วง 0-359 องศา',
              $front['warmer_toward_deg'] >= 0 && $front['warmer_toward_deg'] < 360,
              var_export($front['warmer_toward_deg'], true));
    }
}

check('marine_raw ไม่หลุดออกไปกับคำตอบ (ก้อนใหญ่ ไม่มีใครใช้ฝั่งเบราว์เซอร์)',
      mb_strpos((string) $r['body'], 'marine_raw') === false,
      'พบ marine_raw ใน payload');

echo "\n--- คลอโรฟิลล์ ---\n";

check('มีคีย์ chlorophyll (เป็น object หรือ null)', array_key_exists('chlorophyll', $data));

$chl = $data['chlorophyll'] ?? null;
if (is_array($chl)) {
    foreach (['value_mg_m3', 'min_mg_m3', 'max_mg_m3', 'cells_used', 'observed_month', 'source'] as $field) {
        check("chlorophyll มีคีย์ {$field}", array_key_exists($field, $chl));
    }

    /* น้ำทะเลเปิดอยู่ราว 0.05 ส่วนน้ำชายฝั่งที่มีตะกอนขึ้นได้ถึงหลักสิบ
       เกิน 100 แปลว่าอ่านคอลัมน์ผิดหรือปลายทางเปลี่ยนหน่วย ไม่ใช่ทะเลแปลก */
    check('ค่าคลอโรฟิลล์อยู่ในช่วงที่เป็นไปได้ (0-100 mg/m3)',
          is_numeric($chl['value_mg_m3'] ?? null)
          && $chl['value_mg_m3'] > 0 && $chl['value_mg_m3'] <= 100.0,
          var_export($chl['value_mg_m3'] ?? null, true));

    check('min <= ค่ากลาง <= max',
          $chl['min_mg_m3'] <= $chl['value_mg_m3'] && $chl['value_mg_m3'] <= $chl['max_mg_m3'],
          "min {$chl['min_mg_m3']} กลาง {$chl['value_mg_m3']} max {$chl['max_mg_m3']}");

    check('ใช้ข้อมูลอย่างน้อยหนึ่งเซลล์',
          is_int($chl['cells_used'] ?? null) && $chl['cells_used'] >= 1,
          var_export($chl['cells_used'] ?? null, true));

    check('observed_month เป็นรูปแบบ YYYY-MM',
          preg_match('/^\d{4}-\d{2}$/', (string) ($chl['observed_month'] ?? '')) === 1,
          var_export($chl['observed_month'] ?? null, true));

    /* ต้องบอกที่มาติดมากับข้อมูลเสมอ เป็นกติกาเดียวกับทุก endpoint ในโปรเจค */
    check('ระบุที่มาของข้อมูล',
          is_string($chl['source'] ?? null) && mb_strpos($chl['source'], 'ERDDAP') !== false,
          var_export($chl['source'] ?? null, true));
}

echo "\nผ่าน {$passed} ข้อ ไม่ผ่าน {$failed} ข้อ\n";
exit($failed === 0 ? 0 : 1);
