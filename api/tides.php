<?php
declare(strict_types=1);

/**
 * GET /api/tides.php?lat=6.87&lon=101.25&date=2026-08-08
 * ระดับน้ำรายชั่วโมงและเวลาที่น้ำขึ้น/ลงเต็มที่ ตาม docs/api-contract.md
 *
 * ข้อมูลมาจาก Open-Meteo Marine API ตัวแปร sea_level_height_msl
 * ซึ่งเบื้องหลังเป็นแบบจำลอง MeteoFrance SMOC ความละเอียด 0.08 องศา (~8 กม.)
 *
 * ⚠️ เรื่องที่ต้องเข้าใจก่อนแก้ไฟล์นี้ (รายละเอียดเต็มอยู่ในสัญญา):
 * ค่าที่ได้อ้างอิง "ระดับน้ำทะเลปานกลาง" (MSL) ส่วนตารางน้ำของกรมอุทกศาสตร์
 * อ้างอิง "ระดับน้ำลงต่ำสุด" (chart datum) — เป็นคนละฐานกัน ตัวเลขจึงเทียบกันไม่ได้
 * สิ่งที่ใช้ได้จริงคือ "จังหวะ" และ "พิสัย" ไม่ใช่ความลึกสัมบูรณ์
 * ด้วยเหตุนี้ทุกคำตอบจึงต้องแนบ notice และ datum ไปด้วยเสมอ ห้ามตัดออกเพื่อความสวยงาม
 *
 * ตรรกะการดึงและประกอบข้อมูลอยู่ใน lib/conditions.php เพราะ /api/score.php ใช้ชุดเดียวกัน
 */

require_once __DIR__ . '/lib/conditions.php';

fis_handle(function (): void {
    fis_require_get();

    $lat = fis_conditions_coord('lat', -90.0, 90.0);
    $lon = fis_conditions_coord('lon', -180.0, 180.0);

    $tz = new DateTimeZone(FIS_TIDES_TZ);
    $date = fis_tides_date($tz);

    try {
        $payload = fis_tides_payload($lat, $lon, $date);
    } catch (FisTidesNoDataException $e) {
        fis_tides_fail_no_data($date, $tz);
        return;
    } catch (FisRemoteException $e) {
        // ปลายทางบอก "ไม่มีข้อมูลของจุดนี้" ผ่าน HTTP 400 ก็มี (เช่นขั้วโลกใต้)
        // ซึ่งเป็นเรื่องเดียวกับกรณีค่า null ล้วน ไม่ใช่ปลายทางล้ม จึงต้องตอบให้เหมือนกัน
        if (fis_tides_looks_like_no_data($e->getMessage())) {
            fis_tides_fail_no_data($date, $tz);
            return;
        }
        error_log('[fishing-api/tides] ' . $e->getMessage());
        fis_fail(
            'ขณะนี้ดึงข้อมูลระดับน้ำจากแหล่งข้อมูลภายนอกไม่ได้ (Open-Meteo ไม่ตอบหรือตอบช้าเกินไป) กรุณาลองใหม่อีกครั้งในอีกสักครู่',
            502,
            'upstream_unavailable'
        );
        return;
    }

    fis_json([
        'data' => $payload['data'],
        'meta' => [
            'source' => 'Open-Meteo Marine API',
            'source_url' => 'https://open-meteo.com/en/docs/marine-weather-api',
            'license' => 'CC BY 4.0',
            'model' => 'MeteoFrance SMOC ความละเอียด 0.08° (~8 กม.) รายชั่วโมง',
            'datum' => 'mean_sea_level',
            'accuracy' => 'เวลาน้ำขึ้น-ลงเต็มที่ประมาณจากข้อมูลรายชั่วโมงด้วยการหาจุดยอดแบบพาราโบลา '
                        . 'คลาดเคลื่อนได้ราว 5-15 นาที ค่าที่คืนจึงปัดเป็น 5 นาที',
            'fetched_at' => $payload['fetched_at'],
            'cached' => $payload['cached'],
        ],
    ]);
});
