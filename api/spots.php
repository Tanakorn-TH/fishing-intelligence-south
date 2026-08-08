<?php
declare(strict_types=1);

/**
 * GET /api/spots.php
 * คืนรายการหมายตกปลาสาธารณะ พร้อมพิกัดและช่วงความลึกถ้ามี
 */

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/db.php';

fis_handle(function (): void {
    fis_require_get();

    // MySQL เก็บ SRID 4326 แบบละติจูดก่อน ทำให้ ST_X คืนละติจูด ไม่ใช่ลองจิจูดอย่างที่คนคุ้น PostGIS คาด
    // จึงใช้ ST_Latitude / ST_Longitude ที่ระบุความหมายชัดเจน ไม่ต้องจำลำดับแกน
    $sql = 'SELECT s.id, s.name, s.province, s.fishing_style,
                   ST_Latitude(s.geom) AS lat, ST_Longitude(s.geom) AS lon,
                   p.typical_depth_m, p.min_depth_m, p.max_depth_m, p.sample_radius_m,
                   ds.name AS depth_source
              FROM fishing_spots s
              LEFT JOIN spot_depth_profiles p ON p.spot_id = s.id
              LEFT JOIN data_sources ds ON ds.id = p.source_id
             WHERE s.is_public = TRUE
             ORDER BY s.province, s.name';

    $rows = fis_db()->query($sql)->fetchAll();

    $spots = [];
    foreach ($rows as $row) {
        $depth = null;
        if ($row['typical_depth_m'] !== null) {
            $depth = [
                'typical_m' => (float) $row['typical_depth_m'],
                'min_m' => $row['min_depth_m'] === null ? null : (float) $row['min_depth_m'],
                'max_m' => $row['max_depth_m'] === null ? null : (float) $row['max_depth_m'],
                'sample_radius_m' => (int) $row['sample_radius_m'],
                'source' => $row['depth_source'],
                // ข้อความนี้ต้องติดไปกับค่าความลึกทุกครั้งที่ส่งออก
                'notice' => 'ข้อมูลความลึกใช้เพื่อวางแผนตกปลาเท่านั้น ห้ามใช้เพื่อการเดินเรือ',
            ];
        }

        $spots[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'province' => $row['province'],
            'fishing_style' => $row['fishing_style'],
            'coordinates' => [
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
            ],
            'depth' => $depth,
        ];
    }

    fis_json(['data' => $spots, 'count' => count($spots)]);
});
