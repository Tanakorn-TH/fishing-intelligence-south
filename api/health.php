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

    $ok = $problems === [];
    fis_json([
        'ok' => $ok,
        'checks' => $checks,
        'problems' => $problems,
    ], $ok ? 200 : 503);
});
