<?php
declare(strict_types=1);

/**
 * GET /api/health.php
 * ใช้ยืนยันว่าดีพลอยขึ้นเซิร์ฟเวอร์แล้วทำงานได้จริง
 *
 * ตั้งใจไม่เปิดเผยชื่อฐานข้อมูล ชื่อผู้ใช้ หรือ path ใด ๆ
 * บอกแค่ว่า "ต่อได้หรือไม่" กับข้อมูลเวอร์ชันที่หน้าเว็บอื่นก็เห็นอยู่แล้ว
 */

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/conditions.php';

fis_handle(function (): void {
    fis_require_get();

    $checks = [
        'php_version' => PHP_VERSION,
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'mbstring' => extension_loaded('mbstring'),
        'database' => false,
        'schema_ready' => false,
    ];

    $problems = [];

    if (!$checks['pdo_mysql']) {
        $problems[] = 'ไม่พบ extension pdo_mysql';
    } else {
        try {
            $pdo = fis_db();
            $checks['database'] = true;
            $checks['mysql_version'] = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

            $tables = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME IN ('data_sources','bathymetry_contours','fishing_spots',
                                       'spot_depth_profiles','gear_rules','trip_plans',
                                       'trip_gear_items','catch_logs')"
            )->fetchColumn();

            $checks['tables_found'] = $tables;
            $checks['schema_ready'] = $tables === 8;
            if (!$checks['schema_ready']) {
                $problems[] = "พบตารางของสคีมา {$tables} จาก 8 ตาราง";
            }
        } catch (Throwable $e) {
            error_log('[fishing-api/health] ' . $e->getMessage());
            $problems[] = 'ต่อฐานข้อมูลไม่ได้';
        }
    }

    /* ตรวจว่าเซิร์ฟเวอร์ออกไปหาปลายทางภายนอกได้จริงไหม เปิดด้วย ?upstream=1
       ไม่ทำเป็นค่าเริ่มต้นเพราะต้องยิงออกนอกจริง ซึ่งช้าและไม่ควรทำทุกครั้งที่เช็คสุขภาพ

       ทำไมต้องมี: หลัง deploy รอบหนึ่ง คลอโรฟิลล์คืน null ทุกจุดบน production
       ทั้งที่ในเครื่องได้ครบ แล้วไม่มีทางรู้เลยว่าเป็นเพราะเมฆบัง เน็ตออกไม่ได้
       หรือโค้ดพัง การเดาสาเหตุจากภายนอกใช้เวลานานกว่าการมีปุ่มให้ถามตรง ๆ มาก */
    if ((string) ($_GET['upstream'] ?? '') === '1') {
        $upstream = [];
        foreach ([
            'open-meteo-forecast' => 'https://api.open-meteo.com/v1/forecast?latitude=7&longitude=100&current=temperature_2m',
            'open-meteo-marine' => 'https://marine-api.open-meteo.com/v1/marine?latitude=7&longitude=100&current=wave_height',
            'noaa-erddap' => FIS_CHL_BASE . '/' . FIS_CHL_DATASET . '.csv?chlorophyll%5B(last)%5D%5B(7.0):(7.02)%5D%5B(100.0):(100.02)%5D',
        ] as $name => $url) {
            $started = microtime(true);
            try {
                $body = fis_remote_get_text($url, 8);
                $upstream[$name] = [
                    'reachable' => true,
                    'seconds' => round(microtime(true) - $started, 2),
                    'bytes' => strlen($body),
                ];
            } catch (Throwable $e) {
                // ข้อความของ exception บอกแค่ host กับ HTTP status ไม่มี path หรือพิกัดผู้ใช้
                $upstream[$name] = [
                    'reachable' => false,
                    'seconds' => round(microtime(true) - $started, 2),
                    'reason' => $e->getMessage(),
                ];
                $problems[] = "ออกไปหา {$name} ไม่ได้";
            }
        }
        /* ทดสอบเส้นทางคลอโรฟิลล์ทั้งเส้น ไม่ใช่แค่ "ต่อติดไหม"
           เพราะครั้งที่แล้วปลายทางต่อติดแต่ค่ายังออกมา null ซึ่งแปลว่าปัญหา
           อยู่ที่การแยก CSV ไม่ใช่การเชื่อมต่อ แยกสองอย่างนี้ให้เห็นตั้งแต่แรก */
        try {
            $csv = fis_remote_get_text(fis_chlorophyll_url(6.95, 101.48), 8);
            $lines = preg_split('/?
/', trim($csv));
            $parsed = fis_chlorophyll_parse($csv);
            $upstream['chlorophyll-pipeline'] = [
                'bytes' => strlen($csv),
                'lines' => is_array($lines) ? count($lines) : 0,
                'first_line' => is_array($lines) ? substr((string) $lines[0], 0, 60) : null,
                'parsed' => $parsed === null ? null : $parsed['value_mg_m3'],
                'cells_used' => $parsed['cells_used'] ?? 0,
            ];
            if ($parsed === null) {
                $problems[] = 'ดึง CSV คลอโรฟิลล์ได้แต่แยกค่าไม่ออก';
            }
        } catch (Throwable $e) {
            $upstream['chlorophyll-pipeline'] = ['error' => $e->getMessage()];
            $problems[] = 'เส้นทางคลอโรฟิลล์ล้ม';
        }

        $checks['upstream'] = $upstream;
    }

    $ok = $problems === [];
    fis_json([
        'ok' => $ok,
        'checks' => $checks,
        'problems' => $problems,
    ], $ok ? 200 : 503);
});
