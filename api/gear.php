<?php
declare(strict_types=1);

/**
 * GET /api/gear.php?style=shore&depth=4.5
 * คืนกติกาแนะนำอุปกรณ์ที่ครอบคลุมความลึกที่ระบุ
 */

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/db.php';

fis_handle(function (): void {
    fis_require_get();

    $style = isset($_GET['style']) ? trim((string) $_GET['style']) : '';
    $depthRaw = isset($_GET['depth']) ? trim((string) $_GET['depth']) : '';

    if ($style === '') {
        fis_fail('ต้องระบุพารามิเตอร์ style เช่น shore หรือ boat', 400, 'missing_style');
    }
    if ($depthRaw === '') {
        fis_fail('ต้องระบุพารามิเตอร์ depth เป็นความลึกหน่วยเมตร', 400, 'missing_depth');
    }
    if (!is_numeric($depthRaw)) {
        fis_fail('depth ต้องเป็นตัวเลข', 400, 'invalid_depth');
    }

    $depth = (float) $depthRaw;
    // ขอบบนตรงกับ CHECK constraint ของ gear_rules
    if ($depth < 0 || $depth > 1000) {
        fis_fail('depth ต้องอยู่ระหว่าง 0 ถึง 1000 เมตร', 400, 'invalid_depth');
    }

    $stmt = fis_db()->prepare(
        'SELECT id, fishing_style, min_depth_m, max_depth_m,
                rod, reel, line_and_leader, lure_or_rig, safety_note
           FROM gear_rules
          WHERE fishing_style = :style
            AND :depth BETWEEN min_depth_m AND max_depth_m
          ORDER BY min_depth_m'
    );
    $stmt->execute([':style' => $style, ':depth' => $depth]);
    $rows = $stmt->fetchAll();

    $rules = [];
    foreach ($rows as $row) {
        $rules[] = [
            'id' => (int) $row['id'],
            'fishing_style' => $row['fishing_style'],
            'depth_range_m' => [
                'min' => (float) $row['min_depth_m'],
                'max' => (float) $row['max_depth_m'],
            ],
            'rod' => $row['rod'],
            'reel' => $row['reel'],
            'line_and_leader' => $row['line_and_leader'],
            'lure_or_rig' => $row['lure_or_rig'],
            'safety_note' => $row['safety_note'],
        ];
    }

    fis_json([
        'query' => ['style' => $style, 'depth_m' => $depth],
        'data' => $rules,
        'count' => count($rules),
    ]);
});
