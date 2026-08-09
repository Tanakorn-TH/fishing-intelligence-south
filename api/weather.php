<?php
declare(strict_types=1);

/**
 * GET /api/weather.php?lat=6.87&lon=101.25
 * สภาพอากาศทะเลปัจจุบันและรายชั่วโมง 24 ชั่วโมงข้างหน้า ตาม docs/api-contract.md
 *
 * ข้อมูลมาจาก Open-Meteo สองบริการ:
 *   - Forecast API  → อุณหภูมิ ลม ความกดอากาศ โอกาสฝน (ขาดไม่ได้)
 *   - Marine API    → ความสูงคลื่น (มีเฉพาะพิกัดที่อยู่ในโมเดลคลื่น จึงถือเป็นของแถม)
 *
 * ความสูงคลื่นเป็น null ได้ตามสัญญา เพราะคนเอาข้อมูลนี้ไปตัดสินใจออกทะเลจริง
 * การเดาค่าให้ดูสวยจึงอันตรายกว่าการบอกตรง ๆ ว่าไม่มีข้อมูล
 *
 * ตรรกะการดึงและประกอบข้อมูลอยู่ใน lib/conditions.php เพราะ /api/score.php ใช้ชุดเดียวกัน
 * ไฟล์นี้เหลือหน้าที่แค่ตรวจพารามิเตอร์และจัดรูปคำตอบ
 */

require_once __DIR__ . '/lib/conditions.php';

fis_handle(function (): void {
    fis_require_get();

    $lat = fis_conditions_coord('lat', -90.0, 90.0);
    $lon = fis_conditions_coord('lon', -180.0, 180.0);

    try {
        /* ขอเต็มช่วงเดียวกับที่ตัวคิดคะแนนใช้ ด้วยเหตุผลสองข้อ
           หนึ่ง sea_temperature_daily จะได้เป็นพยากรณ์จริง ไม่ใช่แค่วันนี้กับพรุ่งนี้
           สอง แคชก้อนเดียวกับ /api/score.php จึงยิงไปปลายทางน้อยลง
           แถวรายชั่วโมงที่ส่งกลับยังคงเท่าเดิมที่ FIS_WEATHER_HOURS ชั่วโมง */
        $payload = fis_weather_payload($lat, $lon, FIS_WEATHER_MAX_DAYS);
    } catch (FisRemoteException $e) {
        error_log('[fishing-api/weather] ' . $e->getMessage());
        fis_fail(
            'ขณะนี้ดึงข้อมูลสภาพอากาศจากแหล่งข้อมูลภายนอกไม่ได้ (Open-Meteo ไม่ตอบหรือตอบช้าเกินไป) กรุณาลองใหม่อีกครั้งในอีกสักครู่',
            502,
            'upstream_unavailable'
        );
        return; // ไปไม่ถึงบรรทัดนี้ เขียนไว้ให้เครื่องมือวิเคราะห์โค้ดเห็นว่าจบแล้ว
    }

    fis_json([
        'data' => $payload['data'],
        'meta' => [
            'source' => 'Open-Meteo',
            'source_url' => 'https://open-meteo.com/',
            'license' => 'CC BY 4.0',
            // เวลาที่ "ดึงข้อมูลจริง" ไม่ใช่เวลาที่ตอบคำขอนี้ ผู้ใช้จะได้รู้ว่าของเก่าแค่ไหน
            'fetched_at' => $payload['fetched_at'],
            'cached' => $payload['cached'],
        ],
    ]);
});
